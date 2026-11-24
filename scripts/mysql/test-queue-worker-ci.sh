#!/usr/bin/env bash
# Run the socket-only queue contract once on the hosted MySQL job.
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != true || "${RUNNER_OS:-}" != Linux ]]; then
    echo 'This fixture must run on a hosted Linux CI runner.' >&2
    exit 1
fi
: "${MYSQL_CONTAINER:?The pinned MySQL CI service container is required.}"

image=$(docker inspect --format '{{.Config.Image}}' "$MYSQL_CONTAINER")
if [[ ! "$image" =~ ^mysql:8\.4@sha256:[0-9a-f]{64}$ ]]; then
    echo 'The fixture requires the Oracle image already pinned for MySQL CI.' >&2
    exit 1
fi

socket_dir=/fixture-socket
socket=$socket_dir/mysql.sock
runner_uid=$(id -u)
runner_gid=$(id -g)
container=''
socket_identity=''
cleanup() {
    local status=$?
    trap - EXIT
    if [[ -n "$container" ]] && ! docker rm -f "$container" >/dev/null; then
        status=1
    fi
    if [[ -n "$socket_identity" ]]; then
        if [[ ! -L "$socket_dir" && "$(stat -c '%d:%i' "$socket_dir")" == "$socket_identity" ]]; then
            sudo -n rm -f -- "$socket" "$socket.lock" || status=1
            sudo -n rmdir -- "$socket_dir" || status=1
        else
            status=1
        fi
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# mkdir refuses an existing path. Only the runner's group and the image's
# mysql UID can access this directory during the fixture.
sudo -n mkdir -m 0700 -- "$socket_dir"
socket_identity=$(stat -c '%d:%i' "$socket_dir")
sudo -n chown 999:"$runner_gid" "$socket_dir"
sudo -n chmod 2770 "$socket_dir"

container=$(docker run -d --pull never --network none --read-only --cap-drop ALL \
    --security-opt no-new-privileges --cpus 1 --memory 640m --pids-limit 120 --user mysql \
    --tmpfs '/var/lib/mysql:rw,exec,size=384m,uid=999,gid=999,mode=0700' \
    --tmpfs '/tmp:rw,noexec,size=32m,mode=1777' \
    --mount "type=bind,source=$socket_dir,target=$socket_dir" \
    --entrypoint /bin/bash "$image" -c '
        set -eu
        mysqld --no-defaults --initialize-insecure --datadir=/var/lib/mysql \
            --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=16M --log-error=/tmp/init.log
        exec mysqld --no-defaults --datadir=/var/lib/mysql --socket=/fixture-socket/mysql.sock \
            --pid-file=/tmp/mysql.pid --log-error=/tmp/fixture.log --tmpdir=/tmp \
            --skip-networking --mysqlx=OFF --event-scheduler=OFF --partial-revokes=OFF \
            --default-time-zone=+00:00 --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=16M \
            --max-connections=12 --skip-log-bin --sync-binlog=1 --innodb-flush-log-at-trx-commit=1
    ')
sql=(docker exec -i "$container" mysql --no-defaults --no-login-paths --protocol=SOCKET
    --socket="$socket" --user=root --batch --raw --skip-column-names)
identity=''
for attempt in {1..40}; do
    if identity=$("${sql[@]}" -e 'SELECT @@server_uuid, VERSION(), @@skip_networking, @@socket' 2>/dev/null); then
        break
    fi
    sleep 1
done
IFS=$'\t' read -r uuid version local_only bound_socket <<< "$identity"
if [[ ! "$uuid" =~ ^[0-9a-f-]{36}$ || "$version" != 8.4.13 || "$local_only" != 1 || "$bound_socket" != "$socket" ]]; then
    echo 'The private socket-only MySQL 8.4.13 fixture did not start as expected.' >&2
    exit 1
fi

password=$(openssl rand -hex 32)
key=base64:$(openssl rand -base64 32)
printf '::add-mask::%s\n::add-mask::%s\n' "$password" "$key"
{
    cat <<'SQL'
CREATE DATABASE repro_mysql_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE DATABASE repro_mysql_control CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE TABLE repro_mysql_control.writer_token (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id=1),
    application_database VARBINARY(64) NOT NULL,
    contract_version INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT INTO repro_mysql_control.writer_token VALUES (1,'repro_mysql_test',1);
USE repro_mysql_test;
SQL
    python3 - <<'PY'
from pathlib import Path
import re

schema = Path("database/schema/mysql-schema.sql").read_text(encoding="utf-8")
tables = re.findall(r"^CREATE TABLE `jobs`\s+\([^;]+;", schema, re.MULTILINE)
if len(tables) != 1:
    raise SystemExit("Expected one canonical jobs table")
print(tables[0])
PY
    for user in repro_test repro_token_fixture repro_queue_worker repro_queue_broad; do
        printf "CREATE USER '%s'@'localhost' IDENTIFIED WITH caching_sha2_password BY '%s';\n" "$user" "$password"
    done
    cat <<'SQL'
GRANT SELECT, INSERT, UPDATE, DELETE ON `repro\_mysql\_test`.* TO repro_test@localhost;
GRANT SELECT, UPDATE(id) ON repro_mysql_control.writer_token TO repro_test@localhost;
GRANT ALL ON `repro\_mysql\_test`.* TO repro_token_fixture@localhost;
GRANT ALL ON `repro\_mysql\_control`.* TO repro_token_fixture@localhost;
GRANT SELECT, INSERT, UPDATE, DELETE ON repro_mysql_test.jobs TO repro_queue_worker@localhost;
GRANT SELECT, INSERT, UPDATE, DELETE ON repro_mysql_test.jobs TO repro_queue_broad@localhost;
GRANT SELECT ON repro_mysql_control.writer_token TO repro_queue_broad@localhost;
SQL
} | "${sql[@]}"

# The test uses the installed Composer lock on the runner. The PHP process
# enters an empty network namespace and drops back to the runner's UID/GID.
php=$(command -v php)
fixture_php=(
    sudo -n /usr/bin/unshare --net -- /usr/bin/setpriv
    --reuid "$runner_uid" --regid "$runner_gid" --clear-groups --no-new-privs
    /usr/bin/env -i "PATH=$PATH" "HOME=$HOME" APP_ENV=testing "APP_KEY=$key"
    CACHE_STORE=array SESSION_DRIVER=array MAIL_MAILER=array QUEUE_CONNECTION=sync
    CLAMAV_SCAN_ON_UPLOAD=false LOG_CHANNEL=stderr PULSE_ENABLED=false TELESCOPE_ENABLED=false
    DB_CONNECTION=mysql "DB_SOCKET=$socket" DB_DATABASE=repro_mysql_test DB_USERNAME=repro_test
    "DB_PASSWORD=$password" MYSQL_QUEUE_WORKER_FIXTURE=isolated-v1
    "REPRO_MYSQL_EXPECTED_SERVER_UUID=$uuid" REPRO_MYSQL_QUEUE_WORKER=true
    REPRO_MYSQL_QUEUE_WORKER_USERNAME=repro_queue_worker "REPRO_MYSQL_QUEUE_WORKER_PASSWORD=$password"
    "$php"
)
"${fixture_php[@]}" -l config/queue.php
"${fixture_php[@]}" -l tests/MySql/contracts/MySqlQueueWorkerContract.php
"${fixture_php[@]}" vendor/bin/phpunit --no-progress --do-not-cache-result --fail-on-warning --fail-on-risky \
    --fail-on-phpunit-deprecation -c phpunit.mysql.xml tests/MySql/contracts/MySqlQueueWorkerContract.php
