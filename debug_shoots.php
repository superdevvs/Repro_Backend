<?php

use App\Models\User;
use App\Http\Controllers\API\ShootController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

try {
    $user = User::first();
    if (!$user) {
        echo "No users found!\n";
        exit(1);
    }
    Auth::login($user);

    $controller = app(ShootController::class);
    $request = Request::create('/api/shoots', 'GET', ['tab' => 'scheduled']);
    
    // We need to set the user resolver for the request as well
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $controller->index($request);
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content: " . $response->getContent() . "\n";

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
