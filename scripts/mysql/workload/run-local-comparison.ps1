param(
    [Parameter(Mandatory = $true)][string]$AppArchive,
    [Parameter(Mandatory = $true)][string]$EvidenceDirectory,
    [switch]$RunRegression,
    [string]$DevVendorArchive,
    [switch]$RunNegativeControl
)

$ErrorActionPreference = 'Stop'
$archive = (Resolve-Path -LiteralPath $AppArchive).Path
$candidate = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
if ($RunRegression -and -not $DevVendorArchive) { throw 'Regression requires exact locked development dependencies' }
if ($RunNegativeControl -and -not $RunRegression) { throw 'Negative control requires the regression profile' }
$devArchive = if ($RunRegression) { (Resolve-Path -LiteralPath $DevVendorArchive).Path } else { $null }
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'A fresh evidence directory is required' }
$evidence = [IO.Path]::GetFullPath($EvidenceDirectory)
New-Item -ItemType Directory -Path $evidence | Out-Null
$mysqlImage = 'sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902'
$appImage = 'repro-mysql-rehearsal-qa:20261122'
$suffix = [guid]::NewGuid().ToString('N').Substring(0, 10)
$name = "repro-notification-compare-$suffix"
$volume = "$name-socket"
$bind = "type=volume,source=$volume,target=/fixture-socket,volume-nocopy"

$databaseProgram = @'
set -eu
umask 077
mysqld --no-defaults --initialize-insecure --datadir=/var/lib/mysql --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=16M --log-error=/tmp/setup.log
exec mysqld --no-defaults --datadir=/var/lib/mysql --socket=/fixture-socket/mysql.sock --pid-file=/tmp/fixture.pid --log-error=/tmp/fixture.log --tmpdir=/tmp --skip-networking --mysqlx=OFF --event-scheduler=OFF --partial-revokes=OFF --default-time-zone=+00:00 --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=16M --max-connections=12 --log-bin=/var/lib/mysql/binlog --server-id=3384 --sync-binlog=1 --innodb-flush-log-at-trx-commit=1
'@
$schemaProgram = @'
CREATE DATABASE repro_mysql84_acceptance CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE USER repro_accept_admin@localhost IDENTIFIED WITH caching_sha2_password BY 'ephemeral_synthetic_fixture_admin' PASSWORD EXPIRE NEVER;
CREATE USER repro_accept_worker@localhost IDENTIFIED WITH caching_sha2_password BY 'ephemeral_synthetic_fixture_worker' PASSWORD EXPIRE NEVER;
CREATE USER repro_accept_reader@localhost IDENTIFIED WITH caching_sha2_password BY 'ephemeral_synthetic_fixture_reader' PASSWORD EXPIRE NEVER;
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,REFERENCES,INDEX,ALTER,TRIGGER ON `repro\_mysql84\_acceptance`.* TO repro_accept_admin@localhost;
GRANT SELECT,INSERT,UPDATE,DELETE ON `repro\_mysql84\_acceptance`.* TO repro_accept_worker@localhost;
GRANT SELECT ON `repro\_mysql84\_acceptance`.* TO repro_accept_reader@localhost;
'@
$applicationProgram = @'
set -eu
umask 077
payload=$(cat)
tar --no-same-owner -xzf /fixture-app.tar.gz
cp /workspace/app/Services/NotificationReadStateService.php /tmp/original-notification-service.php
cp /candidate-source/app/Services/NotificationReadStateService.php /workspace/app/Services/NotificationReadStateService.php
cp /candidate-source/tests/MySql/NotificationReadStateConcurrencyTest.php /workspace/tests/MySql/NotificationReadStateConcurrencyTest.php
cp /candidate-source/tests/MySql/support/notification-read-state-worker.php /workspace/tests/MySql/support/notification-read-state-worker.php
for name in compare.py compare.php test_compare.py; do
  cp "/comparison-tools/$name" "/workspace/scripts/mysql/workload/$name"
done
workload=/workspace/scripts/mysql/workload
php -l "$workload/compare.php"
php -l /workspace/app/Services/NotificationReadStateService.php
python3 -B -m unittest discover -s "$workload" -p test_compare.py
if [ "$2" = regression ]; then
  tar --no-same-owner -xzf /fixture-dev-vendor.tar.gz
  python3 -c 'import json; lock=json.load(open("composer.lock")); installed=json.load(open("vendor/composer/installed.json")); actual={p["name"]:p for p in installed["packages"]}; expected=lock["packages"]+lock["packages-dev"]; assert len(expected)==len(actual); assert all(p["name"] in actual and p["version"]==actual[p["name"]]["version"] and p.get("source",{}).get("reference")==actual[p["name"]].get("source",{}).get("reference") for p in expected); print("Exact locked production and development dependency inventory verified.")'
  export APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array MAIL_MAILER=array QUEUE_CONNECTION=sync CLAMAV_SCAN_ON_UPLOAD=false
  APP_KEY=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
  export APP_KEY
  php vendor/bin/phpunit --no-progress tests/Feature/NotificationReadStateTest.php
  export DB_CONNECTION=mysql DB_SOCKET=/fixture-socket/mysql.sock DB_DATABASE=repro_mysql_test DB_USERNAME=repro_test DB_PASSWORD=ephemeral_synthetic_regression_fixture DB_COLLATION=utf8mb4_0900_bin
  php artisan migrate --force --no-interaction > /evidence/migrations.log
  php vendor/bin/phpunit --no-progress -c phpunit.mysql.xml tests/MySql/NotificationReadStateConcurrencyTest.php
  if [ "$3" = negative-control ]; then
    cp /tmp/original-notification-service.php /workspace/app/Services/NotificationReadStateService.php
    set +e
    php vendor/bin/phpunit --no-progress -c phpunit.mysql.xml --filter 'existing receipt duplicate-key upgrade' tests/MySql/NotificationReadStateConcurrencyTest.php > /evidence/negative-control.log 2>&1
    control_status=$?
    set -e
    cp /candidate-source/app/Services/NotificationReadStateService.php /workspace/app/Services/NotificationReadStateService.php
    test "$control_status" -eq 1
    php -r 'exit(str_contains(file_get_contents("/evidence/negative-control.log"), "\"driver_error\":1213") ? 0 : 1);'
    printf '%s\n' 'Negative control reproduced MySQL deadlock 1213 on the original service.'
  fi
  unset DB_CONNECTION DB_SOCKET DB_DATABASE DB_USERNAME DB_PASSWORD DB_COLLATION APP_KEY
fi
printf '%s' "$payload" | python3 -I "$workload/compare.py" --app /workspace --runtime /runs/comparison --php /usr/local/bin/php --supervised-parent-net "$1"
cp /runs/comparison/result.json /evidence/result.json
'@

docker image inspect $mysqlImage $appImage --format '{{.Id}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Only already available fixture images may be used' }
docker volume create --driver local --label purpose=isolated-repro-notification-comparison --opt type=tmpfs --opt device=tmpfs --opt 'o=uid=999,gid=999,mode=0777,size=8m' $volume | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not create disposable socket volume' }
try {
    docker run -d --rm --pull never --label purpose=isolated-repro-notification-comparison --name $name --network none --read-only --cap-drop ALL --security-opt no-new-privileges --cpus 1 --memory 640m --pids-limit 120 --user mysql --tmpfs '/var/lib/mysql:rw,exec,size=384m,uid=999,gid=999,mode=0700' --tmpfs '/tmp:rw,noexec,size=32m,mode=1777' --mount $bind --entrypoint /bin/bash $mysqlImage -c $databaseProgram | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not start disposable MySQL fixture' }
    $dbExec = @('exec', '-i', $name, 'mysql', '--no-defaults', '--no-login-paths', '--protocol=SOCKET', '--socket=/fixture-socket/mysql.sock', '--user=root', '--batch', '--raw', '--skip-column-names')
    $uuid = $null
    for ($attempt = 1; $attempt -le 25; $attempt++) {
        $uuid = docker @dbExec -e 'SELECT @@server_uuid' 2>$null
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Seconds 1
    }
    if ($uuid -cnotmatch '^[0-9a-f-]{36}$') { throw 'Disposable database did not become ready' }
    $schemaProgram | docker @dbExec
    if ($LASTEXITCODE -ne 0) { throw 'Could not create fixture grants' }
    if ($RunRegression) {
        $regressionSchema = @'
SET GLOBAL log_bin_trust_function_creators=ON;
CREATE DATABASE repro_mysql_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE USER repro_test@localhost IDENTIFIED WITH caching_sha2_password BY 'ephemeral_synthetic_regression_fixture' PASSWORD EXPIRE NEVER;
GRANT ALL ON `repro\_mysql\_test`.* TO repro_test@localhost;
'@
        $regressionSchema | docker @dbExec
        if ($LASTEXITCODE -ne 0) { throw 'Could not create isolated regression schema' }
        Get-Content -Raw -LiteralPath (Join-Path $candidate 'database/schema/mysql-schema.sql') | docker @dbExec --database=repro_mysql_test
        if ($LASTEXITCODE -ne 0) { throw 'Could not import schema-only regression baseline' }
    }
    $peerNamespace = docker exec $name /usr/bin/readlink /proc/self/ns/net
    if ($LASTEXITCODE -ne 0 -or $peerNamespace -cnotmatch '^net:\[[0-9]+\]$') { throw 'Invalid fixture namespace' }
    $credentials = @{
        version = 1; server_uuid = $uuid; socket = '/fixture-socket/mysql.sock'; database = 'repro_mysql84_acceptance'
        accounts = @{
            admin = @{username = 'repro_accept_admin'; password = 'ephemeral_synthetic_fixture_admin'}
            worker = @{username = 'repro_accept_worker'; password = 'ephemeral_synthetic_fixture_worker'}
            reader = @{username = 'repro_accept_reader'; password = 'ephemeral_synthetic_fixture_reader'}
        }
    } | ConvertTo-Json -Depth 5 -Compress
    @{
        scope = 'disposable-local-synthetic-comparison'; app_archive_sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLower()
        mysql_image = $mysqlImage; app_image = $appImage; namespace_baseline_source = 'independently observed separate MySQL fixture namespace'
        namespace_baseline = $peerNamespace; network = 'none'; application_user = '1000:1000'; dropped_capabilities = 'ALL'; no_new_privileges = $true
        application_cpus = 1; application_memory_mib = 640; mysql_cpus = 1; mysql_memory_mib = 640; mysql_buffer_pool_mib = 96
        database_storage = 'tmpfs'; application_storage = 'tmpfs'; production_cutover = $false
        application_overlay_sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $candidate 'app/Services/NotificationReadStateService.php')).Hash.ToLower()
        regression_database_used = [bool]$RunRegression
        negative_control_requested = [bool]$RunNegativeControl
        dev_vendor_archive_sha256 = if ($devArchive) { (Get-FileHash -Algorithm SHA256 -LiteralPath $devArchive).Hash.ToLower() } else { $null }
    } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $evidence 'fixture.json') -Encoding utf8
    $regressionMode = if ($RunRegression) { 'regression' } else { 'comparison-only' }
    $controlMode = if ($RunNegativeControl) { 'negative-control' } else { 'none' }
    $devMount = if ($devArchive) { @('--mount', "type=bind,source=$devArchive,target=/fixture-dev-vendor.tar.gz,readonly") } else { @() }
    $credentials | docker run --rm -i --pull never --label purpose=isolated-repro-notification-comparison --network none --read-only --cap-drop ALL --security-opt no-new-privileges --cpus 1 --memory 640m --pids-limit 90 --user '1000:1000' --tmpfs '/workspace:rw,exec,size=384m,uid=1000,gid=1000,mode=0700' --tmpfs '/tmp:rw,noexec,size=32m,mode=1777' --tmpfs '/runs:rw,noexec,size=32m,uid=1000,gid=1000,mode=0700' --mount "type=bind,source=$archive,target=/fixture-app.tar.gz,readonly" --mount "type=bind,source=$PSScriptRoot,target=/comparison-tools,readonly" --mount "type=bind,source=$candidate,target=/candidate-source,readonly" --mount "type=bind,source=$evidence,target=/evidence" --mount "$bind,readonly" @devMount --workdir /workspace --entrypoint /bin/sh $appImage -c $applicationProgram fixture $peerNamespace $regressionMode $controlMode | Tee-Object -FilePath (Join-Path $evidence 'run.log')
    $comparisonStatus = $LASTEXITCODE
    $remaining = docker @dbExec -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='repro_mysql84_acceptance'"
    if ($LASTEXITCODE -ne 0 -or $remaining -ne '0') { throw 'Synthetic tables remained; inspect fixture failure before any reuse' }
    if ($comparisonStatus -ne 0) { throw 'Comparison refused or failed' }
} finally {
    docker stop --time 20 $name 2>$null | Out-Null
    docker volume rm $volume 2>$null | Out-Null
}
