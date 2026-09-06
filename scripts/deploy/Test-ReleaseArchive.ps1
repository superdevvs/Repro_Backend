[CmdletBinding()]
param([Parameter(Mandatory = $true)][string]$ArchivePath)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$members = @(& tar.exe -tzf $ArchivePath)
if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect the release archive.' }

foreach ($member in $members) {
    $normalized = $member.Replace('\', '/') -replace '^\./', ''
    $leaf = ($normalized -split '/')[-1]
    if ($normalized -match '(^/|^[A-Za-z]:|(^|/)\.\.(/|$))' -or
        ($leaf -match '^\.env(?:\.|$)' -and $leaf -cne '.env.example') -or
        $leaf -match '(?i)\.(pem|key|p12|pfx|jks|sqlite|sqlite3|tar|tgz|gz|zip)$' -or
        $leaf -match '^(id_rsa|id_ed25519|credentials(?:\..*)?)$' -or
        $normalized -match '(^|/)(output|quarantine[^/]*|\.git|node_modules|vendor)(/|$)' -or
        $normalized -match '^bootstrap/cache/.*\.php$') {
        throw "Release archive contains a prohibited secret/artifact path: $normalized"
    }
}

Write-Output 'Release archive path scan passed.'
