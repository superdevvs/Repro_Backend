<?php

namespace App\Services\Shoots;

use App\Models\Coupon;
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
        return $this->buildPricingCalculation($services, null, $state, $taxRegion);
    }

    public function buildPricingCalculation(
        array $services,
        ?User $client,
        ?string $state,
        ?string $taxRegion = null,
        ?string $couponCode = null
    ): array {
        $serviceSubtotal = $this->calculateBaseQuote($services);
        $discountSnapshot = $this->resolveDiscountSnapshot($serviceSubtotal, $client, $couponCode);
        $resolvedTaxRegion = $taxRegion ?? $this->taxService->determineTaxRegion((string) $state);
        $taxCalculation = $this->taxService->calculateTotal($discountSnapshot['discounted_subtotal'], $resolvedTaxRegion);

        return array_merge($taxCalculation, [
            'service_subtotal' => $serviceSubtotal,
            'base_quote' => (float) $taxCalculation['base_quote'],
            'discount_type' => $discountSnapshot['discount_type'],
            'discount_value' => $discountSnapshot['discount_value'],
            'discount_amount' => $discountSnapshot['discount_amount'],
            'discounted_subtotal' => (float) $taxCalculation['base_quote'],
            'client_discount_type' => $discountSnapshot['client_discount_type'],
            'client_discount_value' => $discountSnapshot['client_discount_value'],
            'client_discount_amount' => $discountSnapshot['client_discount_amount'],
            'coupon_code' => $discountSnapshot['coupon_code'],
            'coupon_discount_type' => $discountSnapshot['coupon_discount_type'],
            'coupon_discount_value' => $discountSnapshot['coupon_discount_value'],
            'coupon_discount_amount' => $discountSnapshot['coupon_discount_amount'],
        ]);
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

    public function sanitizeEmailList(?array $emails): array
    {
        return collect($emails ?? [])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    public function resolveDiscountSnapshot(
        float $serviceSubtotal,
        ?User $client,
        ?string $couponCode = null
    ): array {
        $clientDiscountType = $client?->client_discount_type ?: null;
        $clientDiscountValue = $client?->client_discount_value !== null
            ? (float) $client->client_discount_value
            : null;
        $clientDiscountAmount = $this->calculateDiscountAmount(
            $serviceSubtotal,
            $clientDiscountType,
            $clientDiscountValue
        );

        $subtotalAfterClientDiscount = max($serviceSubtotal - $clientDiscountAmount, 0);
        $coupon = $this->resolveCoupon($couponCode);
        $couponDiscountType = $coupon?->type ?: null;
        $couponDiscountValue = $coupon?->amount !== null ? (float) $coupon->amount : null;
        $couponDiscountAmount = $this->calculateDiscountAmount(
            $subtotalAfterClientDiscount,
            $couponDiscountType,
            $couponDiscountValue
        );

        $discountAmount = round($clientDiscountAmount + $couponDiscountAmount, 2);
        $discountedSubtotal = round(max($serviceSubtotal - $discountAmount, 0), 2);
        $primaryDiscountType = $clientDiscountAmount > 0 ? $clientDiscountType : $couponDiscountType;
        $primaryDiscountValue = $clientDiscountAmount > 0 ? $clientDiscountValue : $couponDiscountValue;

        return [
            'discount_type' => $primaryDiscountType,
            'discount_value' => $primaryDiscountValue,
            'discount_amount' => $discountAmount,
            'discounted_subtotal' => $discountedSubtotal,
            'client_discount_type' => $clientDiscountType,
            'client_discount_value' => $clientDiscountValue,
            'client_discount_amount' => $clientDiscountAmount,
            'coupon_code' => $coupon?->code,
            'coupon_discount_type' => $couponDiscountType,
            'coupon_discount_value' => $couponDiscountValue,
            'coupon_discount_amount' => $couponDiscountAmount,
        ];
    }

    public function resolveCoupon(?string $couponCode): ?Coupon
    {
        if (!is_string($couponCode) || trim($couponCode) === '') {
            return null;
        }

        $coupon = Coupon::query()
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($couponCode))])
            ->where('is_active', true)
            ->first();

        if (!$coupon) {
            return null;
        }

        if ($coupon->valid_until && $coupon->valid_until->isPast()) {
            return null;
        }

        if ($coupon->max_uses !== null && (int) $coupon->current_uses >= (int) $coupon->max_uses) {
            return null;
        }

        return $coupon;
    }

    public function calculateDiscountAmount(float $subtotal, ?string $discountType, ?float $discountValue): float
    {
        $normalizedType = is_string($discountType) ? strtolower(trim($discountType)) : null;
        $value = $discountValue !== null ? max((float) $discountValue, 0) : 0;
        $subtotal = max($subtotal, 0);

        if ($subtotal <= 0 || !$normalizedType || $value <= 0) {
            return 0.0;
        }

        $amount = match ($normalizedType) {
            'percent', 'percentage' => $subtotal * min($value, 100) / 100,
            'fixed', '$' => $value,
            default => 0,
        };

        return round(min($amount, $subtotal), 2);
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
