#!/usr/bin/env bash
set -euo pipefail

# Run during the backend maintenance window, before new uploads are accepted.
parent=/var/www/backend/storage/app/private
target="$parent/tax-documents"
test "$(realpath "$parent")" = "$parent"
if [ -L "$target" ]; then
  echo 'Private tax storage must not be a symlink.' >&2
  exit 1
fi
if [ ! -e "$target" ]; then
  mkdir -m 0700 "$target"
  chgrp www-data "$target"
  chmod 2770 "$target"
fi
if [ ! -d "$target" ] || [ "$(stat -c '%G %a' "$target")" != 'www-data 2770' ]; then
  echo 'Existing private tax storage needs owner/administrator permission review.' >&2
  exit 1
fi
test -r "$target" && test -w "$target" && test -x "$target"
echo 'Private tax storage is provisioned for the service/operator group.'
