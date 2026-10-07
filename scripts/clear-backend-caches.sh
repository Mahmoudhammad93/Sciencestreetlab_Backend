#!/usr/bin/env bash
# Clear Laravel/Filament caches as www-data so compiled Blade views stay writable.
# Running `php artisan optimize:clear` as root creates root-owned compiled views;
# php-fpm then fatals with: touch(): Utime failed: Operation not permitted
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if docker compose ps -q backend >/dev/null 2>&1 && [[ -n "$(docker compose ps -q backend)" ]]; then
  docker compose exec -u www-data -T backend php artisan optimize:clear
  docker compose exec -u root -T backend chown -R www-data:www-data /var/www/storage/framework/views /var/www/bootstrap/cache
else
  if [[ "$(id -u)" -eq 0 ]]; then
    echo "ERROR: do not clear caches as root; compiled views must be owned by www-data." >&2
    exit 1
  fi
  php artisan optimize:clear
fi
