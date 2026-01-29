<?php

namespace Database\Seeders;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoTourShootSeeder extends Seeder
{
    public function run(): void
    {
        // Get or create a client
        $client = User::where('role', 'client')->first();
        if (!$client) {
            $client = User::create([
                'name' => 'Demo Client',
                'email' => 'demo.client@example.com',
                'password' => bcrypt('password'),
                'role' => 'client',
            ]);
        }

        // Get or create a photographer
        $photographer = User::where('role', 'photographer')->first();
        if (!$photographer) {
            $photographer = User::create([
                'name' => 'Demo Photographer',
                'email' => 'demo.photographer@example.com',
                'password' => bcrypt('password'),
                'role' => 'photographer',
            ]);
        }

        // Get a service
        $service = \App\Models\Service::first();
        
        // Create demo shoot with all required columns
        $shoot = Shoot::create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service?->id ?? 1,
            'address' => '8842 Sunset Boulevard',
            'city' => 'West Hollywood',
            'state' => 'CA',
            'zip' => '90069',
            'status' => 'delivered',
            'scheduled_date' => now(),
            'base_quote' => 500.00,
            'tax_amount' => 0,
            'total_quote' => 500.00,
            'payment_status' => 'paid',
            'workflow_status' => 'delivered',
            'property_details' => ['beds' => 6, 'baths' => 5, 'sqft' => 8500],
        ]);

        // Demo property images from Unsplash (real estate photos)
        $demoImages = [
            'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200',
            'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200',
            'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200',
            'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200',
            'https://images.unsplash.com/photo-1600573472592-401b489a3cdc?w=1200',
            'https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?w=1200',
            'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea?w=1200',
            'https://images.unsplash.com/photo-1600210492493-0946911123ea?w=1200',
            'https://images.unsplash.com/photo-1600585154526-990dced4db0d?w=1200',
            'https://images.unsplash.com/photo-1600607687644-c7171b42498f?w=1200',
            'https://images.unsplash.com/photo-1600566752355-35792bedcfea?w=1200',
            'https://images.unsplash.com/photo-1600573472550-8090b5e0745e?w=1200',
            'https://images.unsplash.com/photo-1600047509358-9dc75507daeb?w=1200',
            'https://images.unsplash.com/photo-1600585154363-67eb9e2e2099?w=1200',
            'https://images.unsplash.com/photo-1600566752734-2a0cd66c42ae?w=1200',
            'https://images.unsplash.com/photo-1600210491892-03d54c0aaf87?w=1200',
            'https://images.unsplash.com/photo-1600585154084-4e5fe7c39198?w=1200',
            'https://images.unsplash.com/photo-1600607688066-890987f18a86?w=1200',
            'https://images.unsplash.com/photo-1600566753051-f0b89df2dd90?w=1200',
            'https://images.unsplash.com/photo-1600573472591-ee6981cf81f5?w=1200',
            'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=1200',
            'https://images.unsplash.com/photo-1560185007-cde436f6a4d0?w=1200',
            'https://images.unsplash.com/photo-1560185008-a33f5c9c5b0d?w=1200',
            'https://images.unsplash.com/photo-1560185009-dddeb820c7b7?w=1200',
            'https://images.unsplash.com/photo-1560185127-6ed189bf02f4?w=1200',
            'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=1200',
            'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=1200',
            'https://images.unsplash.com/photo-1560440021-33f9b867899d?w=1200',
            'https://images.unsplash.com/photo-1560449017-573cb49c9235?w=1200',
            'https://images.unsplash.com/photo-1560185893-a55cbc8c57e8?w=1200',
            'https://images.unsplash.com/photo-1583608205776-bfd35f0d9f83?w=1200',
            'https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=1200',
            'https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=1200',
            'https://images.unsplash.com/photo-1576941089067-2de3c901e126?w=1200',
            'https://images.unsplash.com/photo-1598928506311-c55ez59c2cc?w=1200',
            'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1200',
            'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=1200',
            'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200',
            'https://images.unsplash.com/photo-1605276374104-dee2a0ed3cd6?w=1200',
            'https://images.unsplash.com/photo-1600047509782-20d39509f26d?w=1200',
        ];

        // Create shoot files for each demo image
        foreach ($demoImages as $index => $imageUrl) {
            ShootFile::create([
                'shoot_id' => $shoot->id,
                'url' => $imageUrl,
                'path' => $imageUrl,
                'filename' => "property_photo_" . ($index + 1) . ".jpg",
                'file_type' => 'image/jpeg',
                'file_size' => rand(500000, 2000000),
                'workflow_stage' => 'verified',
                'uploaded_by' => $photographer->id,
            ]);
        }

        // Add sample floorplan
        ShootFile::create([
            'shoot_id' => $shoot->id,
            'url' => 'https://images.unsplash.com/photo-1574362848149-11496d93a7c7?w=1200',
            'path' => 'https://images.unsplash.com/photo-1574362848149-11496d93a7c7?w=1200',
            'filename' => 'floorplan_main.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 800000,
            'workflow_stage' => 'verified',
            'media_type' => 'floorplan',
            'uploaded_by' => $photographer->id,
        ]);

        echo "Created demo shoot ID: {$shoot->id}\n";
        echo "Address: {$shoot->address}, {$shoot->city}, {$shoot->state} {$shoot->zip}\n";
        echo "Added " . count($demoImages) . " demo photos\n";
        echo "Tour URL: /tour/branded?shootId={$shoot->id}\n";
    }
}
