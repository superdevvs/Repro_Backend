#!/usr/bin/env bash
set -euo pipefail

app=/var/www/backend
test -f "$app/artisan"
command -v crontab >/dev/null
test -x /usr/bin/php
cd "$app"
/usr/bin/php artisan auth:prune-security-limits --help >/dev/null

staging=$(mktemp -d)
trap 'rm -f "$staging/current" "$staging/next" "$staging/error"; rmdir "$staging"' EXIT
if ! LC_ALL=C crontab -l > "$staging/current" 2> "$staging/error"; then
  if ! grep -q 'no crontab for' "$staging/error"; then
    echo 'Unable to read the deployment user crontab; no changes made.' >&2
    exit 1
  fi
fi
if grep -Fq 'auth:prune-security-limits' "$staging/current"; then
  echo 'Authentication counter pruning is already scheduled.'
  exit 0
fi

operator_state="$HOME/.repro-security-operations"
mkdir -p "$operator_state"
chmod 0700 "$operator_state"
cp "$staging/current" "$operator_state/crontab-before-auth-pruning-$(date +%Y%m%d%H%M%S)"
chmod 0600 "$operator_state"/crontab-before-auth-pruning-*
cat "$staging/current" > "$staging/next"
printf '\n# Repro bounded authentication counter cleanup\n17 * * * * cd /var/www/backend && /usr/bin/php artisan auth:prune-security-limits --limit=1000 >/dev/null 2>&1\n' >> "$staging/next"
crontab "$staging/next"
echo 'Hourly bounded authentication counter pruning installed.'
