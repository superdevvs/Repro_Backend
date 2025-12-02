<?php

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\API\ShootController;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    // Mock request and auth
    $admin = User::where('role', 'super_admin')->first();
    if (!$admin) {
        $admin = User::where('role', 'admin')->first();
    }
    
    echo "Testing as user: {$admin->email} ({$admin->role})\n";
    
    // Test 'scheduled' tab
    $request = Request::create('/api/shoots', 'GET', ['tab' => 'scheduled']);
    $request->setUserResolver(function () use ($admin) {
        return $admin;
    });
    
    $controller = $app->make(ShootController::class);
    $response = $controller->index($request);
    
    echo "Response status: " . $response->getStatusCode() . "\n";
    $content = $response->getContent();
    $data = json_decode($content, true);
    
    if (isset($data['data'])) {
        echo "Shoots count: " . count($data['data']) . "\n";
        if (count($data['data']) > 0) {
            echo "First shoot status: " . $data['data'][0]['status'] . "\n";
            echo "First shoot workflow_status: " . $data['data'][0]['workflow_status'] . "\n";
        }
    } else {
        echo "No data key in response.\n";
        echo substr($content, 0, 500) . "\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
