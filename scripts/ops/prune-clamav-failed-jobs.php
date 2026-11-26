<?php

// Deletes failed ClamAV-unavailable jobs through the app's configured database connection.
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap-app.php';

$deleted = DB::table('failed_jobs')->where('exception', 'like', '%ClamAvUnavailable%')->delete();
$left = DB::table('failed_jobs')->count();
echo "deleted_clamav={$deleted} remaining={$left}\n";
