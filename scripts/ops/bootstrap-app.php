<?php

/**
 * Boots this checkout's Laravel app, so an ops script uses the app's configured database
 * connection (SQLite today, MySQL after the move) instead of a hard-coded SQLite path.
 *
 * Usage, from the app folder:  $app = require __DIR__.'/bootstrap-app.php';
 */
$base = dirname(__DIR__, 2);

require_once $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

return $app;
