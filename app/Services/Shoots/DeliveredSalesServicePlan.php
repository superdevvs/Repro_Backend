<?php

namespace App\Services\Shoots;

use App\Models\{Service, Shoot, ShootService, User};
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Preserve completed visits while separating new work from billing-only corrections. */
class DeliveredSalesServicePlan
{
    public function applies(Shoot $shoot, ?User $actor, array $data): bool
    {
        if (app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)
            && ($data['service_change_intent'] ?? 'new_work') !== 'billing_correction') {
            $existing = $shoot->serviceItems()->pluck('service_id')->map(fn ($id) => (int) $id)->all();
            $rows = $data['services'] ?? $data['service_lines'] ?? $data['service_items'] ?? [];
            $hasNew = collect($rows)->contains(fn ($row) => ! in_array((int) ($row['service_id'] ?? $row['id'] ?? 0), $existing, true));
            if (! $hasNew || app(ActiveServiceEditGuard::class)->changesCapture($shoot, $data)) return false;
        }
        return ((app(ShootManagementAccess::class)->canCorrectDeliveredServices($shoot, $actor)
                || (app(ShootManagementAccess::class)->canEdit($shoot, $actor)
                    && ($shoot->admin_verified_at || app(ShootManagementAccess::class)->isDeliveredForSalesEdits($shoot)
                        || app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot))))
            && array_intersect(['services', 'service_items', 'service_lines'], array_keys($data)) !== [])
            || app(PostCaptureServiceCorrection::class)->applies($shoot, $actor, $data);
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
        $rows = $data['service_lines'] ?? (array_key_exists('services', $data)
            ? app(ShootMutationSupportService::class)->mergeServiceItemPayload($data['services'], $data['service_items'] ?? null,
                $data['service_photographers'] ?? null, null, true)
            : $data['service_items']);
        if (! array_key_exists('services', $data) && ! array_key_exists('service_lines', $data)) {
            $submitted = collect($rows)->keyBy(fn ($row) => (int) ($row['service_id'] ?? 0));
            $rows = $items->map(fn ($item) => $submitted->get((int) $item->service_id) ?? ['service_id' => $item->service_id])
                ->merge($submitted->except($items->pluck('service_id')->all())->values())->all();
        }
        $catalog = Service::with('category')->whereIn('id', collect($rows)->map(fn ($row) => $row['service_id'] ?? $row['id'] ?? 0))->get()->keyBy('id');
        $seen = [];
        $services = [];
        $canOverridePrice = in_array(strtolower((string) $actor->role), ['admin', 'superadmin', 'super_admin'], true);
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
                'quantity' => $quantity, 'price' => $canOverridePrice && isset($row['price']) ? $row['price'] : ($current?->price ?? $service->getPriceForSqft($sqft)),
                'photographer_pay' => $canOverridePrice && isset($row['photographer_pay']) ? $row['photographer_pay'] : ($current ? $current->photographer_pay : $service->getPhotographerPayForSqft($sqft)),
                'duration_minutes' => $current ? $current->duration_minutes : app(ShootDurationResolver::class)->forService($service, $sqft),
                'contracted_photo_count' => $current ? $current->contracted_photo_count : $service->contractedPhotoCount(),
                'scheduled_at' => $current?->scheduled_at?->format('Y-m-d H:i:s'),
                'photographer_id' => $current?->photographer_id, 'editor_id' => $current?->editor_id,
                'created_at' => $current?->created_at,
                'billing_correction_only' => ($data['service_change_intent'] ?? 'new_work') === 'billing_correction',
            ];
        }
        app(ShootMutationSupportService::class)->ensureClientCanBookServices((int) ($data['client_id'] ?? $shoot->client_id), $services, $shoot, $actor);
        return app(CompletedPhotoPackageReplacement::class)->resolve($shoot, $services);
    }

    public function persist(Shoot $shoot, array $services): void
    {
        $kept = [];
        $newWork = false;
        foreach ($services as $row) {
            if (! empty($row['replaces_shoot_service_id'])) {
                $item = app(CompletedPhotoPackageReplacement::class)->persist($shoot, $row);
            } elseif ($row['shoot_service_id']) {
                $item = $shoot->serviceItems()->findOrFail($row['shoot_service_id']);
                $item->quantity = $row['quantity'];
                $item->price = $row['price'];
                $item->photographer_pay = $row['photographer_pay'];
                if ($item->isDirty()) {
                    if (is_array($item->payout_snapshot)) {
                        $basis = $item->payout_snapshot;
                        $basis['previous_basis'] = Arr::except($basis, ['previous_basis']);
                        $basis['quantity'] = $row['quantity'];
                        $basis['booked_price'] = (float) $row['price'];
                        if ($item->isDirty('photographer_pay') && $row['photographer_pay'] !== null) {
                            $basis['unit_pay'] = (float) $row['photographer_pay'];
                            $basis['override'] = (float) $row['photographer_pay'];
                            $basis['pay_type'] = 'override';
                        }
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
                $item->payout_snapshot = ['billing_correction_only' => (bool) ($row['billing_correction_only'] ?? true),
                    'additional_work' => ! (bool) ($row['billing_correction_only'] ?? true)];
                $newWork = $newWork || ! $item->payout_snapshot['billing_correction_only'];
                $item->save();
            }
            $kept[] = $item->id;
        }
        foreach ($shoot->serviceItems()->whereNotIn('id', $kept)->get() as $removed) $removed->delete();
        if ($shoot->units()->exists()) $shoot->units_revision = ((int) $shoot->units_revision) + 1;
        $shoot->service_id = $services[0]['id'] ?? null;
        $shoot->load(['services', 'serviceItems']);
        if ($newWork && ($shoot->admin_verified_at || app(ShootManagementAccess::class)->isDeliveredForSalesEdits($shoot))) {
            app(ShootActiveWorkLifecycle::class)->begin($shoot);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['services' => [$message]]);
    }
}
