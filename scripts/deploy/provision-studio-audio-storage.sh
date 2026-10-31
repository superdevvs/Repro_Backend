#!/usr/bin/env bash
set -euo pipefail

# Provision only the private soundtrack root, before web requests resume.
# An optional application path supports isolated filesystem acceptance tests.
parent="${1:-/var/www/backend}/storage/app/private"
target="$parent/studio-audio"
test -d "$parent" && test "$(realpath "$parent")" = "$parent"
if [ -L "$target" ]; then
  echo 'Private Studio audio storage must not be a symlink.' >&2
  exit 1
fi
if [ ! -e "$target" ]; then
  mkdir -m 0700 "$target"
  chgrp www-data "$target"
  chmod 2770 "$target"
fi
if [ ! -d "$target" ] || [ "$(stat -c '%G %a' "$target")" != 'www-data 2770' ]; then
  echo 'Existing private Studio audio storage needs owner/administrator permission review.' >&2
  exit 1
fi
test -r "$target" && test -w "$target" && test -x "$target"
echo 'Private Studio audio storage is provisioned for the service/operator group.'
