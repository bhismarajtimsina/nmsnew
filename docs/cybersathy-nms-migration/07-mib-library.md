# Plan 7: MIB Library

> **Phase:** 2 · **Depends on:** 6 · **Status:** Partial

## Goal
Create a read-only MIB library for CyberSathy-NMS documentation and validation.

## Current Source / Reference
MIBs exist in `BDCOM_MIBS`, `NMS_BDCOM_MIBS`, and `bdcom`, plus vendor trap/OID references.

## Target Design
MIBs are searchable reference material.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Import MIB filenames, vendor, object names, enterprise branch, and imported date.
- Add MIB search API.
- Add frontend MIB browser.
- Cross-reference MIB objects to OID definitions where possible.
- Parse MIBs offline with `pysmi` (D-15). The parser is never part of the polling path.
- Cross-check `oid_definitions` against `mib_objects` and produce a mismatch report.
- Make the import idempotent; re-importing changes nothing unless a file changed.

## Database/API Impact
Add `mib_files` and `mib_objects` tables.

## Frontend Impact
Add searchable read-only MIB browser.

## Security / Access Rules
MIB browser is read-only and should not expose credentials or SNMP communities.

## Acceptance Checks
- BDCOM/NMS MIBs are searchable.
- Object name lookup works.
- Runtime polling does not depend directly on raw MIB parsing.
- Re-import is a no-op.
- The mismatch report lists OIDs whose MIB object name or numeric OID differs.

## Risks
- Treating MIB import as a polling source instead of documentation.

## Definition of Done
MIB index imported, searchable, and cross-referenced; mismatch report reviewed.

## Rollback
Read-only reference data; truncate and re-import.

## Implementation notes (2026-09-29)
Built and tested against the real MIB files already in this repository (migration 0012, 482 tests in the suite, 72 deliberate breakages caught in total):
- **Resolver** (`app/registry/mib.py`): finds every OID-carrying construct (`OBJECT-TYPE`, `OBJECT-IDENTITY`, `MODULE-IDENTITY`, `NOTIFICATION-TYPE`, bare `OBJECT IDENTIFIER`) and walks each one's `::= {{ parent subId }}` back to a set of well-known roots, independent of `tools/bdcom-mib-oid-map.py` (left untouched, since that script is named directly in this repository's own safety instructions). Documentation and validation only — this module is never called during polling and never contacts a device.
- **Import**: `python -m app.cli mib import <dir> [--vendor <slug>]` reads every file in a directory and indexes it; re-importing a file replaces its objects.
- **Read API**: `GET /mib/files`, `GET /mib/objects?query=`, `POST /mib/check` (does a declared logical name and numeric OID agree with what an imported MIB assigns that name — the offline validation Plan 6 needs before authoring an OID).
- **Tested against the real files**, not only synthetic ones: standard MIB-II values (`sysDescr` = `1.3.6.1.2.1.1.1`, `ifDescr` = `1.3.6.1.2.1.2.2.1.2`, etc.) resolve correctly from the real `BDCOM_MIBS` directory, and real BDCOM private objects resolve under the real enterprise number `1.3.6.1.4.1.3320`.

**Three real bugs were found while building this, all through testing at the scale of the real corpus, not sampling:**
1. A definition inside a comment, or a name used only as an `IMPORTS` symbol (the exact case `tools/bdcom-mib-oid-map.py`'s own docstring warns about), needed a real regression test that actually depends on comment-stripping to be a meaningful test at all — an earlier version of the test passed by accident regardless of whether stripping ran.
2. **A structural false-positive shared with the existing reference tool**: `SYNTAX  OBJECT IDENTIFIER`, a field inside almost every real `OBJECT-TYPE` body, reads exactly like a definition named `SYNTAX`. Confirmed the same regex shape in `tools/bdcom-mib-oid-map.py` has this same defect. Fixed here by requiring a real definition's name to start lowercase, the actual ASN.1/SMI lexical rule for value identifiers — verified against the full real corpus that every uppercase-first "definition" this produced (45 of them) was one of these field artifacts, never a real object.
3. **A design gap, not a parsing bug**: several real MIBs (`SNMPv2-MIB.my`'s `sysORID`, `BDCOM-LLDP-MIB.MIB`'s `lldpLocManAddrOID`, and others) genuinely declare the same identifier twice with two different OIDs. The first version of this module could only resolve one OID per *name*, so the second occurrence would have silently been stored with the first occurrence's OID. Restructured to resolve every *occurrence* independently from its own `(parent, sub_id)` pair; verified live that both occurrences of `sysORID` now import with their own correct, distinct OIDs.

Verified live: both real MIB directories (214 files, about 6,900 definitions combined) import cleanly through the actual Compose stack with zero errors, and the search and cross-check endpoints serve the real, resolved data through Nginx.

Not done: no frontend MIB browser; MIB import is a manual CLI step, not part of the default seed (these are large, numerous files and which directory to import is an operational choice, unlike the vendor/model catalogues); cross-referencing against `oid_definitions` has nothing to check yet, since no OID has been authored (D-25, Plan 6).