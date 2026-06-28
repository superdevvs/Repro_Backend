<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$users = App\Models\User::whereIn('role', ['superadmin', 'admin'])
    ->get(['id', 'name', 'email', 'role', 'account_status']);

foreach ($users as $u) {
    echo $u->id.' | '.$u->role.' | '.$u->email.' | '.$u->account_status.' | '.$u->name.PHP_EOL;
}
echo 'TOTAL: '.$users->count().PHP_EOL;
