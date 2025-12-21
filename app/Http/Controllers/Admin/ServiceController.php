<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\ServiceSqftRange;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'pricing_type' => 'nullable|in:fixed,variable',
            'allow_multiple' => 'nullable|boolean',
            'delivery_time' => 'required|integer|min:1',
            'category_id' => 'required|exists:categories,id',
            'icon' => 'nullable|string',
            'photographer_required' => 'nullable|boolean',
            'photographer_pay' => 'nullable|numeric|min:0',
            'photo_count' => 'nullable|integer|min:0',
            'quantity' => 'nullable|integer|min:0',
            'sqft_ranges' => 'nullable|array',
            'sqft_ranges.*.sqft_from' => 'required_with:sqft_ranges|integer|min:0',
            'sqft_ranges.*.sqft_to' => 'required_with:sqft_ranges|integer|min:0',
            'sqft_ranges.*.duration' => 'nullable|integer|min:0',
            'sqft_ranges.*.price' => 'required_with:sqft_ranges|numeric|min:0',
            'sqft_ranges.*.photographer_pay' => 'nullable|numeric|min:0',
            'sqft_ranges.*.photo_count' => 'nullable|integer|min:0',
        ]);

        // Ensure category_id is not null
        if (empty($validated['category_id'])) {
            return response()->json([
                'message' => 'Category is required.',
                'errors' => ['category_id' => ['Please select a category.']]
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Extract sqft_ranges before creating service
            $sqftRanges = $validated['sqft_ranges'] ?? [];
            unset($validated['sqft_ranges']);

            $service = Service::create($validated);

            // Create sqft ranges if provided
            if (!empty($sqftRanges)) {
                foreach ($sqftRanges as $range) {
                    $service->sqftRanges()->create($range);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Service created successfully.',
                'service' => $service->load('sqftRanges', 'category')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create service.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index()
    {
        $services = Service::with(['category', 'sqftRanges'])->get();

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
            'pricing_type' => 'nullable|in:fixed,variable',
            'allow_multiple' => 'nullable|boolean',
            'delivery_time' => 'sometimes|integer',
            'category_id' => 'sometimes|exists:categories,id',
            'icon' => 'nullable|string',
            'photographer_required' => 'nullable|boolean',
            'photographer_pay' => 'nullable|numeric|min:0',
            'photo_count' => 'nullable|integer|min:0',
            'quantity' => 'nullable|integer|min:0',
            'sqft_ranges' => 'nullable|array',
            'sqft_ranges.*.id' => 'nullable|integer',
            'sqft_ranges.*.sqft_from' => 'required_with:sqft_ranges|integer|min:0',
            'sqft_ranges.*.sqft_to' => 'required_with:sqft_ranges|integer|min:0',
            'sqft_ranges.*.duration' => 'nullable|integer|min:0',
            'sqft_ranges.*.price' => 'required_with:sqft_ranges|numeric|min:0',
            'sqft_ranges.*.photographer_pay' => 'nullable|numeric|min:0',
            'sqft_ranges.*.photo_count' => 'nullable|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Extract sqft_ranges before updating service
            $sqftRanges = $validated['sqft_ranges'] ?? null;
            unset($validated['sqft_ranges']);

            $service->update($validated);

            // Update sqft ranges if provided
            if ($sqftRanges !== null) {
                // Get existing range IDs
                $existingIds = $service->sqftRanges()->pluck('id')->toArray();
                $submittedIds = [];

                foreach ($sqftRanges as $range) {
                    if (!empty($range['id'])) {
                        // Update existing range
                        $submittedIds[] = $range['id'];
                        ServiceSqftRange::where('id', $range['id'])
                            ->where('service_id', $service->id)
                            ->update([
                                'sqft_from' => $range['sqft_from'],
                                'sqft_to' => $range['sqft_to'],
                                'duration' => $range['duration'] ?? null,
                                'price' => $range['price'],
                                'photographer_pay' => $range['photographer_pay'] ?? null,
                                'photo_count' => $range['photo_count'] ?? null,
                            ]);
                    } else {
                        // Create new range
                        $newRange = $service->sqftRanges()->create($range);
                        $submittedIds[] = $newRange->id;
                    }
                }

                // Delete ranges that were not submitted
                $toDelete = array_diff($existingIds, $submittedIds);
                if (!empty($toDelete)) {
                    ServiceSqftRange::whereIn('id', $toDelete)->delete();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Service updated successfully',
                'data' => $service->load('sqftRanges', 'category')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update service.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $service = Service::with(['category', 'sqftRanges'])->find($id);

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

    /**
     * Calculate price for a service based on square footage.
     */
    public function calculatePrice(Request $request, $id)
    {
        $service = Service::with('sqftRanges')->find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        $sqft = $request->input('sqft');

        return response()->json([
            'service_id' => $service->id,
            'sqft' => $sqft,
            'price' => $service->getPriceForSqft($sqft),
            'photographer_pay' => $service->getPhotographerPayForSqft($sqft),
            'duration' => $service->getDurationForSqft($sqft),
            'pricing_type' => $service->pricing_type,
        ]);
    }
}
