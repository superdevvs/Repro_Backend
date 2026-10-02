#!/bin/sh
set -eu
# A Windows bind mount adds seconds of metadata I/O to every Laravel boot.
# Copy once onto the container filesystem so timings measure the serving stack.
printf '%s\n' 'Copying integration sources and dependencies to container storage...'
if [ -f /media-integration-source.tar ]; then
    tar -xf /media-integration-source.tar -C /app
else
    for directory in app bootstrap config database public resources routes scripts tests vendor; do
        cp -a "/source/$directory" "/app/$directory"
    done
    cp /source/artisan /source/composer.json /app/
fi
mkdir -p /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/storage/app/private
chmod -R a+rwX /app/storage /app/bootstrap/cache
rm -f /app/bootstrap/cache/config.php
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export APP_KEY
export DB_CONNECTION=sqlite
export DB_DATABASE=:memory:
exec python3 /app/tests/Integration/MediaTransfers/verify.py
