<?php

/**
 * Quick script to check if migration ran
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Checking ai_chat_sessions table structure...\n\n";

$columns = Schema::getColumnListing('ai_chat_sessions');

echo "Existing columns:\n";
foreach ($columns as $col) {
    echo "  - $col\n";
}

echo "\n";

$requiredColumns = ['intent', 'step', 'state_data', 'engine'];
$missing = [];

foreach ($requiredColumns as $col) {
    if (in_array($col, $columns)) {
        echo "✓ $col exists\n";
    } else {
        echo "❌ $col MISSING\n";
        $missing[] = $col;
    }
}

if (!empty($missing)) {
    echo "\n⚠️  Missing columns detected. Run migration:\n";
    echo "   php artisan migrate\n\n";
    exit(1);
} else {
    echo "\n✅ All required columns exist!\n";
}

