"""Decodes a raw SNMP trap datagram (v1 Trap-PDU or v2c SNMPv2-Trap-PDU) into a normalized shape.

Pure: no network I/O, no device contact. It only parses bytes the UDP listener (D-19, not yet built) has already
received - the direction of contact is device -> us, and decoding a byte string touches nothing.

D-17 (SNMP library) names `pysnmp` as the candidate; this uses `pysnmp-lextudio`, the actively maintained fork with a
complete, offline PDU codec (`pysnmp.proto.api`) that needs no socket to encode or decode a message. SNMPv3 is not
supported here - decrypting it needs the engine's own USM state, a bigger piece of D-17 this does not settle.

Tested against PDUs this project constructs itself with the same library, not an operator-captured fixture: nothing
here has been verified against a real device's trap bytes yet (safety-and-verification-policy.md, D-17 spike).
"""
from __future__ import annotations

from dataclasses import dataclass, field

from pyasn1.codec.ber import decoder
from pysnmp.proto import api

# RFC 3584 §3.1's fixed mapping from a v1 trap's genericTrap field to the standard SNMPv2 notification OID -
# everything except enterpriseSpecific, which instead uses the trap's own enterprise OID plus its specificTrap number.
_GENERIC_TRAP_OID = {
    "coldStart": "1.3.6.1.6.3.1.1.5.1",
    "warmStart": "1.3.6.1.6.3.1.1.5.2",
    "linkDown": "1.3.6.1.6.3.1.1.5.3",
    "linkUp": "1.3.6.1.6.3.1.1.5.4",
    "authenticationFailure": "1.3.6.1.6.3.1.1.5.5",
    "egpNeighborLoss": "1.3.6.1.6.3.1.1.5.6",
}
SNMP_TRAP_OID_VARBIND = "1.3.6.1.6.3.1.1.4.1.0"


class TrapDecodeError(ValueError):
    """The datagram is not a well-formed SNMP v1/v2c trap. Never raised for a reason that depends on trap content
    being from a known device or a known vendor - that judgment belongs to the caller, not the decoder."""


@dataclass
class DecodedTrap:
    version: str  # "v1" or "v2c"
    community: str
    trap_oid: str  # the notification's own OID, uniform across v1 and v2c
    varbinds: dict[str, str] = field(default_factory=dict)


def decode_trap(data: bytes) -> DecodedTrap:
    try:
        msg_version = api.decodeMessageVersion(data)
    except Exception as exc:  # noqa: BLE001 - untrusted network input; any parse failure is just "not decodable"
        raise TrapDecodeError(f"not a recognizable SNMP message: {exc}") from exc

    proto_mod = api.protoModules.get(msg_version)
    if proto_mod is None or msg_version not in (api.protoVersion1, api.protoVersion2c):
        raise TrapDecodeError(f"unsupported SNMP version: {msg_version!r}")

    try:
        message, _rest = decoder.decode(data, asn1Spec=proto_mod.Message())
    except Exception as exc:  # noqa: BLE001
        raise TrapDecodeError(f"malformed SNMP message: {exc}") from exc
    # decodeMessageVersion above already rejected a buffer with trailing bytes: it checks the declared top-level
    # SEQUENCE length against the whole buffer's length before this second decode ever runs.

    community = str(proto_mod.apiMessage.getCommunity(message))
    pdu = proto_mod.apiMessage.getPDU(message)
    if not isinstance(pdu, proto_mod.TrapPDU):
        raise TrapDecodeError(f"not a trap PDU: {type(pdu).__name__}")

    if msg_version == api.protoVersion1:
        generic = str(proto_mod.apiTrapPDU.getGenericTrap(pdu))
        if generic == "enterpriseSpecific":
            enterprise = proto_mod.apiTrapPDU.getEnterprise(pdu).prettyPrint()
            specific = int(proto_mod.apiTrapPDU.getSpecificTrap(pdu))
            trap_oid = f"{enterprise}.0.{specific}"
        else:
            trap_oid = _GENERIC_TRAP_OID[generic]
        varbinds = {oid.prettyPrint(): val.prettyPrint() for oid, val in proto_mod.apiTrapPDU.getVarBinds(pdu)}
        return DecodedTrap(version="v1", community=community, trap_oid=trap_oid, varbinds=varbinds)

    # v2c: the notification's identity travels as the first varbind's value (snmpTrapOID.0), not as separate fields.
    varbinds = {oid.prettyPrint(): val.prettyPrint() for oid, val in proto_mod.apiPDU.getVarBinds(pdu)}
    trap_oid = varbinds.get(SNMP_TRAP_OID_VARBIND)
    if trap_oid is None:
        raise TrapDecodeError("v2c trap has no snmpTrapOID.0 varbind")
    return DecodedTrap(version="v2c", community=community, trap_oid=trap_oid, varbinds=varbinds)
