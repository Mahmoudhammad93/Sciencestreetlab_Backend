#!/usr/bin/env bash
# Fix production public URLs (IP → domain). Run on the EC2 host inside the app dir.
set -euo pipefail

ENV_FILE="${1:-.env}"
DOMAIN_URL="${PUBLIC_SITE_URL:-https://app.sciencestreetlab.com}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

python3 - "$ENV_FILE" "$DOMAIN_URL" <<'PY'
import re, sys
path, domain = sys.argv[1], sys.argv[2].rstrip("/")
text = open(path).read()

def set_env(key, value):
    global text
    pattern = re.compile(rf"^{re.escape(key)}=.*$", re.M)
    line = f"{key}={value}"
    if pattern.search(text):
        text = pattern.sub(line, text)
    else:
        text = text.rstrip() + "\n" + line + "\n"

set_env("APP_URL", domain)
set_env("FRONTEND_URL", domain)

# Ensure Sanctum knows the public host
stateful = None
m = re.search(r"^SANCTUM_STATEFUL_DOMAINS=(.*)$", text, re.M)
host = domain.split("://", 1)[-1].split("/", 1)[0]
if m:
    parts = [p.strip() for p in m.group(1).split(",") if p.strip()]
    if host not in parts:
        parts.append(host)
    set_env("SANCTUM_STATEFUL_DOMAINS", ",".join(parts))
else:
    set_env("SANCTUM_STATEFUL_DOMAINS", host)

open(path, "w").write(text)
print(f"Updated {path}: APP_URL/FRONTEND_URL -> {domain}")
PY

echo "Next: rebuild/restart the app container and run: php artisan config:clear"
