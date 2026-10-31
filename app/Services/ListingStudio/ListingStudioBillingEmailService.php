<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Jobs\SendListingStudioBillingRequest;
use App\Models\ListingStudioBillingEmail as Delivery;
use App\Models\ListingStudioBillingRequest as BillingRequest;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Messaging\MessagingService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListingStudioBillingEmailService
{
    public function request(User $actor, User $client, BillingRequest $request, string $key): array
    {
        ListingStudioDelegatedAccess::assert($actor, $client);
        abort_unless((int) $request->actor_id === (int) $actor->id && (int) $request->client_id === (int) $client->id, 404);
        if (! Str::isUuid($key)) {
            throw new PublicApiException('A valid email request identifier is required.', 'validation_failed', 422);
        }
        $result = $this->write(function () use ($actor, $client, $request, $key) {
            DB::table('users')->where('id', $client->id)->update(['updated_at' => DB::raw('updated_at')]);
            $actor = User::findOrFail($actor->id);
            $client = User::findOrFail($client->id);
            ListingStudioDelegatedAccess::assert($actor, $client);
            $prior = Delivery::where('billing_request_id', $request->id)->where('request_key', $key)->first();
            if ($prior) {
                return ['delivery' => $prior, 'created' => false];
            }
            ListingStudioDelegatedAccess::assert($actor, $client, true);
            $request = BillingRequest::findOrFail($request->id);
            if ($request->status !== 'pending' || $request->expires_at->isPast()) {
                throw new PublicApiException('Only a pending payment request can be emailed.', 'billing_request_conflict', 409);
            }
            if (! filter_var($client->email, FILTER_VALIDATE_EMAIL)) {
                throw new PublicApiException('This account does not have a valid registered email.', 'email_unavailable', 409);
            }
            $recent = Delivery::where('client_id', $client->id)->where('created_at', '>', now()->subDay());
            if ((clone $recent)->count() >= max(1, (int) config('listing-studio.billing_email_daily_limit', 3))
                || (clone $recent)->where('created_at', '>', now()->subMinutes(max(1, (int) config('listing-studio.billing_email_cooldown_minutes', 60))))->exists()) {
                throw new PublicApiException('An email was already requested recently. Copy the existing link or try later.', 'email_rate_limited', 429);
            }
            $delivery = Delivery::create(['billing_request_id' => $request->id, 'client_id' => $client->id, 'actor_id' => $actor->id,
                'request_key' => $key, 'recipient' => $client->email, 'status' => 'queued']);
            UserActivityLog::record($client, 'listing_studio.payment_request_email', 'Listing Studio payment request email requested', null, $actor, ['requestId' => $request->id, 'deliveryId' => $delivery->id]);

            return ['delivery' => $delivery, 'created' => true];
        });
        if ($result['delivery']->status === 'queued') {
            $this->dispatch($result['delivery']->id);
        }

        return ['status' => $result['delivery']->fresh()->status, 'created' => $result['created']];
    }

    public function dispatch(string $id): void
    {
        try {
            SendListingStudioBillingRequest::dispatch($id);
        } catch (\Throwable) {
            // The persisted queued intent is recovered by the bounded scheduler command.
            // Never repeat an email whose provider submission has already begun.
        }
    }

    public function deliver(string $id): void
    {
        $delivery = $this->write(function () use ($id) {
            $claimed = Delivery::whereKey($id)->where('status', 'queued')->update(['status' => 'submitting', 'submission_started_at' => now()]);
            if ($claimed !== 1) {
                return null;
            }
            $delivery = Delivery::findOrFail($id);
            $client = User::find($delivery->client_id);
            $actor = User::find($delivery->actor_id);
            $request = BillingRequest::find($delivery->billing_request_id);
            try {
                if (! $client || ! $actor || ! $request || $request->status !== 'pending' || $request->expires_at->isPast()
                    || $client->email !== $delivery->recipient || ! config('listing-studio.delegated_enabled', false)) {
                    throw new \RuntimeException('Delivery unavailable.');
                }
                ListingStudioDelegatedAccess::assert($actor, $client);
            } catch (\Throwable) {
                $delivery->update(['status' => 'needs_attention']);

                return null;
            }

            return $delivery;
        });
        if (! $delivery) {
            return;
        }
        try {
            $request = BillingRequest::findOrFail($delivery->billing_request_id);
            $client = User::findOrFail($delivery->client_id);
            $url = app(ListingStudioBillingRequestService::class)->appUrl($request);
            $html = view('emails.listing_studio_payment_request', ['clientName' => $client->name, 'reviewUrl' => $url,
                'expiresAt' => $request->expires_at->format('M j, Y H:i').' UTC'])->render();
            $message = app(MessagingService::class)->sendEmail(['to' => $delivery->recipient, 'subject' => 'Review your Listing Studio purchase',
                'body_html' => $html, 'body_text' => 'Review your Listing Studio purchase after signing in to your own account: '.$url.'. No payment has been taken. This request expires in 24 hours.',
                'send_source' => 'LISTING_STUDIO_PAYMENT_REQUEST', 'sender_name' => 'R/E Pro Photos', 'related_account_id' => $client->id,
                'metadata' => ['listing_studio_billing_request_id' => $request->id, 'billing_email_id' => $delivery->id]]);
            $sent = $message->status === 'SENT';
            $this->write(fn () => Delivery::whereKey($delivery->id)->where('status', 'submitting')
                ->update(['status' => $sent ? 'sent' : 'needs_attention', 'sent_at' => $sent ? now() : null]));
        } catch (\Throwable) {
            // An unknown delivery response must not be automatically resent or expose provider details.
            $this->write(fn () => Delivery::whereKey($delivery->id)->where('status', 'submitting')->update(['status' => 'needs_attention']));
        }
    }

    public function recover(int $limit = 100): int
    {
        $limit = min(100, max(1, $limit));
        $this->write(function () use ($limit): void {
            $ids = Delivery::where('status', 'submitting')->where('submission_started_at', '<', now()->subMinutes(10))->orderBy('created_at')->limit($limit)->pluck('id');
            Delivery::whereIn('id', $ids)->where('status', 'submitting')->update(['status' => 'needs_attention']);
        });
        $ids = Delivery::where('status', 'queued')->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $this->dispatch($id);
        }

        return $ids->count();
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-billing-email');
    }
}
