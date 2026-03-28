<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\Shoot;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\ShootTaxService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShootMutationSupportService
{
    public function __construct(
        protected PhotographerAvailabilityService $availabilityService,
        protected ShootTaxService $taxService
    ) {
    }

    public function calculateBaseQuote(array $services): float
    {
        $total = 0;
        $serviceIds = collect($services)->pluck('id');
        $serviceModels = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        foreach ($services as $service) {
            $serviceModel = $serviceModels->get($service['id']);
            $price = $service['price'] ?? $serviceModel?->price ?? 0;
            $quantity = $service['quantity'] ?? 1;
            $total += $price * $quantity;
        }

        return round($total, 2);
    }

    public function buildTaxCalculation(array $services, ?string $state, ?string $taxRegion = null): array
    {
        $baseQuote = $this->calculateBaseQuote($services);
        $resolvedTaxRegion = $taxRegion ?? $this->taxService->determineTaxRegion((string) $state);

        return $this->taxService->calculateTotal($baseQuote, $resolvedTaxRegion);
    }

    public function getClientRep(int $clientId): ?int
    {
        $mostRecentShoot = Shoot::where('client_id', $clientId)
            ->whereNotNull('rep_id')
            ->orderBy('created_at', 'desc')
            ->first();

        return $mostRecentShoot?->rep_id;
    }

    public function checkPhotographerAvailability(
        int $photographerId,
        \DateTime $scheduledAt,
        ?int $durationMinutes = 120,
        ?int $excludeShootId = null
    ): void {
        $carbonDate = Carbon::parse($scheduledAt);

        if (!$this->availabilityService->isAvailable($photographerId, $carbonDate, $durationMinutes, $excludeShootId)) {
            throw ValidationException::withMessages([
                'photographer_id' => ['Photographer is not available at the selected time.'],
            ]);
        }
    }

    public function calculateShootDurationFromServices(array $services): int
    {
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $minDurationMinutes = config('availability.min_shoot_duration_minutes', 60);
        $maxDurationMinutes = config('availability.max_shoot_duration_minutes', 240);

        $serviceIds = collect($services)->pluck('id')->unique();
        $serviceModels = Service::whereIn('id', $serviceIds)->get();

        if ($serviceModels->isEmpty()) {
            return $defaultDurationMinutes;
        }

        $maxHours = $serviceModels->max('delivery_time') ?? ($defaultDurationMinutes / 60);
        $durationMinutes = (int) ($maxHours * 60);

        return min(max($durationMinutes, $minDurationMinutes), $maxDurationMinutes);
    }

    public function calculateShootDurationFromShoot(Shoot $shoot): int
    {
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $minDurationMinutes = config('availability.min_shoot_duration_minutes', 60);
        $maxDurationMinutes = config('availability.max_shoot_duration_minutes', 240);

        $services = $shoot->services;
        if (!$services || $services->isEmpty()) {
            return $defaultDurationMinutes;
        }

        $maxHours = $services->max('delivery_time') ?? ($defaultDurationMinutes / 60);
        $durationMinutes = (int) ($maxHours * 60);

        return min(max($durationMinutes, $minDurationMinutes), $maxDurationMinutes);
    }

    public function attachServices(Shoot $shoot, array $services): void
    {
        $serviceIds = collect($services)->pluck('id');
        $serviceModels = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        $pivotData = collect($services)->mapWithKeys(function ($service) use ($serviceModels) {
            $serviceModel = $serviceModels->get($service['id']);

            return [
                $service['id'] => [
                    'price' => $service['price'] ?? $serviceModel?->price ?? 0,
                    'quantity' => $service['quantity'] ?? 1,
                    'photographer_pay' => $service['photographer_pay'] ?? null,
                ],
            ];
        })->toArray();

        $shoot->services()->sync($pivotData);
        $shoot->load('services');
    }

    public function assignServicePhotographers(Shoot $shoot, ?array $servicePhotographers): void
    {
        if (!is_array($servicePhotographers) || count($servicePhotographers) === 0) {
            return;
        }

        foreach ($servicePhotographers as $assignment) {
            $serviceId = $assignment['service_id'] ?? null;
            if ($serviceId) {
                $assignedPhotographerId = array_key_exists('photographer_id', $assignment)
                    ? $assignment['photographer_id']
                    : null;

                $shoot->assignPhotographerToService(
                    (int) $serviceId,
                    $assignedPhotographerId !== null && $assignedPhotographerId !== ''
                        ? (int) $assignedPhotographerId
                        : null
                );
            }
        }
    }

    public function ensureClientCanBookServices(int $clientId, array $services): void
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return;
        }

        $client = User::with('serviceGroups')->find($clientId);
        if (!$client || !$client->hasServiceGroupRestrictions()) {
            return;
        }

        $requestedIds = collect($services)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $visibleIds = Service::visibleIdsForClient($client, $requestedIds->all())
            ->map(fn ($id) => (int) $id)
            ->values();

        $invalidIds = $requestedIds->diff($visibleIds)->values()->all();

        if (!empty($invalidIds)) {
            throw ValidationException::withMessages([
                'services' => ['One or more selected services are not available for this client.'],
            ]);
        }
    }

    public function createNotes(Shoot $shoot, array $validated, User $user): void
    {
        $notesToCreate = [];

        if (!empty($validated['shoot_notes'])) {
            $notesToCreate[] = [
                'type' => 'shoot',
                'visibility' => 'client_visible',
                'content' => $validated['shoot_notes'],
            ];
        }

        if (!empty($validated['company_notes'])) {
            $notesToCreate[] = [
                'type' => 'company',
                'visibility' => 'internal',
                'content' => $validated['company_notes'],
            ];
        }

        if (!empty($validated['photographer_notes'])) {
            $notesToCreate[] = [
                'type' => 'photographer',
                'visibility' => 'photographer_only',
                'content' => $validated['photographer_notes'],
            ];
        }

        if (!empty($validated['editor_notes'])) {
            $notesToCreate[] = [
                'type' => 'editing',
                'visibility' => 'internal',
                'content' => $validated['editor_notes'],
            ];
        }

        foreach ($notesToCreate as $noteData) {
            $shoot->notes()->create([
                'author_id' => $user->id,
                'type' => $noteData['type'],
                'visibility' => $noteData['visibility'],
                'content' => $noteData['content'],
            ]);
        }
    }

    public function calculateExpectedRawCount(?int $expectedFinal, ?int $bracketMode): int
    {
        if ($expectedFinal && $bracketMode) {
            return $expectedFinal * $bracketMode;
        }

        return 0;
    }

    public function formatFullAddress(Shoot $shoot): string
    {
        return trim(sprintf(
            '%s, %s, %s %s',
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip
        ), ', ');
    }

    protected function serviceGroupsFeatureAvailable(): bool
    {
        try {
            if (!class_exists(ServiceGroup::class)) {
                return false;
            }

            return ServiceGroup::isFeatureAvailable();
        } catch (\Throwable $exception) {
            Log::warning('Service groups unavailable in shoot mutation support.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
