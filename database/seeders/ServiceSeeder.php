<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;
use App\Models\Category;
use App\Models\ServiceSqftRange;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            // Photos
            [
                'name' => 'HDR Photos',
                'price' => 175.00,
                'category' => 'Photo',
                'icon' => 'Camera',
                'pricing_type' => 'variable',
                'description' => 'HDR photo coverage with the right image count (25-65 photos) based on property size.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 1500, 'price' => 175.00, 'photo_count' => 25],
                    ['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 199.00, 'photo_count' => 35],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 275.00, 'photo_count' => 45],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 350.00, 'photo_count' => 55],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 425.00, 'photo_count' => 65],
                ],
            ],
            [
                'name' => 'Twilight Photos - 3 Photos',
                'photo_count' => 3,
                'price' => 150.00,
                'category' => 'Photo',
                'icon' => 'Sunset',
                'description' => 'Three exterior twilight photos captured on-site.',
            ],
            [
                'name' => 'Twilight Photos - 5 Photos',
                'photo_count' => 5,
                'price' => 200.00,
                'category' => 'Photo',
                'icon' => 'Sunset',
                'description' => 'Five exterior twilight photos captured on-site.',
            ],
            [
                'name' => 'Twilight Photos - 10 Photos',
                'photo_count' => 10,
                'price' => 350.00,
                'category' => 'Photo',
                'icon' => 'Sunset',
                'description' => 'Ten exterior twilight photos captured on-site.',
            ],
            [
                'name' => 'Amenities Photos',
                'photo_count' => 10,
                'price' => 100.00,
                'category' => 'Photo',
                'icon' => 'Image',
                'description' => '10 amenity photos or coverage for up to 5 locations.',
            ],

            // Video
            [
                'name' => 'Luxury Highlight or Walkthrough Video',
                'price' => 175.00,
                'category' => 'Video',
                'icon' => 'Video',
                'pricing_type' => 'variable',
                'description' => 'Luxury highlight or walkthrough video, scaled to the property size.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 175.00],
                    ['sqft_from' => 2001, 'sqft_to' => 3000, 'price' => 175.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 250.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 300.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 375.00],
                ],
            ],
            [
                'name' => 'Social Media Vertical Video - Basic',
                'price' => 200.00,
                'category' => 'Video',
                'icon' => 'Smartphone',
                'description' => 'Vertical video highlighting a property’s best spaces in an artistic style.',
            ],
            [
                'name' => 'Social Media Vertical Video - Enhanced',
                'price' => 350.00,
                'category' => 'Video',
                'icon' => 'Smartphone',
                'description' => 'Vertical video with the agent on camera highlighting key spaces.',
            ],
            [
                'name' => 'Social Media Vertical Video - Ultimate',
                'price' => 450.00,
                'category' => 'Video',
                'icon' => 'Smartphone',
                'description' => 'Vertical video with the agent on camera plus drone footage where possible.',
            ],

            // Floor Plans
            [
                'name' => '2D Floor plans',
                'price' => 125.00,
                'category' => 'Floor Plans',
                'icon' => 'LayoutTemplate',
                'pricing_type' => 'variable',
                'description' => 'Measured 2D floor plans sized to the square footage.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 125.00],
                    ['sqft_from' => 2001, 'sqft_to' => 3000, 'price' => 125.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 175.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 225.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 250.00],
                ],
            ],

            // 360/3D Tours
            [
                'name' => '3D Matterport w/ 2D Floor plans',
                'price' => 250.00,
                'category' => '360/3D Tours',
                'icon' => 'Box',
                'pricing_type' => 'variable',
                'description' => 'Matterport 3D capture with 2D floor plans included.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 250.00],
                    ['sqft_from' => 2001, 'sqft_to' => 3000, 'price' => 325.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 400.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 475.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 550.00],
                ],
            ],
            [
                'name' => 'Premium iGuide w/ 2D Floor plans',
                'price' => 185.00,
                'category' => '360/3D Tours',
                'icon' => 'Map',
                'pricing_type' => 'variable',
                'description' => 'Premium iGuide tour with detailed 2D floor plans.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 1500, 'price' => 185.00],
                    ['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 270.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 350.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 525.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 650.00],
                ],
            ],
            [
                'name' => 'Zillow 3D Home Tour',
                'price' => 100.00,
                'category' => '360/3D Tours',
                'icon' => 'View',
                'pricing_type' => 'variable',
                'description' => 'Zillow 3D Home tour for enhanced listing exposure.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 100.00],
                    ['sqft_from' => 2001, 'sqft_to' => 3000, 'price' => 125.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 150.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 175.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 200.00],
                ],
            ],

            // Drone & Elevated
            [
                'name' => 'Drone/Aerials Photo Set',
                'price' => 150.00,
                'category' => 'Drone',
                'icon' => 'Plane',
                'description' => '6-7 aerial photos. Drone shots subject to FAA airspace restrictions in the DC Metro area.',
            ],
            [
                'name' => 'Drone Silver Package',
                'price' => 199.00,
                'category' => 'Drone',
                'icon' => 'Plane',
                'description' => '6-7 aerial photos plus a 30-40 second edited video. Subject to FAA airspace restrictions.',
            ],
            [
                'name' => 'Drone Gold Package',
                'price' => 399.00,
                'category' => 'Drone',
                'icon' => 'Plane',
                'description' => '10-12 aerial photos with a 60-90 second edited video. Subject to FAA airspace restrictions.',
            ],
            [
                'name' => 'Drone Platinum Package',
                'price' => 699.00,
                'category' => 'Drone',
                'icon' => 'Plane',
                'description' => '15-20 aerial photos with a 2 minute edited video. Subject to FAA airspace restrictions.',
            ],
            [
                'name' => 'Elevated Photos - 5 Photos',
                'price' => 150.00,
                'category' => 'Drone',
                'icon' => 'Camera',
                'description' => 'Five elevated mast photos for dramatic curb appeal.',
            ],

            // Addons & Enhancements
            [
                'name' => 'Boundary Lines - Photos',
                'price' => 60.00,
                'category' => 'Addons',
                'icon' => 'Crop',
                'description' => 'Boundary line overlays on photos (includes 2 images).',
            ],
            [
                'name' => 'Boundary Lines - Video',
                'price' => 100.00,
                'category' => 'Addons',
                'icon' => 'Crop',
                'description' => 'Up to 15 seconds of boundary overlays on video footage.',
            ],
            [
                'name' => 'Boundary Lines - Photos & Video',
                'price' => 150.00,
                'category' => 'Addons',
                'icon' => 'Crop',
                'description' => 'Combination of boundary overlays for both photos and video.',
            ],
            [
                'name' => 'Virtual Staging (per image)',
                'price' => 45.00,
                'category' => 'Virtual Staging',
                'icon' => 'BoxSelect',
                'description' => 'Virtual staging priced per finished image.',
            ],
            [
                'name' => 'Green Grass Enhancement',
                'price' => 25.00,
                'category' => 'Addons',
                'icon' => 'Leaf',
                'description' => 'Green grass color enhancement priced per image.',
            ],
            [
                'name' => 'Digital Twilight/Dusk',
                'price' => 35.00,
                'category' => 'Addons',
                'icon' => 'Moon',
                'description' => 'Digital twilight/dusk conversion priced per image.',
            ],

            // Bundles / Packages
            [
                'name' => 'HDR Photos & Video',
                'price' => 250.00,
                'category' => 'packages',
                'icon' => 'Film',
                'pricing_type' => 'variable',
                'description' => 'Bundle of HDR photos and video with the right photo count per square footage.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 1500, 'price' => 250.00],
                    ['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 299.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 375.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 450.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 525.00],
                ],
            ],
            [
                'name' => 'HDR Photos & 3D Matterport',
                'price' => 400.00,
                'category' => 'Bundles',
                'icon' => 'Box',
                'pricing_type' => 'variable',
                'description' => 'HDR photos bundled with a Matterport tour and 2D floor plans.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 400.00],
                    ['sqft_from' => 2001, 'sqft_to' => 3000, 'price' => 500.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 600.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 700.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 800.00],
                ],
            ],
            [
                'name' => 'HDR Photos, Video & 3D Matterport',
                'price' => 500.00,
                'category' => 'Bundles',
                'icon' => 'Layers',
                'pricing_type' => 'variable',
                'description' => 'Full media bundle: HDR photos, walkthrough video, and Matterport tour.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 500.00],
                    ['sqft_from' => 2001, 'sqft_to' => 3000, 'price' => 575.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 700.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 800.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 950.00],
                ],
            ],
            [
                'name' => 'HDR Photos & Premium iGuide',
                'price' => 310.00,
                'category' => 'Bundles',
                'icon' => 'Map',
                'pricing_type' => 'variable',
                'description' => 'HDR photo package bundled with a premium iGuide tour.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 1500, 'price' => 310.00],
                    ['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 475.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 525.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 625.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 700.00],
                ],
            ],
            [
                'name' => 'HDR Photos, Video & Premium iGuide',
                'price' => 410.00,
                'category' => 'Bundles',
                'icon' => 'Layers',
                'pricing_type' => 'variable',
                'description' => 'HDR photos, video, and premium iGuide in one package.',
                'sqft_ranges' => [
                    ['sqft_from' => 1, 'sqft_to' => 1500, 'price' => 410.00],
                    ['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 575.00],
                    ['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 625.00],
                    ['sqft_from' => 5001, 'sqft_to' => 7000, 'price' => 725.00],
                    ['sqft_from' => 7001, 'sqft_to' => 10000, 'price' => 800.00],
                ],
            ],
        ];

        foreach ($services as $serviceData) {
            $category = Category::firstOrCreate(['name' => $serviceData['category']]);
            $basePrice = $serviceData['price'] ?? ($serviceData['sqft_ranges'][0]['price'] ?? 0);

            $service = Service::updateOrCreate(
                ['name' => $serviceData['name']],
                [
                    'price' => $basePrice,
                    'pricing_type' => $serviceData['pricing_type'] ?? 'fixed',
                    'allow_multiple' => $serviceData['allow_multiple'] ?? false,
                    'description' => $serviceData['description'] ?? $serviceData['name'],
                    'delivery_time' => $serviceData['delivery_time'] ?? 24,
                    'category_id' => $category->id,
                    'icon' => $serviceData['icon'],
                    'photographer_required' => $serviceData['photographer_required'] ?? false,
                    'photographer_pay' => $serviceData['photographer_pay'] ?? null,
                    'photo_count' => $serviceData['photo_count'] ?? null,
                ]
            );

            // Sync SQFT ranges for variable pricing services
            $service->sqftRanges()->delete();

            if (!empty($serviceData['sqft_ranges'])) {
                foreach ($serviceData['sqft_ranges'] as $range) {
                    ServiceSqftRange::create([
                        'service_id' => $service->id,
                        'sqft_from' => $range['sqft_from'],
                        'sqft_to' => $range['sqft_to'],
                        'duration' => $range['duration'] ?? null,
                        'price' => $range['price'],
                        'photographer_pay' => $range['photographer_pay'] ?? null,
                        'photo_count' => $range['photo_count'] ?? null,
                    ]);
                }
            }
        }
    }
}
