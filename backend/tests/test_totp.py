import base64

from app.core import totp

# RFC 6238 appendix B, SHA-1, secret "12345678901234567890". The RFC lists 8 digits; the last 6 are the 6-digit code.
SECRET = base64.b32encode(b"12345678901234567890").decode().rstrip("=")
VECTORS = {59: "287082", 1111111109: "081804", 1111111111: "050471", 1234567890: "005924", 2000000000: "279037", 20000000000: "353130"}


def test_rfc_6238_vectors():
    for timestamp, expected in VECTORS.items():
        assert totp.totp_at(SECRET, timestamp) == expected


def test_verify_accepts_current_and_adjacent_window_only():
    now = 1234567890
    assert totp.verify(SECRET, "005924", timestamp=now) == now // 30
    previous = totp.totp_at(SECRET, now - 30)
    assert totp.verify(SECRET, previous, timestamp=now) == now // 30 - 1
    too_old = totp.totp_at(SECRET, now - 120)
    assert totp.verify(SECRET, too_old, timestamp=now) is None


def test_a_code_cannot_be_replayed():
    now = 1234567890
    counter = totp.verify(SECRET, "005924", timestamp=now)
    assert counter is not None
    assert totp.verify(SECRET, "005924", timestamp=now, last_counter=counter) is None


def test_malformed_pins_are_rejected():
    for pin in ("", "12345", "1234567", "abcdef", "00 5924x"):
        assert totp.verify(SECRET, pin, timestamp=1234567890) is None


def test_generated_secrets_are_base32_and_unique():
    a, b = totp.generate_secret(), totp.generate_secret()
    assert a != b and len(a) == 32
    assert totp.otpauth_uri(a, "alice", "CyberSathy-NMS").startswith("otpauth://totp/CyberSathy-NMS:alice?secret=")
