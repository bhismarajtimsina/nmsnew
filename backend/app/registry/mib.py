"""Reads MIB text and resolves every definition to its full numeric OID.

Documentation and validation only. This module is never called during polling, and never contacts a device — its only
input is MIB files already on disk (see docs/cybersathy-nms-migration/07-mib-library.md and
safety-and-verification-policy.md). It does not implement ASN.1/SMI parsing in general: it finds the handful of
constructs that assign an OID (`OBJECT-TYPE`, `OBJECT-IDENTITY`, `MODULE-IDENTITY`, `NOTIFICATION-TYPE`, and bare
`OBJECT IDENTIFIER` clauses) and walks each one's `::= { parent subId }` back to a known root. Anything else in the
file (types, imports, textual conventions) is ignored, matching the read-only, reference-only role MIBs have here.

Independent of tools/bdcom-mib-oid-map.py, which is a separate, already-relied-upon CLI script named directly in this
repository's safety instructions; this module is not a refactor of it and that script is left untouched.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

# Anchored at the start of a line (after removing comments) so a name inside a comment, or appearing mid-line as an
# argument (IMPORTS lists MODULE-IDENTITY as a symbol it imports), is never mistaken for a definition.
#
# The name must start lowercase: in ASN.1/SMI, a value identifier (what OBJECT-TYPE, OBJECT-IDENTITY, MODULE-IDENTITY,
# NOTIFICATION-TYPE and OBJECT IDENTIFIER all define) always does, while an uppercase-first word in this position is a
# macro field, not a new definition — most importantly `SYNTAX  OBJECT IDENTIFIER` inside an OBJECT-TYPE body, which
# without this check reads exactly like a definition named SYNTAX. Confirmed against every real MIB in this repository:
# every uppercase-first match this regex would otherwise produce is one of these field artifacts, never a real object.
_DEFINITION_RE = re.compile(
    r"^[ \t]*(?P<name>[a-z][\w-]*)[ \t]+(?:OBJECT-TYPE|OBJECT-IDENTITY|MODULE-IDENTITY|NOTIFICATION-TYPE|OBJECT[ \t]+IDENTIFIER)\b",
    re.M,
)
_PARENT_RE = re.compile(r"::=[ \t\r\n]*\{[ \t\r\n]*(?P<parent>[A-Za-z][\w-]*)[ \t\r\n]+(?P<subid>\d+)[ \t\r\n]*\}")
_COMMENT_RE = re.compile(r"--[^\n]*")

# Well-known roots every MIB in practice eventually anchors to. Without these, a MIB that only ever extends
# `mib-2`/`enterprises` (which are declared in MIBs this project does not also import, such as SNMPv2-SMI) would
# never resolve at all.
STANDARD_ROOTS: dict[str, str] = {
    "iso": "1", "org": "1.3", "dod": "1.3.6", "internet": "1.3.6.1", "directory": "1.3.6.1.1",
    "mgmt": "1.3.6.1.2", "mib-2": "1.3.6.1.2.1", "experimental": "1.3.6.1.3", "private": "1.3.6.1.4",
    "enterprises": "1.3.6.1.4.1", "snmpV2": "1.3.6.1.6", "snmpModules": "1.3.6.1.6.3",
}


@dataclass(frozen=True)
class RawDefinition:
    name: str
    kind: str
    parent: str
    sub_id: int


def parse_definitions(text: str) -> list[RawDefinition]:
    """Every OID-carrying definition found in one MIB file's text, in the order they appear."""
    clean = _COMMENT_RE.sub("", text)
    found = []
    for match in _DEFINITION_RE.finditer(clean):
        parent_match = _PARENT_RE.search(clean, match.end())
        if not parent_match:
            continue
        kind = re.sub(r"\s+", " ", match.group(0).split(None, 1)[1].strip())
        found.append(RawDefinition(match.group("name"), kind, parent_match.group("parent"), int(parent_match.group("subid"))))
    return found


@dataclass(frozen=True)
class ResolvedDefinition:
    definition: RawDefinition
    numeric_oid: str | None  # None when its parent chain never reaches a known root


@dataclass
class ResolvedFile:
    entries: list[ResolvedDefinition]

    @property
    def definitions(self) -> list[RawDefinition]:
        return [e.definition for e in self.entries]

    @property
    def resolved(self) -> dict[str, str]:
        """Convenience view, name -> OID, for callers that only care about unique names (most do). A name genuinely
        declared more than once in this file with different OIDs (see `resolve()`) collapses to its first occurrence
        here; use `entries` directly to see every occurrence with its own, independently computed OID."""
        out: dict[str, str] = {}
        for e in self.entries:
            if e.numeric_oid is not None:
                out.setdefault(e.definition.name, e.numeric_oid)
        return out


@dataclass
class ResolveResult:
    files: dict[str, ResolvedFile]
    by_name: dict[str, str]
    """name -> numeric OID across every file; first occurrence wins on a name collision.

    Unsafe for authoring: confirmed against the real MIBs in this repository, vendor-private files reuse common short
    names for their own, unrelated table columns (BDCOM-IF-MIB.my and BDCOM-EPON-ONU.MIB each define their own private
    "ifIndex", and BDCOM-EPON-ONU-IF-STATS.my its own "ifOutOctets"; both collide with the real RFC1213-MIB.my meaning
    and, depending on file order, can make this map report the wrong OID or none at all). Anyone authoring an OID
    definition from a MIB must resolve it from `files[a_specific_filename].resolved`, never from this map — see
    docs/cybersathy-nms-migration/06-oid-profile-registry.md and app/registry/standard_oids.py for how this is done."""


def resolve(sources: dict[str, str]) -> ResolveResult:
    """Resolve every definition across a set of `{filename: text}` MIB sources together, so an object defined in one
    file whose parent lives in another (the common case: vendor MIBs extend `enterprises`, which they do not also
    define) still resolves.

    Resolution is per *occurrence*, not per name: a handful of real MIBs in this repository genuinely declare the same
    identifier twice with two different OIDs (a leftover `OBJECT IDENTIFIER` stub beside the real `OBJECT-TYPE`), and
    each occurrence gets its own, independently computed OID from its own (parent, sub_id) pair. Only *parent* lookups
    go through a flat name map — a symbolic parent reference is assumed unambiguous — and there, a name defined
    identically in two unrelated files resolves once, arbitrarily by iteration order.
    """
    parsed = {filename: parse_definitions(text) for filename, text in sources.items()}
    edges: dict[str, tuple[str, int]] = {}
    for defs in parsed.values():
        for d in defs:
            edges.setdefault(d.name, (d.parent, d.sub_id))

    resolved_names: dict[str, str] = dict(STANDARD_ROOTS)

    def resolve_name(name: str, seen: set[str]) -> str | None:
        """The canonical OID a *name* points to, used only to look up what a definition's parent resolves to."""
        if name in resolved_names:
            return resolved_names[name]
        if name in seen or name not in edges:
            return None
        seen.add(name)
        parent, sub_id = edges[name]
        base = resolve_name(parent, seen)
        if base is None:
            return None
        resolved_names[name] = f"{base}.{sub_id}"
        return resolved_names[name]

    for name in list(edges):
        resolve_name(name, set())

    files: dict[str, ResolvedFile] = {}
    for filename, defs in parsed.items():
        entries = []
        for d in defs:
            base = resolve_name(d.parent, set())
            entries.append(ResolvedDefinition(d, f"{base}.{d.sub_id}" if base is not None else None))
        files[filename] = ResolvedFile(entries=entries)

    by_name = {name: oid for name, oid in resolved_names.items() if name not in STANDARD_ROOTS}
    return ResolveResult(files=files, by_name=by_name)
