"""tools/secret_scan.py, the tracked-secret scanner (Plans 33 and 35). Every fake secret below is assembled at run time
so that this file does not itself trip the scanner when CI scans the repository."""
import subprocess
import sys
from pathlib import Path

import pytest

_CANDIDATES = [Path(__file__).resolve().parents[2] / "tools", Path("/repo/tools")]
_tools_dir = next((p for p in _CANDIDATES if (p / "secret_scan.py").is_file()), None)
if _tools_dir is None:
    pytest.skip("tools/ is not mounted in this test environment", allow_module_level=True)
sys.path.insert(0, str(_tools_dir))
import secret_scan as ss  # noqa: E402

PRIVATE_KEY = "-----BEGIN " + "RSA PRIVATE KEY-----\nMIIEow...\n-----END " + "RSA PRIVATE KEY-----\n"
AWS_KEY = "AKIA" + "Q3EGRIZ7XJ4KLMNO"
GITHUB_TOKEN = "ghp_" + "a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8"
GOOGLE_KEY = "AIza" + "SyD-9tSrke72PouQMnMX-a7eZSW0jkFMBWY"
REAL_LOOKING = "Xk2" + "p9vQzL7r"


def rules(findings):
    return sorted({f.rule for f in findings})


@pytest.mark.parametrize("path, rule", [
    (".env", "env-file"),
    ("frontend/.env", "env-file"),
    (".env.production", "env-file"),
    ("cybersathy.env", "env-file"),
    (".encrypt_passwd", "encryption-password-file"),
    ("certs/server.key", "private-key-file"),
    ("var/docker/mysql/datadir/private_key.pem", "private-key-file"),
    ("var/docker/mysql/datadir/ibdata1", "database-data-dir"),
    ("var/docker/mysql/datadir/undo_001", "database-data-dir"),  # only the data-directory rule catches this one
    ("var/docker/mysql/datadir/support/users.ibd", "database-data-dir"),
    ("var/backups/support-20260817.sql.gz", "database-dump"),
    (".codex/auth.json", "credential-store"),
])
def test_names_that_must_never_be_committed_are_refused_whatever_they_contain(path, rule):
    """Every path here is one that really reached the public repository on 2026-09-30."""
    assert rules(ss.scan(path, b"")) == [rule]


@pytest.mark.parametrize("path", [".env-example", ".env.cybersathy.example", "docker/cybersathy.env.example",
                                  "components/Olts/migrations/01_init/up.sql", "backend/app/core/crypto.py"])
def test_examples_and_ordinary_files_are_not_refused_by_name(path):
    assert ss.file_rule(path) is None


@pytest.mark.parametrize("text, rule", [
    (PRIVATE_KEY, "private-key-block"),
    (f"aws = '{AWS_KEY}'", "aws-access-key"),
    (f"token: {GITHUB_TOKEN}", "github-token"),
    (f"VITE_FIREBASE_API_KEY={GOOGLE_KEY}", "google-api-key"),
])
def test_well_known_secret_formats_are_found_in_any_file(text, rule):
    assert rule in rules(ss.scan_text("src/anything.php", text))


def test_a_real_looking_password_in_a_config_file_is_found():
    found = ss.scan_text("docker/app/datasource.yml", f"    password: {REAL_LOOKING}\n")
    assert rules(found) == ["secret-assignment"] and found[0].line == 1


@pytest.mark.parametrize("value", ["", "change-me", "${DATABASE_PASSWD}", "$DATABASE_PASSWD", "<your-password>",
                                   "${CYBERSATHY_REDIS_PASSWORD:-}", "86400", "yes", "true", "ci-only-password"])
def test_placeholders_and_references_are_not_findings(value):
    assert ss.scan_text("x.env.example", f"DATABASE_PASSWD={value}\n") == []


def test_assignments_in_code_are_left_to_code_review():
    """`password = request.form['password']` is code, not a stored secret."""
    assert ss.scan_text("app/auth.py", f"password = '{REAL_LOOKING}'\n") == []


def test_a_finding_never_reveals_the_secret():
    for finding in ss.scan_text("conf.yml", f"api_token: {REAL_LOOKING}\n") + ss.scan_text("a.php", PRIVATE_KEY + AWS_KEY):
        assert REAL_LOOKING not in finding.render() and AWS_KEY not in finding.render()


def test_binary_and_oversized_files_skip_content_rules_but_keep_name_rules():
    assert ss.scan("blob.bin", b"\0\0" + AWS_KEY.encode()) == []
    huge = b"x" * (ss.MAX_BYTES + 1) + AWS_KEY.encode()
    assert ss.scan("big.txt", huge) == []
    assert rules(ss.scan("big.pem", huge)) == ["private-key-file"]


def test_the_allow_list_is_scoped_to_its_path_rule_and_optional_variable(tmp_path):
    allow_file = tmp_path / ".secret-scan-allow"
    allow_file.write_text("cfg/*.conf secret-assignment SUPERVISOR_RPC_PASSWORD  # reviewed default\n")
    allow = ss.load_allow(allow_file)
    allowed_one = ss.scan_text("cfg/a.conf", f"SUPERVISOR_RPC_PASSWORD={REAL_LOOKING}\n")[0]
    other_name = ss.scan_text("cfg/a.conf", f"DATABASE_PASSWD={REAL_LOOKING}\n")[0]
    other_path = ss.scan_text("elsewhere/a.conf", f"SUPERVISOR_RPC_PASSWORD={REAL_LOOKING}\n")[0]
    assert ss.allowed(allowed_one, allow)
    assert not ss.allowed(other_name, allow) and not ss.allowed(other_path, allow)


def test_the_repository_itself_is_clean():
    """What CI enforces. Fails if anything secret-shaped is committed without a reviewed allow-list entry."""
    if not (ss.ROOT / ".git").exists():
        pytest.skip("not a git checkout")
    result = subprocess.run([sys.executable, str(_tools_dir / "secret_scan.py")], capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr
