"""Drivers behind the dangerous-action layer (Plan 38): what each action actually does on a device.

A driver is built for a worker's transport (`port_admin_executor(transport, enc)`) and has the executor signature
app/actions/safety.py expects. Drivers run in a worker (kind `actions`), never in the API process, and only after Plan
26's confirmation flow. DRIVERS lists the ones that exist; none runs unless DEVICE_ACTIONS_ENABLED is set, and a worker
on the disabled transport consumes no jobs.

Every OID a driver writes is re-derived from a MIB in the repository by its test, with the object's ACCESS checked
writable and its values checked against the MIB's own enumeration, because legacy action names are known to be wrong
(Plans 14, 17, 26).
"""
from __future__ import annotations

from dataclasses import replace
from typing import Any, Awaitable, Callable

import asyncpg

from app.actions.base import ActionFailed, Outcome
from app.core.crypto import EncryptionService
from app.core.security import CurrentUser
from app.polling.engine import scrub
from app.polling.transport import BoundedTransport, Credentials, SnmpTransport, Target, TransportError, VarBind
from app.repositories import access_profiles as profile_repo
from app.repositories import devices as device_repo
from app.repositories import interfaces as interface_repo

# RFC 1213 ifAdminStatus (ifEntry 7): up(1), down(2), testing(3); ACCESS read-write.
IF_ADMIN_STATUS = "1.3.6.1.2.1.2.2.1.7"
ADMIN_STATES = {"up": 1, "down": 2}
ADMIN_LABELS = {1: "up", 2: "down", 3: "testing"}

# BDCOM NMS-CONFIG-MGMT `operation` (nmsWorkGroup 15 1 1), INTEGER (0..127), ACCESS read-write: "1 means to save the
# commmand configuration. 2 means to save ifIndex configuration." Scalar, so instance .0. The name `operation` is
# defined in three BDCOM MIBs with three different OIDs; this is the one in NMS-CONFIG-MGMT.my.
BDCOM_CONFIG_OPERATION = "1.3.6.1.4.1.3320.20.15.1.1.0"
BDCOM_SAVE_COMMAND_CONFIG = 1
# Its sibling `result` (configuration 2), read-only, has no DESCRIPTION; it is recorded as read, never interpreted.
BDCOM_CONFIG_RESULT = "1.3.6.1.4.1.3320.20.15.1.2.0"
# Writing flash can take longer than a poll, and an access profile's timeout is at most 10 s (ck_access_profiles_timeout),
# so the save uses its own; the transport still caps it at its maximum.
SAVE_TIMEOUT_MS = 15_000


def write_credentials(row: asyncpg.Record, enc: EncryptionService) -> Credentials:
    """Credentials for a write: the v1/v2c write community, or the v3 user. The read community is not loaded at all."""
    pid = str(row["access_profile_id"])

    def secret(column: str, field_name: str) -> str | None:
        return enc.decrypt(row[column], profile_repo.aad(pid, field_name)) if row[column] else None

    return Credentials(
        version=row["snmp_version"], write_community=secret("snmp_write_community_enc", "snmp_write_community"),
        v3_username=row["snmp_v3_username"], v3_auth_protocol=row["snmp_v3_auth_protocol"],
        v3_auth_secret=secret("snmp_v3_auth_secret_enc", "snmp_v3_auth_secret"), v3_priv_protocol=row["snmp_v3_priv_protocol"],
        v3_priv_secret=secret("snmp_v3_priv_secret_enc", "snmp_v3_priv_secret"),
    )


async def _device_access(conn: asyncpg.Connection, user: CurrentUser, device_id: str, enc: EncryptionService
                         ) -> tuple[asyncpg.Record, asyncpg.Record, Credentials]:
    """The device, its access profile row and the write credentials, or ActionFailed before anything is sent."""
    device = await device_repo.action_target(conn, user, device_id)
    if device is None:
        raise ActionFailed("the device is not visible")
    if device["management_ip"] is None or device["access_profile_id"] is None:
        raise ActionFailed("the device has no management address or access profile")
    row = await profile_repo.write_credentials_row(conn, str(device["access_profile_id"]))
    if row is None:
        raise ActionFailed("the device's access profile is missing")
    creds = write_credentials(row, enc)
    if creds.version in ("v1", "v2c") and not creds.write_community:
        raise ActionFailed("the device's access profile has no write community")
    return device, row, creds


def _read_target(address: Target, creds: Credentials) -> Target:
    # Reads around a write use the write credentials too, so an action never needs the read community.
    return replace(address, credentials=replace(creds, community=creds.write_community))


def port_admin_executor(transport: SnmpTransport, enc: EncryptionService) -> Callable[..., Awaitable[Outcome]]:
    """`switch.port.set_admin_state`: set one interface's ifAdminStatus to up or down, then read it back."""

    async def run(conn: asyncpg.Connection, user: CurrentUser, target: dict[str, Any], params: dict[str, str]) -> Outcome:
        interface = await interface_repo.action_target(conn, user, target["interface_id"])
        if interface is None:
            raise ActionFailed("the interface is not visible")
        if interface["protected"] and params["state"] == "down":
            # An operator marked this port (typically the uplink the switch is managed through): shutting it down
            # would cut the NMS off from the device. Checked before anything is sent.
            raise ActionFailed("this interface is protected; an operator must remove the protection before it can be shut down")
        device, row, creds = await _device_access(conn, user, str(interface["device_id"]), enc)

        oid = f"{IF_ADMIN_STATUS}.{interface['if_index']}"
        desired = ADMIN_STATES[params["state"]]
        snmp = BoundedTransport(transport)
        address = Target(str(device["management_ip"]), creds)
        read = _read_target(address, creds)
        timeout = row["timeout_ms"] or 2000

        def state(value: Any) -> str:
            return ADMIN_LABELS.get(value, f"unknown ({value})") if value is not None else "no answer"

        try:
            before = (await snmp.get(read, [oid], timeout_ms=timeout, retries=1)).get(oid)
            context = {"interface": interface["name"], "if_index": interface["if_index"]}
            if before == desired:
                # Already as asked: nothing is written. The audit still records that it was requested.
                return Outcome(before={**context, "admin_status": state(before)},
                               after={**context, "admin_status": state(before), "changed": False})
            await snmp.set(address, [VarBind(oid, "integer", desired)], timeout_ms=timeout)
            after = (await snmp.get(read, [oid], timeout_ms=timeout, retries=1)).get(oid)
        except TransportError as exc:
            raise ActionFailed(scrub(str(exc) or type(exc).__name__, creds.secrets())) from exc
        if after != desired:
            raise ActionFailed(f"the device accepted the change but now reports {state(after)}")
        # `before` is the undo hint: setting admin_status back to it reverts the action.
        return Outcome(before={**context, "admin_status": state(before)},
                       after={**context, "admin_status": state(after), "changed": True})

    return run


def save_config_executor(transport: SnmpTransport, enc: EncryptionService) -> Callable[..., Awaitable[Outcome]]:
    """`switch.save_config` on BDCOM switches and OLTs: write 1 ("save the command configuration") to NMS-CONFIG-MGMT
    `operation`, then read `result` once and record it. Other vendors are refused before anything is sent: their save
    objects are not in any MIB in the repository (K-25).

    A save cannot be undone or read back: the MIB defines no way to tell that the running configuration is now in
    flash. Success means the device accepted the write; `result` is recorded raw because the MIB does not say what its
    values mean. A timeout is reported as a failure that may still have saved."""

    async def run(conn: asyncpg.Connection, user: CurrentUser, target: dict[str, Any], params: dict[str, str]) -> Outcome:
        device, row, creds = await _device_access(conn, user, target["device_id"], enc)
        if device["vendor_slug"] != "bdcom":
            raise ActionFailed("saving the configuration is only supported on BDCOM devices so far")
        snmp = BoundedTransport(transport)
        address = Target(str(device["management_ip"]), creds)
        context = {"device": device["name"]}
        try:
            await snmp.set(address, [VarBind(BDCOM_CONFIG_OPERATION, "integer", BDCOM_SAVE_COMMAND_CONFIG)],
                           timeout_ms=SAVE_TIMEOUT_MS)
        except TransportError as exc:
            reason = scrub(str(exc) or type(exc).__name__, creds.secrets())
            raise ActionFailed(f"{reason}; the device may still have saved its configuration") from exc
        try:
            result = (await snmp.get(_read_target(address, creds), [BDCOM_CONFIG_RESULT],
                                     timeout_ms=row["timeout_ms"] or 2000, retries=1)).get(BDCOM_CONFIG_RESULT)
        except TransportError:
            result = None  # the save was accepted; an unanswered follow-up read does not undo that
        return Outcome(before=context, after={**context, "saved": True, "result_code": result})

    return run


# action key -> driver factory. The only registry: prepare refuses an action that is not here.
DRIVERS: dict[str, Callable[[SnmpTransport, EncryptionService], Callable[..., Awaitable[Outcome]]]] = {
    "switch.port.set_admin_state": port_admin_executor,
    "switch.save_config": save_config_executor,
}
