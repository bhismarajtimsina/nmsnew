import socket

import pytest

from tests.conftest import NetworkBlocked


def test_connection_to_a_non_local_address_is_refused_before_any_packet_is_sent():
    # 192.0.2.0/24 is TEST-NET-1, reserved for documentation. The guard must raise before the kernel is asked to connect.
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(0.2)
    with pytest.raises(NetworkBlocked):
        sock.connect(("192.0.2.1", 161))
    sock.close()


def test_private_device_range_is_refused_too():
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    with pytest.raises(NetworkBlocked):
        sock.connect(("10.20.30.40", 161))
    sock.close()


def test_loopback_is_allowed():
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.bind(("127.0.0.1", 0))
    server.listen(1)
    client = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    client.connect(server.getsockname())
    client.close()
    server.close()
