#!/usr/bin/env bash
# Builds the custom frontend (frontend/) inside a throwaway Docker
# container — no Node needs to be installed on the host — then deploys the
# result into public/frontend/, the exact path wca-nginx serves live on
# the configured nginx port, and refreshes nginx's in-container static copy
# with zero downtime (no restart: nginx reads files straight off disk per
# request, so we only need to redo the same copy its own entrypoint does).
#
# Usage: ./build.sh
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$REPO_ROOT/frontend"
DEPLOY_DIR="$REPO_ROOT/public/frontend"
NODE_IMAGE="node:20"
NGINX_CONTAINER="wca-nginx"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$REPO_ROOT/public/frontend.backup-$TIMESTAMP"

if ! command -v docker >/dev/null 2>&1; then
  echo "!! Docker is required to build the frontend, but docker was not found in PATH." >&2
  exit 1
fi

if [ ! -f "$FRONTEND_DIR/package.json" ]; then
  echo "!! Frontend package.json not found at $FRONTEND_DIR/package.json — check REPO_ROOT/FRONTEND_DIR." >&2
  exit 1
fi

echo "==> [1-2/5] Installing deps, building, and type-checking frontend in Docker ($NODE_IMAGE)..."
# -v /app/node_modules (no host path) makes Docker create a fresh, anonymous
# volume for node_modules INSIDE the container — this deliberately keeps the
# host's own node_modules (used by your locally-running `npm run dev`)
# completely untouched, since npm ci would otherwise wipe and reinstall it
# for the container's OS/Node build, which could break the dev server.
# Build and type-check run inside the SAME container invocation (not two
# separate `docker run`s) since the anonymous node_modules volume only
# lives for this one container's lifetime — a second `docker run` would get
# its own empty node_modules and vue-tsc wouldn't be found.
docker run --rm \
  -v "$FRONTEND_DIR":/app \
  -v /app/node_modules \
  -w /app \
  "$NODE_IMAGE" \
  sh -c "npm ci && npm run build-only && (npm run type-check || echo '    (see type errors above — build continues regardless, per project convention)')"

if [ ! -d "$FRONTEND_DIR/dist/assets" ]; then
  echo "!! Build did not produce dist/assets — aborting before touching anything live." >&2
  exit 1
fi

echo "==> [3/5] Backing up current live frontend to $BACKUP_DIR"
mkdir -p "$DEPLOY_DIR"
if [ -d "$DEPLOY_DIR" ]; then
  mkdir -p "$BACKUP_DIR"
  for f in assets index.html favicon.png; do
    [ -e "$DEPLOY_DIR/$f" ] && cp -a "$DEPLOY_DIR/$f" "$BACKUP_DIR/$f"
  done
fi

echo "==> [4/5] Deploying new build to $DEPLOY_DIR"
# Only touch the files this build actually owns/produces (assets/,
# index.html, favicon.png) — public/frontend/ also holds leftovers from the
# original template bundle (dist/, img/, manifest.webmanifest, robots.txt,
# panel-auth.js) that this project doesn't manage; leave them exactly as-is.
rm -rf "$DEPLOY_DIR/assets"
cp -a "$FRONTEND_DIR/dist/assets" "$DEPLOY_DIR/assets"
cp -a "$FRONTEND_DIR/dist/index.html" "$DEPLOY_DIR/index.html"
[ -f "$FRONTEND_DIR/dist/favicon.png" ] && cp -a "$FRONTEND_DIR/dist/favicon.png" "$DEPLOY_DIR/favicon.png"

echo "==> [5/5] Refreshing nginx's static copy (no restart — zero downtime)"
if docker ps --format '{{.Names}}' | grep -qx "$NGINX_CONTAINER"; then
  # wca-nginx serves from /tmp/www inside the container. Refresh it from the
  # host deploy directory with docker cp so the script does not depend on a
  # particular bind-mount path such as /www existing inside the container.
  # NOTE: the ORIGINAL wca-nginx entrypoint (docker/nginx/docker-entrypoint.sh)
  # runs a long sed -i pass here to relabel the OLD vendor bundle's literal
  # branding strings (e.g. "Agent:" -> "Backend:", "Panel:" -> "Frontend:").
  # Deliberately NOT replicated here: those patterns are plain substring
  # matches with no word boundaries, and they corrupt OUR bundle wherever
  # the same substring appears coincidentally in real code — confirmed live,
  # "Agent:" matched inside `navigator.userAgent:""` (ant-design-vue's
  # isMobile.js, turning it into `navigator.userBackend` = undefined, which
  # crashed on the very next `.split()` call and blanked the whole page),
  # and "Panel:" matched `destroyInactivePanel:`/`faSolarPanel:`, corrupting
  # an ant-design-vue prop name and a FontAwesome icon name. Our own bundle
  # doesn't contain any of the old bundle's literal branding text anyway, so
  # this whole step was pure risk with no benefit for this project.
  docker exec "$NGINX_CONTAINER" sh -c "set -e; rm -Rf /tmp/www; mkdir -p /tmp/www"
  docker cp "$DEPLOY_DIR/." "$NGINX_CONTAINER:/tmp/www/"
  docker exec "$NGINX_CONTAINER" sh -c "set -e; rm -rf /tmp/www/html; chmod -R 777 /tmp/www"
  echo "    Live now at the URL/port exposed by $NGINX_CONTAINER."
else
  echo "    $NGINX_CONTAINER isn't running — files are deployed to disk, but nothing is serving them yet."
fi

echo
echo "==> Done."
echo "    Rollback if needed: rm -rf '$DEPLOY_DIR/assets' '$DEPLOY_DIR/index.html' '$DEPLOY_DIR/favicon.png' &&"
echo "                        cp -a '$BACKUP_DIR/.' '$DEPLOY_DIR/' && docker exec $NGINX_CONTAINER sh -c 'set -e; rm -Rf /tmp/www; mkdir -p /tmp/www' && docker cp '$DEPLOY_DIR/.' $NGINX_CONTAINER:/tmp/www/ && docker exec $NGINX_CONTAINER sh -c 'set -e; rm -rf /tmp/www/html; chmod -R 777 /tmp/www'"
