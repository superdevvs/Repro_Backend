[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Backend', 'Frontend')]
    [string]$Component,
    [Parameter(Mandatory = $true)]
    [string]$CommitMessage,
    [switch]$Yes,
    [switch]$NoPause,
    [switch]$DryRun,
    [switch]$SkipLocalTests,
    [switch]$SkipMigrate,
    [string]$RepositoryPath,
    [switch]$PreparedRelease,
    [switch]$ConfigureStripeWebhook
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspaceRoot = 'C:\Users\shubh\Desktop\work\repro\Dashboard\Live working'
$deployWorkspace = 'C:\Users\shubh\.openclaw\workspace'
$repoPath = if ($RepositoryPath) { (Resolve-Path -LiteralPath $RepositoryPath).Path } else { Join-Path $workspaceRoot $Component.ToLowerInvariant() }
$repoSlug = if ($Component -eq 'Backend') { 'superdevvs/Repro_Backend' } else { 'superdevvs/Repro-Frontend' }
$serverHost = 'repro-deploy'
$remoteApp = if ($Component -eq 'Backend') { '/var/www/backend' } else { '/var/www/frontend' }
$logDirectory = Join-Path $deployWorkspace 'logs\deploy'
$logPath = Join-Path $logDirectory ('{0}-{1}.log' -f $Component.ToLowerInvariant(), (Get-Date -Format 'yyyyMMdd-HHmmss'))
$sshOptions = @(
    '-o', 'BatchMode=yes',
    '-o', 'ConnectTimeout=15',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    '-o', 'StrictHostKeyChecking=yes'
)
$archivePath = $null
$transcriptStarted = $false
$exitCode = 0

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath failed with exit code $LASTEXITCODE."
    }
}

function Invoke-Captured {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )
    $result = @(& $FilePath @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath failed: $($result -join [Environment]::NewLine)"
    }
    return $result
}

function Invoke-Ssh {
    param([Parameter(Mandatory = $true)][string]$Command)
    & ssh.exe @script:sshOptions $script:serverHost $Command
    if ($LASTEXITCODE -eq 255) {
        throw 'SSH authentication or connection failed. Automatic deployment requires the saved key C:\Users\shubh\.ssh\repro_maverick_deploy (or its matching loaded agent key). Restore the saved private key or re-establish SSH access before retrying. Password prompting is disabled for unattended deployment.'
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Remote deployment command failed with exit code $LASTEXITCODE."
    }
}

function Invoke-Scp {
    param(
        [Parameter(Mandatory = $true)][string]$LocalPath,
        [Parameter(Mandatory = $true)][string]$RemotePath
    )
    Invoke-Checked -FilePath 'scp.exe' -Arguments ($script:sshOptions + @($LocalPath, "$($script:serverHost):$RemotePath"))
}

function Invoke-RemoteBash {
    param(
        [Parameter(Mandatory = $true)][string]$Source,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )
    foreach ($argument in $Arguments) {
        if ($argument -notmatch '^[A-Za-z0-9_./-]+$') {
            throw "Unsafe remote script argument: $argument"
        }
    }
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Source))
    $remoteCommand = "printf '%s' '$encoded' | base64 -d | bash -s -- " + ($Arguments -join ' ')
    Invoke-Ssh -Command $remoteCommand
}

function Wait-ForGitHubQuality {
    param(
        [Parameter(Mandatory = $true)][string]$Repository,
        [Parameter(Mandatory = $true)][string]$Commit
    )
    Write-Host "Waiting for GitHub quality workflow for $Commit ..."
    $runId = $null
    $deadline = (Get-Date).AddMinutes(10)
    while ((Get-Date) -lt $deadline -and -not $runId) {
        $json = @(
            & gh.exe run list --repo $Repository --workflow deploy.yml --commit $Commit --limit 1 --json databaseId,status,conclusion,headSha 2>&1
        )
        if ($LASTEXITCODE -ne 0) {
            throw "Unable to inspect GitHub Actions: $($json -join [Environment]::NewLine)"
        }
        $parsed = @((($json -join [Environment]::NewLine) | ConvertFrom-Json))
        if ($parsed.Count -gt 0) {
            $runId = [string]$parsed[0].databaseId
            break
        }
        Start-Sleep -Seconds 5
    }
    if (-not $runId) {
        throw "GitHub quality workflow did not appear for commit $Commit."
    }
    Invoke-Checked -FilePath 'gh.exe' -Arguments @('run', 'watch', $runId, '--repo', $Repository, '--exit-status', '--interval', '10')
    Write-Host 'GitHub quality passed.'
}

function Deploy-Backend {
    param(
        [Parameter(Mandatory = $true)][string]$RemoteArchive,
        [Parameter(Mandatory = $true)][string]$Commit,
        [Parameter(Mandatory = $true)][bool]$RunMigrations
    )
    $remoteScript = @'
set -Eeuo pipefail
archive="$1"
commit="$2"
run_migrations="$3"
configure_stripe="$4"
app="/var/www/backend"
stamp="$(date +%Y%m%d-%H%M%S)"
stage="/tmp/repro-backend-$commit"
restore_stage="/tmp/repro-backend-restore-$commit"
backup="$HOME/repro-deploy-backups/backend/$stamp"
preflight="/tmp/repro-stripe-preflight-$commit.php"
webhook_setup="/tmp/repro-stripe-webhook-$commit.php"
maintenance=0
code_replaced=0

case "$archive" in /tmp/repro-backend-*.tar.gz) ;; *) echo "Unsafe archive path" >&2; exit 90 ;; esac
case "$stage" in /tmp/repro-backend-*) ;; *) echo "Unsafe stage path" >&2; exit 91 ;; esac
[ "$app" = "/var/www/backend" ] || exit 92

finish() {
  rc=$?
  trap - EXIT
  set +e
  rm -f "$preflight" "$webhook_setup" "$app/public/opcache_reset_temp.php"
  if [ "$rc" -ne 0 ] && [ "$code_replaced" -eq 1 ] && [ -s "$backup/source.tar.gz" ]; then
    echo "Deployment failed; restoring the previous backend source." >&2
    rm -rf "$restore_stage"
    mkdir -p "$restore_stage"
    tar -xzf "$backup/source.tar.gz" -C "$restore_stage"
    rsync -rltD --omit-dir-times --delete --no-owner --no-group --no-perms \
      --exclude='.env*' --exclude='*.pem' --exclude='*.key' --exclude='*.p12' --exclude='*.pfx' --exclude='*.jks' --exclude='output/' --exclude='quarantine*/' --exclude='.git/' --exclude='vendor/' --exclude='node_modules/' \
      --exclude='storage/' --exclude='database/*.sqlite' --exclude='database/*.sqlite-*' \
      --exclude='public/storage' --exclude='fix-db-perms.sh' "$restore_stage/" "$app/"
    (
      cd "$app"
      composer install --no-dev --optimize-autoloader --no-interaction || true
      php artisan optimize:clear || true
      php artisan config:cache || true
      php artisan route:cache || true
      php artisan view:cache || true
      php artisan queue:restart || true
    )
    echo "backend_source_rollback_complete:$backup" >&2
  fi
  if [ "$maintenance" -eq 1 ]; then
    (cd "$app" && php artisan up) || true
  fi
  rm -rf "$stage" "$restore_stage"
  rm -f "$archive"
  exit "$rc"
}
trap finish EXIT

command -v php >/dev/null
command -v composer >/dev/null
command -v rsync >/dev/null
test -d "$app"
test -s "$app/.env"
test -s "$archive"
rm -rf "$stage"
mkdir -p "$stage" "$backup"
chmod 0700 "$backup"
tar -xzf "$archive" -C "$stage"
test -s "$stage/artisan"
test -s "$stage/composer.lock"

cat > "$preflight" <<'PHP'
<?php
require '/var/www/backend/vendor/autoload.php';
$app = require '/var/www/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$errors = [];
if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'stripe_session_id')) {
    $duplicate = DB::table('payments')
        ->select('stripe_session_id')
        ->whereNotNull('stripe_session_id')
        ->where('stripe_session_id', '!=', '')
        ->groupBy('stripe_session_id')
        ->havingRaw('COUNT(*) > 1')
        ->first();
    if ($duplicate) {
        $errors[] = 'payments contains duplicate non-empty stripe_session_id values';
    }
}
if (Schema::hasTable('payment_refunds') && Schema::hasColumn('payment_refunds', 'provider_refund_id')) {
    $duplicate = DB::table('payment_refunds')
        ->select('payment_id', 'provider', 'provider_refund_id')
        ->whereNotNull('provider_refund_id')
        ->groupBy('payment_id', 'provider', 'provider_refund_id')
        ->havingRaw('COUNT(*) > 1')
        ->first();
    if ($duplicate) {
        $errors[] = 'payment_refunds contains duplicate provider refund IDs';
    }
}
if ($errors !== []) {
    fwrite(STDERR, "Stripe migration preflight failed: ".implode('; ', $errors).PHP_EOL);
    exit(42);
}
echo "stripe_migration_preflight=passed".PHP_EOL;
PHP
php "$preflight"

cd "$app"
php artisan down --retry=60
maintenance=1
tar -czf "$backup/source.tar.gz" -C "$app" \
  --exclude='.env*' --exclude='*.pem' --exclude='*.key' --exclude='*.p12' --exclude='*.pfx' --exclude='*.jks' --exclude='./output' --exclude='quarantine*' --exclude='./bootstrap/cache/*.php' --exclude='./.git' --exclude='./vendor' --exclude='./node_modules' \
  --exclude='./storage' --exclude='./database/*.sqlite' --exclude='./database/*.sqlite-*' \
  --exclude='./public/storage' .
if [ -f "$app/database/database.sqlite" ]; then
  if command -v sqlite3 >/dev/null; then
    sqlite3 "$app/database/database.sqlite" ".backup '$backup/database.sqlite'"
  else
    cp -p "$app/database/database.sqlite" "$backup/database.sqlite"
    for suffix in -wal -shm; do
      test ! -f "$app/database/database.sqlite$suffix" || cp -p "$app/database/database.sqlite$suffix" "$backup/database.sqlite$suffix"
    done
  fi
fi
echo "Rollback backups: $backup"

code_replaced=1
rsync -rltD --omit-dir-times --delete --no-owner --no-group --no-perms \
  --exclude='.env*' --exclude='*.pem' --exclude='*.key' --exclude='*.p12' --exclude='*.pfx' --exclude='*.jks' --exclude='output/' --exclude='quarantine*/' --exclude='.git/' --exclude='vendor/' --exclude='node_modules/' \
  --exclude='storage/' --exclude='database/*.sqlite' --exclude='database/*.sqlite-*' \
  --exclude='public/storage' --exclude='fix-db-perms.sh' "$stage/" "$app/"

cd "$app"
composer install --no-dev --optimize-autoloader --no-interaction
if [ "$run_migrations" = "1" ]; then
  php artisan migrate --force --no-interaction
fi
php artisan storage:link || true

if [ "$configure_stripe" = "1" ]; then
cat > "$webhook_setup" <<'PHP'
<?php
require '/var/www/backend/vendor/autoload.php';
$app = require '/var/www/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$stripeKey = (string) config('services.stripe.secret_key');
if (! str_starts_with($stripeKey, 'sk_live_')) {
    throw new RuntimeException('Production STRIPE_SECRET_KEY is missing or is not a live key.');
}
$url = 'https://api.reprodashboard.com/api/webhooks/stripe';
$events = [
    'checkout.session.completed',
    'checkout.session.async_payment_succeeded',
    'checkout.session.async_payment_failed',
    'checkout.session.expired',
    'refund.created',
    'refund.updated',
    'refund.failed',
];
$client = new Stripe\StripeClient($stripeKey);
$listed = $client->webhookEndpoints->all(['limit' => 100]);
$matching = array_values(array_filter(
    $listed->data,
    static fn ($candidate) => (string) ($candidate->url ?? '') === $url
));
$envPath = '/var/www/backend/.env';
$env = file_get_contents($envPath);
if ($env === false) {
    throw new RuntimeException('Unable to read the production environment file.');
}
preg_match('/^STRIPE_WEBHOOK_SECRET=(.*)$/m', $env, $secretMatch);
$currentSecret = isset($secretMatch[1]) ? trim($secretMatch[1], " \t\n\r\0\x0B\"'") : '';
$writeSecret = static function (string $secret) use ($envPath, $env): void {
    if (! str_starts_with($secret, 'whsec_')) {
        throw new RuntimeException('Stripe did not return a valid webhook signing secret.');
    }
    $line = 'STRIPE_WEBHOOK_SECRET='.$secret;
    $next = preg_match('/^STRIPE_WEBHOOK_SECRET=.*$/m', $env)
        ? preg_replace('/^STRIPE_WEBHOOK_SECRET=.*$/m', $line, $env, 1)
        : rtrim($env).PHP_EOL.$line.PHP_EOL;
    $temporary = $envPath.'.stripe-webhook-'.getmypid();
    if (file_put_contents($temporary, $next, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the Stripe webhook secret.');
    }
    @chmod($temporary, fileperms($envPath) & 0777);
    if (! rename($temporary, $envPath)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to atomically update the production environment file.');
    }
};

if ($matching === [] || ! str_starts_with($currentSecret, 'whsec_')) {
    $endpoint = $client->webhookEndpoints->create([
        'url' => $url,
        'enabled_events' => $events,
        'description' => 'Repro production checkout and refund reconciliation',
    ]);
    $writeSecret((string) $endpoint->secret);
    foreach ($matching as $oldEndpoint) {
        try {
            $client->webhookEndpoints->update($oldEndpoint->id, ['disabled' => true]);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Warning: could not disable superseded endpoint '.$oldEndpoint->id.PHP_EOL);
        }
    }
    $action = $matching === [] ? 'created' : 'replaced';
} else {
    $endpoint = $client->webhookEndpoints->update($matching[0]->id, [
        'enabled_events' => $events,
        'disabled' => false,
    ]);
    $action = 'verified';
}
echo 'stripe_webhook_endpoint='.$action.':'.$endpoint->id.PHP_EOL;
PHP
php "$webhook_setup"
fi
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
if [ -x "$app/fix-db-perms.sh" ]; then
  "$app/fix-db-perms.sh"
fi
printf '%s\n' '<?php opcache_reset(); echo "opcache_cleared";' > "$app/public/opcache_reset_temp.php"
curl --fail --silent --show-error https://api.reprodashboard.com/opcache_reset_temp.php
rm -f "$app/public/opcache_reset_temp.php"
php artisan up
maintenance=0

pending="$(php artisan migrate:status --no-interaction | grep -c 'Pending' || true)"
test "$pending" = "0"
curl --fail --silent --show-error --output /dev/null https://api.reprodashboard.com/up
printf '{"commit":"%s","deployed_at":"%s"}\n' "$commit" "$(date -u +%FT%TZ)" > "$app/storage/app/deploy-meta.json"
echo "backend_deploy_complete:$commit"
'@
    Invoke-RemoteBash -Source $remoteScript -Arguments @($RemoteArchive, $Commit, $(if ($RunMigrations) { '1' } else { '0' }), $(if ($ConfigureStripeWebhook) { '1' } else { '0' }))
}

function Deploy-Frontend {
    param(
        [Parameter(Mandatory = $true)][string]$RemoteArchive,
        [Parameter(Mandatory = $true)][string]$Commit
    )
    $remoteScript = @'
set -Eeuo pipefail
archive="$1"
commit="$2"
app="/var/www/frontend"
stage="/tmp/repro-frontend-$commit"

case "$archive" in /tmp/repro-frontend-*.tar.gz) ;; *) echo "Unsafe archive path" >&2; exit 90 ;; esac
case "$stage" in /tmp/repro-frontend-*) ;; *) echo "Unsafe stage path" >&2; exit 91 ;; esac
[ "$app" = "/var/www/frontend" ] || exit 92
finish() {
  rc=$?
  trap - EXIT
  set +e
  rm -rf "$stage"
  rm -f "$archive"
  exit "$rc"
}
trap finish EXIT

command -v rsync >/dev/null
test -d "$app"
test -s "$app/.env"
test -s "$archive"
rm -rf "$stage"
mkdir -p "$stage"
tar -xzf "$archive" -C "$stage"
test -s "$stage/package-lock.json"
test -s "$stage/deploy.sh"

rsync -rltD --omit-dir-times --delete --no-owner --no-group --no-perms \
  --exclude='.env*' --exclude='*.pem' --exclude='*.key' --exclude='*.p12' --exclude='*.pfx' --exclude='*.jks' --exclude='output/' --exclude='quarantine*/' \
  --exclude='.git/' --exclude='node_modules/' --exclude='/dist' --exclude='/releases' \
  "$stage/" "$app/"
chmod +x "$app/deploy.sh"
cd "$app"
DEPLOY_COMMIT="$commit" bash "$app/deploy.sh"
grep -q "\"commit\":\"$commit\"" "$app/dist/deploy-meta.json"
curl --fail --silent --show-error --output /dev/null https://reprodashboard.com/
echo "frontend_deploy_wrapper_complete:$commit"
'@
    Invoke-RemoteBash -Source $remoteScript -Arguments @($RemoteArchive, $Commit)
}

try {
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
    Start-Transcript -Path $logPath -Force | Out-Null
    $transcriptStarted = $true
    Write-Host "=== Repro $Component deployment ==="
    Write-Host "Repository: $repoPath"
    Write-Host "Log: $logPath"

    if (-not (Test-Path -LiteralPath (Join-Path $repoPath '.git'))) {
        throw "Git repository not found: $repoPath"
    }
    foreach ($command in @('git.exe', 'gh.exe', 'ssh.exe', 'scp.exe')) {
        if (-not (Get-Command $command -ErrorAction SilentlyContinue)) {
            throw "Required command is unavailable: $command"
        }
    }

    Set-Location -LiteralPath $repoPath
    Invoke-Checked -FilePath 'git.exe' -Arguments @('fetch', '--prune', 'origin')
    $branch = ((Invoke-Captured -FilePath 'git.exe' -Arguments @('rev-parse', '--abbrev-ref', 'HEAD')) | Select-Object -Last 1).ToString().Trim()
    if ($branch -ne 'main' -and -not $PreparedRelease) {
        throw "Deployment requires the main branch; current branch is $branch."
    }
    $counts = (((Invoke-Captured -FilePath 'git.exe' -Arguments @('rev-list', '--left-right', '--count', 'origin/main...HEAD')) | Select-Object -Last 1).ToString().Trim() -split '\s+')
    if ([int]$counts[0] -gt 0) {
        throw "Local main is behind origin/main by $($counts[0]) commit(s)."
    }
    Invoke-Checked -FilePath 'git.exe' -Arguments @('diff', '--check')
    $remoteUrl = ((Invoke-Captured -FilePath 'git.exe' -Arguments @('remote', 'get-url', 'origin')) | Select-Object -Last 1).ToString().Trim()
    if ($remoteUrl -notin @("https://github.com/$repoSlug.git", "git@github.com:$repoSlug.git")) {
        throw 'Repository origin does not match the configured deployment target.'
    }
    if ($PreparedRelease) {
        $dirty = @(Invoke-Captured -FilePath 'git.exe' -Arguments @('status', '--porcelain'))
        if ($dirty.Count -gt 0) { throw 'Prepared releases must be committed and clean; no automatic staging is allowed.' }
    }

    if ($DryRun) {
        Write-Host 'Dry run passed: repository and branch preflight are clean.'
        Write-Host 'No commit, push, Stripe change, or production change was made.'
    } else {
        if (-not $Yes) {
            $confirmation = Read-Host "Type DEPLOY to publish $Component to production"
            if ($confirmation -cne 'DEPLOY') {
                throw 'Deployment cancelled.'
            }
        }

        if (-not $PreparedRelease) {
        Invoke-Checked -FilePath 'git.exe' -Arguments @('add', '-A')
        Invoke-Checked -FilePath 'git.exe' -Arguments @('diff', '--cached', '--check')
        & git.exe diff --cached --quiet
        $cachedExit = $LASTEXITCODE
        if ($cachedExit -eq 1) {
            Invoke-Checked -FilePath 'git.exe' -Arguments @('commit', '-m', $CommitMessage)
        } elseif ($cachedExit -ne 0) {
            throw "Unable to inspect the staged changes (exit $cachedExit)."
        } else {
            Write-Host 'No new local changes to commit.'
        }

        }

        $commit = ((Invoke-Captured -FilePath 'git.exe' -Arguments @('rev-parse', 'HEAD')) | Select-Object -Last 1).ToString().Trim()
        Invoke-Checked -FilePath 'git.exe' -Arguments @('push', 'origin', 'HEAD:main')
        Wait-ForGitHubQuality -Repository $repoSlug -Commit $commit

        Write-Host 'GitHub quality checks passed. Checking automatic SSH key authentication (no password prompts).'
        Invoke-Ssh -Command "set -eu; test -d '$remoteApp'; test -s '$remoteApp/.env'; command -v base64 >/dev/null; command -v rsync >/dev/null; echo ssh_preflight=passed"

        $shortCommit = $commit.Substring(0, 12)
        $archivePath = Join-Path $env:TEMP ("repro-{0}-{1}-{2}.tar.gz" -f $Component.ToLowerInvariant(), $shortCommit, $PID)
        Invoke-Checked -FilePath 'git.exe' -Arguments @('archive', '--format=tar.gz', '-o', $archivePath, 'HEAD')
        $remoteArchive = "/tmp/repro-$($Component.ToLowerInvariant())-$shortCommit.tar.gz"
        & (Join-Path $PSScriptRoot 'Test-ReleaseArchive.ps1') -ArchivePath $archivePath
        Invoke-Scp -LocalPath $archivePath -RemotePath $remoteArchive

        if ($Component -eq 'Backend') {
            Deploy-Backend -RemoteArchive $remoteArchive -Commit $commit -RunMigrations:(-not $SkipMigrate)
            $apiStatus = (Invoke-WebRequest -Uri 'https://api.reprodashboard.com/up' -Method Get -TimeoutSec 30 -SkipHttpErrorCheck).StatusCode
            if ($apiStatus -ne 200) {
                throw "Backend health check returned HTTP $apiStatus."
            }
            $webhookProbe = Invoke-WebRequest -Uri 'https://api.reprodashboard.com/api/webhooks/stripe' -Method Post -ContentType 'application/json' -Body '{}' -TimeoutSec 30 -SkipHttpErrorCheck
            if ($webhookProbe.StatusCode -ne 400) {
                throw "Unsigned Stripe webhook probe returned HTTP $($webhookProbe.StatusCode), expected 400."
            }
        } else {
            Deploy-Frontend -RemoteArchive $remoteArchive -Commit $commit
            $markerUri = "https://reprodashboard.com/deploy-meta.json?commit=$commit"
            $markerResponse = Invoke-WebRequest -Uri $markerUri -Method Get -TimeoutSec 30 -SkipHttpErrorCheck
            if ($markerResponse.StatusCode -ne 200) {
                throw "Frontend deployment marker returned HTTP $($markerResponse.StatusCode)."
            }
            $marker = $markerResponse.Content | ConvertFrom-Json
            if ([string]$marker.commit -ne $commit) {
                throw "Frontend marker commit did not match $commit."
            }
        }
        Write-Host "DONE: $Component deployed successfully at commit $commit"
    }
} catch {
    $exitCode = 1
    Write-Error $_
} finally {
    if ($archivePath -and (Test-Path -LiteralPath $archivePath)) {
        Remove-Item -LiteralPath $archivePath -Force -ErrorAction SilentlyContinue
    }
    if ($transcriptStarted) {
        Stop-Transcript | Out-Null
    }
    Write-Host "Log: $logPath"
    if (-not $NoPause) {
        Write-Host ''
        Read-Host 'Press Enter to close'
    }
}
exit $exitCode
