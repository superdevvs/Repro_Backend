<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;
use App\Models\Category;

class MergePhotoCategoriesSeeder extends Seeder
{
    /**
     * Merge "Photo" category into "Photos" and delete the duplicate.
     */
    public function run(): void
    {
        // Find the "Photos" category (the one to keep)
        $photosCategory = Category::where('name', 'Photos')->first();
        
        // Find the "Photo" category (the duplicate to remove)
        $photoCategory = Category::where('name', 'Photo')->first();
        
        if (!$photosCategory) {
            $this->command->info('Photos category not found. Creating it...');
            $photosCategory = Category::create([
                'name' => 'Photos',
                'icon' => 'Camera',
            ]);
        }
        
        if ($photoCategory) {
            // Move all services from "Photo" to "Photos"
            $servicesCount = Service::where('category_id', $photoCategory->id)->count();
            
            Service::where('category_id', $photoCategory->id)
                ->update(['category_id' => $photosCategory->id]);
            
            $this->command->info("Moved {$servicesCount} services from 'Photo' to 'Photos'");
            
            // Delete the duplicate "Photo" category
            $photoCategory->delete();
            $this->command->info("Deleted duplicate 'Photo' category");
        } else {
            $this->command->info("No 'Photo' category found to merge");
        }
        
        $this->command->info('Category merge completed!');
    }
}
