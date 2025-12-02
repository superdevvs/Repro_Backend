<?php

/**
 * Quick test script for Robbie
 * 
 * Usage: php test-ai-quick.php
 * 
 * Make sure you have:
 * 1. Run migrations: php artisan migrate
 * 2. Have at least one user in database
 * 3. Have at least one service in database
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Service;
use App\Models\AiChatSession;
use App\Services\ReproAi\RuleBasedOrchestrator;
use App\Services\ReproAi\Flows\BookShootFlow;
use App\Services\ReproAi\Flows\ManageBookingFlow;
use App\Services\ReproAi\Flows\AvailabilityFlow;
use App\Services\ReproAi\Flows\ClientStatsFlow;
use App\Services\ReproAi\Flows\AccountingFlow;

echo "🧪 Quick Robbie Test\n";
echo "=====================\n\n";

// Get or create a test user
$user = User::first();
if (!$user) {
    echo "❌ No users found. Please create a user first.\n";
    exit(1);
}

echo "✓ Using user: {$user->name} (ID: {$user->id})\n";

// Check for services
$serviceCount = Service::count();
if ($serviceCount === 0) {
    echo "⚠️  No services found. Creating a test service...\n";
    Service::create([
        'name' => 'Photos',
        'price' => 100.00,
        'category_id' => 1,
    ]);
    echo "✓ Test service created\n";
} else {
    echo "✓ Found {$serviceCount} service(s)\n";
}

echo "\n";

// Create orchestrator
$orchestrator = new RuleBasedOrchestrator(
    new BookShootFlow(),
    new ManageBookingFlow(),
    new AvailabilityFlow(),
    new ClientStatsFlow(),
    new AccountingFlow()
);

// Create a test session
$session = AiChatSession::create([
    'user_id' => $user->id,
    'title' => 'Test Session',
    'engine' => 'rules',
]);

echo "✓ Created test session: {$session->id}\n\n";

// Test 1: Book shoot flow
echo "Test 1: Book Shoot Flow\n";
echo "----------------------\n";

$result = $orchestrator->handle($session, "I want to book a shoot", ['intent' => 'book_shoot']);
echo "Response:\n";
echo "- Messages: " . count($result['messages']) . "\n";
echo "- Last message: " . substr($result['messages'][count($result['messages']) - 1]['content'], 0, 80) . "...\n";
echo "- Suggestions: " . count($result['meta']['suggestions'] ?? []) . "\n";
echo "\n";

// Test 2: Continue with property
echo "Test 2: Providing property\n";
echo "--------------------------\n";

$result = $orchestrator->handle($session, "123 Main St, San Francisco, CA", [
    'propertyAddress' => '123 Main St',
    'propertyCity' => 'San Francisco',
    'propertyState' => 'CA',
    'propertyZip' => '94102',
]);
echo "Response:\n";
echo "- Last message: " . substr($result['messages'][count($result['messages']) - 1]['content'], 0, 80) . "...\n";
echo "- Step: " . ($session->fresh()->step ?? 'N/A') . "\n";
echo "\n";

// Test 3: Provide date
echo "Test 3: Providing date\n";
echo "----------------------\n";

$result = $orchestrator->handle($session, "Tomorrow", []);
echo "Response:\n";
echo "- Last message: " . substr($result['messages'][count($result['messages']) - 1]['content'], 0, 80) . "...\n";
echo "- Step: " . ($session->fresh()->step ?? 'N/A') . "\n";
echo "\n";

// Test 4: Provide time
echo "Test 4: Providing time\n";
echo "----------------------\n";

$result = $orchestrator->handle($session, "Morning", []);
echo "Response:\n";
echo "- Last message: " . substr($result['messages'][count($result['messages']) - 1]['content'], 0, 80) . "...\n";
echo "- Step: " . ($session->fresh()->step ?? 'N/A') . "\n";
echo "\n";

// Test 5: Provide services
echo "Test 5: Providing services\n";
echo "--------------------------\n";

$result = $orchestrator->handle($session, "Photos only", []);
echo "Response:\n";
echo "- Last message: " . substr($result['messages'][count($result['messages']) - 1]['content'], 0, 80) . "...\n";
echo "- Step: " . ($session->fresh()->step ?? 'N/A') . "\n";
echo "\n";

// Check state data
$session->refresh();
echo "Session State:\n";
echo "- Intent: " . ($session->intent ?? 'N/A') . "\n";
echo "- Step: " . ($session->step ?? 'N/A') . "\n";
echo "- State Data: " . json_encode($session->state_data, JSON_PRETTY_PRINT) . "\n";
echo "\n";

echo "✅ Quick test completed!\n";
echo "Session ID: {$session->id}\n";
echo "You can view the full conversation in the database or via API.\n";

