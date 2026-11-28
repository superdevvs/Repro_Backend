#!/bin/bash
# The local end-to-end drill (build plan D18) in Docker, never on a production host:
#
#   scripts/cutover/production/e2e/run-drill.sh [run_drill.py run options, e.g. --through r1.init-live-config]
#
# Starts a socket-only MySQL 8.4.13 fixture (mysql/fixture.sh serve, from this checkout's accounts.sql) in
# one --network none container, then the QA image as a second --network none container, --privileged
# (the namespace sandbox needs it), with the checkout read-only at /src, the locked vendor volume
# read-only at /vendor-src, the fixture's volume at /fixture and a fresh volume at /srv for the sandbox
# roots, and runs run_drill.py there as root. Every container and volume it made is removed at exit,
# also after a failure or Ctrl-C.
#
# Environment: REPRO_DRILL_PREFIX names the containers and volumes (default repro-drill-<pid>; names that
# already exist are refused, not reused); REPRO_MYSQL_IMAGE, REPRO_QA_IMAGE and REPRO_DRILL_VENDOR_VOLUME
# override the pinned images and the vendor volume. Exit status: run_drill.py's (0 green, 1 a step
# failed, 3 stopped at a pending step, 4 a stand-in run); 2 for a usage error or a fixture that does not
# come up.
set -euo pipefail
export MSYS_NO_PATHCONV=1

die() { printf 'run-drill: %s\n' "$*" >&2; exit 2; }

mysql_image=${REPRO_MYSQL_IMAGE:-mysql@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902}
qa_image=${REPRO_QA_IMAGE:-repro-mysql-drill-qa:pymysql-1.1.2}
vendor_volume=${REPRO_DRILL_VENDOR_VOLUME:-repro-locked-dev-vendor-20261125}
prefix=${REPRO_DRILL_PREFIX:-repro-drill-$$}
[[ $prefix =~ ^[a-z0-9][a-z0-9_.-]{0,40}$ ]] || die "unsafe prefix: $prefix"
command -v docker >/dev/null || die "docker is not installed"

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# Docker on Windows needs C:/... rather than Git Bash's /c/...
checkout=$(cd "$here/../../../.." && { pwd -W 2>/dev/null || pwd; })
[ -f "$checkout/scripts/cutover/production/e2e/run_drill.py" ] || die "not a checkout: $checkout"
release=$(git -C "$checkout" rev-parse HEAD 2>/dev/null || printf '%040d' 0)
docker volume inspect "$vendor_volume" >/dev/null 2>&1 || die "vendor volume $vendor_volume is missing"

for name in "$prefix-mysql" "$prefix-qa"; do
    ! docker container inspect "$name" >/dev/null 2>&1 || die "container $name already exists"
done
source_mount="type=bind,source=$checkout,target=/src,readonly"
mysql_mount="type=bind,source=$checkout/scripts/cutover/production/mysql,target=/src,readonly"
archive_ref=${REPRO_DRILL_ARCHIVE_REF:-}
if [ -n "$archive_ref" ]; then
    release=$(git -C "$checkout" rev-parse --verify --end-of-options "${archive_ref}^{commit}") || die "invalid archive commit"
fi
for name in "$prefix-mysql" "$prefix-srv" ${archive_ref:+"$prefix-src"}; do
    ! docker volume inspect "$name" >/dev/null 2>&1 || die "volume $name already exists"
done

containers=()
volumes=()
cleanup() {
    local name
    for name in ${containers[@]+"${containers[@]}"}; do docker rm -f "$name" >/dev/null 2>&1 || true; done
    for name in ${volumes[@]+"${volumes[@]}"}; do docker volume rm "$name" >/dev/null 2>&1 || true; done
}
trap cleanup EXIT
trap 'exit 130' INT TERM

volumes+=("$prefix-mysql" "$prefix-srv")
docker volume create "$prefix-mysql" >/dev/null
docker volume create "$prefix-srv" >/dev/null
if [ -n "$archive_ref" ]; then
    volumes+=("$prefix-src")
    docker volume create "$prefix-src" >/dev/null
    git -C "$checkout" archive --format=tar "$release" | docker run --rm -i --pull never --network none \
        --mount "type=volume,source=$prefix-src,target=/snapshot" --entrypoint /bin/tar "$qa_image" -xf - -C /snapshot
    source_mount="type=volume,source=$prefix-src,target=/src,readonly"
    mysql_mount="type=volume,source=$prefix-src,target=/src,volume-subpath=scripts/cutover/production/mysql,readonly"
    echo "run-drill: testing archive $release"
fi
containers+=("$prefix-mysql")
docker run -d --pull never --name "$prefix-mysql" --network none --cpus 2 --memory 2g \
    --mount "type=volume,source=$prefix-mysql,target=/fixture" \
    --mount "$mysql_mount" \
    --tmpfs /var/lib/mysql:rw,exec,size=1500m --tmpfs /tmp:rw,size=256m,mode=1777 \
    --entrypoint /bin/bash "$mysql_image" /src/fixture.sh serve /fixture >/dev/null
for _ in $(seq 1 240); do
    docker exec "$prefix-mysql" test -f /fixture/ready 2>/dev/null && break
    [ "$(docker container inspect -f '{{.State.Running}}' "$prefix-mysql")" = true ] || break
    sleep 1
done
if ! docker exec "$prefix-mysql" test -f /fixture/ready 2>/dev/null; then
    docker logs "$prefix-mysql" >&2 || true
    die "fixture $prefix-mysql did not become ready"
fi
echo "run-drill: MySQL fixture ready: $prefix-mysql"

containers+=("$prefix-qa")
status=0
docker run --rm --pull never --name "$prefix-qa" --privileged --network none --cpus 4 --memory 4g \
    --mount "$source_mount" \
    --mount "type=volume,source=$vendor_volume,target=/vendor-src,volume-subpath=vendor,readonly" \
    --mount "type=volume,source=$prefix-mysql,target=/fixture" \
    --mount "type=volume,source=$prefix-srv,target=/srv" \
    --tmpfs /tmp:rw,exec,size=512m,mode=1777 \
    --entrypoint /usr/bin/python3 "$qa_image" \
    -I -S -B /src/scripts/cutover/production/e2e/run_drill.py run --release-sha "$release" "$@" || status=$?
echo "run-drill: run_drill.py exited $status"
exit "$status"
