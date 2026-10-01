#!/usr/bin/env python3
"""Refuses secrets in git (Plans 33 and 35, risks K-05 and K-23).

    python3 tools/secret_scan.py                 scan every file git tracks in the working tree
    python3 tools/secret_scan.py --rev <commit>  scan the tree of a commit instead (e.g. origin/main), read through git
    python3 tools/secret_scan.py FILE...         scan just these files

Exit 0 when clean, 1 when anything is found. It never prints a secret: a finding shows the file, line, rule and at
most the first and last two characters of the matched value.

Two kinds of rule:
  * file rules: names that must never be committed whatever they contain (.env files, private keys, database data
    directories and dumps, credential stores);
  * content rules: private-key blocks, well-known token formats, and `NAME=value` assignments whose name says
    password/secret/token/key and whose value is not an obvious placeholder.

Known-safe findings are listed in `.secret-scan-allow` at the repository root, one `<path glob> <rule> [NAME]` per
line with a reason after `#`; NAME narrows a secret-assignment entry to that one variable, so allowing it does not
hide a different secret added to the same file later. Allowing a finding is a reviewed change to that file, never a
flag on the command line.

Offline: it reads files and git objects only.
"""
from __future__ import annotations

import argparse
import fnmatch
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ALLOW_FILE = ROOT / ".secret-scan-allow"
MAX_BYTES = 2_000_000  # larger files are skipped for content rules (file rules still apply)

# (rule, glob) - matched against the path's basename unless the glob contains a slash.
FILE_RULES: list[tuple[str, str]] = [
    ("env-file", ".env"),
    ("env-file", ".env.*"),
    ("env-file", "*.env"),
    ("encryption-password-file", ".encrypt_passwd"),
    ("private-key-file", "*.pem"),
    ("private-key-file", "*.key"),
    ("private-key-file", "*.p12"),
    ("private-key-file", "*.pfx"),
    ("private-key-file", "id_rsa*"),
    ("private-key-file", "id_ed25519*"),
    ("credential-store", "auth.json"),
    ("credential-store", "secrets.json"),
    ("credential-store", "credentials.json"),
    ("credential-store", ".netrc"),
    ("database-dump", "*.sql.gz"),
    ("database-dump", "*.dump"),
    ("database-data-dir", "*/mysql/datadir/*"),
    ("database-data-dir", "ibdata1"),
    ("database-data-dir", "*.ibd"),
]
# Example and template files carry placeholders by design; their content is still scanned.
FILE_RULE_EXEMPT = re.compile(r"(\.example|-example|\.sample|\.template|\.dist)$")

SECRET_NAME = r"[A-Za-z0-9_]*(?:PASSWORD|PASSWD|PASS|SECRET|TOKEN|API_KEY|APIKEY|AUTH_KEY|PRIVATE_KEY|ACCESS_KEY)[A-Za-z0-9_]*"
CONTENT_RULES: list[tuple[str, re.Pattern[str]]] = [
    ("private-key-block", re.compile(r"-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED |PGP )?PRIVATE KEY(?: BLOCK)?-----")),
    ("aws-access-key", re.compile(r"\b(?:AKIA|ASIA)[0-9A-Z]{16}\b")),
    ("github-token", re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{60,})\b")),
    ("slack-token", re.compile(r"\bxox[abprs]-[A-Za-z0-9-]{10,}\b")),
    ("google-api-key", re.compile(r"\bAIza[0-9A-Za-z_-]{35}\b")),
    ("telegram-bot-token", re.compile(r"\b\d{8,10}:AA[A-Za-z0-9_-]{33}\b")),
    ("secret-assignment", re.compile(rf"^\s*(?:export\s+)?({SECRET_NAME})\s*[=:]\s*[\"']?([^\s\"'#]+)", re.I)),
]

# A value that is clearly not a real secret: empty-ish, a variable reference, or a placeholder word.
PLACEHOLDER = re.compile(
    r"^(?:\$\{?[A-Za-z_][A-Za-z0-9_:-]*\}?.*|<[^>]*>|\*+|x+|\d+|yes|no|on|off|\.\.\.|changeme|change_me|change-me|replace[-_]?me|example|"
    r"secret|password|passwd|token|your[-_].*|none|null|true|false|0|1|todo|tbd|placeholder|ci-only-password|"
    r"test|dummy|redacted|\[?redacted\]?|%.*%|\{\{.*\}\})$",
    re.I,
)
# secret-assignment only makes sense in config-shaped files; elsewhere `password = request.form[...]` is code.
ASSIGNMENT_FILES = re.compile(r"(\.env[^/]*|\.ya?ml|\.ini|\.cfg|\.conf|\.properties|\.toml|\.example|-example|\.sh)$")


@dataclass(frozen=True)
class Finding:
    path: str
    line: int  # 0 for a file rule
    rule: str
    sample: str = ""
    name: str = ""  # the variable name, for a secret-assignment

    def render(self) -> str:
        where = f"{self.path}:{self.line}" if self.line else self.path
        return f"{where}: {self.rule}" + (f" ({self.sample})" if self.sample else "")


def mask(value: str) -> str:
    return f"{value[:2]}…{value[-2:]}" if len(value) > 8 else "…"


def file_rule(path: str) -> str | None:
    name = path.rsplit("/", 1)[-1]
    if FILE_RULE_EXEMPT.search(name):
        return None
    for rule, glob in FILE_RULES:
        target = path if "/" in glob else name
        if fnmatch.fnmatch(target, glob):
            return rule
    return None


def scan_text(path: str, text: str) -> list[Finding]:
    findings = []
    assignments_apply = bool(ASSIGNMENT_FILES.search(path))
    for number, line in enumerate(text.splitlines(), 1):
        for rule, pattern in CONTENT_RULES:
            match = pattern.search(line)
            if not match:
                continue
            if rule == "secret-assignment":
                if not assignments_apply:
                    continue
                value = match.group(2)
                if PLACEHOLDER.match(value):
                    continue
                findings.append(Finding(path, number, rule, f"{match.group(1)}={mask(value)}", match.group(1)))
            else:
                findings.append(Finding(path, number, rule, mask(match.group(0))))
    return findings


def scan(path: str, data: bytes | None) -> list[Finding]:
    findings = []
    rule = file_rule(path)
    if rule:
        findings.append(Finding(path, 0, rule))
    if data is None or len(data) > MAX_BYTES or b"\0" in data[:8192]:
        return findings  # missing, too large, or binary
    return findings + scan_text(path, data.decode("utf-8", errors="replace"))


def load_allow(path: Path = ALLOW_FILE) -> list[tuple[str, str, str]]:
    entries = []
    if path.exists():
        for raw in path.read_text().splitlines():
            parts = raw.split("#", 1)[0].split()
            if parts:
                entries.append((parts[0], parts[1] if len(parts) > 1 else "*", parts[2] if len(parts) > 2 else ""))
    return entries


def allowed(finding: Finding, allow: list[tuple[str, str, str]]) -> bool:
    return any(
        fnmatch.fnmatch(finding.path, glob) and rule in ("*", finding.rule) and (not name or name == finding.name)
        for glob, rule, name in allow
    )


def _git(*args: str) -> bytes:
    return subprocess.run(["git", "-C", str(ROOT), *args], check=True, capture_output=True).stdout


def tracked_files() -> list[str]:
    return [p for p in _git("ls-files", "-z").decode().split("\0") if p]


def blobs_at(rev: str) -> dict[str, str]:
    """path -> blob id for every file in the commit's tree; submodules and other non-file entries are skipped."""
    blobs = {}
    for entry in _git("ls-tree", "-r", "-z", rev).decode().split("\0"):
        if not entry:
            continue
        meta, path = entry.split("\t", 1)
        _mode, kind, sha = meta.split()
        if kind == "blob":
            blobs[path] = sha
    return blobs


class BlobReader:
    """One long-lived `git cat-file --batch` instead of a process per file."""

    def __init__(self) -> None:
        self.proc = subprocess.Popen(["git", "-C", str(ROOT), "cat-file", "--batch"],
                                     stdin=subprocess.PIPE, stdout=subprocess.PIPE)

    def read(self, sha: str) -> bytes:
        self.proc.stdin.write(sha.encode() + b"\n")
        self.proc.stdin.flush()
        header = self.proc.stdout.readline().split()
        size = int(header[2])
        data = self.proc.stdout.read(size)
        self.proc.stdout.read(1)  # trailing newline
        return data

    def close(self) -> None:
        self.proc.stdin.close()
        self.proc.wait()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--rev", help="scan the tree of this commit instead of the working tree")
    parser.add_argument("files", nargs="*")
    args = parser.parse_args(argv)

    if args.rev:
        blobs, reader = blobs_at(args.rev), BlobReader()
        paths = list(blobs)
        read = lambda p: reader.read(blobs[p])  # noqa: E731
    else:
        paths = args.files or tracked_files()
        read = lambda p: (ROOT / p).read_bytes() if (ROOT / p).is_file() else None  # noqa: E731

    allow = load_allow()
    findings = [f for p in paths for f in scan(p, read(p)) if not allowed(f, allow)]
    for finding in findings:
        print(finding.render())
    if findings:
        print(f"\n{len(findings)} possible secret(s) in {len({f.path for f in findings})} file(s). Remove them from git "
              f"(and rotate anything real), or, only if a finding is a known false positive, add it to "
              f".secret-scan-allow with a reason.", file=sys.stderr)
        return 1
    print(f"no secrets found in {len(paths)} file(s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
