<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ClientBillingService
{
    public function getClientBilling(User $client): array
    {
        $invoices = Invoice::query()
            ->with([
                'client',
                'photographer',
                'payments',
                'shoot',
                'shoot.client',
                'shoot.photographer',
                'shoot.payments',
                'shoots',
                'shoots.client',
                'shoots.photographer',
                'shoots.payments',
                'items',
                'items.shoot',
                'items.shoot.client',
                'items.shoot.photographer',
                'items.shoot.payments',
            ])
            ->where(function ($query) use ($client) {
                $query->where('client_id', $client->id)
                    ->orWhereHas('shoot', function ($shootQuery) use ($client) {
                        $shootQuery->where('client_id', $client->id);
                    })
                    ->orWhereHas('shoots', function ($shootQuery) use ($client) {
                        $shootQuery->where('client_id', $client->id);
                    })
                    ->orWhereHas('items.shoot', function ($shootQuery) use ($client) {
                        $shootQuery->where('client_id', $client->id);
                    });
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Invoice $invoice) => $this->isClientBillableInvoice($invoice, $client->id))
            ->values();

        $invoicedShootIds = [];
        $invoiceItems = $invoices->map(function (Invoice $invoice) use ($client, &$invoicedShootIds) {
            $relatedShoots = $this->collectInvoiceShoots($invoice, $client->id);
            foreach ($relatedShoots as $shoot) {
                $invoicedShootIds[$shoot->id] = true;
            }

            return $this->buildInvoiceItem($invoice, $client, $relatedShoots);
        });

        $shootItems = Shoot::query()
            ->with(['client', 'photographer', 'payments'])
            ->where('client_id', $client->id)
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
            ->get()
            ->reject(fn (Shoot $shoot) => isset($invoicedShootIds[$shoot->id]))
            ->map(fn (Shoot $shoot) => $this->buildShootBalanceItem($shoot, $client))
            ->filter()
            ->values();

        $items = $invoiceItems
            ->concat($shootItems)
            ->sort(fn (array $left, array $right) => $this->compareBillingItems($left, $right))
            ->values();

        return [
            'summary' => $this->buildSummary($items),
            'items' => $items->all(),
        ];
    }

    private function isClientBillableInvoice(Invoice $invoice, int $clientId): bool
    {
        if ((int) $invoice->client_id === $clientId) {
            return true;
        }

        return $this->normalizeInvoiceRole($invoice->role) === Invoice::ROLE_CLIENT
            && $this->collectInvoiceShoots($invoice, $clientId)->isNotEmpty();
    }

    private function collectInvoiceShoots(Invoice $invoice, int $clientId): Collection
    {
        $shoots = collect();

        if ($invoice->shoot && (int) $invoice->shoot->client_id === $clientId) {
            $shoots->push($invoice->shoot);
        }

        if ($invoice->relationLoaded('shoots')) {
            $shoots = $shoots->concat(
                $invoice->shoots->filter(fn (Shoot $shoot) => (int) $shoot->client_id === $clientId)
            );
        }

        if ($invoice->relationLoaded('items')) {
            $shoots = $shoots->concat(
                $invoice->items
                    ->map(fn ($item) => $item->shoot)
                    ->filter(fn ($shoot) => $shoot instanceof Shoot && (int) $shoot->client_id === $clientId)
            );
        }

        return $shoots
            ->unique(fn (Shoot $shoot) => $shoot->id)
            ->values();
    }

    private function buildInvoiceItem(Invoice $invoice, User $client, Collection $relatedShoots): array
    {
        $amount = $this->roundMoney(
            $invoice->total
            ?? $invoice->total_amount
            ?? $invoice->charges_total
            ?? $invoice->subtotal
            ?? 0
        );

        [$amountPaid, $balance] = $this->resolveInvoicePaidAndBalance($invoice, $amount, $relatedShoots);

        $issueDate = $this->normalizeDate($invoice->issue_date ?? $invoice->billing_period_start ?? $invoice->created_at);
        $paymentRequired = $invoice->requiresPayment();
        $dueDate = $paymentRequired
            ? $this->normalizeDate($invoice->due_date ?? $invoice->billing_period_end ?? $invoice->period_end)
            : null;
        $paymentRequiredToRelease = $paymentRequired && $balance > 0
            && $relatedShoots->contains(fn (Shoot $shoot) => $this->isReleaseBlockedShoot($shoot));
        $bucket = $paymentRequired
            ? $this->resolveBucket($balance, $dueDate, $paymentRequiredToRelease, $relatedShoots->first())
            : 'no_payment_required';
        $status = $paymentRequired
            ? $this->resolveStatus($balance, $dueDate)
            : 'no_payment_required';
        $primaryShoot = $relatedShoots->first() ?: $invoice->shoot;

        $resolvedPayment = $invoice->resolvePaymentMetadata();
        $paymentData = [
            'method' => $resolvedPayment['payment_method'] ?? null,
            'details' => $resolvedPayment['payment_details'] ?? null,
        ];

        return [
            'id' => 'invoice-' . $invoice->id,
            'source' => 'invoice',
            'sourceLabel' => 'Invoice',
            'documentType' => $invoice->document_type,
            'paymentRequired' => $paymentRequired,
            'invoiceId' => $invoice->id,
            'shootId' => $primaryShoot?->id,
            'number' => $invoice->invoice_number ?: (string) $invoice->id,
            'property' => $this->describeProperty($relatedShoots, $primaryShoot),
            'issueDate' => $issueDate,
            'dueDate' => $dueDate,
            'amount' => $amount,
            'amountPaid' => $amountPaid,
            'balance' => $balance,
            'status' => $status,
            'rawStatus' => $invoice->status,
            'bucket' => $bucket,
            'paymentRequiredToRelease' => $paymentRequiredToRelease,
            'paymentMethod' => $paymentData['method'],
            'paymentDetails' => $paymentData['details'],
            'client' => $invoice->client?->name ?: $client->name,
            'clientId' => $client->id,
            'photographer' => $invoice->photographer?->name ?: $primaryShoot?->photographer?->name,
            'photographerId' => $invoice->photographer_id ?: $primaryShoot?->photographer_id,
            'services' => $this->extractInvoiceServices($invoice),
            'items' => $this->serializeInvoiceItems($invoice),
            'shoot' => $primaryShoot ? $this->serializeShoot($primaryShoot) : null,
            'shoots' => $relatedShoots->map(fn (Shoot $shoot) => $this->serializeShoot($shoot))->all(),
            'notes' => $invoice->notes,
            'paidAt' => $this->normalizeDateTime($invoice->paid_at ?: $this->latestRelatedShootPaidAt($relatedShoots)),
        ];
    }


    /**
     * @return array{0: float, 1: float}
     */
    private function resolveInvoicePaidAndBalance(Invoice $invoice, float $amount, Collection $relatedShoots): array
    {
        $invoicePaid = $this->roundMoney($invoice->totalPaid());
        $shootPaid = $this->roundMoney(
            $relatedShoots->sum(fn (Shoot $shoot) => $shoot->calculateCanonicalTotalPaid())
        );
        // Invoice and shoot payment rows can describe the same cash. Take the
        // larger live total instead of adding them.
        $amountPaid = max($invoicePaid, $shootPaid);

        $hasLivePayments = $invoice->hasRelatedPaymentRecords()
            || $relatedShoots->contains(
                fn (Shoot $shoot) => $shoot->getCanonicalCompletedPayments()->isNotEmpty()
            );

        // Stored amount_paid / payments_total are a legacy fallback only when
        // neither the invoice nor its related shoots have payment records.
        if (! $hasLivePayments && $amountPaid <= 0 && $invoice->getAttribute('amount_paid') !== null) {
            $amountPaid = $this->roundMoney($invoice->getAttribute('amount_paid'));
        }
        if (! $hasLivePayments && $amountPaid <= 0 && $invoice->getAttribute('payments_total') !== null) {
            $amountPaid = $this->roundMoney($invoice->getAttribute('payments_total'));
        }

        if ($this->relatedShootsAreFullyPaid($relatedShoots)) {
            $amountPaid = max($amountPaid, $amount);

            return [$amountPaid, 0.0];
        }

        return [$amountPaid, $this->roundMoney(max($amount - $amountPaid, 0))];
    }

    private function relatedShootsAreFullyPaid(Collection $relatedShoots): bool
    {
        return $relatedShoots->isNotEmpty()
            && $relatedShoots->every(fn (Shoot $shoot) => $this->isShootFullyPaid($shoot));
    }

    private function isShootFullyPaid(Shoot $shoot): bool
    {
        $status = strtolower((string) ($shoot->payment_status ?? ''));
        if (in_array($status, ['paid', 'full', Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED], true)) {
            return true;
        }

        $quote = (float) ($shoot->total_quote ?? 0);
        if ($quote <= 0.01) {
            return true;
        }

        return $shoot->calculateCanonicalTotalPaid() + 0.01 >= $quote;
    }

    private function latestRelatedShootPaidAt(Collection $relatedShoots): mixed
    {
        return $relatedShoots
            ->map(fn (Shoot $shoot) => $this->latestCompletedPayment($shoot)?->processed_at)
            ->filter()
            ->sortByDesc(function ($value) {
                if ($value instanceof Carbon) {
                    return $value->timestamp;
                }

                return strtotime((string) $value) ?: 0;
            })
            ->first();
    }

    private function buildShootBalanceItem(Shoot $shoot, User $client): ?array
    {
        $amount = $this->roundMoney($shoot->total_quote ?? 0);
        $amountPaid = $this->roundMoney($shoot->calculateCanonicalTotalPaid());
        $balance = $this->roundMoney(max($amount - $amountPaid, 0));

        if ($amount <= 0 && $amountPaid <= 0) {
            return null;
        }

        $paymentRequiredToRelease = $balance > 0 && $this->isReleaseBlockedShoot($shoot);
        $bucket = $balance <= 0
            ? 'paid'
            : ($paymentRequiredToRelease ? 'due_now' : 'upcoming');
        $status = $balance <= 0 ? 'paid' : 'pending';
        $paymentData = $this->resolvePaymentMetadata(null, null, collect([$shoot]));

        return [
            'id' => 'shoot-' . $shoot->id,
            'source' => 'shoot_balance',
            'sourceLabel' => 'Shoot balance',
            'invoiceId' => null,
            'shootId' => $shoot->id,
            'number' => null,
            'property' => $this->describeProperty(collect([$shoot]), $shoot),
            'issueDate' => $this->normalizeDate($shoot->created_at ?? $shoot->scheduled_date),
            'dueDate' => $this->normalizeDate($shoot->scheduled_date),
            'amount' => $amount,
            'amountPaid' => $amountPaid,
            'balance' => $balance,
            'status' => $status,
            'rawStatus' => $shoot->payment_status,
            'bucket' => $bucket,
            'paymentRequiredToRelease' => $paymentRequiredToRelease,
            'paymentMethod' => $paymentData['method'],
            'paymentDetails' => $paymentData['details'],
            'client' => $shoot->client?->name ?: $client->name,
            'clientId' => $client->id,
            'photographer' => $shoot->photographer?->name,
            'photographerId' => $shoot->photographer_id,
            'services' => ['Shoot balance'],
            'items' => [[
                'id' => 'shoot-balance-' . $shoot->id,
                'description' => 'Shoot balance',
                'quantity' => 1,
                'unit_amount' => $amount,
                'total_amount' => $amount,
                'type' => 'charge',
                'shoot_id' => $shoot->id,
                'meta' => [
                    'source' => 'shoot_balance',
                    'service_name' => 'Shoot balance',
                ],
            ]],
            'shoot' => $this->serializeShoot($shoot),
            'shoots' => [$this->serializeShoot($shoot)],
            'notes' => $shoot->notes,
            'paidAt' => $this->normalizeDateTime($this->latestCompletedPayment($shoot)?->processed_at),
        ];
    }

    private function buildSummary(Collection $items): array
    {
        $summary = [
            'dueNow' => ['amount' => 0.0, 'count' => 0],
            'upcoming' => ['amount' => 0.0, 'count' => 0],
            'paid' => ['amount' => 0.0, 'count' => 0],
            'noPaymentRequired' => ['amount' => 0.0, 'count' => 0],
            'paymentRequiredToReleaseCount' => 0,
        ];

        foreach ($items as $item) {
            $amount = $item['bucket'] === 'paid'
                ? ($item['amountPaid'] > 0 ? $item['amountPaid'] : $item['amount'])
                : $item['balance'];

            if ($item['bucket'] === 'due_now') {
                $summary['dueNow']['amount'] += $amount;
                $summary['dueNow']['count']++;
            } elseif ($item['bucket'] === 'upcoming') {
                $summary['upcoming']['amount'] += $amount;
                $summary['upcoming']['count']++;
            } elseif ($item['bucket'] === 'no_payment_required') {
                $summary['noPaymentRequired']['count']++;
            } else {
                $summary['paid']['amount'] += $amount;
                $summary['paid']['count']++;
            }

            if ($item['paymentRequiredToRelease'] && $item['bucket'] !== 'paid') {
                $summary['paymentRequiredToReleaseCount']++;
            }
        }

        $summary['dueNow']['amount'] = $this->roundMoney($summary['dueNow']['amount']);
        $summary['upcoming']['amount'] = $this->roundMoney($summary['upcoming']['amount']);
        $summary['paid']['amount'] = $this->roundMoney($summary['paid']['amount']);
        $summary['noPaymentRequired']['amount'] = 0.0;

        return $summary;
    }

    private function compareBillingItems(array $left, array $right): int
    {
        $bucketOrder = [
            'due_now' => 0,
            'upcoming' => 1,
            'paid' => 2,
            'no_payment_required' => 3,
        ];

        $leftBucket = $bucketOrder[$left['bucket']] ?? 99;
        $rightBucket = $bucketOrder[$right['bucket']] ?? 99;

        if ($leftBucket !== $rightBucket) {
            return $leftBucket <=> $rightBucket;
        }

        $leftDate = $left['bucket'] === 'paid'
            ? ($left['paidAt'] ?? $left['issueDate'] ?? $left['dueDate'])
            : ($left['dueDate'] ?? $left['issueDate'] ?? $left['paidAt']);
        $rightDate = $right['bucket'] === 'paid'
            ? ($right['paidAt'] ?? $right['issueDate'] ?? $right['dueDate'])
            : ($right['dueDate'] ?? $right['issueDate'] ?? $right['paidAt']);

        $leftTimestamp = $leftDate ? strtotime((string) $leftDate) : 0;
        $rightTimestamp = $rightDate ? strtotime((string) $rightDate) : 0;

        if ($left['bucket'] === 'paid') {
            return $rightTimestamp <=> $leftTimestamp;
        }

        return $leftTimestamp <=> $rightTimestamp;
    }

    private function resolveBucket(float $balance, ?string $dueDate, bool $paymentRequiredToRelease, ?Shoot $shoot): string
    {
        if ($balance <= 0) {
            return 'paid';
        }

        if ($paymentRequiredToRelease) {
            return 'due_now';
        }

        $due = $dueDate ? Carbon::parse($dueDate)->startOfDay() : null;
        if ($due && $due->lessThanOrEqualTo(now()->startOfDay())) {
            return 'due_now';
        }

        if ($shoot && $this->isUpcomingShoot($shoot)) {
            return 'upcoming';
        }

        return $due ? 'upcoming' : 'due_now';
    }

    private function resolveStatus(float $balance, ?string $dueDate): string
    {
        if ($balance <= 0) {
            return 'paid';
        }

        $due = $dueDate ? Carbon::parse($dueDate)->startOfDay() : null;
        if ($due && $due->lessThan(now()->startOfDay())) {
            return 'overdue';
        }

        return 'pending';
    }

    private function isReleaseBlockedShoot(Shoot $shoot): bool
    {
        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status ?: ''));

        foreach (['delivered', 'admin_verified', 'ready', 'ready_for_client', 'completed', 'finalized'] as $keyword) {
            if (str_contains($status, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isUpcomingShoot(Shoot $shoot): bool
    {
        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status ?: ''));
        foreach (['requested', 'scheduled', 'booked', 'editing', 'uploaded', 'on_hold'] as $keyword) {
            if (str_contains($status, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePaymentMetadata(
        mixed $method,
        mixed $details,
        Collection $relatedShoots
    ): array {
        $resolvedDetails = is_array($details) ? $details : null;
        if ($method) {
            return [
                'method' => (string) $method,
                'details' => $resolvedDetails,
            ];
        }

        $completedPayments = $relatedShoots
            ->flatMap(fn (Shoot $shoot) => $shoot->getCanonicalCompletedPayments())
            ->filter()
            ->values();
        $latestPayment = $completedPayments
            ->sortByDesc(fn (Payment $payment) => optional($payment->processed_at)->timestamp ?? 0)
            ->first();
        $paymentBreakdown = $this->buildPaymentBreakdown($completedPayments);
        $resolvedMethod = $latestPayment?->payment_method;

        if (count($paymentBreakdown) > 1) {
            $resolvedMethod = 'mixed';
        } elseif (count($paymentBreakdown) === 1) {
            $resolvedMethod = $paymentBreakdown[0]['method'] ?? $resolvedMethod;
        }

        if ($paymentBreakdown !== []) {
            $resolvedDetails = $resolvedDetails ?? [];
            $resolvedDetails['payment_breakdown'] = $paymentBreakdown;
        }

        return [
            'method' => $resolvedMethod,
            'details' => $resolvedDetails ?? $latestPayment?->payment_details,
        ];
    }

    private function latestCompletedPayment(Shoot $shoot): ?Payment
    {
        return $shoot->getCanonicalCompletedPayments()
            ->sortByDesc(fn (Payment $payment) => optional($payment->processed_at)->timestamp ?? 0)
            ->first();
    }

    private function buildPaymentBreakdown(Collection $payments): array
    {
        return $payments
            ->groupBy(fn (Payment $payment) => $this->normalizePaymentMethodKey($payment->payment_method))
            ->map(function (Collection $groupedPayments, string $method) {
                $latestPayment = $groupedPayments
                    ->sortByDesc(fn (Payment $payment) => optional($payment->processed_at)->timestamp ?? 0)
                    ->first();

                return [
                    'method' => $method,
                    'amount' => round((float) $groupedPayments->sum('amount'), 2),
                    'details' => is_array($latestPayment?->payment_details) ? $latestPayment->payment_details : null,
                    'paid_at' => $this->normalizeDateTime($latestPayment?->processed_at),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function normalizePaymentMethodKey(?string $method): string
    {
        $normalized = strtolower(trim((string) $method));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'manual' => 'other',
            'bank_transfer' => 'ach',
            'cheque' => 'check',
            '', null => 'other',
            default => $normalized,
        };
    }

    private function extractInvoiceServices(Invoice $invoice): array
    {
        if (!$invoice->relationLoaded('items')) {
            return [];
        }

        return $invoice->items
            ->map(fn ($item) => trim((string) ($item->description ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    private function serializeInvoiceItems(Invoice $invoice): array
    {
        if (!$invoice->relationLoaded('items')) {
            return [];
        }

        return $invoice->items->map(function ($item) {
            return [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_amount' => $this->roundMoney($item->unit_amount ?? 0),
                'total_amount' => $this->roundMoney($item->total_amount ?? 0),
                'type' => $item->type,
                'shoot_id' => $item->shoot_id,
                'meta' => $item->meta,
            ];
        })->all();
    }

    private function serializeShoot(Shoot $shoot): array
    {
        return [
            'id' => $shoot->id,
            'client_id' => $shoot->client_id,
            'photographer_id' => $shoot->photographer_id,
            'address' => $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'zip' => $shoot->zip,
            'status' => $shoot->status,
            'workflow_status' => $shoot->workflow_status,
            'scheduled_date' => $this->normalizeDate($shoot->scheduled_date),
            'client' => $shoot->client ? [
                'id' => $shoot->client->id,
                'name' => $shoot->client->name,
                'email' => $shoot->client->email,
            ] : null,
            'photographer' => $shoot->photographer ? [
                'id' => $shoot->photographer->id,
                'name' => $shoot->photographer->name,
            ] : null,
            'location' => [
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'fullAddress' => $this->formatShootAddress($shoot),
            ],
        ];
    }

    private function describeProperty(Collection $shoots, ?Shoot $fallbackShoot = null): string
    {
        $shootAddresses = $shoots
            ->map(fn (Shoot $shoot) => $this->formatShootAddress($shoot))
            ->filter()
            ->unique()
            ->values();

        if ($shootAddresses->count() === 1) {
            return (string) $shootAddresses->first();
        }

        if ($shootAddresses->count() > 1) {
            $first = (string) $shootAddresses->first();
            return sprintf('%s (+%d more)', $first, $shootAddresses->count() - 1);
        }

        if ($fallbackShoot) {
            return $this->formatShootAddress($fallbackShoot) ?: 'Property';
        }

        return 'Property';
    }

    private function formatShootAddress(?Shoot $shoot): ?string
    {
        if (!$shoot) {
            return null;
        }

        $parts = array_filter([
            $shoot->address,
            $shoot->city,
            trim(implode(' ', array_filter([$shoot->state, $shoot->zip]))),
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function roundMoney(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function normalizeInvoiceRole(?string $role): ?string
    {
        if (!$role) {
            return null;
        }

        return strtolower(trim($role));
    }
}
