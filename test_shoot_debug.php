<?php

use App\Models\Shoot;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    echo "Testing Shoot::with('services')...\n";
    $shoot = Shoot::with('services')->first();
    if ($shoot) {
        echo "Shoot found: " . $shoot->id . "\n";
        echo "Services count: " . $shoot->services->count() . "\n";
    } else {
        echo "No shoots found.\n";
    }

    echo "Testing ShootController logic...\n";
    
    $query = Shoot::with([
        'client:id,name,email,company_name,phonenumber',
        'photographer:id,name,avatar',
        'service:id,name',
        'services:id,name',
        'files' => function ($query) {
            $query->select('id', 'shoot_id', 'workflow_stage', 'is_favorite', 'is_cover', 'flag_reason', 'url', 'path', 'dropbox_path');
        },
        'payments:id,shoot_id,amount,paid_at,status' // Added status
    ]);

    $shoots = $query->limit(5)->get();
    echo "Loaded " . $shoots->count() . " shoots.\n";

    foreach ($shoots as $shoot) {
        // Mimic transformShoot
        $shoot->append('total_paid', 'remaining_balance');
        echo "Shoot " . $shoot->id . " Total Paid: " . $shoot->total_paid . "\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
