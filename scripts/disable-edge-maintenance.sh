#!/usr/bin/env bash
# Narrow, reversible: turn OFF host/nginx maintenance if a maintenance.sh switch exists.
# Snapshots evidence first. Does not delete the maintenance page or nginx history.
# Run on the production host after confirming application containers are healthy.
set -euo pipefail

APPLY="${1:-}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
EVIDENCE="${EVIDENCE_DIR:-/home/ubuntu/backups/maintenance-incident-$STAMP}"

mkdir -p "$EVIDENCE"
echo "EVIDENCE=$EVIDENCE"

{
  echo "utc=$(date -u +%FT%TZ)"
  echo "host=$(hostname)"
  command -v nginx >/dev/null && nginx -v 2>&1 || true
  docker ps --format '{{.Names}} {{.Status}} {{.Image}}' 2>/dev/null || true
} >"$EVIDENCE/host-state.txt" 2>&1 || true

copy_if() {
  local src="$1"
  if [[ -e "$src" ]]; then
    mkdir -p "$EVIDENCE$(dirname "$src")"
    cp -a "$src" "$EVIDENCE$src" 2>/dev/null || true
    echo "COPIED $src"
  fi
}

# Preserve likely config/flag locations without deleting anything.
while IFS= read -r f; do
  copy_if "$f"
done < <(find /home/ubuntu /etc/nginx /opt -maxdepth 4 \( \
  -iname '*maintenance*' -o -iname 'down' \
  \) 2>/dev/null | head -80)

if [[ -f /home/ubuntu/apps/Sciencestreetlab_Backend/nginx/default.conf ]]; then
  copy_if /home/ubuntu/apps/Sciencestreetlab_Backend/nginx/default.conf
  (cd /home/ubuntu/apps/Sciencestreetlab_Backend && git status --porcelain nginx/default.conf && git diff --stat nginx/default.conf) \
    >"$EVIDENCE/nginx-default.conf.git.txt" 2>&1 || true
fi

SWITCH=""
for cand in \
  /home/ubuntu/apps/maintenance.sh \
  /home/ubuntu/maintenance.sh \
  /usr/local/bin/maintenance.sh \
  /home/ubuntu/apps/Sciencestreetlab_Backend/scripts/maintenance.sh \
  /home/ubuntu/apps/Sciencestreetlab_Frontent/scripts/maintenance.sh
do
  if [[ -x "$cand" ]]; then
    SWITCH="$cand"
    break
  fi
done

echo "SWITCH=${SWITCH:-NOT_FOUND}" | tee "$EVIDENCE/switch.txt"

if [[ "$APPLY" != "--apply" ]]; then
  echo "Dry run. Re-run with --apply to execute the off switch after reviewing $EVIDENCE"
  exit 0
fi

if [[ -z "$SWITCH" ]]; then
  echo "ERROR: maintenance.sh not found. Do not guess-edit nginx. Inspect $EVIDENCE" >&2
  exit 2
fi

# Prefer explicit off/disable/stop arguments; do not pass unknown flags.
if "$SWITCH" off; then
  echo "MAINTENANCE_OFF via $SWITCH off"
elif "$SWITCH" disable; then
  echo "MAINTENANCE_OFF via $SWITCH disable"
else
  echo "ERROR: $SWITCH off/disable failed" >&2
  exit 3
fi

if docker compose -f /home/ubuntu/apps/Sciencestreetlab_Backend/docker-compose.yml ps -q nginx >/dev/null 2>&1; then
  docker compose -f /home/ubuntu/apps/Sciencestreetlab_Backend/docker-compose.yml exec -T nginx nginx -s reload || true
fi
