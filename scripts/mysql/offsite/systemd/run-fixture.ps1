param(
    [string] $Image = 'sha256:90096fc97dcb15e27a5f2d2b91522af449cdb924364fa876910fcd33ac67130e'
)
$ErrorActionPreference = 'Stop'
if ($Image -notmatch '^sha256:[0-9a-f]{64}$') { throw 'Fixture requires an exact existing image ID.' }
$unitRoot = (Resolve-Path -LiteralPath $PSScriptRoot).Path
$fixtureName = 'repro-backup-units-' + [Guid]::NewGuid().ToString('N').Substring(0, 12)
$fixtureLabel = 'repro.backup.unit-fixture=' + $fixtureName
$runExit = 1
$cleanupOk = $false
try {
    & docker image inspect --format '{{.Id}}' $Image | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'The reviewed image is unavailable; do not pull a replacement.' }
    & docker run --rm --pull never --name $fixtureName --label $fixtureLabel `
        --network none --read-only --cap-drop ALL --security-opt no-new-privileges `
        --user 1000:1000 --memory 256m --cpus 1 --pids-limit 48 `
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=16m,mode=1777 `
        --tmpfs /var/lib/repro-backup:rw,nosuid,nodev,noexec,size=4m,mode=0700,uid=1000,gid=1000 `
        --mount "type=bind,source=$unitRoot,target=/fixture,readonly" `
        --mount "type=bind,source=$unitRoot\fixtures\inert_runner.py,target=/opt/repro-backup-tools/scheduled_backup.py,readonly" `
        --mount "type=bind,source=$unitRoot\fixtures\plan.json,target=/etc/repro-backup/plan.json,readonly" `
        --entrypoint /usr/bin/python3 $Image -I -B /fixture/fixtures/verify_linux.py
    $runExit = $LASTEXITCODE
} finally {
    # Inspect by a fresh ownership label, then delete only the exact owned name.
    $owned = @(& docker ps -a --filter "label=$fixtureLabel" --format '{{.Names}}')
    if ($LASTEXITCODE -ne 0) { throw 'Cannot verify fixture cleanup inventory.' }
    foreach ($entry in $owned) {
        if ($entry -ne $fixtureName) { throw 'Unexpected fixture ownership inventory.' }
        & docker rm -f $fixtureName | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Could not remove the exact owned fixture.' }
    }
    $remaining = @(& docker ps -a --filter "label=$fixtureLabel" --format '{{.Names}}')
    if ($LASTEXITCODE -ne 0 -or $remaining.Count -ne 0) { throw 'Fixture cleanup could not be confirmed.' }
    $cleanupOk = $true
}
if ($runExit -ne 0) { throw 'Linux unit fixture failed; no manager or cloud operation was attempted.' }
if (-not $cleanupOk) { throw 'Fixture cleanup was not verified.' }
Write-Output '{"fixture_cleanup":"verified","persistent_volumes_created":0}'
