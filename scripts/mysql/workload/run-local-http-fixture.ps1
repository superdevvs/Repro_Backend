param(
    [Parameter(Mandatory = $true)][string]$LockedVendorArchive,
    [Parameter(Mandatory = $true)][string]$EvidenceDirectory,
    [switch]$SimulateCopy,
    [switch]$MediaFixture
)

$ErrorActionPreference = 'Stop'
$candidate = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
$vendorArchive = (Resolve-Path -LiteralPath $LockedVendorArchive).Path
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'Fresh evidence directory required' }
$evidence = [IO.Path]::GetFullPath($EvidenceDirectory)
New-Item -ItemType Directory -Path $evidence | Out-Null
git -C $candidate diff --quiet HEAD -- app bootstrap config routes composer.json composer.lock database
if ($LASTEXITCODE -ne 0) { throw 'Application changes must be reviewed in the candidate commit before the fixture archive' }
$untrackedApplication = @(git -C $candidate ls-files --others --exclude-standard -- app bootstrap config routes composer.json composer.lock database)
if ($LASTEXITCODE -ne 0 -or $untrackedApplication.Count -ne 0) { throw 'Untracked application files cannot be part of a committed fixture archive' }
$release = git -C $candidate rev-parse HEAD
if ($LASTEXITCODE -ne 0 -or $release -cnotmatch '^[0-9a-f]{40}$') { throw 'Candidate commit unavailable' }
$sourceArchive = Join-Path $evidence 'source.tar'
git -C $candidate archive --format=tar --output $sourceArchive $release
if ($LASTEXITCODE -ne 0) { throw 'Could not archive candidate' }
$mysqlImage = 'sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902'
$appImage = 'sha256:90096fc97dcb15e27a5f2d2b91522af449cdb924364fa876910fcd33ac67130e'
docker image inspect $mysqlImage $appImage --format '{{.Id}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Only pre-existing pinned images are allowed' }
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 10)
$databaseName = 'repro-laravel-http-db-' + $suffix
$appName = 'repro-laravel-http-app-' + $suffix
$socketVolume = 'repro-laravel-http-socket-' + $suffix
$socketMount = "type=volume,source=$socketVolume,target=/db,volume-nocopy"
function New-FixtureSecret { [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant() }
$rootPassword = New-FixtureSecret
$adminPassword = New-FixtureSecret
$appPassword = New-FixtureSecret
$monitorPassword = New-FixtureSecret
$databaseProgram = @'
set -eu
umask 077
mysqld --no-defaults --initialize-insecure --datadir=/var/lib/mysql --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=32M --log-error=/tmp/initialize.log
exec mysqld --no-defaults --datadir=/var/lib/mysql --socket=/db/mysql.sock --pid-file=/tmp/mysql.pid --log-error=/tmp/mysql.log --tmpdir=/tmp --skip-networking --mysqlx=OFF --event-scheduler=OFF --partial-revokes=OFF --default-time-zone=+00:00 --innodb-buffer-pool-size=128M --innodb-redo-log-capacity=32M --max-connections=24 --log-bin=/var/lib/mysql/binlog --server-id=3385 --sync-binlog=1 --innodb-flush-log-at-trx-commit=1 --log-bin-trust-function-creators=ON --performance-schema-events-statements-history-size=256 --performance-schema-max-sql-text-length=256 --performance-schema-max-digest-length=256
'@

try {
    docker volume create --label purpose=repro-laravel-http-fixture --driver local --opt type=tmpfs --opt device=tmpfs --opt 'o=uid=1000,gid=1000,mode=0700,size=8m' $socketVolume | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not create isolated socket volume' }
    docker run -d --pull never --name $databaseName --label purpose=repro-laravel-http-fixture --network none --read-only --cap-drop ALL --security-opt no-new-privileges --user '1000:1000' --memory 768m --cpus 1 --pids-limit 120 --tmpfs '/var/lib/mysql:rw,exec,size=512m,uid=1000,gid=1000,mode=0700' --tmpfs '/tmp:rw,noexec,size=32m,mode=1777' --mount $socketMount --entrypoint /bin/bash $mysqlImage -c $databaseProgram | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not start isolated MySQL fixture' }
    $mysql = @('exec', '-i', $databaseName, 'mysql', '--no-defaults', '--no-login-paths', '--protocol=SOCKET', '--socket=/db/mysql.sock', '--user=root', '--batch', '--raw', '--skip-column-names')
    $uuid = $null
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        $uuid = docker @mysql -e 'SELECT @@server_uuid' 2>$null
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Seconds 1
    }
    if ($uuid -cnotmatch '^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$') { throw 'Isolated database identity unavailable' }
    $sql = @"
CREATE DATABASE repro_http_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE USER repro_http_admin@localhost IDENTIFIED WITH caching_sha2_password BY '$adminPassword';
CREATE USER repro_http_app@localhost IDENTIFIED WITH caching_sha2_password BY '$appPassword';
CREATE USER repro_http_monitor@localhost IDENTIFIED WITH caching_sha2_password BY '$monitorPassword';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX,REFERENCES,TRIGGER ON ``repro\_http\_fixture``.* TO repro_http_admin@localhost;
GRANT SELECT,INSERT,UPDATE,DELETE ON ``repro\_http\_fixture``.* TO repro_http_app@localhost;
GRANT SELECT ON performance_schema.threads TO repro_http_monitor@localhost;
GRANT SELECT ON performance_schema.events_statements_history TO repro_http_monitor@localhost;
UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME IN ('events_statements_current','events_statements_history');
ALTER USER root@localhost IDENTIFIED WITH caching_sha2_password BY '$rootPassword';
"@
    $sql | docker @mysql
    if ($LASTEXITCODE -ne 0) { throw 'Could not configure restricted synthetic fixture principals' }
    $baseline = docker exec $databaseName /usr/bin/readlink /proc/self/ns/net
    if ($LASTEXITCODE -ne 0 -or $baseline -cnotmatch '^net:\[[0-9]+\]$') { throw 'Namespace baseline unavailable' }
    $payload = @{
        uuid = $uuid
        admin = @{username = 'repro_http_admin'; password = $adminPassword}
        app = @{username = 'repro_http_app'; password = $appPassword}
        monitor = @{username = 'repro_http_monitor'; password = $monitorPassword}
        source_archive_sha256 = (Get-FileHash -LiteralPath $sourceArchive -Algorithm SHA256).Hash.ToLowerInvariant()
        vendor_archive_sha256 = (Get-FileHash -LiteralPath $vendorArchive -Algorithm SHA256).Hash.ToLowerInvariant()
    } | ConvertTo-Json -Depth 5 -Compress
    @{
        scope = 'local-synthetic-laravel-fpm-http'; release_sha = $release; mysql_image = $mysqlImage; app_image = $appImage
        namespace_baseline = $baseline; namespace_baseline_source = 'separate network-disabled MySQL fixture'
        network = 'none'; user = '1000:1000'; capabilities = 'none'; no_new_privileges = $true
        database_memory_mib = 768; application_memory_mib = 1024; database_cpus = 1; application_cpus = 2
        storage = 'tmpfs'; production_readiness = $false
        tools = @(Get-FileHash -LiteralPath $PSCommandPath,(Join-Path $PSScriptRoot 'laravel_http_fixture.py'),(Join-Path $PSScriptRoot 'laravel-http-fixture.php'),(Join-Path $PSScriptRoot 'http_execute.py'),(Join-Path $PSScriptRoot 'http_profile.py') -Algorithm SHA256 | ForEach-Object { @{name = [IO.Path]::GetFileName($_.Path); sha256 = $_.Hash.ToLowerInvariant()} })
    } | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $evidence 'supervisor.json') -Encoding utf8
    $fixtureArgs = @('-B', '/fixture-tools/laravel_http_fixture.py', '--release', $release, '--baseline-net', $baseline)
    if ($SimulateCopy) { $fixtureArgs += '--simulate-copy' }
    if ($MediaFixture) { $fixtureArgs += '--media-fixture' }
    $payload | docker run --rm -i --pull never --name $appName --label purpose=repro-laravel-http-fixture --network none --read-only --cap-drop ALL --security-opt no-new-privileges --user '1000:1000' --memory 1024m --cpus 2 --pids-limit 100 --tmpfs '/workspace:rw,exec,size=640m,uid=1000,gid=1000,mode=0700' --tmpfs '/runs:rw,noexec,size=128m,uid=1000,gid=1000,mode=0700' --tmpfs '/tmp:rw,noexec,size=64m,mode=1777' --mount $socketMount --mount "type=bind,source=$sourceArchive,target=/source.tar,readonly" --mount "type=bind,source=$vendorArchive,target=/vendor.tar.gz,readonly" --mount "type=bind,source=$PSScriptRoot,target=/fixture-tools,readonly" --mount "type=bind,source=$evidence,target=/evidence" --workdir /workspace --entrypoint /usr/bin/python3 $appImage @fixtureArgs | Tee-Object -FilePath (Join-Path $evidence 'run.log')
    if ($LASTEXITCODE -ne 0) { throw 'Local synthetic Laravel HTTP fixture refused' }
} finally {
    docker inspect $databaseName --format '{"running":{{.State.Running}},"oom_killed":{{.State.OOMKilled}},"exit_code":{{.State.ExitCode}}}' 2>$null | Set-Content -LiteralPath (Join-Path $evidence 'database-final-state.json') -Encoding utf8
    docker rm -f $appName 2>$null | Out-Null
    docker rm -f $databaseName 2>$null | Out-Null
    docker volume rm $socketVolume 2>$null | Out-Null
    $remainingContainers = @(docker ps -a --format '{{.Names}}' 2>$null)
    $containersChecked = $LASTEXITCODE -eq 0
    $remainingVolumes = @(docker volume ls --format '{{.Name}}' 2>$null)
    $volumesChecked = $LASTEXITCODE -eq 0
    $cleanupVerified = $containersChecked -and $volumesChecked -and
        $appName -notin $remainingContainers -and $databaseName -notin $remainingContainers -and
        $socketVolume -notin $remainingVolumes
    @{scope = 'exact-local-fixture-resources'; removed = $cleanupVerified} |
        ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $evidence 'cleanup.json') -Encoding utf8
    if (-not $cleanupVerified) { throw 'Exact fixture cleanup could not be verified; ownership-scoped inspection required' }
}
