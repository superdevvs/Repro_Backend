<?php

namespace App\Services\Shoots;

use App\Models\CompReshootItem;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\ShootService;
use App\Models\User;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComplimentaryReshootService
{
    public function __construct(
        private readonly ComplimentaryReshootReasonPolicy $reasonPolicy,
        private readonly ShootCompensationCalculator $calculator,
    ) {}

    public function template(Shoot $sourceShoot, ?string $reasonCode = null): array
    {
        $sourceShoot->loadMissing([
            'client:id,name,email',
            'rep:id,name,email,role,metadata',
            'rootShoot:id,address,city,state,zip',
            'serviceItems.service',
            'serviceItems.photographer:id,name,email,role',
        ]);

        $reasonCode = in_array($reasonCode, ComplimentaryReshootReasonPolicy::REASONS, true)
            ? $reasonCode
            : ComplimentaryReshootReasonPolicy::MISSED_AREA;

        $sourceItems = $sourceShoot->serviceItems->map(function (ShootService $item) use ($sourceShoot, $reasonCode) {
            $service = $item->service;
            $quantity = max((int) ($item->quantity ?? 1), 1);
            $nominalUnit = $service
                ? $this->calculator->nominalUnitPrice($sourceShoot, $service, $item)
                : round((float) ($item->price ?? 0), 2);
            $standard = $service
                ? $this->calculator->photographerStandard($sourceShoot, $service, $quantity, $item)
                : ['amount' => 0, 'rate_snapshot' => 0, 'calculation_method' => ShootCompensation::CALCULATION_FIXED];

            return [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'name' => $service?->name ?? 'Service',
                'quantity' => $quantity,
                'nominal_unit_price' => $nominalUnit,
                'nominal_total' => round($nominalUnit * $quantity, 2),
                'standard_photographer_pay' => round((float) $standard['amount'], 2),
                'photographer' => $item->photographer ? [
                    'id' => $item->photographer->id,
                    'name' => $item->photographer->name,
                    'email' => $item->photographer->email,
                ] : null,
                'suggested_responsibility' => $this->reasonPolicy->suggestedResponsibility($reasonCode),
                'suggested_photographer_mode' => $this->reasonPolicy->suggestedMode(
                    $reasonCode,
                    ShootCompensation::RECIPIENT_PHOTOGRAPHER
                ),
            ];
        })->values();

        $commissionableBasis = round((float) $sourceShoot->serviceItems
            ->filter(fn (ShootService $item) => ! (bool) $item->service?->exclude_from_sales_commission)
            ->sum(function (ShootService $item) use ($sourceShoot) {
                $quantity = max((int) ($item->quantity ?? 1), 1);
                $unit = $item->service
                    ? $this->calculator->nominalUnitPrice($sourceShoot, $item->service, $item)
                    : (float) ($item->price ?? 0);

                return $unit * $quantity;
            }), 2);
        $repStandard = $this->calculator->salesRepStandard($commissionableBasis, $sourceShoot->rep);

        return [
            'policy_version' => ComplimentaryReshootReasonPolicy::VERSION,
            'source' => $this->shootSummary($sourceShoot),
            'parent' => $this->shootSummary($sourceShoot),
            'root' => $this->shootSummary($sourceShoot->rootShoot ?: $sourceShoot),
            'client' => $sourceShoot->client ? [
                'id' => $sourceShoot->client->id,
                'name' => $sourceShoot->client->name,
                'email' => $sourceShoot->client->email,
            ] : null,
            'property' => [
                'address' => $sourceShoot->address,
                'city' => $sourceShoot->city,
                'state' => $sourceShoot->state,
                'zip' => $sourceShoot->zip,
                'timezone' => $sourceShoot->timezone,
                'property_details' => $sourceShoot->property_details,
            ],
            'reason_options' => $this->reasonPolicy->options(),
            'responsibility_options' => CompReshootItem::RESPONSIBILITIES,
            'source_service_items' => $sourceItems,
            'catalog_services' => Service::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'price' => round((float) $service->getPriceForSqft($this->extractSqft($sourceShoot)), 2),
                    'standard_photographer_pay' => round((float) ($service->getPhotographerPayForSqft($this->extractSqft($sourceShoot)) ?? 0), 2),
                    'exclude_from_sales_commission' => (bool) $service->exclude_from_sales_commission,
                ])
                ->values(),
            'sales_rep' => $sourceShoot->rep ? [
                'id' => $sourceShoot->rep->id,
                'name' => $sourceShoot->rep->name,
                'email' => $sourceShoot->rep->email,
            ] : null,
            'sales_rep_standard' => [
                'basis_amount' => $commissionableBasis,
                'rate' => $repStandard['rate_snapshot'],
                'amount' => $repStandard['amount'],
                'suggested_mode' => $this->reasonPolicy->suggestedMode(
                    $reasonCode,
                    ShootCompensation::RECIPIENT_SALES_REP
                ),
            ],
        ];
    }

    /**
     * Translate the deliberately small Edit Shoot toggle contract into the
     * full audited complimentary-reshoot command. The linked child shoot is an
     * accounting implementation detail: it preserves a second visit for the
     * same catalog service without weakening shoot_service's one-service-per-
     * shoot invariant.
     *
     * @return array{shoot: Shoot, replayed: bool}
     */
    public function createFromEditOptions(Shoot $sourceShoot, array $options, User $actor): array
    {
        $reasonCode = (string) $options['reason_code'];
        $responsibility = $this->reasonPolicy->suggestedResponsibility($reasonCode)
            ?? CompReshootItem::RESPONSIBILITY_OTHER;
        $photographerMode = (bool) $options['pay_photographer']
            ? ShootCompensation::MODE_STANDARD
            : ShootCompensation::MODE_NONE;
        $salesRepMode = (bool) $options['pay_sales_rep']
            ? ShootCompensation::MODE_STANDARD
            : ShootCompensation::MODE_NONE;
        $rawItems = collect($options['service_items']);
        $scheduledAt = $options['scheduled_at']
            ?? $rawItems->pluck('scheduled_at')->first(fn ($value) => $value !== null && $value !== '');
        $defaultPhotographerId = $options['photographer_id']
            ?? $rawItems->pluck('photographer_id')->first(fn ($value) => $value !== null && $value !== '')
            ?? $sourceShoot->photographer_id;

        $items = $rawItems
            ->map(fn (array $item) => [
                'source_shoot_service_id' => (int) $item['source_shoot_service_id'],
                'service_id' => (int) $item['service_id'],
                'quantity' => max((int) ($item['quantity'] ?? 1), 1),
                'photographer_id' => $item['photographer_id'] ?? $defaultPhotographerId,
                'editor_id' => null,
                'scheduled_at' => $item['scheduled_at'] ?? $scheduledAt,
                'reason_code' => $reasonCode,
                'reason_note' => $options['reason_note'] ?? null,
                'responsibility' => $responsibility,
                'responsible_staff_id' => null,
                'photographer_compensation' => [
                    'mode' => $photographerMode,
                    'amount' => null,
                ],
            ])
            ->values()
            ->all();

        return $this->create($sourceShoot, [
            '_idempotency_key' => (string) ($options['idempotency_key'] ?? Str::uuid()),
            'shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT,
            'scheduled_at' => $scheduledAt,
            'scheduled_date' => $options['scheduled_date'] ?? null,
            'time' => $options['time'] ?? null,
            'timezone' => $options['timezone'] ?? $sourceShoot->timezone,
            'photographer_id' => $defaultPhotographerId,
            'reason_code' => $reasonCode,
            'reason_note' => $options['reason_note'] ?? null,
            'items' => $items,
            'sales_rep_compensation' => [
                'mode' => $salesRepMode,
                'amount' => null,
            ],
        ], $actor);
    }

    /**
     * @return array{shoot: Shoot, replayed: bool}
     */
    public function create(Shoot $sourceShoot, array $data, User $actor): array
    {
        $idempotencyKey = (string) $data['_idempotency_key'];
        $requestHash = $this->requestHash($data);

        try {
            return DB::transaction(function () use ($sourceShoot, $data, $actor, $idempotencyKey, $requestHash) {
                $sourceShoot = Shoot::query()->lockForUpdate()->findOrFail($sourceShoot->id);

                $replayed = Shoot::query()
                    ->where('complimentary_reshoot_idempotency_key', $idempotencyKey)
                    ->first();
                if ($replayed) {
                    $this->assertReplayMatches($replayed, $sourceShoot, $requestHash);
                    app(InvoiceService::class)->generateForShoot($replayed);

                    return ['shoot' => $this->loadForResponse($replayed), 'replayed' => true];
                }

                $sourceShoot->loadMissing(['serviceItems.service', 'rep']);
                $requestedItems = collect($data['items']);
                $sourceItems = ShootService::query()
                    ->with(['service', 'photographer'])
                    ->where('shoot_id', $sourceShoot->id)
                    ->whereIn('id', $requestedItems->pluck('source_shoot_service_id')->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($sourceItems->count() !== $requestedItems->count()) {
                    throw ValidationException::withMessages([
                        'items' => ['Every affected service item must belong to the selected source shoot.'],
                    ]);
                }

                $serviceIds = $requestedItems
                    ->map(fn (array $item) => (int) ($item['service_id'] ?: $sourceItems[(int) $item['source_shoot_service_id']]->service_id))
                    ->unique()
                    ->values();
                $services = Service::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
                if ($services->count() !== $serviceIds->count()) {
                    throw ValidationException::withMessages(['items' => ['One or more selected services no longer exist.']]);
                }

                $sourceRepId = (int) ($sourceShoot->rep_id ?? 0) ?: null;
                $requestedRepId = array_key_exists('rep_id', $data)
                    ? ((int) ($data['rep_id'] ?? 0) ?: null)
                    : $sourceRepId;
                if ($requestedRepId !== $sourceRepId) {
                    throw ValidationException::withMessages([
                        'rep_id' => [
                            'The sales representative is inherited from the source shoot and cannot be changed on a complimentary reshoot.',
                        ],
                    ]);
                }

                $repId = $sourceRepId;
                $defaultPhotographerId = (int) ($data['photographer_id'] ?? $sourceShoot->photographer_id ?? 0) ?: null;
                $this->assertRecipientRole($repId, ['salesrep', 'sales_rep'], 'rep_id');
                $this->assertRecipientRole($defaultPhotographerId, ['photographer'], 'photographer_id');

                $scheduledAt = $this->resolveScheduledAt($data, $sourceShoot->timezone);
                $scheduledDate = $scheduledAt?->toDateString() ?? ($data['scheduled_date'] ?? null);
                $scheduledTime = $scheduledAt?->format('H:i') ?? ($data['time'] ?? null);
                $hasSchedule = $scheduledAt !== null || $scheduledDate !== null;
                $rootShootId = $this->resolveRootShootId($sourceShoot);

                $shoot = Shoot::create([
                    'client_id' => $sourceShoot->client_id,
                    'rep_id' => $repId,
                    'photographer_id' => $defaultPhotographerId,
                    'editor_id' => $sourceShoot->editor_id,
                    'service_id' => $serviceIds->first(),
                    'address' => $sourceShoot->address,
                    'city' => $sourceShoot->city,
                    'state' => $sourceShoot->state,
                    'zip' => $sourceShoot->zip,
                    'latitude' => $sourceShoot->latitude,
                    'longitude' => $sourceShoot->longitude,
                    'property_slug' => $sourceShoot->property_slug,
                    'timezone' => $data['timezone'] ?? $sourceShoot->timezone,
                    'scheduled_at' => $scheduledAt,
                    'scheduled_date' => $scheduledDate,
                    'time' => $scheduledTime,
                    'status' => $hasSchedule ? Shoot::STATUS_SCHEDULED : Shoot::STATUS_ON_HOLD,
                    'workflow_status' => $hasSchedule ? Shoot::STATUS_SCHEDULED : Shoot::STATUS_ON_HOLD,
                    'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
                    'base_quote' => 0,
                    'discount_type' => null,
                    'discount_value' => null,
                    'discount_amount' => 0,
                    'tax_region' => $sourceShoot->tax_region,
                    'tax_percent' => 0,
                    'tax_amount' => 0,
                    'total_quote' => 0,
                    'payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
                    'payment_type' => 'complimentary',
                    'bypass_paywall' => true,
                    'shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT,
                    'product_status' => Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT,
                    'reshoot_of_shoot_id' => $sourceShoot->id,
                    'root_shoot_id' => $rootShootId,
                    'complimentary_reshoot_idempotency_key' => $idempotencyKey,
                    'complimentary_reshoot_request_hash' => $requestHash,
                    'property_details' => $sourceShoot->property_details,
                    'mls_id' => $sourceShoot->mls_id,
                    'listing_source' => $sourceShoot->listing_source,
                    'listing_type' => $sourceShoot->listing_type,
                    'package_name' => 'Complimentary reshoot',
                    'shoot_notes' => $data['reason_note'] ?? null,
                    'created_by' => $actor->name,
                    'updated_by' => $actor->name,
                ]);

                $createdItems = collect();
                foreach ($requestedItems as $itemIndex => $itemData) {
                    $sourceItem = $sourceItems[(int) $itemData['source_shoot_service_id']];
                    $serviceId = (int) ($itemData['service_id'] ?: $sourceItem->service_id);
                    $service = $services[$serviceId];
                    $quantity = max((int) ($itemData['quantity'] ?? 1), 1);
                    $nominalUnit = $this->calculator->nominalUnitPrice($sourceShoot, $service, $sourceItem);
                    $nominalTotal = round($nominalUnit * $quantity, 2);
                    $photographerId = (int) ($itemData['photographer_id']
                        ?? $defaultPhotographerId
                        ?? $sourceItem->photographer_id
                        ?? $sourceShoot->photographer_id
                        ?? 0) ?: null;
                    $this->assertRecipientRole($photographerId, ['photographer'], 'items.photographer_id');

                    $itemScheduledAt = ! empty($itemData['scheduled_at'])
                        ? Carbon::parse($itemData['scheduled_at'])
                        : $scheduledAt;
                    $childItem = ShootService::create([
                        'shoot_id' => $shoot->id,
                        'service_id' => $serviceId,
                        'photographer_id' => $photographerId,
                        'editor_id' => $itemData['editor_id'] ?? $sourceItem->editor_id,
                        'price' => 0,
                        'nominal_value_snapshot' => $nominalTotal,
                        'quantity' => $quantity,
                        // Explicit compensation rows are authoritative. Zero prevents
                        // a legacy fallback from accidentally paying a comp twice.
                        'photographer_pay' => 0,
                        'bracket_mode' => $sourceItem->bracket_mode,
                        'scheduled_at' => $itemScheduledAt,
                        'workflow_status' => $itemScheduledAt ? ShootService::WORKFLOW_SCHEDULED : ShootService::WORKFLOW_PENDING,
                        'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
                        'is_deliverable' => true,
                    ]);

                    $reasonCode = (string) $itemData['reason_code'];
                    $responsibility = (string) $itemData['responsibility'];
                    $responsibleStaffId = $itemData['responsible_staff_id'] ?? null;
                    if (! $responsibleStaffId && $responsibility === CompReshootItem::RESPONSIBILITY_PHOTOGRAPHER) {
                        $responsibleStaffId = $sourceItem->photographer_id ?: $sourceShoot->photographer_id;
                    }

                    CompReshootItem::create([
                        'shoot_id' => $shoot->id,
                        'shoot_service_id' => $childItem->id,
                        'source_shoot_service_id' => $sourceItem->id,
                        'service_id_snapshot' => $service->id,
                        'service_name_snapshot' => $service->name,
                        'source_service_id_snapshot' => $sourceItem->service_id,
                        'source_service_name_snapshot' => $sourceItem->service?->name,
                        'nominal_unit_price_snapshot' => $nominalUnit,
                        'quantity_snapshot' => $quantity,
                        'nominal_total_snapshot' => $nominalTotal,
                        'reason_code' => $reasonCode,
                        'reason_note' => $itemData['reason_note'] ?? null,
                        'responsibility' => $responsibility,
                        'responsible_staff_id' => $responsibleStaffId,
                        'created_by' => $actor->id,
                    ]);

                    $standard = $this->calculator->photographerStandard($sourceShoot, $service, $quantity, $sourceItem);
                    $mode = (string) data_get($itemData, 'photographer_compensation.mode');
                    if (! $photographerId && $mode !== ShootCompensation::MODE_NONE) {
                        throw ValidationException::withMessages([
                            "items.{$itemIndex}.photographer_id" => [
                                'Assign the service photographer before choosing Standard or Custom compensation.',
                            ],
                        ]);
                    }

                    $resolved = $this->calculator->resolveAmount(
                        $mode,
                        $standard,
                        data_get($itemData, 'photographer_compensation.amount')
                    );
                    $suggestedMode = $this->reasonPolicy->suggestedMode(
                        $reasonCode,
                        ShootCompensation::RECIPIENT_PHOTOGRAPHER
                    );
                    $suggested = $suggestedMode
                        ? $this->calculator->resolveAmount($suggestedMode, $standard)
                        : null;

                    ShootCompensation::create([
                        'shoot_id' => $shoot->id,
                        'shoot_service_id' => $childItem->id,
                        'scope_key' => ShootCompensation::serviceScopeKey($childItem->id),
                        'recipient_type' => ShootCompensation::RECIPIENT_PHOTOGRAPHER,
                        'recipient_user_id' => $photographerId,
                        'mode' => $mode,
                        'suggested_mode' => $suggestedMode,
                        'calculation_method' => $resolved['calculation_method'],
                        'standard_calculation_method' => $standard['calculation_method'],
                        'quantity_snapshot' => $quantity,
                        'basis_amount_snapshot' => $nominalTotal,
                        'rate_snapshot' => $resolved['rate_snapshot'],
                        'standard_rate_snapshot' => $standard['rate_snapshot'],
                        'amount' => $resolved['amount'],
                        'suggested_amount' => $suggested['amount'] ?? null,
                        'standard_amount_snapshot' => $standard['amount'],
                        'currency' => 'USD',
                        'reason_code' => $reasonCode,
                        'policy_version' => ComplimentaryReshootReasonPolicy::VERSION,
                        'metadata' => [
                            'source_shoot_id' => $sourceShoot->id,
                            'source_shoot_service_id' => $sourceItem->id,
                            'service_id' => $service->id,
                        ],
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);

                    $createdItems->push([
                        'child' => $childItem,
                        'service' => $service,
                        'nominal_total' => $nominalTotal,
                    ]);
                }

                $rep = $repId ? User::find($repId) : null;
                $commissionableItems = $createdItems->filter(
                    fn (array $row) => ! (bool) $row['service']->exclude_from_sales_commission
                );
                $basis = round((float) $commissionableItems->sum('nominal_total'), 2);
                $standard = $this->calculator->salesRepStandard($basis, $rep);
                $mode = (string) data_get($data, 'sales_rep_compensation.mode');
                if (! $repId && $mode !== ShootCompensation::MODE_NONE) {
                    throw ValidationException::withMessages([
                        'sales_rep_compensation.mode' => [
                            'The source shoot has no sales representative to receive Standard or Custom compensation.',
                        ],
                    ]);
                }

                $resolved = $this->calculator->resolveAmount(
                    $mode,
                    $standard,
                    data_get($data, 'sales_rep_compensation.amount')
                );
                $reasonCode = (string) $data['reason_code'];
                $suggestedMode = $this->reasonPolicy->suggestedMode(
                    $reasonCode,
                    ShootCompensation::RECIPIENT_SALES_REP
                );
                $suggested = $suggestedMode
                    ? $this->calculator->resolveAmount($suggestedMode, $standard)
                    : null;

                ShootCompensation::create([
                    'shoot_id' => $shoot->id,
                    'shoot_service_id' => null,
                    'scope_key' => ShootCompensation::shootScopeKey(),
                    'recipient_type' => ShootCompensation::RECIPIENT_SALES_REP,
                    'recipient_user_id' => $repId,
                    'mode' => $mode,
                    'suggested_mode' => $suggestedMode,
                    'calculation_method' => $resolved['calculation_method'],
                    'standard_calculation_method' => $standard['calculation_method'],
                    'quantity_snapshot' => 1,
                    'basis_amount_snapshot' => $basis,
                    'rate_snapshot' => $resolved['rate_snapshot'],
                    'standard_rate_snapshot' => $standard['rate_snapshot'],
                    'amount' => $resolved['amount'],
                    'suggested_amount' => $suggested['amount'] ?? null,
                    'standard_amount_snapshot' => $standard['amount'],
                    'currency' => 'USD',
                    'reason_code' => $reasonCode,
                    'policy_version' => ComplimentaryReshootReasonPolicy::VERSION,
                    'metadata' => [
                        'source_shoot_id' => $sourceShoot->id,
                        'commissionable_shoot_service_ids' => $commissionableItems
                            ->pluck('child.id')
                            ->values()
                            ->all(),
                    ],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                // Receipt generation is itself keyed by shoot and rebuilds the
                // same document on replay, so booking and accounting remain
                // atomic without ever creating duplicate client documents.
                app(InvoiceService::class)->generateForShoot($shoot);

                return ['shoot' => $this->loadForResponse($shoot), 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            $replayed = Shoot::query()
                ->where('complimentary_reshoot_idempotency_key', $idempotencyKey)
                ->first();
            if (! $replayed) {
                throw $exception;
            }

            $this->assertReplayMatches($replayed, $sourceShoot, $requestHash);
            app(InvoiceService::class)->generateForShoot($replayed);

            return ['shoot' => $this->loadForResponse($replayed), 'replayed' => true];
        }
    }

    public function get(Shoot $shoot): Shoot
    {
        $this->assertComplimentaryReshoot($shoot);

        return $this->loadForResponse($shoot);
    }

    public function updateCompensations(Shoot $shoot, array $updates, User $actor): Shoot
    {
        return DB::transaction(function () use ($shoot, $updates, $actor) {
            $shoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            $this->assertComplimentaryReshoot($shoot);
            $shoot->loadMissing(['rep', 'compReshootItems']);

            $updatesById = collect($updates)->keyBy(fn (array $row) => (int) $row['id']);
            $compensations = ShootCompensation::query()
                ->with(['serviceItem.service', 'recipient', 'invoiceItem.invoice'])
                ->where('shoot_id', $shoot->id)
                ->whereIn('id', $updatesById->keys()->all())
                ->lockForUpdate()
                ->get();

            if ($compensations->count() !== $updatesById->count()) {
                throw ValidationException::withMessages([
                    'compensations' => ['Every compensation row must belong to this complimentary reshoot.'],
                ]);
            }

            foreach ($compensations as $compensation) {
                $update = $updatesById[(int) $compensation->id];
                if ($compensation->locked_at || $compensation->isSettlementLocked()) {
                    throw new \App\Exceptions\PublicConflictException(
                        'Compensation is locked because the work was earned or its payout was approved.',
                        409
                    );
                }

                if (! empty($update['expected_updated_at'])
                    && ! Carbon::parse($update['expected_updated_at'])->equalTo($compensation->updated_at)) {
                    throw new \App\Exceptions\PublicConflictException('Compensation changed in another session. Refresh and try again.', 409);
                }

                $mode = (string) $update['mode'];
                $standard = [
                    'calculation_method' => $compensation->standard_calculation_method,
                    'rate_snapshot' => $compensation->standard_rate_snapshot === null
                        ? null
                        : (float) $compensation->standard_rate_snapshot,
                    'amount' => (float) $compensation->standard_amount_snapshot,
                ];

                $resolved = $this->calculator->resolveAmount($mode, $standard, $update['amount'] ?? null);
                $compensation->update([
                    'mode' => $mode,
                    'calculation_method' => $resolved['calculation_method'],
                    'rate_snapshot' => $resolved['rate_snapshot'],
                    'amount' => $resolved['amount'],
                    'updated_by' => $actor->id,
                ]);
            }

            return $this->loadForResponse($shoot);
        }, 3);
    }

    /**
     * Add an immutable correction or reversal beside a locked compensation.
     * The original decision is never changed, even when its payout is already
     * approved or paid; the new signed line is earned into the next payout run.
     *
     * @return array{compensation: ShootCompensation, replayed: bool}
     */
    public function createCompensationAdjustment(
        Shoot $shoot,
        ShootCompensation $original,
        array $data,
        User $actor
    ): array {
        return DB::transaction(function () use ($shoot, $original, $data, $actor): array {
            $shoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            $this->assertComplimentaryReshoot($shoot);

            $original = ShootCompensation::query()
                ->with(['invoiceItem.invoice', 'adjustmentLines'])
                ->where('shoot_id', $shoot->id)
                ->whereKey($original->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (($original->line_type ?? ShootCompensation::LINE_TYPE_BASE) !== ShootCompensation::LINE_TYPE_BASE) {
                throw ValidationException::withMessages([
                    'compensation' => ['Corrections and reversals must reference the original compensation decision.'],
                ]);
            }

            if (! $original->locked_at && ! $original->earned_at && ! $original->invoiceItem) {
                throw ValidationException::withMessages([
                    'compensation' => ['This compensation is still editable. Update the original decision instead.'],
                ]);
            }

            if (! $original->recipient_user_id) {
                throw ValidationException::withMessages([
                    'compensation' => ['Assign a payout recipient before creating a compensation correction.'],
                ]);
            }

            $lineType = (string) $data['line_type'];
            $magnitude = round((float) $data['amount'], 2);
            $signedAmount = $lineType === ShootCompensation::LINE_TYPE_REVERSAL
                ? -$magnitude
                : $magnitude;
            $idempotencyKey = trim((string) $data['idempotency_key']);
            $scopeKey = sprintf(
                'adjustment:%d:%s',
                $original->id,
                substr(hash('sha256', $idempotencyKey), 0, 32)
            );
            $requestHash = hash('sha256', json_encode([
                'line_type' => $lineType,
                'amount' => $signedAmount,
                'note' => trim((string) $data['note']),
            ], JSON_UNESCAPED_SLASHES));

            $replayed = ShootCompensation::query()
                ->where('shoot_id', $shoot->id)
                ->where('recipient_type', $original->recipient_type)
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();
            if ($replayed) {
                if ((string) data_get($replayed->metadata, 'adjustment_request_hash') !== $requestHash) {
                    throw new \App\Exceptions\PublicConflictException(
                        'This Idempotency-Key was already used for a different compensation adjustment.',
                        409
                    );
                }

                return ['compensation' => $replayed, 'replayed' => true];
            }

            if ($lineType === ShootCompensation::LINE_TYPE_REVERSAL) {
                $currentNet = round(
                    (float) $original->amount
                    + (float) $original->adjustmentLines
                        ->whereNull('voided_at')
                        ->sum(fn (ShootCompensation $line) => (float) $line->amount),
                    2
                );
                if ($magnitude > $currentNet + 0.001) {
                    throw ValidationException::withMessages([
                        'amount' => ['A reversal cannot exceed the remaining compensation balance.'],
                    ]);
                }
            }

            // Corrections belong to the current accounting period. Reusing the
            // original line's (possibly closed) earning date would make a late
            // correction invisible to the next normal payout run.
            $earnedAt = now();
            $metadata = is_array($original->metadata) ? $original->metadata : [];
            $metadata['adjustment'] = [
                'original_compensation_id' => $original->id,
                'line_type' => $lineType,
                'note' => trim((string) $data['note']),
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
                'created_at' => now()->toIso8601String(),
            ];
            $metadata['adjustment_request_hash'] = $requestHash;

            $adjustment = ShootCompensation::create([
                'shoot_id' => $shoot->id,
                'shoot_service_id' => $original->shoot_service_id,
                'scope_key' => $scopeKey,
                'line_type' => $lineType,
                'adjusts_compensation_id' => $original->id,
                'recipient_type' => $original->recipient_type,
                'recipient_user_id' => $original->recipient_user_id,
                'mode' => ShootCompensation::MODE_CUSTOM,
                'suggested_mode' => null,
                'calculation_method' => ShootCompensation::CALCULATION_FIXED,
                'standard_calculation_method' => $original->standard_calculation_method,
                'quantity_snapshot' => 1,
                'basis_amount_snapshot' => $original->basis_amount_snapshot,
                'rate_snapshot' => $signedAmount,
                'standard_rate_snapshot' => $original->standard_rate_snapshot,
                'amount' => $signedAmount,
                'suggested_amount' => null,
                'standard_amount_snapshot' => 0,
                'currency' => $original->currency,
                'reason_code' => $original->reason_code,
                'policy_version' => $original->policy_version,
                'metadata' => $metadata,
                'earned_at' => $earnedAt,
                'locked_at' => $earnedAt,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            return ['compensation' => $adjustment, 'replayed' => false];
        }, 3);
    }

    private function loadForResponse(Shoot $shoot): Shoot
    {
        return $shoot->fresh([
            'client:id,name,email',
            'rep:id,name,email,role',
            'photographer:id,name,email,role',
            'reshootOf:id,address,city,state,zip,shoot_type,reshoot_of_shoot_id,root_shoot_id',
            'rootShoot:id,address,city,state,zip,shoot_type',
            'serviceItems.service',
            'serviceItems.photographer:id,name,email,role',
            'compReshootItems.responsibleStaff:id,name,email,role',
            'compReshootItems.sourceServiceItem.service',
            'compensations.recipient:id,name,email,role',
            'compensations.serviceItem.service',
            'compensations.invoiceItem.invoice',
            'editorPayouts',
        ]);
    }

    private function assertReplayMatches(Shoot $shoot, Shoot $sourceShoot, string $requestHash): void
    {
        if (! $shoot->isComplimentaryReshoot()
            || (int) $shoot->reshoot_of_shoot_id !== (int) $sourceShoot->id
            || ! hash_equals((string) $shoot->complimentary_reshoot_request_hash, $requestHash)) {
            throw new \App\Exceptions\PublicConflictException('This Idempotency-Key was already used for a different reshoot request.', 409);
        }
    }

    private function resolveRootShootId(Shoot $sourceShoot): int
    {
        $seen = [];
        $lineage = [];
        $current = $sourceShoot;

        while ($current) {
            $currentId = (int) $current->id;
            if (isset($seen[$currentId])) {
                throw ValidationException::withMessages([
                    'source_shoot' => ['The selected source shoot lineage contains a cycle.'],
                ]);
            }

            $seen[$currentId] = true;
            $lineage[] = $current;
            $parentId = (int) ($current->reshoot_of_shoot_id ?? 0);
            $current = $parentId
                ? Shoot::query()->lockForUpdate()->find($parentId)
                : null;

            if ($parentId && ! $current) {
                throw ValidationException::withMessages([
                    'source_shoot' => ['The selected source shoot lineage is incomplete.'],
                ]);
            }
        }

        /** @var Shoot $root */
        $root = collect($lineage)->last();
        $rootId = (int) $root->id;

        foreach ($lineage as $lineageShoot) {
            $storedRootId = (int) ($lineageShoot->root_shoot_id ?? 0);
            if ($storedRootId && $storedRootId !== $rootId) {
                throw ValidationException::withMessages([
                    'source_shoot' => ['The selected source shoot has inconsistent root lineage.'],
                ]);
            }
        }

        return $rootId;
    }

    private function assertComplimentaryReshoot(Shoot $shoot): void
    {
        if (! $shoot->isComplimentaryReshoot()) {
            throw ValidationException::withMessages([
                'shoot' => ['Compensation decisions are available only for complimentary reshoots.'],
            ]);
        }
    }

    private function assertRecipientRole(?int $userId, array $allowedRoles, string $field): void
    {
        if (! $userId) {
            return;
        }

        $user = User::find($userId);
        $normalizedRole = strtolower((string) $user?->role);
        if (! $user || ! in_array($normalizedRole, $allowedRoles, true)) {
            throw ValidationException::withMessages([
                $field => ['The selected person does not have the required payout role.'],
            ]);
        }
    }

    private function resolveScheduledAt(array $data, ?string $fallbackTimezone): ?Carbon
    {
        if (! empty($data['scheduled_at'])) {
            return Carbon::parse($data['scheduled_at']);
        }

        if (empty($data['scheduled_date']) || empty($data['time'])) {
            return null;
        }

        return Carbon::parse(
            $data['scheduled_date'].' '.$data['time'],
            $data['timezone'] ?? $fallbackTimezone ?? config('app.timezone')
        );
    }

    private function requestHash(array $data): string
    {
        unset($data['_idempotency_key']);
        $data['items'] = collect($data['items'] ?? [])
            ->sortBy('source_shoot_service_id')
            ->values()
            ->all();

        return hash('sha256', json_encode($this->canonicalize($data), JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (Arr::isAssoc($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    private function shootSummary(?Shoot $shoot): ?array
    {
        if (! $shoot) {
            return null;
        }

        return [
            'id' => $shoot->id,
            'address' => $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'zip' => $shoot->zip,
            'shoot_type' => $shoot->shoot_type,
        ];
    }

    private function extractSqft(Shoot $shoot): ?int
    {
        $value = data_get($shoot->property_details, 'sqft')
            ?? data_get($shoot->property_details, 'squareFeet');

        return is_numeric($value) ? (int) $value : null;
    }
}
