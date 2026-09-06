<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config('database.default');
$path = $connection === 'sqlite' ? realpath(config('database.connections.sqlite.database')) : false;
$root = realpath(base_path());
if (! $path || ! str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root).'/')) {
    echo "Local migration not run: the configured database is not a verified workspace-local SQLite file.\n";
    exit(0);
}
$backup = storage_path('app/studio-smoke/database-before-v4.sqlite');
if (! is_dir(dirname($backup))) {
    mkdir(dirname($backup), 0755, true);
}
if (! is_file($backup) && ! copy($path, $backup)) {
    throw new RuntimeException('Could not create the local database backup.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_06_000001_create_studio_workspaces.php', '--force' => true]);
echo "V4 workspace migration applied to the verified local SQLite database; backup retained.\n";
