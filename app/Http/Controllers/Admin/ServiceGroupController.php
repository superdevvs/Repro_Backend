<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceGroupController extends Controller
{
    public function index()
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Service groups are not available until migrations are applied.',
            ]);
        }

        $groups = ServiceGroup::with([
            'services:id,name,category_id',
            'services.category:id,name',
            'clients:id,name,email,company_name',
        ])
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceGroup $group) => $this->transformGroup($group));

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Service groups are not available until migrations are applied.',
            ], 503);
        }

        $validated = $this->validatePayload($request);
        [$serviceIds, $clientIds] = $this->extractAssignments($validated);

        $group = DB::transaction(function () use ($validated, $serviceIds, $clientIds) {
            $group = ServiceGroup::create($validated);
            $group->services()->sync($serviceIds);
            $group->clients()->sync($clientIds);

            return $group;
        });

        return response()->json([
            'success' => true,
            'message' => 'Service group created successfully.',
            'data' => $this->transformGroup($group->fresh(['services.category', 'clients'])),
        ], 201);
    }

    public function update(Request $request, ServiceGroup $serviceGroup)
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Service groups are not available until migrations are applied.',
            ], 503);
        }

        $validated = $this->validatePayload($request, $serviceGroup);
        [$serviceIds, $clientIds] = $this->extractAssignments($validated);

        DB::transaction(function () use ($serviceGroup, $validated, $serviceIds, $clientIds) {
            $serviceGroup->update($validated);
            $serviceGroup->services()->sync($serviceIds);
            $serviceGroup->clients()->sync($clientIds);
        });

        return response()->json([
            'success' => true,
            'message' => 'Service group updated successfully.',
            'data' => $this->transformGroup($serviceGroup->fresh(['services.category', 'clients'])),
        ]);
    }

    public function destroy(ServiceGroup $serviceGroup)
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Service groups are not available until migrations are applied.',
            ], 503);
        }

        $serviceGroup->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service group deleted successfully.',
        ]);
    }

    protected function validatePayload(Request $request, ?ServiceGroup $serviceGroup = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('service_groups', 'name')->ignore($serviceGroup?->id),
            ],
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer|exists:services,id',
            'client_ids' => 'nullable|array',
            'client_ids.*' => 'integer|exists:users,id',
        ]);

        $clientIds = collect($validated['client_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($clientIds->isNotEmpty()) {
            $invalidClientIds = User::whereIn('id', $clientIds)
                ->where('role', '!=', 'client')
                ->pluck('id');

            if ($invalidClientIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'client_ids' => ['One or more selected users are not clients.'],
                ]);
            }
        }

        return $validated;
    }

    protected function extractAssignments(array &$validated): array
    {
        $serviceIds = collect($validated['service_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $clientIds = collect($validated['client_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        unset($validated['service_ids'], $validated['client_ids']);

        return [$serviceIds, $clientIds];
    }

    protected function transformGroup(ServiceGroup $group): array
    {
        $services = $group->services->map(function ($service) {
            return [
                'id' => (string) $service->id,
                'name' => $service->name,
                'category' => $service->category ? [
                    'id' => (string) $service->category->id,
                    'name' => $service->category->name,
                ] : null,
            ];
        })->values();

        $clients = $group->clients->map(function ($client) {
            return [
                'id' => (string) $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'company' => $client->company_name,
            ];
        })->values();

        return [
            'id' => (string) $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'is_active' => $group->is_active,
            'services' => $services,
            'clients' => $clients,
            'service_ids' => $services->pluck('id')->values()->all(),
            'client_ids' => $clients->pluck('id')->values()->all(),
            'service_count' => $services->count(),
            'client_count' => $clients->count(),
            'created_at' => optional($group->created_at)?->toIso8601String(),
            'updated_at' => optional($group->updated_at)?->toIso8601String(),
        ];
    }

    protected function serviceGroupsFeatureAvailable(): bool
    {
        try {
            if (!class_exists(ServiceGroup::class)) {
                return false;
            }

            return ServiceGroup::isFeatureAvailable();
        } catch (\Throwable $exception) {
            Log::warning('Service groups unavailable in ServiceGroupController.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
