<?php

namespace App\Services\ListingStudio;

use App\Contracts\ListingStudio\ListingStudioSourceResolver;
use App\Exceptions\PublicApiException;
use App\Models\ListingStudioQuote;
use App\Models\User;
use App\Services\Studio\VirtualStagingOptions;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ListingStudioQuoteService
{
    public function __construct(
        private readonly ListingStudioCatalogService $catalog,
        private readonly ListingStudioCreditReservationService $reservations,
        private readonly ListingStudioCreditService $credits,
        private readonly ListingStudioSourceResolver $sources,
    ) {}

    public function create(User $client, array $input): ListingStudioQuote
    {
        ListingStudioClientAccess::assert($client);
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in(['ai_edit', 'human_revision'])],
            'service' => ['required', Rule::in(array_keys(config('listing-studio.services', [])))],
            'source' => ['required', 'array:kind,id'],
            'source.kind' => ['required', Rule::in(['upload', 'paid_item', 'claimed_trial'])],
            'source.id' => ['required', 'uuid'],
            'options' => ['sometimes', 'array:roomType,style'],
            'options.roomType' => ['sometimes', Rule::in(array_keys(VirtualStagingOptions::ROOMS))],
            'options.style' => ['sometimes', Rule::in(array_keys(VirtualStagingOptions::STYLES))],
            'instructions' => ['sometimes', 'string', 'max:4000'],
        ])->validate();
        $kind = $data['kind'];
        $service = $data['service'];
        $sourceKind = $data['source']['kind'];
        $options = $data['options'] ?? [];
        if (($kind === 'ai_edit' && $sourceKind !== 'upload')
            || ($kind === 'human_revision' && ! in_array($sourceKind, ['paid_item', 'claimed_trial'], true))
            || ($kind === 'ai_edit' && array_key_exists('instructions', $data))
            || (($kind === 'human_revision' || $service !== 'staging') && $options !== [])
            || ($kind === 'human_revision' && trim($data['instructions'] ?? '') === '')) {
            throw new PublicApiException('Choose an eligible photo and supported editing options.', 'validation_failed', 422);
        }
        if ($kind === 'ai_edit' && $service === 'staging') {
            $options = ['roomType' => $options['roomType'] ?? 'living', 'style' => $options['style'] ?? 'modern'];
        }
        $this->catalog->assertAvailable($kind, $service);
        $snapshot = $this->sources->resolve($client, $kind, $sourceKind, $data['source']['id']);
        if (($snapshot['kind'] ?? null) !== $sourceKind || ($snapshot['id'] ?? null) !== $data['source']['id']
            || (int) ($snapshot['clientId'] ?? 0) !== (int) $client->id
            || ($kind === 'human_revision' && ($snapshot['service'] ?? null) !== $service)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $snapshot['sha256'] ?? '')) {
            throw new PublicApiException('The source photo is unavailable.', 'source_unavailable', 422);
        }

        // File inspection happens above, outside the short immutable quote write.
        return LockedWrite::run(fn () => DB::transaction(function () use ($client, $kind, $service, $sourceKind, $snapshot, $options, $data) {
            $client = User::findOrFail($client->id);
            ListingStudioClientAccess::assert($client);
            $this->catalog->assertAvailable($kind, $service);
            $selection = $this->reservations->selection($client);
            $subscription = $selection['creditSubscription'];
            $expires = CarbonImmutable::now()->addMinutes(15);
            if ($subscription?->current_period_end && $subscription->current_period_end->lt($expires)) {
                $expires = CarbonImmutable::instance($subscription->current_period_end);
            }
            if (! empty($snapshot['expiresAt'])) {
                $sourceExpiry = CarbonImmutable::parse($snapshot['expiresAt']);
                if ($sourceExpiry->lt($expires)) {
                    $expires = $sourceExpiry;
                }
            }
            if ($expires->lte(now())) {
                throw new PublicApiException('The source photo has expired. Upload it again.', 'source_unavailable', 422);
            }
            $pricing = $this->catalog->price($kind, $service, $subscription?->plan_code);
            $instructions = $kind === 'human_revision' ? trim($data['instructions']) : null;

            return ListingStudioQuote::create(['client_id' => $client->id, 'kind' => $kind, 'service' => $service,
                'source_kind' => $sourceKind, 'source_id' => $snapshot['id'], 'source_snapshot' => $snapshot,
                'options' => $options, 'instructions' => $instructions, 'pricing_snapshot' => $pricing,
                'subscription_id' => $subscription?->id, 'catalog_version' => $this->catalog->version(),
                'request_hash' => hash('sha256', json_encode([$kind, $service, $snapshot['id'], $snapshot['sha256'],
                    $options, $instructions, $pricing], JSON_THROW_ON_ERROR)), 'expires_at' => $expires]);
        }), 'listing-studio-quote');
    }

    public function assertCurrent(ListingStudioQuote $quote, User $client): array
    {
        if ((int) $quote->client_id !== (int) $client->id) {
            abort(404);
        }
        if ($quote->expires_at->lte(now())) {
            throw new PublicApiException('This quote expired. Review a fresh quote before paying.', 'quote_expired', 409, ['requiresNewQuote' => true]);
        }
        $this->catalog->assertAvailable($quote->kind, $quote->service);
        $selection = $this->reservations->selection($client);
        $subscription = $selection['creditSubscription'];
        $pricing = $this->catalog->price($quote->kind, $quote->service, $subscription?->plan_code);
        if ($quote->catalog_version !== $this->catalog->version() || $quote->pricing_snapshot !== $pricing
            || (string) $quote->subscription_id !== (string) $subscription?->id) {
            throw new PublicApiException('Your price or credit period changed. Review a fresh quote.', 'quote_stale', 409, ['requiresNewQuote' => true]);
        }

        return $selection;
    }

    public function present(ListingStudioQuote $quote, User $client): array
    {
        $selection = $this->reservations->selection($client);
        $subscription = $selection['creditSubscription'];
        $available = $subscription ? $this->credits->summary($subscription)['available_cents'] : 0;
        $cost = $quote->pricing_snapshot['creditCostCents'];
        $reason = $selection['billingAttention'] ? 'billing_attention'
            : (! $subscription ? 'no_active_subscription' : ($available < $cost ? 'insufficient_credits' : null));

        return ['id' => $quote->id, 'expiresAt' => $quote->expires_at->toIso8601String(),
            'catalogVersion' => $quote->catalog_version, 'kind' => $quote->kind, 'service' => $quote->service,
            'source' => ['kind' => $quote->source_kind, 'id' => $quote->source_id],
            'options' => $quote->options, 'instructions' => $quote->instructions, 'pricing' => $quote->pricing_snapshot,
            'fundingOptions' => ['credits' => ['available' => $reason === null, 'shortfallCents' => max(0, $cost - $available), 'reason' => $reason],
                'card' => ['available' => $this->catalog->checkoutAvailable(), 'reason' => $this->catalog->checkoutAvailable() ? null : 'checkout_unavailable']],
            'confirmationRequired' => true];
    }
}
