#!/usr/bin/env bash
# Fail if the public edge is serving the branded nginx maintenance page.
# Legitimate future maintenance: set MAINTENANCE_HOLD=1 (deploy scripts skip this check).
set -euo pipefail

if [[ "${MAINTENANCE_HOLD:-0}" == "1" ]]; then
  echo "MAINTENANCE_HOLD=1 — skipping public maintenance check"
  exit 0
fi

URL="${1:-https://sciencestreetlab.com/}"
BODY="$(mktemp)"
trap 'rm -f "$BODY"' EXIT

CODE=""
for _ in 1 2 3; do
  CODE="$(curl -sS -o "$BODY" -w '%{http_code}' --max-time 20 -A 'ScienceStreet-DeployGuard/1.0' "$URL" || true)"
  if [[ -n "$CODE" && "$CODE" != "000" ]]; then
    break
  fi
  sleep 2
done
if [[ -z "$CODE" || "$CODE" == "000" ]]; then
  echo "ERROR: could not fetch $URL" >&2
  exit 1
fi

if grep -q 'maintenance.sh' "$BODY" && grep -q 'Science Street Lab | Maintenance' "$BODY"; then
  echo "ERROR: $URL is serving the nginx maintenance page (HTTP ${CODE})." >&2
  echo "Clear host maintenance (maintenance.sh off) before treating a deploy as complete." >&2
  echo "To keep the site in maintenance on purpose, rerun with MAINTENANCE_HOLD=1." >&2
  exit 1
fi

echo "MAINTENANCE_CHECK_OK url=$URL http=$CODE"
