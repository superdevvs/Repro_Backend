<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(Category::all(), 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:categories,name',
            'icon' => 'nullable|string',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'icon' => $request->icon,
        ]);

        return response()->json(['message' => 'Category created', 'data' => $category], 201);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'required|string|unique:categories,name,' . $id,
            'icon' => 'nullable|string',
        ]);

        $category->update([
            'name' => $request->name,
            'icon' => $request->icon ?? $category->icon,
        ]);

        return response()->json(['message' => 'Category updated', 'data' => $category]);
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);
        
        // Prevent deletion of default categories (Photo, Video)
        // Check both is_default flag and name as fallback
        $defaultNames = ['photo', 'photos', 'video'];
        $isDefaultByName = in_array(strtolower($category->name), $defaultNames);
        
        if ($category->is_default || $isDefaultByName) {
            return response()->json([
                'message' => 'Cannot delete default category. Photo and Video categories are required.',
            ], 403);
        }
        
        $result = DB::transaction(function () use ($category, $id) {
            // Get or create "Unassigned" category
            $unassignedCategory = Category::firstOrCreate(
                ['name' => 'Unassigned'],
                ['icon' => 'package']
            );

            // Move all services from this category to "Unassigned"
            $servicesCount = Service::where('category_id', $id)->count();

            if ($servicesCount > 0) {
                Service::where('category_id', $id)
                    ->update(['category_id' => $unassignedCategory->id]);

                Log::info("Moved {$servicesCount} service(s) from category '{$category->name}' to 'Unassigned'");
            }

            $photographerCapabilitiesCleaned = $this->removeCategoryFromPhotographerCapabilities($category);

            $category->delete();

            return [
                'services_moved' => $servicesCount,
                'photographer_capabilities_cleaned' => $photographerCapabilitiesCleaned,
            ];
        });

        return response()->json([
            'message' => 'Category deleted',
            'services_moved' => $result['services_moved'],
            'photographer_capabilities_cleaned' => $result['photographer_capabilities_cleaned'],
            'moved_to' => 'Unassigned'
        ]);
    }

    private function removeCategoryFromPhotographerCapabilities(Category $category): int
    {
        $categoryKeys = $this->categoryCapabilityKeys($category);
        $updatedCount = 0;

        User::where('role', 'photographer')
            ->whereNotNull('metadata')
            ->chunkById(100, function ($photographers) use ($categoryKeys, &$updatedCount) {
                foreach ($photographers as $photographer) {
                    $metadata = is_array($photographer->metadata) ? $photographer->metadata : [];
                    $specialties = $metadata['specialties'] ?? [];

                    if (!is_array($specialties)) {
                        continue;
                    }

                    $cleanedSpecialties = array_values(array_filter(
                        $specialties,
                        fn ($specialty) => !in_array((string) $specialty, $categoryKeys, true)
                    ));

                    if (count($cleanedSpecialties) === count($specialties)) {
                        continue;
                    }

                    $metadata['specialties'] = $cleanedSpecialties;
                    $photographer->metadata = $metadata;
                    $photographer->save();
                    $updatedCount++;
                }
            });

        return $updatedCount;
    }

    private function categoryCapabilityKeys(Category $category): array
    {
        return array_values(array_unique([
            'category:' . (string) $category->id,
            'category-name:' . $this->normalizeCategoryNameForCapability($category->name),
        ]));
    }

    private function normalizeCategoryNameForCapability(?string $name): string
    {
        $normalized = strtolower(trim((string) $name));
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? '';
        $normalized = preg_replace('/[^a-z0-9-]/', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : 'other';
    }
}
