#!/bin/bash
set -e

echo "=== REPRO Backend Deploy Script ==="
cd /var/www/backend

# --- Configuration for post-deploy verification ---
# Base URL used to probe the deployed API. Override per environment if needed.
API_BASE_URL="${API_BASE_URL:-https://api.reprodashboard.com}"
# Optional bearer token for an authenticated voice-route probe. When set, the
# verification asserts HTTP 200; when unset, it asserts the route is registered
# and reachable (a registered protected route returns 401/403, not 404).
VOICE_VERIFY_TOKEN="${VOICE_VERIFY_TOKEN:-}"

# Refresh the route and config caches so the cached route table matches the
# repository route definitions (fixes production route/config cache drift).
refresh_route_config_cache() {
    php artisan route:clear 2>&1
    php artisan route:cache 2>&1
    php artisan config:clear 2>&1
    php artisan config:cache 2>&1
}

# Verify the voice routes are registered and reachable. Returns non-zero if any
# route is missing from the route table or fails its HTTP probe.
verify_voice_routes() {
    local result=0
    local route url code

    for route in "voice/schedule/state" "voice/llm-usage"; do
        # 1) Confirm the route is present in the (cached) route table.
        if ! php artisan route:list 2>/dev/null | grep -q "$route"; then
            echo "     [WARN] route '$route' not found in route:list"
            result=1
            continue
        fi

        # 2) Probe the route over HTTP.
        url="${API_BASE_URL}/api/${route}"
        if [ -n "$VOICE_VERIFY_TOKEN" ]; then
            code=$(curl -sk -o /dev/null -w '%{http_code}' \
                -H "Authorization: Bearer ${VOICE_VERIFY_TOKEN}" \
                -H "Accept: application/json" \
                "$url" 2>/dev/null || echo "000")
            if [ "$code" = "200" ]; then
                echo "     [OK] '$route' returned HTTP 200"
            else
                echo "     [WARN] authenticated probe of '$route' returned HTTP $code (expected 200)"
                result=1
            fi
        else
            # Without a token, a registered protected route returns 401/403 while
            # a missing or cache-drifted route returns 404.
            code=$(curl -sk -o /dev/null -w '%{http_code}' \
                -H "Accept: application/json" \
                "$url" 2>/dev/null || echo "000")
            if [ "$code" = "404" ] || [ "$code" = "000" ]; then
                echo "     [WARN] probe of '$route' returned HTTP $code (route not reachable)"
                result=1
            else
                echo "     [OK] '$route' reachable (HTTP $code; set VOICE_VERIFY_TOKEN for a full 200 check)"
            fi
        fi
    done

    return $result
}

echo "[1/7] Pulling latest code..."
git pull origin main 2>&1

echo "[2/7] Installing dependencies..."
composer install --no-dev --optimize-autoloader 2>&1

echo "[3/7] Running migrations..."
php artisan migrate --force 2>&1

echo "[4/7] Creating storage link..."
php artisan storage:link 2>&1 || echo "     (already exists, skipping)"

echo "[5/7] Caching config & routes..."
php artisan config:cache 2>&1
php artisan route:cache 2>&1
php artisan view:cache 2>&1
php artisan queue:restart 2>&1

echo "[6/7] Clearing OPcache..."
php -r 'file_put_contents("/var/www/backend/public/opcache_reset_temp.php", "<?php opcache_reset(); echo \"opcache_cleared\";");'
curl -sk https://api.reprodashboard.com/opcache_reset_temp.php 2>&1
echo ""
rm -f /var/www/backend/public/opcache_reset_temp.php

echo "[7/7] Verifying voice routes..."
if ! verify_voice_routes; then
    echo "     Voice route verification failed - refreshing route & config caches..."
    refresh_route_config_cache
    echo "     Re-verifying voice routes..."
    if ! verify_voice_routes; then
        echo "     [ERROR] Voice routes still not verified after cache refresh." >&2
        echo "     Check route definitions, the 'voice-calls' permission, and VOICE_VERIFY_TOKEN." >&2
        exit 1
    fi
fi
echo "     Voice routes verified."

echo ""
echo "=== Deploy complete! ==="
