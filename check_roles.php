<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $stats = User::groupBy('role')->select('role', DB::raw('count(*) as total'))->get();
    foreach ($stats as $s) {
        echo "Role '{$s->role}': {$s->total}\n";
    }
    
    echo "Total Users: " . User::count() . "\n";
    
    $clients = User::where('role', 'client')->limit(5)->get();
    echo "Sample Clients:\n";
    foreach ($clients as $c) {
        echo "- {$c->name} ({$c->email})\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
