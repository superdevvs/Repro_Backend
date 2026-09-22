#!/usr/bin/env bash
set -euo pipefail

# No Artisan boot is needed to repair the link. Run as the deployment owner,
# before Composer boots Laravel; PHP-FPM only needs to read public assets.
mode="${1:-}"
app="${2:-}"
base_url="${3:-}"
case "$mode" in ensure|verify) ;; *) echo 'Usage: provision-public-storage.sh ensure|verify APP_PATH [PUBLIC_URL]' >&2; exit 64 ;; esac
[ -n "$app" ] || exit 64
app="$(realpath -e -- "$app")"
target="$(realpath -e -- "$app/storage/app/public")"
link="$app/public/storage"
test -d "$app/public"
test -d "$target" && test -r "$target" && test -x "$target"

link_stage=''
probe=''
cleanup() {
  local rc=$?
  trap - EXIT
  if [ -n "$probe" ]; then rm -f -- "$probe"; fi
  if [ -n "$link_stage" ]; then
    rm -f -- "$link_stage/storage"
    rmdir -- "$link_stage"
  fi
  exit "$rc"
}
trap cleanup EXIT

if [ -e "$link" ] && [ ! -L "$link" ]; then
  echo 'public/storage is a regular file or directory; refusing to replace it.' >&2
  exit 1
fi

if [ "$mode" = ensure ] && { [ ! -L "$link" ] || [ "$(readlink -f -- "$link" || true)" != "$target" ]; }; then
  # Rename over a missing path or a symlink atomically. mv -T refuses to replace
  # a regular directory, including one created after the check above.
  link_stage="$(mktemp -d "$app/public/.storage-link.XXXXXXXX")"
  ln -s -- "$target" "$link_stage/storage"
  mv -Tf -- "$link_stage/storage" "$link"
fi

if [ ! -L "$link" ] || [ "$(readlink -f -- "$link" || true)" != "$target" ] || [ ! -r "$link" ] || [ ! -x "$link" ]; then
  echo 'public/storage does not resolve to the expected readable public storage directory.' >&2
  exit 1
fi

if [ "$mode" = verify ]; then
  case "$base_url" in http://*|https://*) ;; *) echo 'A public HTTP(S) base URL is required for verification.' >&2; exit 64 ;; esac
  # The random fixture contains no user data. Fetching its exact bytes catches
  # nginx permissions, a wrong alias, and SPA fallback HTTP 200s that /up misses.
  probe="$(mktemp "$target/deploy-storage-probe-XXXXXXXX.txt")"
  probe_name="$(basename "$probe")"
  expected="repro-public-storage:$probe_name"
  printf '%s' "$expected" > "$probe"
  chmod 0644 "$probe"
  test "$(cat "$link/$probe_name")" = "$expected"
  actual="$(curl --fail --silent --show-error --connect-timeout 5 --max-time 20 \
    -H 'Cache-Control: no-cache' "${base_url%/}/storage/$probe_name")"
  if [ "$actual" != "$expected" ]; then
    echo 'Public storage HTTP probe returned unexpected content.' >&2
    exit 1
  fi
fi

echo "public_storage_$mode=passed"
