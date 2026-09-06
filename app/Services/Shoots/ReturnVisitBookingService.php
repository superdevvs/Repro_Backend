<?php

namespace App\Services\Shoots;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnVisitBookingService
{
    public function __construct(
        private readonly ComplimentaryReshootService $complimentaryReshoots,
        private readonly ShootMutationSupportService $support,
        private readonly ShootCompensationCalculator $compensationCalculator,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Book the follow-up selected from Edit Shoot. Complimentary visits retain
     * their established audited $0 path; a client-paid visit is a distinct
     * standard shoot in the same lineage and receives an ordinary invoice.
     *
     * @return array{shoot: Shoot, replayed: bool, classification: string, invoice_id: int|null}
     */
    public function createFromEditOptions(Shoot $sourceShoot, array $options, User $actor): array
    {
        if (! (bool) ($options['client_pays'] ?? false)) {
            $result = $this->complimentaryReshoots->createFromEditOptions($sourceShoot, $options, $actor);
            $invoiceId = Invoice::query()
                ->where('shoot_id', $result['shoot']->id)
                ->where('role', Invoice::ROLE_CLIENT)
                ->value('id');

            return [
                ...$result,
                'classification' => 'complimentary_reshoot',
                'invoice_id' => $invoiceId ? (int) $invoiceId : null,
            ];
        }

        return $this->createBillableAdditionalWork($sourceShoot, $options, $actor);
    }

    /**
     * @return array{shoot: Shoot, replayed: bool, classification: string, invoice_id: int|null}
     */
    private function createBillableAdditionalWork(Shoot $sourceShoot, array $options, User $actor): array
    {
        $idempotencyKey = (string) $options['idempotency_key'];
        $command = $this->normalizeBillableCommand($options);
        $requestHash = $this->requestHash($command);

        try {
            return DB::transaction(function () use (
                $sourceShoot,
                $actor,
                $command,
                $idempotencyKey,
                $requestHash,
            ): array {
                $sourceShoot = Shoot::query()->lockForUpdate()->findOrFail($sourceShoot->id);

                $replayed = Shoot::query()
                    ->where('complimentary_reshoot_idempotency_key', $idempotencyKey)
                    ->first();
                if ($replayed) {
                    $this->assertBillableReplayMatches($replayed, $sourceShoot, $requestHash);
                    $invoice = $this->invoiceService->generateForShoot($replayed);

                    return [
                        'shoot' => $this->loadForResponse($replayed),
                        'replayed' => true,
                        'classification' => 'additional_work',
                        'invoice_id' => $invoice?->id,
                    ];
                }

                $sourceShoot->loadMissing(['client', 'rep', 'serviceItems.service']);
                $requestedItems = collect($command['service_items']);
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

                $serviceIds = $requestedItems->pluck('service_id')->map(fn ($id) => (int) $id)->unique()->values();
                $services = Service::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
                if ($services->count() !== $serviceIds->count()) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more selected services no longer exist.'],
                    ]);
                }

                // A client-paid return is an ordinary booking financially. It
                // must pass the same catalog-visibility and deliverable-email
                // gates as the normal booking action before an invoice and
                // payment link are created.
                $this->support->ensureClientCanBookServices(
                    (int) $sourceShoot->client_id,
                    $serviceIds->map(fn (int $id) => ['id' => $id])->all(),
                );
                $sourceShoot->setRelation(
                    'client',
                    $this->support->ensureClientHasDeliverableEmail((int) $sourceShoot->client_id),
                );

                $repId = (int) ($sourceShoot->rep_id ?? 0) ?: null;
                $defaultPhotographerId = (int) (
                    $command['photographer_id']
                    ?? $sourceShoot->photographer_id
                    ?? 0
                ) ?: null;
                $this->assertRecipientRole($repId, ['salesrep', 'sales_rep'], 'rep_id');
                $this->assertRecipientRole($defaultPhotographerId, ['photographer'], 'photographer_id');

                if ((bool) $command['pay_sales_rep'] && ! $repId) {
                    throw ValidationException::withMessages([
                        'complimentary_service_options.pay_sales_rep' => [
                            'The source shoot has no sales representative to pay.',
                        ],
                    ]);
                }

                $scheduledAt = $this->resolveScheduledAt($command, $sourceShoot->timezone);
                $scheduledDate = $scheduledAt?->toDateString() ?? ($command['scheduled_date'] ?? null);
                $scheduledTime = $scheduledAt?->format('H:i') ?? ($command['time'] ?? null);
                $hasSchedule = $scheduledAt !== null || $scheduledDate !== null;
                $rootShootId = $this->resolveRootShootId($sourceShoot);

                $servicePayload = $requestedItems->map(function (array $item, int $index) use (
                    $sourceShoot,
                    $sourceItems,
                    $services,
                    $defaultPhotographerId,
                    $scheduledAt,
                    $command,
                ): array {
                    $sourceItem = $sourceItems[(int) $item['source_shoot_service_id']];
                    $service = $services[(int) $item['service_id']];
                    $quantity = max((int) ($item['quantity'] ?? 1), 1);
                    $photographerId = (int) (
                        $item['photographer_id']
                        ?? $defaultPhotographerId
                        ?? $sourceItem->photographer_id
                        ?? $sourceShoot->photographer_id
                        ?? 0
                    ) ?: null;
                    $this->assertRecipientRole(
                        $photographerId,
                        ['photographer'],
                        "complimentary_service_options.service_items.{$index}.photographer_id"
                    );

                    if ((bool) $command['pay_photographer'] && ! $photographerId) {
                        throw ValidationException::withMessages([
                            "complimentary_service_options.service_items.{$index}.photographer_id" => [
                                'Assign the service photographer before enabling photographer pay.',
                            ],
                        ]);
                    }

                    // The client price is the live catalog/square-footage price.
                    // A submitted price is never accepted by this contract.
                    $unitPrice = round((float) $service->getPriceForSqft($this->extractSqft($sourceShoot)), 2);
                    $standardPay = $this->compensationCalculator->photographerStandard(
                        $sourceShoot,
                        $service,
                        $quantity,
                        (int) $sourceItem->service_id === (int) $service->id ? $sourceItem : null,
                    );
                    $photographerUnitPay = (bool) $command['pay_photographer']
                        ? round((float) $standardPay['amount'] / $quantity, 2)
                        : 0.0;

                    return [
                        'id' => $service->id,
                        'price' => $unitPrice,
                        'quantity' => $quantity,
                        'photographer_pay' => $photographerUnitPay,
                        'photographer_id' => $photographerId,
                        'editor_id' => $sourceItem->editor_id,
                        'scheduled_at' => $item['scheduled_at'] ?? $scheduledAt,
                        'workflow_status' => ($item['scheduled_at'] ?? $scheduledAt)
                            ? ShootService::WORKFLOW_SCHEDULED
                            : ShootService::WORKFLOW_PENDING,
                        'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
                        'is_deliverable' => true,
                    ];
                })->values()->all();

                $pricing = $this->support->buildPricingCalculation(
                    $servicePayload,
                    $sourceShoot->client,
                    $sourceShoot->state,
                    $sourceShoot->tax_region ?: null,
                );
                if ((float) $pricing['total_quote'] <= 0.01) {
                    throw ValidationException::withMessages([
                        'complimentary_service_options.client_pays' => [
                            'Client pay requires a positive server-calculated total. Leave it off for a no-charge return visit.',
                        ],
                    ]);
                }

                $reason = $this->reasonSummary(
                    (string) $command['reason_code'],
                    $command['reason_note'] ?? null,
                );
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
                    'timezone' => $command['timezone'] ?? $sourceShoot->timezone,
                    'scheduled_at' => $scheduledAt,
                    'scheduled_date' => $scheduledDate,
                    'time' => $scheduledTime,
                    'status' => $hasSchedule ? Shoot::STATUS_SCHEDULED : Shoot::STATUS_ON_HOLD,
                    'workflow_status' => $hasSchedule ? Shoot::STATUS_SCHEDULED : Shoot::STATUS_ON_HOLD,
                    'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
                    'base_quote' => $pricing['base_quote'],
                    'discount_type' => $pricing['discount_type'],
                    'discount_value' => $pricing['discount_value'],
                    'discount_amount' => $pricing['discount_amount'],
                    'tax_region' => $pricing['tax_region'],
                    'tax_percent' => $pricing['tax_percent'],
                    'tax_amount' => $pricing['tax_amount'],
                    'total_quote' => $pricing['total_quote'],
                    'payment_status' => 'unpaid',
                    'bypass_paywall' => false,
                    'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
                    'product_status' => Shoot::PRODUCT_STATUS_HAS_PRODUCT,
                    'reshoot_of_shoot_id' => $sourceShoot->id,
                    'root_shoot_id' => $rootShootId,
                    // Reuse the deployed unique request key so retries cannot
                    // create a second visit. Its name is legacy; its behavior is
                    // now the return-visit idempotency boundary.
                    'complimentary_reshoot_idempotency_key' => $idempotencyKey,
                    'complimentary_reshoot_request_hash' => $requestHash,
                    'sales_rep_pay_enabled' => (bool) $command['pay_sales_rep'],
                    'property_details' => $sourceShoot->property_details,
                    'mls_id' => $sourceShoot->mls_id,
                    'listing_source' => $sourceShoot->listing_source,
                    'listing_type' => $sourceShoot->listing_type,
                    'property_status' => $sourceShoot->property_status,
                    'package_name' => 'Reshoot / Additional Work',
                    'company_notes' => $reason,
                    'created_by' => $actor->name,
                    'updated_by' => $actor->name,
                ]);

                $this->support->attachServices($shoot, $servicePayload);
                $invoice = $this->invoiceService->generateForShoot($shoot);

                return [
                    'shoot' => $this->loadForResponse($shoot),
                    'replayed' => false,
                    'classification' => 'additional_work',
                    'invoice_id' => $invoice?->id,
                ];
            }, 3);
        } catch (QueryException $exception) {
            $replayed = Shoot::query()
                ->where('complimentary_reshoot_idempotency_key', $idempotencyKey)
                ->first();
            if (! $replayed) {
                throw $exception;
            }

            $this->assertBillableReplayMatches($replayed, $sourceShoot, $requestHash);
            $invoice = $this->invoiceService->generateForShoot($replayed);

            return [
                'shoot' => $this->loadForResponse($replayed),
                'replayed' => true,
                'classification' => 'additional_work',
                'invoice_id' => $invoice?->id,
            ];
        }
    }

    private function assertBillableReplayMatches(Shoot $shoot, Shoot $sourceShoot, string $requestHash): void
    {
        if ($shoot->shoot_type !== Shoot::SHOOT_TYPE_STANDARD
            || (int) $shoot->reshoot_of_shoot_id !== (int) $sourceShoot->id
            || ! hash_equals((string) $shoot->complimentary_reshoot_request_hash, $requestHash)) {
            throw new \App\Exceptions\PublicConflictException('This Idempotency-Key was already used for a different return-visit request.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function normalizeBillableCommand(array $options): array
    {
        $serviceItems = collect($options['service_items'])
            ->map(fn (array $item) => [
                'source_shoot_service_id' => (int) $item['source_shoot_service_id'],
                'service_id' => (int) $item['service_id'],
                'quantity' => max((int) ($item['quantity'] ?? 1), 1),
                'photographer_id' => $item['photographer_id'] ?? null,
                'scheduled_at' => $item['scheduled_at'] ?? null,
            ])
            ->sortBy('source_shoot_service_id')
            ->values();
        $scheduledAt = $options['scheduled_at']
            ?? $serviceItems->pluck('scheduled_at')->first(fn ($value) => ! empty($value));

        return [
            'client_pays' => true,
            'reason_code' => (string) $options['reason_code'],
            'reason_note' => $options['reason_note'] ?? null,
            'pay_photographer' => (bool) $options['pay_photographer'],
            'pay_sales_rep' => (bool) $options['pay_sales_rep'],
            'scheduled_at' => $scheduledAt,
            'scheduled_date' => $options['scheduled_date'] ?? null,
            'time' => $options['time'] ?? null,
            'timezone' => $options['timezone'] ?? null,
            'photographer_id' => $options['photographer_id'] ?? null,
            'service_items' => $serviceItems->all(),
        ];
    }

    /** @param array<string, mixed> $command */
    private function requestHash(array $command): string
    {
        return hash('sha256', json_encode($this->canonicalize($command), JSON_UNESCAPED_SLASHES));
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

    /** @param array<string, mixed> $data */
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
            $data['timezone'] ?? $fallbackTimezone ?? config('app.timezone'),
        );
    }

    private function reasonSummary(string $reasonCode, ?string $note): string
    {
        $label = str($reasonCode)->replace('_', ' ')->title()->toString();
        $note = trim((string) $note);

        return $note !== '' ? "Return visit reason: {$label} — {$note}" : "Return visit reason: {$label}";
    }

    private function extractSqft(Shoot $shoot): ?int
    {
        $details = is_array($shoot->property_details) ? $shoot->property_details : [];
        $value = $details['sqft'] ?? $details['squareFeet'] ?? $details['square_feet'] ?? null;

        return is_numeric($value) ? (int) $value : null;
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
        ]);
    }
}
