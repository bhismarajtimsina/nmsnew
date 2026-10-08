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
        device = await device_repo.action_target(conn, user, str(interface["device_id"]))
        if device is None or device["management_ip"] is None or device["access_profile_id"] is None:
            raise ActionFailed("the device has no management address or access profile")
        row = await profile_repo.write_credentials_row(conn, str(device["access_profile_id"]))
        if row is None:
            raise ActionFailed("the device's access profile is missing")
        creds = write_credentials(row, enc)
        if creds.version in ("v1", "v2c") and not creds.write_community:
            raise ActionFailed("the device's access profile has no write community")

        oid = f"{IF_ADMIN_STATUS}.{interface['if_index']}"
        desired = ADMIN_STATES[params["state"]]
        snmp = BoundedTransport(transport)
        address = Target(str(device["management_ip"]), creds)
        # The before/after reads use the write credentials too, so this path never needs the read community.
        read = replace(address, credentials=replace(creds, community=creds.write_community))
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


# action key -> driver factory. The only registry: prepare refuses an action that is not here.
DRIVERS: dict[str, Callable[[SnmpTransport, EncryptionService], Callable[..., Awaitable[Outcome]]]] = {
    "switch.port.set_admin_state": port_admin_executor,
}
