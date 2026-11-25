#!/bin/sh
# Run the Linux-only cutover tests inside a disposable, network-disabled
# container as root. The source tree is mounted read-only at /src.
#
#   docker run --rm --pull never --network none --cpus 2 --memory 2g \
#     --mount type=bind,source=<checkout>,target=/src,readonly \
#     --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 /src/scripts/cutover/run-linux-tests.sh
#
# Never run this on a real host: it installs a copy of the guard at its fixed
# production path inside the container.
set -eu
test "$(id -u)" -eq 0
test -f /.dockerenv || { echo 'Run only inside a disposable container' >&2; exit 2; }
cutover=/src/scripts/cutover

install -d -o root -g root -m 0755 /opt/repro-cutover-fence
install -o root -g root -m 0644 "$cutover/fence/guard.py" /opt/repro-cutover-fence/guard.py
install -d -o root -g root -m 0700 /var/lib/repro-fence-test

python3 -B -m unittest discover -s "$cutover" -p test_runner.py
python3 -B -m unittest discover -s "$cutover/fence" -p test_guard.py
python3 -B -m unittest discover -s "$cutover" -p test_process_contract.py -v
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
