param(
    [Parameter(Mandatory = $true)][string]$LockedVendorArchive,
    [Parameter(Mandatory = $true)][string]$PinnedPyMySqlWheel,
    [Parameter(Mandatory = $true)][string]$EvidenceDirectory
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
$vendor = (Resolve-Path -LiteralPath $LockedVendorArchive).Path
$wheel = (Resolve-Path -LiteralPath $PinnedPyMySqlWheel).Path
$evidence = [IO.Path]::GetFullPath($EvidenceDirectory)
if ((Test-Path -LiteralPath $evidence) -or $evidence.StartsWith($repo + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Use a fresh evidence directory outside the checkout'
}
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $wheel).Hash -cne 'E6B1D89711DD51F8F74B1631FE08F039E7D76CF67A42A323D3178F0F25762ED9') {
    throw 'The pinned offline PyMySQL wheel does not match'
}
if ((Get-CimInstance -ClassName Win32_OperatingSystem).FreePhysicalMemory -lt 2300000) {
    throw 'Insufficient device headroom for the bounded fixture processes'
}
$appImage = 'sha256:90096fc97dcb15e27a5f2d2b91522af449cdb924364fa876910fcd33ac67130e'
$mysqlImage = 'sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902'
docker image inspect $appImage $mysqlImage --format '{{.Id}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'The pre-existing pinned QA images are required' }
$release = git -C $repo rev-parse HEAD
if ($LASTEXITCODE -ne 0 -or $release -cnotmatch '^[0-9a-f]{40}$') { throw 'Commit identity unavailable' }
New-Item -ItemType Directory -Path $evidence | Out-Null
$archive = Join-Path $evidence 'release.tar'
git -C $repo archive --format=tar --output=$archive $release
if ($LASTEXITCODE -ne 0) { throw 'Could not archive the reviewed source' }
$artifact = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
$id = [Guid]::NewGuid().ToString('N').Substring(0, 10)
$owner = "isolated-repro-dress-$id"
$label = "purpose=$owner"
$db = "repro-dress-db-$id"
$app = "repro-dress-app-$id"
$volume = "repro-dress-socket-$id"
$socket = "type=volume,source=$volume,target=/fixture-socket,volume-nocopy"
$dbCommand = @'
set -eu
umask 077
test "$(id -u)" -eq 1000
test "$(wc -l </proc/net/route)" -le 1
mysqld --no-defaults --initialize-insecure --datadir=/var/lib/mysql --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=48M --log-error=/tmp/initialize.log
exec mysqld --no-defaults --datadir=/var/lib/mysql --socket=/fixture-socket/mysql.sock --pid-file=/tmp/fixture.pid --tmpdir=/tmp --skip-networking --mysqlx=OFF --event-scheduler=OFF --local-infile=OFF --innodb-buffer-pool-size=96M --innodb-redo-log-capacity=48M --max-connections=12 --performance-schema=OFF --default-time-zone=+00:00 --log-bin=/var/lib/mysql/binlog --server-id=3386 --sync-binlog=1 --innodb-flush-log-at-trx-commit=1 --log-error=/tmp/server.log
'@
$appCommand = @'
set -eu
umask 077
test "$(id -u)" -eq 1000
test "$(wc -l </proc/net/route)" -le 1
sha256sum /release.tar | grep -q "^$2 "
tar --no-same-owner -xf /release.tar
tar --no-same-owner -xzf /vendor.tar.gz
test ! -e .env && test ! -e .env.production && test ! -e .env.testing && test ! -e bootstrap/cache/config.php
python3 -c 'import json; lock=json.load(open("composer.lock")); installed=json.load(open("vendor/composer/installed.json")); entries=lambda packages:{p["name"]:(p["version"],p.get("source",{}).get("reference")) for p in packages}; assert entries(installed["packages"])==entries(lock["packages"]+lock["packages-dev"])'
php -l /host/probe.php >/dev/null
timeout -k 6 120 python3 -I -B -c 'import runpy,sys; sys.path.insert(0,"/workspace"); runpy.run_path("/host/rehearse.py",run_name="__main__")' "$1" "$2"
'@
$dbCommand = $dbCommand.Replace([string][char]13, '')
$appCommand = $appCommand.Replace([string][char]13, '')
$result = 1
try {
    docker volume create --driver local --label $label --opt type=tmpfs --opt device=tmpfs --opt 'o=uid=1000,gid=1000,mode=0700,size=8m' $volume | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the private socket volume' }
    docker run -d --rm --pull never --name $db --label $label --network none --read-only --cap-drop ALL --security-opt no-new-privileges --cpus 1 --memory 640m --pids-limit 90 --user '1000:1000' --tmpfs '/var/lib/mysql:rw,exec,size=384m,uid=1000,gid=1000,mode=0700' --tmpfs '/tmp:rw,noexec,size=32m,mode=1777' --mount $socket --entrypoint /bin/sh $mysqlImage -c $dbCommand | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not start the owned fixture database' }
    $ready = $false
    for ($check = 0; $check -lt 40; $check++) {
        $version = docker exec $db mysql --no-defaults --no-login-paths --protocol=SOCKET --socket=/fixture-socket/mysql.sock --user=root --skip-column-names -e 'SELECT VERSION()' 2>$null
        if ($LASTEXITCODE -eq 0 -and $version -ceq '8.4.13') { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Owned fixture MySQL did not become ready' }
    docker run --rm --pull never --name $app --label $label --network none --read-only --cap-drop ALL --security-opt no-new-privileges --cpus 1 --memory 768m --pids-limit 64 --user '1000:1000' --tmpfs '/workspace:rw,exec,size=384m,uid=1000,gid=1000,mode=0700' --tmpfs '/private:rw,noexec,size=40m,uid=1000,gid=1000,mode=0700' --tmpfs '/tmp:rw,noexec,size=32m,mode=1777' --mount "$socket,readonly" --mount "type=bind,source=$PSScriptRoot,target=/host,readonly" --mount "type=bind,source=$archive,target=/release.tar,readonly" --mount "type=bind,source=$vendor,target=/vendor.tar.gz,readonly" --mount "type=bind,source=$wheel,target=/offline-pymysql.whl,readonly" --workdir /workspace --entrypoint /bin/sh $appImage -c $appCommand fixture $release $artifact | Tee-Object -FilePath (Join-Path $evidence 'report.json')
    $result = $LASTEXITCODE
} finally {
    foreach ($container in @($app, $db)) {
        $ownerFlag = docker inspect --type container --format '{{index .Config.Labels "purpose"}}' $container 2>$null
        if ($LASTEXITCODE -eq 0 -and $ownerFlag -ceq $owner) { docker stop --time 12 $container | Out-Null }
    }
    $ownerFlag = docker volume inspect --format '{{index .Labels "purpose"}}' $volume 2>$null
    if ($LASTEXITCODE -eq 0 -and $ownerFlag -ceq $owner) { docker volume rm $volume | Out-Null }
    $remaining = docker ps -a --filter "label=$label" --format '{{.Names}}'
    if ($LASTEXITCODE -ne 0 -or $remaining) { throw 'An owned fixture container remains' }
    $remaining = docker volume ls --filter "label=$label" --format '{{.Name}}'
    if ($LASTEXITCODE -ne 0 -or $remaining) { throw 'An owned fixture volume remains' }
    @{ owned_resources_removed = $true; release = $release; artifact_sha256 = $artifact } |
        ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $evidence 'cleanup.json') -Encoding utf8NoBOM
}
exit $result
