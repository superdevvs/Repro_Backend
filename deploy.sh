#!/usr/bin/env bash
set -euo pipefail

echo 'This legacy backend deploy entry point is disabled.' >&2
echo 'Use the Backend desktop shortcut, which runs the verified deployment workflow with tests, backups, migrations, cache rebuilds, queue restart, rollback, and health checks.' >&2
exit 64
