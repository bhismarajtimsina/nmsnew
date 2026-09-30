#!/usr/bin/env bash
# Build a site topology (devices + wires) so it renders on the map.
#
#   ./topology-setup.sh up      create sites and the wires between them
#   ./topology-setup.sh show    print what is currently on the map
#   ./topology-setup.sh down    remove everything this script created
#
# ---------------------------------------------------------------------------
# EDIT THESE TWO BLOCKS to match your real network, then re-run.
# ---------------------------------------------------------------------------
#
# SITES:  <key>|<display name>|<management IP>|<lat>|<lon>
# Coordinates are what place the node on the map - a site without them is
# created but cannot be drawn.
SITES=(
  "ktm|Kathmandu|10.255.255.129|27.7172|85.3240"
  "belbari|Belbari|10.255.255.133|26.6333|87.3500"
  "pathari|Pathari|10.255.255.152|26.6500|87.4667"
  "damak|Damak|203.0.113.13|26.6667|87.7000"
  "sanischare|Sanischare|203.0.113.14|26.6100|87.9000"
)

# WIRES:  <site key> <site key>   — one physical connection per line.
WIRES=(
  "ktm belbari"
  "belbari pathari"
  "pathari damak"
  "pathari sanischare"
)

# ---------------------------------------------------------------------------
set -euo pipefail

API="${API:-http://127.0.0.1:8088}"
BASE="${API}/api/v1"
# 106 = Alcatel switch, a generic SWITCH model with a valid controller.
# (144/161/162 shipped with a malformed controller column - see error-log notes.)
MODEL_ID="${MODEL_ID:-106}"
MARKER="[topology]"

# Created automatically if absent. Point SNMP/login at real values before using
# this against production gear - these are placeholders.
GROUP_NAME="${GROUP_NAME:-Backbone}"
ACCESS_NAME="${ACCESS_NAME:-backbone-access}"
SNMP_COMMUNITY="${SNMP_COMMUNITY:-public}"
DEV_LOGIN="${DEV_LOGIN:-admin}"
DEV_PASSWORD="${DEV_PASSWORD:-changeme}"

command -v curl >/dev/null || { echo "Missing: curl"; exit 1; }
command -v python3 >/dev/null || { echo "Missing: python3"; exit 1; }

auth=()
[[ -n "${WCA_AUTH_KEY:-}" ]] && auth=(-H "X-Auth-Key: ${WCA_AUTH_KEY}")

api() {
  local method="$1" path="$2" body="${3:-}"
  if [[ -n "$body" ]]; then
    curl -sS -X "$method" "${BASE}${path}" "${auth[@]}" -H 'Content-Type: application/json' -d "$body"
  else
    curl -sS -X "$method" "${BASE}${path}" "${auth[@]}"
  fi
}

jid() { python3 -c "import sys,json;print(json.load(sys.stdin)['data']['id'])"; }

# A device cannot exist without a group and an access record, so create them if
# this is a fresh database. Both are reused when they already exist.
ensure_group() {
  local existing
  existing=$(api GET /device-group | python3 -c "
import sys,json
for g in json.load(sys.stdin).get('data') or []:
    if g['name']=='${GROUP_NAME}': print(g['id']); break
")
  if [[ -n "$existing" ]]; then echo "$existing"; return; fi
  api POST /device-group \
    "{\"name\":\"${GROUP_NAME}\",\"description\":\"${MARKER} created by topology-setup.sh\"}" | jid
}

ensure_access() {
  local existing
  existing=$(api GET /device-access | python3 -c "
import sys,json
for a in json.load(sys.stdin).get('data') or []:
    if a['name']=='${ACCESS_NAME}': print(a['id']); break
")
  if [[ -n "$existing" ]]; then echo "$existing"; return; fi
  api POST /device-access "$(python3 - "$ACCESS_NAME" "$SNMP_COMMUNITY" "$DEV_LOGIN" "$DEV_PASSWORD" <<'PY'
import json,sys
name,comm,login,pw = sys.argv[1:5]
print(json.dumps({"name":name,"public_community":comm,"private_community":comm,
                  "login":login,"password":pw}))
PY
)" | jid
}

cmd_up() {
  declare -A ID

  echo "==> Ensuring device group and access exist"
  GROUP_ID=$(ensure_group)
  ACCESS_ID=$(ensure_access)
  echo "    group id=${GROUP_ID}  access id=${ACCESS_ID}"

  echo "==> Creating sites"
  for entry in "${SITES[@]}"; do
    IFS='|' read -r key name ip lat lon <<<"$entry"
    local payload
    payload=$(python3 - "$name" "$ip" "$GROUP_ID" "$ACCESS_ID" "$MODEL_ID" "$MARKER" <<'PY'
import json,sys
name,ip,g,a,m,marker = sys.argv[1:7]
print(json.dumps({"name":name,"ip":ip,"description":f"{marker} site node",
  "group":{"id":int(g)},"access":{"id":int(a)},"model":{"id":int(m)}}))
PY
)
    local id
    id=$(api POST /device "$payload" | jid)
    #Coordinates are only accepted by the update endpoint, and as an object.
    api PUT "/device/${id}" "{\"coordinates\":{\"lat\":${lat},\"lon\":${lon}}}" >/dev/null
    ID[$key]=$id
    printf "    %-12s id=%-4s %-16s %s,%s\n" "$name" "$id" "$ip" "$lat" "$lon"
  done

  echo "==> Creating wires"
  for wire in "${WIRES[@]}"; do
    read -r a b <<<"$wire"
    local lid
    lid=$(api POST /component/links \
      "{\"src_device\":{\"id\":${ID[$a]}},\"dest_device\":{\"id\":${ID[$b]}}}" | jid)
    printf "    %-12s <-> %-12s link=%s\n" "$a" "$b" "$lid"
  done

  echo
  echo "Done. The map now has ${#SITES[@]} sites and ${#WIRES[@]} wires."
  echo "  $0 show"
}

cmd_show() {
  echo "=== Sites on the map ==="
  api PUT /maps/devices '{}' | python3 -c "
import sys,json
d=json.load(sys.stdin).get('data') or []
if not d: print('  (none)')
for x in d:
    c=x.get('coordinates') or {}
    print(f\"  {x.get('name','?'):14} {x.get('ip',''):16} lat={c.get('lat')}, lon={c.get('lon')}\")
"
  echo
  echo "=== Wires on the map ==="
  api PUT /maps/device-links '{}' | python3 -c "
import sys,json
d=json.load(sys.stdin).get('data') or []
if not d: print('  (none)')
for l in d:
    dev=l.get('devices') or {}; co=l.get('coordinates') or {}
    s=dev.get('src') or {}; t=dev.get('dest') or {}
    cs=co.get('src') or {}; ct=co.get('dest') or {}
    print(f\"  link={l.get('id'):<4} {s.get('name','?'):12} ({cs.get('lat')},{cs.get('lon')})\"
          f\"  <->  {t.get('name','?'):12} ({ct.get('lat')},{ct.get('lon')})\")
"
}

cmd_down() {
  echo "==> Removing topology devices (their links cascade)"
  api GET /device | python3 -c "
import sys,json
for d in json.load(sys.stdin).get('data') or []:
    if '${MARKER}' in str(d.get('description','')): print(d['id'])
" | while read -r id; do
    [[ -n "$id" ]] && api DELETE "/device/${id}" >/dev/null && echo "    device $id"
  done
  echo "Done."
}

case "${1:-}" in
  up) cmd_up ;;
  show) cmd_show ;;
  down) cmd_down ;;
  *) echo "Usage: $0 {up|show|down}"; exit 1 ;;
esac
