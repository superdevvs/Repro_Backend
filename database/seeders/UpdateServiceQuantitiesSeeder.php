<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

class UpdateServiceQuantitiesSeeder extends Seeder
{
    /**
     * Update services with proper categories and auto-fill quantities based on names/descriptions.
     * Also moves drone-related services to Drone category, image-related to Photos, video-related to Video.
     */
    public function run(): void
    {
        // Get or create categories
        $photoCategory = Category::firstOrCreate(['name' => 'Photo'], ['icon' => 'Camera']);
        $videoCategory = Category::firstOrCreate(['name' => 'Video'], ['icon' => 'Video']);
        $droneCategory = Category::firstOrCreate(['name' => 'Drone'], ['icon' => 'Plane']);
        
        // Get all services
        $services = Service::with('category')->get();
        
        foreach ($services as $service) {
            $name = strtolower($service->name);
            $description = strtolower($service->description ?? '');
            $categoryName = strtolower($service->category->name ?? '');
            
            $updates = [];
            
            // --- Move services to correct categories ---
            
            // Drone-related services should be in Drone category
            if (str_contains($name, 'drone') || str_contains($name, 'aerial') || str_contains($name, 'elevated')) {
                if ($categoryName !== 'drone') {
                    $updates['category_id'] = $droneCategory->id;
                }
            }
            
            // Video-related services should be in Video category
            elseif (str_contains($name, 'video') || str_contains($name, 'walkthrough') || str_contains($name, 'highlight')) {
                if (!str_contains($categoryName, 'video') && !str_contains($categoryName, 'package') && !str_contains($categoryName, 'bundle')) {
                    $updates['category_id'] = $videoCategory->id;
                }
            }
            
            // Photo-related services in Unassigned should go to Photo
            elseif ($categoryName === 'unassigned' && (str_contains($name, 'photo') || str_contains($name, 'hdr') || str_contains($name, 'twilight'))) {
                $updates['category_id'] = $photoCategory->id;
            }
            
            // --- Extract and set quantities ---
            
            // For Photo category: extract photo count from name
            $isPhotoService = str_contains($categoryName, 'photo') || 
                              (isset($updates['category_id']) && $updates['category_id'] === $photoCategory->id);
            
            if ($isPhotoService && $service->photo_count === null) {
                $photoCount = $this->extractNumber($name, 'photo');
                if ($photoCount) {
                    $updates['photo_count'] = $photoCount;
                }
            }
            
            // For non-photo services: extract quantity from name/description
            if (!$isPhotoService && $service->quantity === null) {
                // Try to extract numbers for videos, images, etc.
                $quantity = $this->extractQuantityFromName($name, $description);
                if ($quantity) {
                    $updates['quantity'] = $quantity;
                }
            }
            
            // Apply updates if any
            if (!empty($updates)) {
                $service->update($updates);
                $this->command->info("Updated service: {$service->name}");
            }
        }
        
        // Update HDR Photos sqft_ranges with photo counts
        $this->updateHdrPhotoRanges();
        
        $this->command->info('Service quantities and categories updated successfully!');
    }
    
    /**
     * Extract a number that appears before a keyword (e.g., "3 Photos" -> 3)
     */
    private function extractNumber(string $text, string $keyword): ?int
    {
        // Pattern: number followed by keyword (e.g., "25 photos", "3 photo")
        if (preg_match('/(\d+)\s*' . preg_quote($keyword, '/') . '/i', $text, $matches)) {
            return (int) $matches[1];
        }
        // Pattern: keyword followed by number (e.g., "photos - 25")
        if (preg_match('/' . preg_quote($keyword, '/') . '\s*[-:]\s*(\d+)/i', $text, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }
    
    /**
     * Extract quantity from service name/description for non-photo services
     */
    private function extractQuantityFromName(string $name, string $description): ?int
    {
        // Social media videos: look for quantity indicators
        if (str_contains($name, 'social media') || str_contains($name, 'vertical video')) {
            // Basic = 1, Enhanced = 1, Ultimate = 1 (single video)
            return 1;
        }
        
        // Drone packages: extract photo count
        if (str_contains($name, 'drone')) {
            if (preg_match('/(\d+)[-\s]*(\d+)?\s*(aerial|photo)/i', $description, $matches)) {
                // Take the higher number if range (e.g., "6-7 photos" -> 7)
                return (int) ($matches[2] ?? $matches[1]);
            }
        }
        
        // Twilight photos
        if (str_contains($name, 'twilight')) {
            return $this->extractNumber($name, 'photo');
        }
        
        // Elevated photos
        if (str_contains($name, 'elevated')) {
            return $this->extractNumber($name, 'photo');
        }
        
        // Amenities photos
        if (str_contains($name, 'amenities')) {
            // "10 amenity photos" from description
            if (preg_match('/(\d+)\s*amenity/i', $description, $matches)) {
                return (int) $matches[1];
            }
            return 10; // Default
        }
        
        // Virtual staging (per image)
        if (str_contains($name, 'virtual staging')) {
            return 1;
        }
        
        // Boundary lines
        if (str_contains($name, 'boundary')) {
            if (str_contains($name, 'photo') && !str_contains($name, 'video')) {
                return 2; // "includes 2 images"
            }
        }
        
        return null;
    }
    
    /**
     * Update HDR Photos sqft_ranges with appropriate photo counts
     */
    private function updateHdrPhotoRanges(): void
    {
        $hdrService = Service::where('name', 'HDR Photos')->first();
        
        if ($hdrService) {
            // Photo counts based on sqft ranges (from description: 25-65 photos)
            $photoCounts = [
                ['sqft_from' => 1, 'sqft_to' => 1500, 'photo_count' => 25],
                ['sqft_from' => 1501, 'sqft_to' => 3000, 'photo_count' => 35],
                ['sqft_from' => 3001, 'sqft_to' => 5000, 'photo_count' => 45],
                ['sqft_from' => 5001, 'sqft_to' => 7000, 'photo_count' => 55],
                ['sqft_from' => 7001, 'sqft_to' => 10000, 'photo_count' => 65],
            ];
            
            foreach ($photoCounts as $pc) {
                DB::table('service_sqft_ranges')
                    ->where('service_id', $hdrService->id)
                    ->where('sqft_from', $pc['sqft_from'])
                    ->where('sqft_to', $pc['sqft_to'])
                    ->update(['photo_count' => $pc['photo_count']]);
            }
            
            $this->command->info('Updated HDR Photos sqft_ranges with photo counts');
        }
    }
}
