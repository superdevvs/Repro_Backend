<?php

/**
 * Quick test script to verify AI chat endpoint is accessible
 * Usage: php test-ai-endpoint.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing AI Chat Controller instantiation...\n";

try {
    // Test RuleBasedOrchestrator
    echo "1. Testing RuleBasedOrchestrator...\n";
    $orchestrator = app(App\Services\ReproAi\RuleBasedOrchestrator::class);
    echo "   ✓ RuleBasedOrchestrator instantiated\n";
    
    // Test AiChatController
    echo "2. Testing AiChatController...\n";
    $controller = app(App\Http\Controllers\API\AiChatController::class);
    echo "   ✓ AiChatController instantiated\n";
    
    // Test all flows
    echo "3. Testing Flow classes...\n";
    $flows = [
        App\Services\ReproAi\Flows\BookShootFlow::class,
        App\Services\ReproAi\Flows\ManageBookingFlow::class,
        App\Services\ReproAi\Flows\AvailabilityFlow::class,
        App\Services\ReproAi\Flows\ClientStatsFlow::class,
        App\Services\ReproAi\Flows\AccountingFlow::class,
    ];
    
    foreach ($flows as $flowClass) {
        $flow = app($flowClass);
        echo "   ✓ " . class_basename($flowClass) . " instantiated\n";
    }
    
    echo "\n✅ All dependencies resolved successfully!\n";
    echo "The backend should be able to handle requests.\n";
    echo "\nIf you're still getting network errors, check:\n";
    echo "1. Is the Laravel server running? (php artisan serve)\n";
    echo "2. Is the API_BASE_URL in frontend config correct?\n";
    echo "3. Check browser console for CORS errors\n";
    echo "4. Check backend logs: storage/logs/laravel.log\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
