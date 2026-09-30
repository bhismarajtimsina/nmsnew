"""Pure SNMP trap PDU decoding: no device, no socket, no database. Built PDUs are round-tripped through the same
library (pysnmp) that will decode a real one, using the real trap definitions from
backend/app/registry/trap_profile_data.py so a passing test means "this OID, however it travels, decodes to the
name the vendor config expects" - not just "some bytes decoded to some bytes"."""
import pytest
from pyasn1.codec.ber import encoder
from pysnmp.proto.api import v1, v2c

from app.registry.trap_profile_data import TRAP_PROFILES
from app.traps.decode import decode_trap, TrapDecodeError

BY_NAME = {(vendor, name): oid for vendor, name, oid, *_ in TRAP_PROFILES}


def v2c_trap(trap_oid: str, extra_varbinds=()) -> bytes:
    msg = v2c.Message()
    v2c.apiMessage.setDefaults(msg)
    v2c.apiMessage.setCommunity(msg, "public")
    pdu = v2c.TrapPDU()
    v2c.apiTrapPDU.setDefaults(pdu)
    varbinds = [((1, 3, 6, 1, 6, 3, 1, 1, 4, 1, 0), v2c.ObjectIdentifier(tuple(int(p) for p in trap_oid.split("."))))]
    varbinds.extend(extra_varbinds)
    v2c.apiTrapPDU.setVarBinds(pdu, varbinds)
    v2c.apiMessage.setPDU(msg, pdu)
    return encoder.encode(msg)


def test_a_v2c_trap_decodes_to_the_real_catalogued_oid_for_every_vendor():
    # One real trap per vendor, decoded exactly as a modern device would send it: the OID travels verbatim.
    for vendor, name in [("global", "LinkDown"), ("bdcom", "OnuDyingGaspNotification"),
                        ("cdata", "onuOnlineStatusChange"), ("huawei", "hwEponConfigChangeOntDiscTrap")]:
        oid = BY_NAME[(vendor, name)]
        decoded = decode_trap(v2c_trap(oid))
        assert decoded.version == "v2c" and decoded.trap_oid == oid, f"{vendor}/{name}"


def test_a_v2c_trap_carries_its_varbinds():
    data = v2c_trap(BY_NAME[("bdcom", "OnuDyingGaspNotification")],
                    [((1, 3, 6, 1, 4, 1, 3320, 10, 3, 8, 2, 1), v2c.OctetString("onu-serial-42"))])
    decoded = decode_trap(data)
    assert decoded.varbinds["1.3.6.1.4.1.3320.10.3.8.2.1"] == "onu-serial-42"


def test_a_v1_generic_trap_maps_to_the_standard_notification_oid():
    msg = v1.Message()
    v1.apiMessage.setDefaults(msg)
    v1.apiMessage.setCommunity(msg, "public")
    pdu = v1.TrapPDU()
    v1.apiTrapPDU.setDefaults(pdu)
    v1.apiTrapPDU.setEnterprise(pdu, (1, 3, 6, 1, 4, 1, 3320))
    v1.apiTrapPDU.setAgentAddr(pdu, "10.0.0.5")
    v1.apiTrapPDU.setGenericTrap(pdu, "linkDown")
    v1.apiMessage.setPDU(msg, pdu)
    decoded = decode_trap(encoder.encode(msg))
    assert decoded.version == "v1" and decoded.trap_oid == BY_NAME[("global", "LinkDown")]


def test_a_v1_enterprise_specific_trap_appends_dot_zero_then_the_specific_number():
    # RFC 3584 section 3.1's fixed v1-to-v2 conversion rule, not something choosable.
    msg = v1.Message()
    v1.apiMessage.setDefaults(msg)
    v1.apiMessage.setCommunity(msg, "public")
    pdu = v1.TrapPDU()
    v1.apiTrapPDU.setDefaults(pdu)
    v1.apiTrapPDU.setEnterprise(pdu, (1, 3, 6, 1, 4, 1, 3320, 10, 3, 8))
    v1.apiTrapPDU.setAgentAddr(pdu, "10.0.0.5")
    v1.apiTrapPDU.setGenericTrap(pdu, "enterpriseSpecific")
    v1.apiTrapPDU.setSpecificTrap(pdu, 2)
    v1.apiMessage.setPDU(msg, pdu)
    decoded = decode_trap(encoder.encode(msg))
    assert decoded.trap_oid == "1.3.6.1.4.1.3320.10.3.8.0.2"


def test_garbage_bytes_are_rejected_not_crashed_on():
    with pytest.raises(TrapDecodeError):
        decode_trap(b"this is not an SNMP message")
    with pytest.raises(TrapDecodeError):
        decode_trap(b"")


def test_a_well_formed_non_trap_pdu_is_rejected():
    msg = v2c.Message()
    v2c.apiMessage.setDefaults(msg)
    pdu = v2c.GetRequestPDU()
    v2c.apiPDU.setDefaults(pdu)
    v2c.apiMessage.setPDU(msg, pdu)
    with pytest.raises(TrapDecodeError, match="not a trap PDU"):
        decode_trap(encoder.encode(msg))


def test_a_v2c_trap_with_no_snmp_trap_oid_varbind_is_rejected():
    msg = v2c.Message()
    v2c.apiMessage.setDefaults(msg)
    pdu = v2c.TrapPDU()
    v2c.apiTrapPDU.setDefaults(pdu)
    v2c.apiTrapPDU.setVarBinds(pdu, [((1, 3, 6, 1, 2, 1, 1, 1, 0), v2c.OctetString("no trap oid here"))])
    v2c.apiMessage.setPDU(msg, pdu)
    with pytest.raises(TrapDecodeError, match="snmpTrapOID"):
        decode_trap(encoder.encode(msg))


def test_trailing_bytes_after_a_valid_message_are_rejected():
    # Rejected by decodeMessageVersion itself, which checks the declared SEQUENCE length against the whole buffer.
    data = v2c_trap(BY_NAME[("global", "LinkUp")]) + b"\x00\x01\x02"
    with pytest.raises(TrapDecodeError):
        decode_trap(data)
