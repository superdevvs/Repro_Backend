<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;
use App\Models\Category;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            // Photos
            ['name' => '25 HDR Photos', 'price' => 175.00, 'category' => 'Photos', 'icon' => 'Camera'],
            ['name' => 'HDR Photos', 'price' => 199.00, 'category' => 'Photos', 'icon' => 'Camera'],
            ['name' => '25 Flash Photos', 'price' => 125.00, 'category' => 'Photos', 'icon' => 'Zap'],
            ['name' => '35 HDR Photos', 'price' => 199.00, 'category' => 'Photos', 'icon' => 'Camera'],
            ['name' => '15 HDR -Rental Listings only', 'price' => 145.00, 'category' => 'Photos', 'icon' => 'Home'],
            ['name' => '10 Exterior HDR Photos', 'price' => 100.00, 'category' => 'Photos', 'icon' => 'Image'],
            ['name' => '45 HDR Photos', 'price' => 275.00, 'category' => 'Photos', 'icon' => 'Camera'],
            ['name' => '55 HDR Photos', 'price' => 350.00, 'category' => 'Photos', 'icon' => 'Camera'],
            ['name' => 'Interior reshoot 10 images', 'price' => 100.00, 'category' => 'Photos', 'icon' => 'RotateCcw'],
            ['name' => 'LocalSTR - 40 Photos', 'price' => 200.00, 'category' => 'Photos', 'icon' => 'Building'],
            ['name' => 'Weather/Limited Exteriors Photos', 'price' => 50.00, 'category' => 'Photos', 'icon' => 'CloudRain'],

            // Video
            ['name' => 'Walkthrough Video', 'price' => 225.00, 'category' => 'Video', 'icon' => 'Video'],
            ['name' => 'Basic: Social media/vertical video', 'price' => 150.00, 'category' => 'Video', 'icon' => 'Smartphone'],
            ['name' => 'Ultimate: Social media/vertical Video', 'price' => 450.00, 'category' => 'Video', 'icon' => 'Film'],

            // Drone
            ['name' => 'Just Drone Photos Package', 'price' => 142.50, 'category' => 'Drone', 'icon' => 'Plane'],
            ['name' => 'Silver Drone Package', 'price' => 199.00, 'category' => 'Drone', 'icon' => 'Plane'],

            // Floor Plans
            ['name' => '2D Floor plans', 'price' => 125.00, 'category' => 'Floor Plans', 'icon' => 'LayoutTemplate'],

            // Virtual Staging
            ['name' => 'Virtual Staging (per image)', 'price' => 40.00, 'category' => 'Virtual Staging', 'icon' => 'BoxSelect'],

            // 360/3D Tours
            ['name' => 'Premium iGuide with Floor plans', 'price' => 185.00, 'category' => '360/3D Tours', 'icon' => 'Map'],
            ['name' => 'HDR Photo + 3D Matterport', 'price' => 400.00, 'category' => '360/3D Tours', 'icon' => 'Box'],
            ['name' => 'HDR Photo + iGuide', 'price' => 475.00, 'category' => '360/3D Tours', 'icon' => 'View'],
            ['name' => 'HDR Photo + Video + iGuide', 'price' => 575.00, 'category' => '360/3D Tours', 'icon' => 'Layers'],

            // Bundles
            ['name' => '30 HDR Photos + 2D Floor Plans*', 'price' => 256.50, 'category' => 'Bundles', 'icon' => 'Package'],
            ['name' => 'HDR Photos + Video', 'price' => 299.00, 'category' => 'Bundles', 'icon' => 'Film'],
            ['name' => '25 HDR Photo + Walkthough Video', 'price' => 250.00, 'category' => 'Bundles', 'icon' => 'Video'],
            ['name' => '40 HDR + 1 Min Vertical Video *', 'price' => 280.00, 'category' => 'Bundles', 'icon' => 'Video'],

            // Addons
            ['name' => 'On site Cancellation/Reschedule Fee', 'price' => 57.00, 'category' => 'Addons', 'icon' => 'AlertCircle'],
            ['name' => 'Rush Fee', 'price' => 50.00, 'category' => 'Addons', 'icon' => 'Clock'],
            ['name' => 'Travel Fee', 'price' => 50.00, 'category' => 'Addons', 'icon' => 'Car'],
            ['name' => 'Blue Sky Replacement', 'price' => 0.00, 'category' => 'Addons', 'icon' => 'Sun'],

            // Commercials
            ['name' => 'Commercial Photography (Hourly)', 'price' => 300.00, 'category' => 'Commercials', 'icon' => 'Briefcase'],
        ];

        foreach ($services as $serviceData) {
            $category = Category::firstOrCreate(['name' => $serviceData['category']]);
            
            Service::updateOrCreate(
                ['name' => $serviceData['name']],
                [
                    'price' => $serviceData['price'],
                    'description' => $serviceData['name'], // Default description
                    'delivery_time' => '24-48 Hours', // Default delivery time
                    'category_id' => $category->id,
                    'icon' => $serviceData['icon'],
                ]
            );
        }
    }
}
