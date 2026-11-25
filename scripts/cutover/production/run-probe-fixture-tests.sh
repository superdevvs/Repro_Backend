#!/bin/bash
# Build the MySQL probe's three fixtures, run its tests against them with
# REPRO_REQUIRE_PROBE_FIXTURE=1 (a fixture test that would skip fails the run),
# and remove every container and volume it made. Runs where Docker runs (Linux,
# or Git Bash on Windows), never on a production host.
#
#   scripts/cutover/production/run-probe-fixture-tests.sh [--linux-suite]
#
# mysql/fixture.sh serve builds each fixture from this checkout's accounts.sql
# in a disposable MySQL 8.4.13 container: socket-only, a networking variant and
# a --skip-grant-tables variant, each serving from its own Docker volume. The QA
# container mounts the checkout read-only and the three volumes, and runs
# test_mysql_probe.py as root; with --linux-suite it runs ../run-linux-tests.sh
# (every Linux suite, the probe tests included) instead. Every container runs
# with --network none and is removed at exit, also after a failure or Ctrl-C.
#
# Environment: REPRO_PROBE_FIXTURE_PREFIX names the containers and volumes
# (default repro-probe-fixture-<pid>; names that already exist are refused, not
# reused); REPRO_MYSQL_IMAGE and REPRO_QA_IMAGE override the pinned images.
# Exit status: the tests' status; 2 for a usage error or a fixture that does not
# come up.
set -euo pipefail
export MSYS_NO_PATHCONV=1

die() { printf 'run-probe-fixture-tests: %s\n' "$*" >&2; exit 2; }

mode=probe
case $# in
    0) ;;
    1) [ "$1" = --linux-suite ] || die "usage: run-probe-fixture-tests.sh [--linux-suite]"; mode=linux ;;
    *) die "usage: run-probe-fixture-tests.sh [--linux-suite]" ;;
esac
mysql_image=${REPRO_MYSQL_IMAGE:-mysql@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902}
qa_image=${REPRO_QA_IMAGE:-repro-mysql-rehearsal-qa:20261122}
prefix=${REPRO_PROBE_FIXTURE_PREFIX:-repro-probe-fixture-$$}
[[ $prefix =~ ^[a-z0-9][a-z0-9_.-]{0,40}$ ]] || die "unsafe prefix: $prefix"
command -v docker >/dev/null || die "docker is not installed"

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# Docker on Windows needs C:/... rather than Git Bash's /c/...
checkout=$(cd "$here/../../.." && { pwd -W 2>/dev/null || pwd; })
[ -f "$checkout/scripts/cutover/production/test_mysql_probe.py" ] || die "not a checkout: $checkout"

variants=(socket networking skip-grant-tables)
for variant in "${variants[@]}" qa; do
    ! docker container inspect "$prefix-$variant" >/dev/null 2>&1 || die "container $prefix-$variant already exists"
    [ "$variant" = qa ] || ! docker volume inspect "$prefix-$variant" >/dev/null 2>&1 \
        || die "volume $prefix-$variant already exists"
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

for variant in "${variants[@]}"; do
    name=$prefix-$variant
    argument=()
    [ "$variant" = socket ] || argument=("$variant")
    volumes+=("$name")
    docker volume create "$name" >/dev/null
    containers+=("$name")
    docker run -d --pull never --name "$name" --network none --cpus 2 --memory 2g \
        --mount "type=volume,source=$name,target=/fixture" \
        --mount "type=bind,source=$checkout/scripts/cutover/production/mysql,target=/src,readonly" \
        --tmpfs /var/lib/mysql:rw,exec,size=1500m --tmpfs /tmp:rw,size=256m,mode=1777 \
        --entrypoint /bin/bash "$mysql_image" /src/fixture.sh serve /fixture ${argument[@]+"${argument[@]}"} >/dev/null
done

for variant in "${variants[@]}"; do
    name=$prefix-$variant
    for _ in $(seq 1 240); do
        docker exec "$name" test -f /fixture/ready 2>/dev/null && continue 2
        [ "$(docker container inspect -f '{{.State.Running}}' "$name")" = true ] || break
        sleep 1
    done
    docker logs "$name" >&2 || true
    die "fixture $name did not become ready"
done
echo "run-probe-fixture-tests: fixtures ready: ${variants[*]/#/$prefix-}"

if [ "$mode" = linux ]; then
    tests='/bin/sh /src/scripts/cutover/run-linux-tests.sh'
else
    tests='cd /src && python3 -B -W error::ResourceWarning -m unittest discover -s scripts/cutover/production -p test_mysql_probe.py -v'
fi
containers+=("$prefix-qa")
status=0
docker run --rm --pull never --name "$prefix-qa" --network none --cpus 2 --memory 2g \
    --mount "type=bind,source=$checkout,target=/src,readonly" \
    --mount "type=volume,source=$prefix-socket,target=/fixture/socket" \
    --mount "type=volume,source=$prefix-networking,target=/fixture/networking" \
    --mount "type=volume,source=$prefix-skip-grant-tables,target=/fixture/skip-grant-tables" \
    --tmpfs /tmp:rw,exec,size=512m,mode=1777 \
    -e REPRO_MYSQL_PROBE_FIXTURE=/fixture/socket \
    -e REPRO_MYSQL_PROBE_FIXTURE_NETWORKING=/fixture/networking \
    -e REPRO_MYSQL_PROBE_FIXTURE_SKIP_GRANTS=/fixture/skip-grant-tables \
    -e REPRO_REQUIRE_PROBE_FIXTURE=1 \
    --entrypoint /bin/sh "$qa_image" -c "$tests" || status=$?
if [ "$status" -eq 0 ]; then
    echo "run-probe-fixture-tests: OK ($mode)"
else
    echo "run-probe-fixture-tests: FAILED ($mode, exit $status)" >&2
fi
exit "$status"
