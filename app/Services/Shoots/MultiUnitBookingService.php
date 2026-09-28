<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\ShootUnit;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Unit and execution identities are persisted independently of catalog IDs. */
class MultiUnitBookingService
{
    public static function rules(): array
    {
        return [
            'expected_units_revision' => 'sometimes|integer|min:0',
            'units' => 'sometimes|array|min:1|max:500',
            'units.*.id' => 'nullable|integer|distinct',
            'units.*.client_key' => 'required|string|max:100|distinct',
            'units.*.label' => 'required|string|max:120',
            'units.*.kind' => 'required|in:unit,common_area',
            'units.*.sqft' => 'nullable|integer|min:1|max:10000000',
            'units.*.beds' => 'nullable|integer|min:0|max:999',
            'units.*.baths' => 'nullable|numeric|min:0|max:999',
            'units.*.access_notes' => 'nullable|string|max:4000',
            'units.*.sort_order' => 'nullable|integer|min:0|max:100000',
            'service_lines' => 'sometimes|array|min:1|max:3000',
            'service_lines.*.shoot_service_id' => 'nullable|integer|distinct',
            'service_lines.*.client_key' => 'required|string|max:100|distinct',
            'service_lines.*.unit_client_key' => 'nullable|string|max:100',
            'service_lines.*.shoot_unit_id' => 'nullable|integer',
            'service_lines.*.service_id' => 'required|integer|exists:services,id',
            'service_lines.*.price' => 'nullable|numeric|min:0',
            'service_lines.*.quantity' => 'nullable|integer|min:1',
            'service_lines.*.scheduled_at' => 'nullable|date',
            'service_lines.*.photographer_id' => 'nullable|integer|exists:users,id',
            'service_lines.*.editor_id' => 'nullable|integer|exists:users,id',
            'service_lines.*.is_deliverable' => 'nullable|boolean',
        ];
    }

    public function handles(?Shoot $shoot, array $data): bool
    {
        return array_key_exists('units', $data) || array_key_exists('service_lines', $data)
            || ($shoot && $shoot->units()->exists());
    }

    public function prepare(?Shoot $shoot, array $data, ?User $actor): array
    {
        foreach (['services', 'service_items', 'service_photographers'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->fail($field, 'Use service_lines with booked line identities for a multi-unit shoot.');
            }
        }
        if (! $actor) {
            $this->fail('units', 'An authenticated actor is required.');
        }
        if ($shoot?->isComplimentaryReshoot()) {
            $this->fail('units', 'Use the dedicated complimentary-reshoot workflow.');
        }
        $existingUnits = $shoot?->units()->get() ?? collect();
        $existingLines = $shoot?->serviceItems()->get() ?? collect();
        if ($existingUnits->isNotEmpty() && (array_key_exists('units', $data) || array_key_exists('service_lines', $data))) {
            if (! array_key_exists('expected_units_revision', $data) || (int) $data['expected_units_revision'] !== (int) $shoot->units_revision) {
                $this->fail('expected_units_revision', 'The units changed in another session. Reload the shoot before saving.');
            }
        }
        $units = array_key_exists('units', $data) ? $data['units'] : $existingUnits->toArray();
        if (! $units) {
            $this->fail('units', 'At least one unit is required with service_lines.');
        }
        $unitIds = [];
        $labels = [];
        $unitKeys = [];
        foreach ($units as $index => &$unit) {
            $id = $unit['id'] ?? null;
            $current = $id ? $existingUnits->firstWhere('id', $id) : $existingUnits->firstWhere('client_key', $unit['client_key']);
            if ($id && ! $current) {
                $this->fail("units.$index.id", 'This unit does not belong to this shoot.');
            }
            if ($current && $current->client_key !== $unit['client_key']) {
                $this->fail("units.$index.client_key", 'The unit identity cannot be changed.');
            }
            $unit['id'] = $current?->id;
            $unit['label'] = trim($unit['label']);
            $labelKey = mb_strtolower($unit['label']);
            if ($unit['label'] === '' || isset($labels[$labelKey]) || isset($unitKeys[$unit['client_key']])) {
                $this->fail("units.$index.label", 'Use a unique, nonempty label and identity for each unit.');
            }
            $labels[$labelKey] = true;
            $unitKeys[$unit['client_key']] = true;
            if ($current) {
                if (isset($unitIds[$current->id])) {
                    $this->fail("units.$index.id", 'Duplicate unit identity.');
                }
                $unitIds[$current->id] = true;
            }
            $unit['sort_order'] = $unit['sort_order'] ?? $index;
        }
        unset($unit);
        $byKey = collect($units)->keyBy('client_key');
        $byId = collect($units)->filter(fn ($unit) => $unit['id'])->keyBy('id');
        $rawLines = array_key_exists('service_lines', $data) ? $data['service_lines'] : $existingLines->map(fn ($line) => [
            'shoot_service_id' => $line->id, 'client_key' => $line->client_key ?? 'legacy-'.$line->id,
            'shoot_unit_id' => $line->shoot_unit_id, 'service_id' => $line->service_id,
        ])->all();
        if (! $rawLines) {
            $this->fail('service_lines', 'At least one booked service line is required.');
        }
        $catalog = Service::query()->whereIn('id', collect($rawLines)->pluck('service_id'))->get()->keyBy('id');
        $assignmentRoles = User::query()->whereIn('id', collect($rawLines)
            ->flatMap(fn ($line) => [$line['photographer_id'] ?? null, $line['editor_id'] ?? null])
            ->push($data['photographer_id'] ?? $shoot?->photographer_id)->filter()->unique())
            ->pluck('role', 'id');
        $services = [];
        $scopes = [];
        $lineKeys = [];
        $keptIds = [];
        $pricing = [];
        foreach ($rawLines as $index => $line) {
            $id = $line['shoot_service_id'] ?? null;
            $current = $id ? $existingLines->firstWhere('id', $id) : $existingLines->firstWhere('client_key', $line['client_key']);
            if ($id && ! $current) {
                $this->fail("service_lines.$index.shoot_service_id", 'This service line does not belong to this shoot.');
            }
            if ($current && $current->client_key && $current->client_key !== $line['client_key']) {
                $this->fail("service_lines.$index.client_key", 'The service-line identity cannot be changed.');
            }
            $unit = ! empty($line['unit_client_key']) ? $byKey->get($line['unit_client_key']) : $byId->get($line['shoot_unit_id'] ?? $current?->shoot_unit_id);
            if (! $unit) {
                $this->fail("service_lines.$index.shoot_unit_id", 'Choose a unit belonging to this shoot.');
            }
            if (! empty($line['shoot_unit_id']) && (int) $line['shoot_unit_id'] !== (int) $unit['id']) {
                $this->fail("service_lines.$index.shoot_unit_id", 'Unit ID and client key do not match.');
            }
            $service = $catalog->get($line['service_id']);
            if (! $service) {
                $this->fail("service_lines.$index.service_id", 'Service does not exist.');
            }
            $quantity = (int) ($line['quantity'] ?? $current?->quantity ?? 1);
            app(ShootMutationSupportService::class)->assertServiceQuantityAllowed(
                $service,
                $quantity,
                $current?->quantity,
                "service_lines.$index.quantity"
            );
            if ($current && ((int) $current->service_id !== (int) $service->id || (int) $current->shoot_unit_id !== (int) $unit['id'])) {
                $this->fail("service_lines.$index", 'A booked line cannot be moved to a different unit or service. Add a new line.');
            }
            if (! $current && $unit['id'] && $existingLines->contains(fn ($existing) => (int) $existing->shoot_unit_id === (int) $unit['id'] && (int) $existing->service_id === (int) $service->id)) {
                $this->fail("service_lines.$index.shoot_service_id", 'Keep the existing booked line identity for this unit and service.');
            }
            $scope = $unit['client_key'].':'.$service->id;
            if (isset($scopes[$scope]) || isset($lineKeys[$line['client_key']]) || ($current && isset($keptIds[$current->id]))) {
                $this->fail("service_lines.$index", 'Duplicate service line within a unit.');
            }
            $scopes[$scope] = $lineKeys[$line['client_key']] = true;
            if ($current) {
                $keptIds[$current->id] = true;
            }
            $sqft = isset($unit['sqft']) ? (int) $unit['sqft'] : null;
            if (! $current && $service->pricing_type === 'variable' && ! $sqft) {
                $this->fail("service_lines.$index", 'Square footage is required to price this unit.');
            }
            $priceKey = $service->id.':'.$sqft;
            $pricing[$priceKey] ??= [
                'price' => $service->getPriceForSqft($sqft),
                'photographer_pay' => $service->getPhotographerPayForSqft($sqft),
                'duration_minutes' => $service->getShootDurationMinutes($sqft),
                'contracted_photo_count' => $service->pricing_type === 'variable' && $sqft
                    ? ($service->sqftRanges()->where('sqft_from', '<=', $sqft)->where('sqft_to', '>=', $sqft)->value('photo_count') ?? $service->contractedPhotoCount())
                    : $service->contractedPhotoCount(),
            ];
            $canOverride = in_array(strtolower($actor->role), ['admin', 'superadmin', 'super_admin'], true);
            $row = [
                'id' => $service->id, 'shoot_service_id' => $current?->id,
                'client_key' => $line['client_key'], 'unit_client_key' => $unit['client_key'],
                'shoot_unit_id' => $unit['id'], 'quantity' => $quantity,
                'price' => $canOverride && isset($line['price']) ? (float) $line['price'] : ($current?->price ?? $pricing[$priceKey]['price']),
                'photographer_pay' => $current?->photographer_pay ?? $pricing[$priceKey]['photographer_pay'],
                'duration_minutes' => $current?->duration_minutes ?? $pricing[$priceKey]['duration_minutes'],
                'contracted_photo_count' => $current ? $current->contracted_photo_count : $pricing[$priceKey]['contracted_photo_count'],
                'photographer_required' => $service->requiresPhotographer(),
                'scheduled_at' => array_key_exists('scheduled_at', $line) ? $line['scheduled_at'] : ($current ? $current->scheduled_at?->format('Y-m-d H:i:s') : ($data['scheduled_at'] ?? $shoot?->scheduled_at?->format('Y-m-d H:i:s'))),
                'photographer_id' => $service->requiresPhotographer() ? (array_key_exists('photographer_id', $line) ? $line['photographer_id'] : ($current ? $current->photographer_id : ($data['photographer_id'] ?? $shoot?->photographer_id))) : null,
                'editor_id' => array_key_exists('editor_id', $line) ? $line['editor_id'] : $current?->editor_id,
                'is_deliverable' => $line['is_deliverable'] ?? $current?->is_deliverable ?? true,
            ];
            foreach (['photographer_id' => 'photographer', 'editor_id' => 'editor'] as $field => $role) {
                if ($row[$field] && (! $current || (int) $current->{$field} !== (int) $row[$field])
                    && strtolower((string) $assignmentRoles->get($row[$field])) !== $role) {
                    $this->fail("service_lines.$index.$field", "Choose a user with the {$role} role for this assignment.");
                }
            }
            $services[] = $row;
        }
        $removed = $existingLines->reject(fn ($line) => isset($keptIds[$line->id]));
        $windows = [];
        foreach ($services as $index => $row) {
            if (! $row['scheduled_at'] || ! $row['photographer_id']) {
                continue;
            }
            $start = \Carbon\Carbon::parse($row['scheduled_at'])->getTimestamp();
            $end = $start + ((int) $row['duration_minutes']) * 60;
            foreach ($windows[$row['photographer_id']] ?? [] as $window) {
                if ($window['unit'] !== $row['unit_client_key'] && $start < $window['end'] && $end > $window['start']) {
                    $this->fail("service_lines.$index.scheduled_at", 'This photographer has overlapping unit visits. Choose separate times or another photographer.');
                }
            }
            $windows[$row['photographer_id']][] = ['unit' => $row['unit_client_key'], 'start' => $start, 'end' => $end];
        }
        foreach ($removed as $line) {
            $this->assertRemovable($line);
        }
        // A missing unit can only be removed after every one of its lines was explicitly removed.
        foreach ($existingUnits as $unit) {
            if (! isset($unitIds[$unit->id]) && $existingLines->where('shoot_unit_id', $unit->id)->contains(fn ($line) => isset($keptIds[$line->id]))) {
                $this->fail('units', 'Remove the unit service lines explicitly before removing the unit.');
            }
            if (! isset($unitIds[$unit->id]) && (! empty($unit->tour_links) || ! empty($unit->provider_data))) {
                $this->fail('units', 'This unit has tour or provider history. Keep it to preserve its links and media.');
            }
        }
        app(ShootMutationSupportService::class)->ensureClientCanBookServices((int) ($data['client_id'] ?? $shoot?->client_id ?? $actor->id), $services, $shoot);

        return ['units' => $units, 'services' => $services, 'removed_ids' => $removed->pluck('id')->all()];
    }

    public function persist(Shoot $shoot, array $prepared): void
    {
        $unitIds = [];
        foreach ($prepared['units'] as $unitData) {
            $unit = ! empty($unitData['id']) ? $shoot->units()->findOrFail($unitData['id']) : new ShootUnit(['shoot_id' => $shoot->id]);
            $unit->fill(Arr::only($unitData, ['client_key', 'label', 'kind', 'sqft', 'beds', 'baths', 'access_notes', 'sort_order']));
            $unit->save();
            $unitIds[$unit->client_key] = $unit->id;
        }
        foreach ($prepared['services'] as $row) {
            $line = $row['shoot_service_id'] ? $shoot->serviceItems()->findOrFail($row['shoot_service_id']) : new ShootService(['shoot_id' => $shoot->id]);
            $line->fill(Arr::only($row, ['client_key', 'price', 'quantity', 'photographer_pay', 'duration_minutes', 'contracted_photo_count', 'photographer_id', 'editor_id', 'is_deliverable']));
            $line->service_id = $row['id'];
            $line->shoot_unit_id = $unitIds[$row['unit_client_key']];
            $line->scheduled_at = app(ShootMutationSupportService::class)->normalizeDateTimeForDatabase($row['scheduled_at']);
            if (! $line->exists) {
                $line->workflow_status = $line->scheduled_at ? 'scheduled' : 'pending';
                $line->delivery_status = 'not_started';
            }
            $line->save();
        }
        $shoot->serviceItems()->whereIn('id', $prepared['removed_ids'])->delete();
        $shoot->units()->whereNotIn('id', array_values($unitIds))->delete();
        $shoot->units_revision = ((int) $shoot->units_revision) + 1;
        $shoot->save();
        $shoot->load(['units', 'services', 'serviceItems']);
        app(ShootMutationSupportService::class)->snapshotBracketModes($shoot);
        $shoot->syncServiceItemRollups();
    }

    private function assertRemovable(ShootService $line): void
    {
        $blocked = ! in_array($line->workflow_status, [null, 'pending', 'scheduled'], true)
            || $line->files()->exists() || $line->paymentAllocations()->exists()
            || $line->compensations()->exists() || $line->sourceForCompReshootItems()->exists();
        foreach (['shoot_media_albums', 'shoot_upload_attempts', 'editor_payouts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'shoot_service_id')) {
                $blocked = $blocked || DB::table($table)->where('shoot_service_id', $line->id)->exists();
            }
        }
        if (Schema::hasTable('studio_workspaces')) {
            $blocked = $blocked || DB::table('studio_workspaces')->where('shoot_id', $line->shoot_id)->get()->contains(function ($row) use ($line) {
                if (isset($row->shoot_service_item_ids)) {
                    return in_array($line->id, json_decode($row->shoot_service_item_ids, true) ?: [], false);
                }

                return ! $line->shoot_unit_id && in_array($line->service_id, json_decode($row->shoot_service_ids ?? '[]', true) ?: [], false);
            });
        }
        if ($blocked) {
            $this->fail('service_lines', 'This line has media, work, payment, or audit history. Keep it and use its dedicated cancellation workflow.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
