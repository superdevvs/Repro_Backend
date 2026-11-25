#!/bin/bash
# The cutover's MySQL accounts, rendered and (locally) served.
#
#   fixture.sh render <accounts.sql> <password-dir>
#       Print accounts.sql with each `IDENTIFIED BY :name;` filled from <password-dir>/<name>.
#       Each file must be a regular file owned by the caller, mode 0600 or stricter, holding one
#       password of 16 to 256 characters from [A-Za-z0-9._~+/=-] (nothing that needs quoting).
#       Nothing is printed unless every placeholder is filled. The administrator session (A1) and
#       the local fixture use this same rendering, so the tests exercise the SQL that is installed.
#
#   fixture.sh serve <fixture-dir> [networking|skip-grant-tables]
#       Only as root inside a disposable container of mysql 8.4.13 (the pinned image), with a tmpfs
#       at /var/lib/mysql: initialise a socket-only server with the production server options that
#       matter here, create the control table (runbook Release step 4), render and apply
#       accounts.sql with fresh random passwords and keep serving. <fixture-dir> (empty, or an
#       earlier fixture) receives:
#         run/mysql.sock          the server socket
#         root.cnf, <account>.cnf [client] user and password, 0600 (no socket: callers pass it)
#         passwords/<name>        the rendered passwords, 0600
#         server_uuid, accounts.sha256 (of the accounts.sql applied), variant, ready (written last)
#       A variant restarts the built server with networking on, or with --skip-grant-tables.
set -euo pipefail

die() { printf 'fixture.sh: %s\n' "$*" >&2; exit 1; }

password() {
    local file=$1 mode owner value
    [ -f "$file" ] && [ ! -L "$file" ] || die "missing password file: $file"
    read -r mode owner < <(stat -c '%a %u' "$file")
    [ "$owner" = "$(id -u)" ] || die "password file not owned by the caller: $file"
    (( (8#$mode & 8#077) == 0 )) || die "password file readable by others: $file"
    value=$(cat "$file")
    [[ $value =~ ^[A-Za-z0-9._~+/=-]{16,256}$ ]] \
        || die "password in $file must be 16 to 256 characters of [A-Za-z0-9._~+/=-]"
    printf '%s' "$value"
}

render() {
    local accounts=$1 directory=$2 line name value rendered=""
    [ -f "$accounts" ] || die "missing accounts file: $accounts"
    [ -d "$directory" ] || die "missing password directory: $directory"
    while IFS= read -r line || [ -n "$line" ]; do
        if [[ $line =~ IDENTIFIED\ BY\ :([a-z]+)\; ]]; then
            name=${BASH_REMATCH[1]}
            value=$(password "$directory/$name") || exit 1
            line=${line/":$name;"/"'$value';"}
        fi
        rendered+="$line"$'\n'
    done < "$accounts"
    [[ $rendered != *"IDENTIFIED BY :"* ]] || die "an account placeholder was left unfilled"
    printf '%s' "$rendered"
}

# Production options that shape grants, sessions and the binary log (scripts/mysql/native/mysql.cnf),
# with a small memory budget.
server_options() {
    printf '%s\n' --no-defaults --user=mysql --datadir=/var/lib/mysql --socket="$1/run/mysql.sock" \
        --pid-file=/tmp/mysqld.pid --log-error=/tmp/mysqld.log --tmpdir=/tmp --mysqlx=OFF \
        --skip-name-resolve --require-secure-transport=ON --local-infile=OFF --secure-file-priv=NULL \
        --persisted-globals-load=OFF --event-scheduler=OFF --default-time-zone=+00:00 \
        --character-set-server=utf8mb4 --collation-server=utf8mb4_0900_bin \
        --sql-mode=ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION \
        --innodb-buffer-pool-size=128M --innodb-redo-log-capacity=64M --innodb-flush-log-at-trx-commit=1 \
        --sync-binlog=1 --max-connections=96 --innodb-lock-wait-timeout=5 --server-id=64084 \
        --log-bin=binlog --binlog-format=ROW --binlog-row-image=FULL --gtid-mode=ON \
        --enforce-gtid-consistency=ON --binlog-expire-logs-seconds=0 --binlog-expire-logs-auto-purge=OFF
}

root_sql() {
    mysql --defaults-file="$FIXTURE/root.cnf" --no-login-paths --protocol=SOCKET \
        --socket="$FIXTURE/run/mysql.sock" --batch --raw --skip-column-names "$@"
}

start_server() {
    local -a options
    mapfile -t options < <(server_options "$FIXTURE")
    case ${1:-} in
        "") options+=(--skip-networking) ;;
        networking) options+=(--skip-networking=OFF --bind-address=127.0.0.1 --port=3306) ;;
        skip-grant-tables) options+=(--skip-grant-tables) ;;
    esac
    mysqld "${options[@]}" &
    SERVER=$!
    for _ in $(seq 1 120); do
        if [ -f "$FIXTURE/root.cnf" ]; then
            root_sql -e 'SELECT 1' >/dev/null 2>&1 && return 0
        else
            mysql --no-defaults --protocol=SOCKET --socket="$FIXTURE/run/mysql.sock" --user=root \
                -e 'SELECT 1' >/dev/null 2>&1 && return 0
        fi
        kill -0 "$SERVER" 2>/dev/null || { cat /tmp/mysqld.log >&2; die "mysqld exited"; }
        sleep 1
    done
    die "mysqld did not accept connections"
}

stop_server() {
    root_sql -e 'SHUTDOWN'
    wait "$SERVER" || true
}

new_password() {
    od -An -tx1 -N24 /dev/urandom | tr -d ' \n'
}

serve() {
    FIXTURE=$1
    local variant=${2:-} here accounts user name value
    case $variant in ""|networking|skip-grant-tables) ;; *) die "unknown variant: $variant" ;; esac
    [ "$(id -u)" = 0 ] && [ -f /.dockerenv ] || die "serve runs only as root inside a disposable container"
    case $FIXTURE in /?*) ;; *) die "the fixture directory must be absolute" ;; esac
    here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
    accounts=$here/accounts.sql
    umask 077
    install -d -m 0755 "$FIXTURE"
    if [ -n "$(ls -A "$FIXTURE")" ]; then
        [ -f "$FIXTURE/.repro-mysql-fixture" ] || die "$FIXTURE is not empty and is not an earlier fixture"
        find "$FIXTURE" -mindepth 1 -delete
    fi
    touch "$FIXTURE/.repro-mysql-fixture"
    install -d -o mysql -g mysql -m 0755 "$FIXTURE/run"
    install -d -m 0700 "$FIXTURE/passwords"
    chown mysql:mysql /var/lib/mysql
    mysqld --no-defaults --user=mysql --initialize-insecure --datadir=/var/lib/mysql \
        --innodb-buffer-pool-size=128M --innodb-redo-log-capacity=64M --log-error=/tmp/init.log
    start_server
    value=$(new_password)
    mysql --no-defaults --protocol=SOCKET --socket="$FIXTURE/run/mysql.sock" --user=root \
        -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$value'"
    printf '[client]\nuser=root\npassword=%s\n' "$value" > "$FIXTURE/root.cnf"
    # Runbook Release step 4 without its generic grant (design 2.2): the table grants need the table.
    root_sql <<'SQL'
CREATE DATABASE repro_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE DATABASE repro_mysql_control CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE TABLE repro_mysql_control.writer_token (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id = 1),
  application_database VARBINARY(64) NOT NULL,
  contract_version INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT INTO repro_mysql_control.writer_token VALUES (1, 'repro_dashboard', 1);
SQL
    while IFS=' ' read -r user name; do
        value=$(new_password)
        printf '%s\n' "$value" > "$FIXTURE/passwords/$name"
        printf '[client]\nuser=%s\npassword=%s\n' "$user" "$value" > "$FIXTURE/$user.cnf"
    done < <(sed -n "s/^CREATE USER '\([a-z_]*\)'@'localhost' IDENTIFIED BY :\([a-z]*\);$/\1 \2/p" "$accounts")
    value=$(render "$accounts" "$FIXTURE/passwords")
    root_sql <<<"$value"
    sha256sum "$accounts" | cut -d' ' -f1 > "$FIXTURE/accounts.sha256"
    root_sql -e 'SELECT @@GLOBAL.server_uuid' > "$FIXTURE/server_uuid"
    if [ -n "$variant" ]; then
        stop_server
        start_server "$variant"
    fi
    printf '%s\n' "${variant:-socket-only}" > "$FIXTURE/variant"
    chmod 0644 "$FIXTURE/server_uuid" "$FIXTURE/accounts.sha256" "$FIXTURE/variant"
    touch "$FIXTURE/ready"
    trap 'stop_server; exit 0' TERM INT
    wait "$SERVER"
}

case ${1:-} in
    render) [ $# -eq 3 ] || die "usage: fixture.sh render <accounts.sql> <password-dir>"; render "$2" "$3" ;;
    serve) [ $# -ge 2 ] && [ $# -le 3 ] || die "usage: fixture.sh serve <dir> [variant]"; serve "$2" "${3:-}" ;;
    *) die "usage: fixture.sh render <accounts.sql> <password-dir> | serve <dir> [networking|skip-grant-tables]" ;;
esac
