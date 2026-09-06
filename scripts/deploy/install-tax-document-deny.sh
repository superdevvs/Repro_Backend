#!/usr/bin/env bash
set -euo pipefail

# Requires the server administrator; inspect/merge the existing site rather than replace it.
if [ "$(id -u)" -ne 0 ]; then
  echo 'Run this installer with sudo on the production API host.' >&2
  exit 77
fi
site=/etc/nginx/sites-available/laravel.conf
test -f "$site"
grep -q 'server_name api.reprodashboard.com;' "$site"
grep -q 'root /var/www/backend/public;' "$site"
if grep -q 'Repro private tax document guard' "$site"; then
  echo 'Tax document URL guard already installed.'
  exit 0
fi
backup="${site}.before-tax-guard-$(date +%Y%m%d%H%M%S)"
cp -p "$site" "$backup"
chmod 0600 "$backup"
python3 - "$site" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
source = path.read_text()
anchor = '    # Laravel front-controller'
if source.count(anchor) != 1:
    raise SystemExit('Expected unique Laravel site anchor; no configuration changed.')
guard = '''    # Repro private tax document guard
    location = /storage/tax-documents { return 404; }
    location ^~ /storage/tax-documents/ { return 404; }

'''
path.write_text(source.replace(anchor, guard + anchor))
PY
if ! nginx -t; then
  cp -p "$backup" "$site"
  echo 'Configuration validation failed; original restored.' >&2
  exit 1
fi
if ! systemctl reload nginx; then
  cp -p "$backup" "$site"
  nginx -t && systemctl reload nginx
  exit 1
fi
echo 'Tax document URL guard installed and nginx reloaded.'
