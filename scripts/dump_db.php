<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Categories:\n";
foreach (App\Models\Category::orderBy('id')->get(['id', 'name']) as $category) {
    echo $category->id . ' | ' . $category->name . PHP_EOL;
}

echo PHP_EOL . "Services (name | category | pricing_type | price):\n";
foreach (App\Models\Service::orderBy('name')->take(30)->get(['name', 'category_id', 'pricing_type', 'price']) as $service) {
    $categoryName = optional(App\Models\Category::find($service->category_id))->name;
    echo $service->name . ' | ' . $categoryName . ' | ' . $service->pricing_type . ' | ' . $service->price . PHP_EOL;
}
