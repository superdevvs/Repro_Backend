#!/usr/bin/env bash
set -euo pipefail

# Provision only the new private paid-media root. Existing trees require explicit review.
parent=/var/www/backend/storage/app/private
target="$parent/listing-studio-orders"
test "$(realpath "$parent")" = "$parent"
if [ -L "$target" ]; then
  echo 'Private Listing Studio order storage must not be a symlink.' >&2
  exit 1
fi
if [ ! -e "$target" ]; then
  mkdir -m 0700 "$target"
  chgrp www-data "$target"
  chmod 2770 "$target"
fi
if [ ! -d "$target" ] || [ "$(stat -c '%G %a' "$target")" != 'www-data 2770' ]; then
  echo 'Existing Listing Studio order storage needs owner/administrator permission review.' >&2
  exit 1
fi
test -r "$target" && test -w "$target" && test -x "$target"
echo 'Private Listing Studio order storage is provisioned for the service/operator group.'
