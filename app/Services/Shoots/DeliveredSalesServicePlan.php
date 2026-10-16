<?php

namespace App\Services\Shoots;

use App\Models\{Service, Shoot, ShootService, User};
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Billing corrections never reserve another visit or reopen completed production. */
class DeliveredSalesServicePlan
{
    public function applies(Shoot $shoot, ?User $actor, array $data): bool
    {
        return app(ShootManagementAccess::class)->canCorrectDeliveredServices($shoot, $actor)
            && array_intersect(['services', 'service_items', 'service_lines'], array_keys($data)) !== [];
    }

    public function services(Shoot $shoot, array $data, User $actor): array
    {
        $items = $shoot->serviceItems()->get();
        $units = $shoot->units()->get();
        $multiUnit = $units->isNotEmpty();
        if ($multiUnit && (! array_key_exists('service_lines', $data) || isset($data['services']) || isset($data['service_items']))) {
            $this->fail('Use booked service-line identities for this multi-unit work order.');
        }
        if ($multiUnit && (! array_key_exists('expected_units_revision', $data)
            || (int) $data['expected_units_revision'] !== (int) $shoot->units_revision)) {
            $this->fail('The units changed in another session. Reload the shoot before saving.');
        }
        $rows = $data['service_lines'] ?? $data['service_items'] ?? $data['services'];
        $catalog = Service::with('category')->whereIn('id', collect($rows)->map(fn ($row) => $row['service_id'] ?? $row['id'] ?? 0))->get()->keyBy('id');
        $seen = [];
        $services = [];
        foreach ($rows as $index => $row) {
            $serviceId = (int) ($row['service_id'] ?? $row['id'] ?? 0);
            $service = $catalog->get($serviceId);
            if (! $service) $this->fail('Choose an existing service.');
            $current = $multiUnit
                ? $items->first(fn ($item) => isset($row['shoot_service_id']) ? (int) $row['shoot_service_id'] === (int) $item->id : isset($row['client_key']) && $row['client_key'] === $item->client_key)
                : $items->firstWhere('service_id', $serviceId);
            if (! empty($row['shoot_service_id']) && ! $current) $this->fail('This service line does not belong to the work order.');
            $unit = $multiUnit ? (! empty($row['unit_client_key']) ? $units->firstWhere('client_key', $row['unit_client_key']) : $units->firstWhere('id', $row['shoot_unit_id'] ?? $current?->shoot_unit_id)) : null;
            if ($multiUnit && (! $unit || (! empty($row['shoot_unit_id']) && (int) $row['shoot_unit_id'] !== (int) $unit->id))) $this->fail('Choose an existing unit belonging to this work order.');
            if ($current && ((int) $current->service_id !== $serviceId || (int) $current->shoot_unit_id !== (int) $unit?->id)) $this->fail('A booked line cannot change service or unit. Remove it and add a new line.');
            $scope = ($unit?->id ?? 'order').':'.$serviceId;
            if (isset($seen[$scope])) $this->fail('Select each service once per unit.');
            $seen[$scope] = true;
            if (! $current && $items->contains(fn ($item) => (int) $item->service_id === $serviceId && (int) $item->shoot_unit_id === (int) $unit?->id)) $this->fail('Keep the existing booked line identity.');
            $quantity = (int) ($row['quantity'] ?? $current?->quantity ?? 1);
            app(ShootMutationSupportService::class)->assertServiceQuantityAllowed($service, $quantity, $current?->quantity, "services.$index.quantity");
            $sqft = $multiUnit ? $unit->sqft : $shoot->propertySqft();
            if (! $current && $service->pricing_type === 'variable' && ! $sqft) $this->fail('Square footage is required to price this service.');
            $services[] = [
                'id' => $serviceId, 'shoot_service_id' => $current?->id,
                'client_key' => $current?->client_key ?? ($multiUnit ? ($row['client_key'] ?? (string) Str::uuid()) : null),
                'unit_client_key' => $unit?->client_key, 'shoot_unit_id' => $unit?->id,
                'quantity' => $quantity, 'price' => $current?->price ?? $service->getPriceForSqft($sqft),
                'photographer_pay' => $current ? $current->photographer_pay : $service->getPhotographerPayForSqft($sqft),
                'duration_minutes' => $current ? $current->duration_minutes : app(ShootDurationResolver::class)->forService($service, $sqft),
                'contracted_photo_count' => $current ? $current->contracted_photo_count : $service->contractedPhotoCount(),
                'scheduled_at' => $current?->scheduled_at?->format('Y-m-d H:i:s'),
                'photographer_id' => $current?->photographer_id, 'editor_id' => $current?->editor_id,
                'created_at' => $current?->created_at,
            ];
        }
        app(ShootMutationSupportService::class)->ensureClientCanBookServices((int) ($data['client_id'] ?? $shoot->client_id), $services, $shoot, $actor);
        return $services;
    }

    public function persist(Shoot $shoot, array $services): void
    {
        $kept = [];
        foreach ($services as $row) {
            if ($row['shoot_service_id']) {
                $item = $shoot->serviceItems()->findOrFail($row['shoot_service_id']);
                $item->quantity = $row['quantity'];
                if ($item->isDirty()) {
                    if (is_array($item->payout_snapshot)) {
                        $basis = $item->payout_snapshot;
                        $basis['previous_basis'] = Arr::except($basis, ['previous_basis']);
                        $basis['quantity'] = $row['quantity'];
                        $basis['review_required'] = true;
                        $item->payout_snapshot = $basis;
                    }
                    // Keep the agreed per-unit payout rather than sampling a
                    // changed catalogue rate during a billing correction.
                    $item->saveQuietly();
                }
            } else {
                $item = new ShootService(Arr::only($row, ['client_key', 'shoot_unit_id', 'quantity', 'price', 'photographer_pay', 'duration_minutes', 'contracted_photo_count']));
                $item->shoot_id = $shoot->id;
                $item->service_id = $row['id'];
                $item->scheduled_at = null;
                $item->photographer_id = $item->editor_id = $item->video_editor_id = null;
                $item->workflow_status = 'pending';
                $item->delivery_status = 'not_started';
                $item->is_deliverable = true;
                $item->payout_snapshot = ['billing_correction_only' => true];
                $item->save();
            }
            $kept[] = $item->id;
        }
        foreach ($shoot->serviceItems()->whereNotIn('id', $kept)->get() as $removed) $removed->delete();
        if ($shoot->units()->exists()) $shoot->units_revision = ((int) $shoot->units_revision) + 1;
        $shoot->service_id = $services[0]['id'] ?? null;
        $shoot->load(['services', 'serviceItems']);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['services' => [$message]]);
    }
}
