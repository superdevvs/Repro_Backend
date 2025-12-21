<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Service;
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
        $defaultNames = ['photo', 'video'];
        $isDefaultByName = in_array(strtolower($category->name), $defaultNames);
        
        if ($category->is_default || $isDefaultByName) {
            return response()->json([
                'message' => 'Cannot delete default category. Photo and Video categories are required.',
            ], 403);
        }
        
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
        
        $category->delete();

        return response()->json([
            'message' => 'Category deleted',
            'services_moved' => $servicesCount,
            'moved_to' => 'Unassigned'
        ]);
    }
}
