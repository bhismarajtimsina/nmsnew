"""The UDP trap-receiver, exercised over loopback with packets this test builds itself - the same synthetic-but-real
PDUs test_trap_decode.py uses, sent over a real socket instead of decoded in-process. No device is involved: both
ends of every packet in this file are this test process, on 127.0.0.1."""
import asyncio
import socket

import pytest_asyncio
from pyasn1.codec.ber import encoder
from pysnmp.proto.api import v2c

from app.core.crypto import EncryptionService
from app.core.database import create_pool
from app.traps.listener import serve
from tests.helpers import make_device
from tests.polling_helpers import COMMUNITY, make_access_profile


@pytest_asyncio.fixture
async def pool(clean):
    p = await create_pool()
    yield p
    await p.close()


def v2c_trap(trap_oid: str, community: str = "public") -> bytes:
    msg = v2c.Message()
    v2c.apiMessage.setDefaults(msg)
    v2c.apiMessage.setCommunity(msg, community)
    pdu = v2c.TrapPDU()
    v2c.apiTrapPDU.setDefaults(pdu)
    v2c.apiTrapPDU.setVarBinds(pdu, [((1, 3, 6, 1, 6, 3, 1, 1, 4, 1, 0), v2c.ObjectIdentifier(tuple(int(p) for p in trap_oid.split("."))))])
    v2c.apiMessage.setPDU(msg, pdu)
    return encoder.encode(msg)


def _total(protocol) -> int:
    c = protocol.counters
    return c.accepted + c.unknown + c.unknown_source + c.bad_community + c.malformed


async def _send_and_drain(protocol, sock, data: bytes, port: int) -> None:
    before = _total(protocol)
    sock.sendto(data, ("127.0.0.1", port))
    for _ in range(100):  # the datagram arrives asynchronously; give the event loop room to deliver and handle it
        if _total(protocol) > before:
            break
        await asyncio.sleep(0.02)
    else:
        raise AssertionError("the listener never counted the packet")
    await protocol.drain()


async def test_a_known_trap_from_a_registered_device_is_stored(pool, db):
    await make_device(db, "sw1", ip="127.0.0.1")
    transport, protocol = await serve(pool, host="127.0.0.1", port=0)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.6.3.1.1.5.3"), port)
        assert protocol.counters.accepted == 1
        row = await db.fetchrow("select trap_name, host(source_ip) as ip from trap_history")
        assert row["trap_name"] == "LinkDown" and row["ip"] == "127.0.0.1"
    finally:
        sock.close()
        transport.close()


async def test_a_trap_from_an_unregistered_source_is_dropped_and_counted(pool, db):
    transport, protocol = await serve(pool, host="127.0.0.1", port=0)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.6.3.1.1.5.4"), port)
        assert protocol.counters.unknown_source == 1 and protocol.counters.accepted == 0
        assert await db.fetchval("select count(*) from trap_history") == 0
    finally:
        sock.close()
        transport.close()


async def test_garbage_bytes_are_counted_as_malformed_not_a_crash(pool, db):
    await make_device(db, "sw1", ip="127.0.0.1")
    transport, protocol = await serve(pool, host="127.0.0.1", port=0)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, b"not an snmp trap", port)
        assert protocol.counters.malformed == 1
        # the listener is still alive: a real trap sent right after is handled normally
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.6.3.1.1.5.3"), port)
        assert protocol.counters.accepted == 1
    finally:
        sock.close()
        transport.close()


async def test_an_unknown_oid_from_a_registered_device_is_accepted_and_counted_separately(pool, db):
    await make_device(db, "sw1", ip="127.0.0.1")
    transport, protocol = await serve(pool, host="127.0.0.1", port=0)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.4.1.99999.1.1.1"), port)
        assert protocol.counters.unknown == 1 and protocol.counters.accepted == 0
        assert await db.fetchval("select count(*) from trap_history") == 1
    finally:
        sock.close()
        transport.close()


async def test_by_default_a_mismatched_community_is_still_accepted(pool, db):
    """check_community is off by default, matching legacy's own `_env('TRAP_SERVICE_CHECK_COMMUNITY', false)` - a
    trap's community is not even read from the device's access profile unless explicitly turned on."""
    profile = await make_access_profile(db)
    device_id = str(await db.fetchval(
        "insert into devices (name, management_ip, device_type, access_profile_id) values ('sw1', '127.0.0.1', 'switch', $1::uuid) returning id",
        profile,
    ))
    transport, protocol = await serve(pool, host="127.0.0.1", port=0)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.6.3.1.1.5.3", community="totally-wrong"), port)
        assert protocol.counters.accepted == 1 and protocol.counters.bad_community == 0
        assert await db.fetchval("select count(*) from trap_history where device_id = $1::uuid", device_id) == 1
    finally:
        sock.close()
        transport.close()


async def test_check_community_on_accepts_a_matching_community(pool, db):
    profile = await make_access_profile(db)
    device_id = str(await db.fetchval(
        "insert into devices (name, management_ip, device_type, access_profile_id) values ('sw1', '127.0.0.1', 'switch', $1::uuid) returning id",
        profile,
    ))
    enc = EncryptionService.from_settings()
    transport, protocol = await serve(pool, host="127.0.0.1", port=0, enc=enc, check_community=True)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.6.3.1.1.5.3", community=COMMUNITY), port)
        assert protocol.counters.accepted == 1 and protocol.counters.bad_community == 0
        assert await db.fetchval("select count(*) from trap_history where device_id = $1::uuid", device_id) == 1
    finally:
        sock.close()
        transport.close()


async def test_check_community_on_drops_a_mismatched_community_and_stores_nothing(pool, db):
    profile = await make_access_profile(db)
    await db.execute(
        "insert into devices (name, management_ip, device_type, access_profile_id) values ('sw1', '127.0.0.1', 'switch', $1::uuid)",
        profile,
    )
    enc = EncryptionService.from_settings()
    transport, protocol = await serve(pool, host="127.0.0.1", port=0, enc=enc, check_community=True)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.6.3.1.1.5.3", community="not-the-real-one"), port)
        assert protocol.counters.bad_community == 1 and protocol.counters.accepted == 0
        assert await db.fetchval("select count(*) from trap_history") == 0
    finally:
        sock.close()
        transport.close()


async def test_check_community_on_drops_a_device_with_no_community_configured(pool, db):
    """A device with no access profile at all (or a v3 profile with no community) matches nothing under
    check_community - the same as legacy comparing a real string against two nulls."""
    await make_device(db, "sw1", ip="127.0.0.1")  # no access profile
    enc = EncryptionService.from_settings()
    transport, protocol = await serve(pool, host="127.0.0.1", port=0, enc=enc, check_community=True)
    port = transport.get_extra_info("sockname")[1]
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        await _send_and_drain(protocol, sock, v2c_trap("1.3.6.1.6.3.1.1.5.3", community="public"), port)
        assert protocol.counters.bad_community == 1
    finally:
        sock.close()
        transport.close()
