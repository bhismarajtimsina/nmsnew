"""Check every BDCOM trap OID against the NOTIFICATION-TYPE definitions.

A trap listener matches on the notification's OID, so a wrong one means the
trap is silently never recognised. The MIBs define these; the config should
agree with them. Self-contained: builds its own OID map from the real MIB
files on every run (the same resolver tools/bdcom-mib-oid-map.py uses), so it
never depends on stale state from an earlier run or a different session.
"""
import re, os, sys

MIB_DIRS = ['/root/nms/NMS_BDCOM_MIBS', '/root/nms/BDCOM_MIBS', '/root/nms/bdcom']

ROOTS = {'nms': '.1.3.6.1.4.1.3320', 'bdcom': '.1.3.6.1.4.1.3320',
         'enterprises': '.1.3.6.1.4.1', 'mib-2': '.1.3.6.1.2.1',
         'private': '.1.3.6.1.4', 'internet': '.1.3.6.1'}

DEF = re.compile(r'^[ \t]*([A-Za-z][\w-]*)[ \t]+(?:OBJECT-TYPE|OBJECT-IDENTITY|MODULE-IDENTITY|NOTIFICATION-TYPE|OBJECT[ \t]+IDENTIFIER)\b', re.M)
PARENT = re.compile(r'::=[ \t\r\n]*\{[ \t\r\n]*([A-Za-z][\w-]*)[ \t\r\n]+(\d+)[ \t\r\n]*\}')

edges, notif = {}, {}
for mibdir in MIB_DIRS:
    for fn in sorted(os.listdir(mibdir)):
        path = os.path.join(mibdir, fn)
        if not os.path.isfile(path):
            continue
        text = re.sub(r'--[^\n]*', '', open(path, errors='ignore').read())
        for m in DEF.finditer(text):
            name = m.group(1)
            p = PARENT.search(text, m.end())
            if not p:
                continue
            edges.setdefault(name, (p.group(1), int(p.group(2))))
            if m.group(0).split()[-1] == 'NOTIFICATION-TYPE':
                notif.setdefault(name, fn)

resolved = dict(ROOTS)


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

byoid: dict[str, list[str]] = {}
for name, oid in resolved.items():
    byoid.setdefault(oid, []).append(name)

src = open('/root/nms/vendor/meklis/switcher-core/configs/traps/bdcom.yml').read()
entries = re.findall(r'-\s+name:\s*(\S+)[\s\S]*?object:\s*([.0-9]+)', src)


def describe(oid):
    """Exact object, else nearest named ancestor plus the remaining path."""
    if oid in byoid:
        return byoid[oid], ''
    parts = oid.strip('.').split('.')
    for cut in range(len(parts) - 1, 3, -1):
        cand = '.' + '.'.join(parts[:cut])
        if cand in byoid:
            return byoid[cand], '.' + '.'.join(parts[cut:])
    return None, ''


print(f'{len(entries)} traps declared, {len(edges)} MIB definitions loaded\n')
unknown = 0
for name, oid in entries:
    names, tail = describe(oid)
    if names is None:
        unknown += 1
        print(f'  {name:34} {oid:34} NOT DEFINED in any BDCOM MIB')
        continue
    isnotif = [n for n in names if n in notif]
    label = ', '.join(names) + tail
    kind = 'NOTIFICATION-TYPE' if isnotif else 'object (not a notification)'
    print(f'  {name:34} {oid:34} {label}  [{kind}]')
print(f'\n{unknown} trap OIDs match nothing in the MIBs')
sys.exit(1 if unknown else 0)
