<?php

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\UserController;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    // Mock request and auth
    $admin = User::where('role', 'superadmin')->first();
    if (!$admin) {
        $admin = User::where('role', 'admin')->first();
    }
    if (!$admin) {
        die("No admin found to test with.\n");
    }
    
    echo "Testing as user: {$admin->email} ({$admin->role})\n";
    
    $request = Request::create('/api/admin/users', 'GET');
    $request->setUserResolver(function () use ($admin) {
        return $admin;
    });
    
    $controller = new UserController();
    $response = $controller->index($request);
    
    echo "Response status: " . $response->getStatusCode() . "\n";
    $content = $response->getContent();
    $data = json_decode($content, true);
    
    if (isset($data['users'])) {
        echo "Users count: " . count($data['users']) . "\n";
    } else {
        echo "No users key in response.\n";
        echo substr($content, 0, 500) . "\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
