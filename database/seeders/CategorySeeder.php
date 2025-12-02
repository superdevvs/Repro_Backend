<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Video', 'icon' => 'Video'],
            ['name' => 'Drone', 'icon' => 'Plane'],
            ['name' => 'Addons', 'icon' => 'PlusCircle'],
            ['name' => 'Bundles', 'icon' => 'Package'],
            ['name' => 'Commercials', 'icon' => 'Briefcase'],
            ['name' => 'Photos', 'icon' => 'Camera'],
            ['name' => 'Virtual Staging', 'icon' => 'BoxSelect'],
            ['name' => 'Floor Plans', 'icon' => 'LayoutTemplate'],
            ['name' => '360/3D Tours', 'icon' => 'View'],
        ];

        foreach ($categories as $categoryData) {
            Category::updateOrCreate(
                ['name' => $categoryData['name']],
                ['icon' => $categoryData['icon']]
            );
        }
    }
}
