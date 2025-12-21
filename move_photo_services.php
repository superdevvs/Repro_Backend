<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Service;

// Get Photo category
$photoCategory = Category::where('name', 'Photo')->first();
$unassignedCategory = Category::where('name', 'Unassigned')->first();

if (!$photoCategory) {
    echo "Photo category not found!\n";
    exit(1);
}

if (!$unassignedCategory) {
    echo "Unassigned category not found - no services to move.\n";
    exit(0);
}

// Move services with "photo" in name from Unassigned to Photo
$count = Service::where('category_id', $unassignedCategory->id)
    ->whereRaw('LOWER(name) LIKE ?', ['%photo%'])
    ->update(['category_id' => $photoCategory->id]);

echo "Moved {$count} photo service(s) back to Photo category.\n";

// List remaining unassigned services
$remaining = Service::where('category_id', $unassignedCategory->id)->get();
if ($remaining->count() > 0) {
    echo "\nRemaining unassigned services:\n";
    foreach ($remaining as $service) {
        echo "- {$service->name}\n";
    }
}
