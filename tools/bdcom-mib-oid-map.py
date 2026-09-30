"""Resolve every object in BDCOM's NMS-* MIBs to its full numeric OID.

MIB objects only carry `::= { parent N }`, so a numeric OID needs the whole
chain walked back to a known root. Definitions are matched at the start of a
line: an earlier attempt anchored on any preceding word and happily matched
the word IMPORTS, since IMPORTS lists MODULE-IDENTITY as a symbol.
"""
import re, os, sys, json

MIBDIR = sys.argv[2] if len(sys.argv) > 2 else '/root/nms/NMS_BDCOM_MIBS'

roots = {'nms': '.1.3.6.1.4.1.3320', 'bdcom': '.1.3.6.1.4.1.3320',
         'enterprises': '.1.3.6.1.4.1', 'mib-2': '.1.3.6.1.2.1',
         'private': '.1.3.6.1.4', 'internet': '.1.3.6.1'}

DEF = re.compile(r'^[ \t]*([A-Za-z][\w-]*)[ \t]+(?:OBJECT-TYPE|OBJECT-IDENTITY|MODULE-IDENTITY|NOTIFICATION-TYPE|OBJECT[ \t]+IDENTIFIER)\b', re.M)
PARENT = re.compile(r'::=[ \t\r\n]*\{[ \t\r\n]*([A-Za-z][\w-]*)[ \t\r\n]+(\d+)[ \t\r\n]*\}')

edges, defined_in, kind = {}, {}, {}
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
json.dump({'byname': resolved, 'byoid': byoid, 'file': defined_in}, open(sys.argv[1], 'w'))
print('objects resolved:', len(resolved), 'of', len(edges), 'definitions')
