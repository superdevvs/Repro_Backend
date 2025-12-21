# Start Laravel backend server with increased PHP limits for file uploads
# Designed to handle 100-300 photos of 5-15MB each per shoot

Write-Host "============================================" -ForegroundColor Green
Write-Host "  REPRO Backend Server - Photo Upload Mode  " -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
Write-Host ""
Write-Host "PHP Configuration (from php-upload.ini):" -ForegroundColor Cyan
Write-Host "  upload_max_filesize: 50M (per file)" -ForegroundColor White
Write-Host "  post_max_size: 60M (per request)" -ForegroundColor White
Write-Host "  memory_limit: 512M" -ForegroundColor White
Write-Host "  max_execution_time: 600s (10 min)" -ForegroundColor White
Write-Host "  max_input_time: 600s" -ForegroundColor White
Write-Host ""
Write-Host "Upload Strategy: Individual file uploads (3 concurrent)" -ForegroundColor Yellow
Write-Host "This allows handling 100-300 photos per shoot efficiently." -ForegroundColor Yellow
Write-Host ""

# Use custom php.ini for upload limits
$phpIni = Join-Path $PSScriptRoot "php-upload.ini"

# Kill any existing PHP processes on port 8000
Write-Host "Checking for existing processes on port 8000..." -ForegroundColor Gray
$existingProcess = Get-NetTCPConnection -LocalPort 8000 -ErrorAction SilentlyContinue | Select-Object -First 1
if ($existingProcess) {
    Write-Host "Stopping existing process on port 8000..." -ForegroundColor Yellow
    Stop-Process -Id $existingProcess.OwningProcess -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

Write-Host "Starting Laravel server..." -ForegroundColor Green
php -c $phpIni artisan serve --host=127.0.0.1 --port=8000
