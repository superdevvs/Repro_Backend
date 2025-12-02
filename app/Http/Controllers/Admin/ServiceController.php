<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;

class ServiceController extends Controller
{
    //
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'delivery_time' => 'required|integer|min:1',
            'category_id' => 'required|exists:categories,id',
        ]);

        // Ensure category_id is not null
        if (empty($validated['category_id'])) {
            return response()->json([
                'message' => 'Category is required.',
                'errors' => ['category_id' => ['Please select a category.']]
            ], 422);
        }

        $service = Service::create($validated);

        return response()->json([
            'message' => 'Service created successfully.',
            'service' => $service
        ]);
    }

    public function index()
    {
        $services = Service::with('category')->get();

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    public function update(Request $request, $id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric',
            'delivery_time' => 'sometimes|integer',
            'category_id' => 'sometimes|exists:categories,id',
        ]);

        $service->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Service updated successfully',
            'data' => $service
        ]);
    }

    public function show($id)
    {
        $service = Service::with('category')->find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        return response()->json(['service' => $service], 200);
    }

    public function destroy($id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        $service->delete();

        return response()->json(['message' => 'Service deleted successfully'], 200);
    }

}
