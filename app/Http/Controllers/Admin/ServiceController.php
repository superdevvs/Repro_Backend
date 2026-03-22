<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\ServiceSqftRange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceController extends Controller
{
    public function store(Request $request)
    {
        $rules = [
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
        ];

        if ($this->serviceGroupsFeatureAvailable()) {
            $rules['service_group_ids'] = 'nullable|array';
            $rules['service_group_ids.*'] = 'integer|exists:service_groups,id';
        }

        $validated = $request->validate($rules);

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
            $serviceGroupIds = collect($validated['service_group_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            unset($validated['sqft_ranges']);
            unset($validated['service_group_ids']);

            $service = Service::create($validated);
            if ($this->serviceGroupsFeatureAvailable()) {
                $service->serviceGroups()->sync($serviceGroupIds);
            }

            // Create sqft ranges if provided
            if (!empty($sqftRanges)) {
                foreach ($sqftRanges as $range) {
                    $service->sqftRanges()->create($range);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Service created successfully.',
                'service' => $this->loadServiceRelations($service)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create service.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $relations = ['category', 'sqftRanges'];
            if ($this->serviceGroupsFeatureAvailable()) {
                $relations[] = 'serviceGroups';
            }

            $services = Service::query()
                ->with($relations)
                ->visibleToClient($this->resolveVisibleClient($request))
                ->orderBy('category_id')
                ->orderBy('name')
                ->get();
        } catch (\Throwable $exception) {
            Log::warning('Falling back to plain services catalog.', [
                'error' => $exception->getMessage(),
            ]);

            $services = Service::query()
                ->with(['category', 'sqftRanges'])
                ->orderBy('category_id')
                ->orderBy('name')
                ->get();
        }

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

        $rules = [
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
        ];

        if ($this->serviceGroupsFeatureAvailable()) {
            $rules['service_group_ids'] = 'nullable|array';
            $rules['service_group_ids.*'] = 'integer|exists:service_groups,id';
        }

        $validated = $request->validate($rules);

        DB::beginTransaction();
        try {
            // Extract sqft_ranges before updating service
            $sqftRanges = $validated['sqft_ranges'] ?? null;
            $serviceGroupIds = array_key_exists('service_group_ids', $validated)
                ? collect($validated['service_group_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all()
                : null;
            unset($validated['sqft_ranges']);
            unset($validated['service_group_ids']);

            $service->update($validated);
            if ($this->serviceGroupsFeatureAvailable() && $serviceGroupIds !== null) {
                $service->serviceGroups()->sync($serviceGroupIds);
            }

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
                'data' => $this->loadServiceRelations($service)
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
        $relations = ['category', 'sqftRanges'];
        if ($this->serviceGroupsFeatureAvailable()) {
            $relations[] = 'serviceGroups';
        }

        $service = Service::with($relations)->find($id);

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

    protected function resolveVisibleClient(Request $request): ?User
    {
        $authenticatedUser = auth('sanctum')->user();

        if ($authenticatedUser && $authenticatedUser->role === 'client') {
            return $this->serviceGroupsFeatureAvailable()
                ? $authenticatedUser->loadMissing('serviceGroups')
                : $authenticatedUser;
        }

        if (!$request->filled('client_id') || !$authenticatedUser) {
            return null;
        }

        if (!in_array($authenticatedUser->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return null;
        }

        $query = User::query()->where('role', 'client');

        if ($this->serviceGroupsFeatureAvailable()) {
            $query->with('serviceGroups');
        }

        return $query->find($request->integer('client_id'));
    }

    protected function loadServiceRelations(Service $service): Service
    {
        $relations = ['sqftRanges', 'category'];

        if ($this->serviceGroupsFeatureAvailable()) {
            $relations[] = 'serviceGroups';
        }

        return $service->load($relations);
    }

    protected function serviceGroupsFeatureAvailable(): bool
    {
        try {
            if (!class_exists(ServiceGroup::class)) {
                return false;
            }

            return ServiceGroup::isFeatureAvailable();
        } catch (\Throwable $exception) {
            Log::warning('Service groups unavailable in ServiceController.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
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
