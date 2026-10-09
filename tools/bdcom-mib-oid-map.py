"""Resolve every object in BDCOM's NMS-* MIBs to its full numeric OID.

MIB objects only carry `::= { parent N }`, so a numeric OID needs the whole
chain walked back to a known root. Definitions are matched at the start of a
line: an earlier attempt anchored on any preceding word and happily matched
the word IMPORTS, since IMPORTS lists MODULE-IDENTITY as a symbol.

A name can be defined more than once with different OIDs: BDCOM's `onuReset`
is column 29 of the EPON ONU table (reset(0), no-reset(1)) in one file and a
separate reset table (reset(1)) in another. `byname` and `file` keep the
first definition that resolves; every definition still appears in `byoid`, and
`duplicates` lists each name that resolves to more than one OID, with the
file of each, so a lookup by name cannot silently return the wrong object.
"""
import re, os, sys, json

MIBDIR = sys.argv[2] if len(sys.argv) > 2 else '/root/nms/NMS_BDCOM_MIBS'

roots = {'nms': '.1.3.6.1.4.1.3320', 'bdcom': '.1.3.6.1.4.1.3320',
         'enterprises': '.1.3.6.1.4.1', 'mib-2': '.1.3.6.1.2.1',
         'private': '.1.3.6.1.4', 'internet': '.1.3.6.1'}

DEF = re.compile(r'^[ \t]*([A-Za-z][\w-]*)[ \t]+(?:OBJECT-TYPE|OBJECT-IDENTITY|MODULE-IDENTITY|NOTIFICATION-TYPE|OBJECT[ \t]+IDENTIFIER)\b', re.M)
PARENT = re.compile(r'::=[ \t\r\n]*\{[ \t\r\n]*([A-Za-z][\w-]*)[ \t\r\n]+(\d+)[ \t\r\n]*\}')

edges, defined_in, kind = {}, {}, {}
every = []  # (name, file, parent, sub-id) for every definition, repeats included
for fn in sorted(os.listdir(MIBDIR)):
    path = os.path.join(MIBDIR, fn)
    if not os.path.isfile(path):
        continue
    text = re.sub(r'--[^\n]*', '', open(path, errors='ignore').read())
    for m in DEF.finditer(text):
        name = m.group(1)
        p = PARENT.search(text, m.end())
        if not p:
            continue
        every.append((name, fn, p.group(1), int(p.group(2))))
        edges.setdefault(name, (p.group(1), int(p.group(2))))
        defined_in.setdefault(name, fn)
        kind.setdefault(name, m.group(0).split()[-1])

resolved = dict(roots)
def resolve(name, seen=None):
    if name in resolved:
        return resolved[name]
    seen = seen or set()
    if name in seen or name not in edges:
        return None
    seen.add(name)
    parent, num = edges[name]
    base = resolve(parent, seen)
    if base is None:
        return None
    resolved[name] = f'{base}.{num}'
    return resolved[name]

for name in list(edges):
    resolve(name)

byoid = {}
for name, oid in resolved.items():
    byoid.setdefault(oid, []).append(name)

# Each repeated definition resolved through its own parent (parents are looked up by name as above).
seen_oids = {}
for name, fn, parent, num in every:
    base = resolve(parent)
    if base is None:
        continue
    oid = f'{base}.{num}'
    seen_oids.setdefault(name, {}).setdefault(oid, fn)
    if name not in byoid.get(oid, []):
        byoid.setdefault(oid, []).append(name)
duplicates = {name: [{'oid': oid, 'file': fn} for oid, fn in oids.items()]
              for name, oids in seen_oids.items() if len(oids) > 1}
# A name whose first definition cannot be resolved (its parent is unknown under that name) takes the first one
# that can, instead of being reported as unresolved.
for name, oids in seen_oids.items():
    if name not in resolved and oids:
        oid, fn = next(iter(oids.items()))
        resolved[name], defined_in[name] = oid, fn

json.dump({'byname': resolved, 'byoid': byoid, 'file': defined_in, 'duplicates': duplicates}, open(sys.argv[1], 'w'))
print('objects resolved:', len(resolved), 'of', len(edges), 'definitions')
if duplicates:
    print('names defined with more than one OID (see "duplicates"):', ', '.join(sorted(duplicates)))
