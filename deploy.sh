#!/bin/bash
set -e

echo "=== REPRO Backend Deploy Script ==="
cd /var/www/backend

echo "[1/6] Pulling latest code..."
git pull origin main 2>&1

echo "[2/6] Installing dependencies..."
composer install --no-dev --optimize-autoloader 2>&1

echo "[3/6] Running migrations..."
php artisan migrate --force 2>&1

echo "[4/6] Creating storage link..."
php artisan storage:link 2>&1 || echo "     (already exists, skipping)"

echo "[5/6] Caching config & routes..."
php artisan config:cache 2>&1
php artisan route:cache 2>&1
php artisan view:cache 2>&1

echo "[6/6] Clearing OPcache..."
php -r 'file_put_contents("/var/www/backend/public/opcache_reset_temp.php", "<?php opcache_reset(); echo \"opcache_cleared\";");'
curl -sk https://api.reprodashboard.com/opcache_reset_temp.php 2>&1
echo ""
rm -f /var/www/backend/public/opcache_reset_temp.php

echo ""
echo "=== Deploy complete! ==="
