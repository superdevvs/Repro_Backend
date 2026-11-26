#!/bin/sh
# Run the Linux-only cutover tests inside a disposable, network-disabled
# container as root. The source tree is mounted read-only at /src.
#
#   docker run --rm --pull never --network none --cpus 2 --memory 2g \
#     --mount type=bind,source=<checkout>,target=/src,readonly \
#     --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 /src/scripts/cutover/run-linux-tests.sh
#
# The Laravel tests in production/test_queue_flag.py also need the vendor tree:
#   --mount type=volume,source=<vendor volume>,target=/src/vendor,readonly,volume-subpath=vendor
# (an empty vendor/ folder in the checkout is the mount point). Without it they are skipped.
#
# Never run this on a real host: it installs a copy of the guard at its fixed
# production path inside the container.
#
# The MySQL probe's gate-state tests need the three fixtures that
# production/mysql/fixture.sh serves (REPRO_MYSQL_PROBE_FIXTURE and its
# _NETWORKING and _SKIP_GRANTS variants). Without them those tests skip and a
# WARNING line names them, first and last; with REPRO_REQUIRE_PROBE_FIXTURE=1
# the run stops before any suite instead. production/run-probe-fixture-tests.sh
# --linux-suite builds the fixtures and runs this with the requirement set.
set -eu
test "$(id -u)" -eq 0
test -f /.dockerenv || { echo 'Run only inside a disposable container' >&2; exit 2; }
cutover=/src/scripts/cutover

probe_warning=$(python3 -B "$cutover/production/test_mysql_probe.py" --fixture-check)
if [ -n "$probe_warning" ]; then
    printf '%s\n' "$probe_warning" >&2
    trap 'printf "%s\n" "$probe_warning" >&2' EXIT
fi

install -d -o root -g root -m 0755 /opt/repro-cutover-fence
install -o root -g root -m 0644 "$cutover/fence/guard.py" /opt/repro-cutover-fence/guard.py
install -d -o root -g root -m 0700 /var/lib/repro-fence-test

python3 -B -m unittest discover -s "$cutover" -p test_runner.py
python3 -B -m unittest discover -s "$cutover/fence" -p test_guard.py
python3 -B -m unittest discover -s "$cutover" -p test_process_contract.py -v
for production in test_admit.py test_fences.py test_adapter.py test_nginx_hold.py test_queue_flag.py test_capture.py \
                  test_cross_slice.py; do
    python3 -W ignore::ResourceWarning -B -m unittest discover -s "$cutover/production" -p "$production"
done
python3 -W ignore::ResourceWarning -B -m unittest discover -s "$cutover/production" -p test_identity_probe.py
# The MySQL probe's fixture tests skip without their fixtures (see the check at the top).
python3 -W ignore::ResourceWarning -B -m unittest discover -s "$cutover/production" -p test_mysql_probe.py
python3 -B -m unittest discover -s "$cutover/host" -p test_prewrite_fastcgi.py
# The sandbox config tests need no privileges and catch drift in the host.json contract. The
# launcher tests in the same file need docker run --privileged (they fail without it) and run only
# when production/sandbox/test_sandbox.py is passed as an extra argument.
python3 -B -m unittest discover -s "$cutover/production/sandbox" -p test_sandbox.py -k MakeConfigTests -v
for extra in "$@"; do
    python3 -B -m unittest discover -s "$(dirname "$cutover/$extra")" -p "$(basename "$extra")" -v
done

# Load the guard from its fixed installed path so its own entrypoint check holds.
REPRO_FENCE_TEST_ROOT=/var/lib/repro-fence-test /usr/bin/python3 -I -S -B -c '
import importlib.util, sys, types, unittest
fence = types.ModuleType("fence")
fence.__path__ = []
spec = importlib.util.spec_from_file_location("fence.guard", "/opt/repro-cutover-fence/guard.py")
guard = importlib.util.module_from_spec(spec)
sys.modules["fence"], sys.modules["fence.guard"] = fence, guard
spec.loader.exec_module(guard)
fence.guard = guard
sys.path.insert(0, "/src/scripts/cutover")
sys.path.insert(0, "/src/scripts/cutover/fence")
import test_refresh
result = unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromModule(test_refresh))
skipped = len(result.skipped)
sys.exit(0 if result.wasSuccessful() and skipped == 0 and result.testsRun > 0 else 1)
'
