<?php

namespace App\Jobs;

use App\Models\ListingStudioAccountSetup;
use App\Models\User;
use App\Services\Users\AccountCreatedNotificationService;
use App\Support\LockedWrite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendListingStudioAccountSetup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public int $timeout = 120;

    public function __construct(public int $setupId)
    {
        $this->onQueue('default');
    }

    public function handle(AccountCreatedNotificationService $notifications): void
    {
        $lock = Cache::lock('listing-studio-account-setup:'.$this->setupId, 150);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            $setup = ListingStudioAccountSetup::find($this->setupId);
            if (! $setup || in_array($setup->status, ['sent', 'needs_attention'], true) || $setup->attempts >= 5) {
                return;
            }
            // A worker that died during a send has an uncertain provider outcome.
            // Recovery flags that row for review instead of blindly resending it.
            if ($setup->status === 'processing') {
                return;
            }
            $user = User::find($setup->client_id);
            if (! $user || $user->role !== 'client' || ! $user->isAccountEligibleForAuthentication()
                || collect($user->secondary_roles ?? [])->contains(fn ($role) => strtolower(trim((string) $role)) !== 'client')) {
                $setup->update(['status' => 'needs_attention', 'last_error' => 'The account is no longer available for setup.']);

                return;
            }
            LockedWrite::run(fn () => $setup->update(['status' => 'processing', 'attempts' => $setup->attempts + 1]), 'listing-studio-setup-claim');
            try {
                $result = $notifications->dispatch($user, [
                    'issued_context' => 'listing_studio_signup', 'include_password_creation_link' => true,
                    'require_verification' => true, 'resume_state' => $setup->notification_state ?? [],
                    'checkpoint' => function (array $state) use ($setup) {
                        LockedWrite::run(fn () => $setup->update(['notification_state' => $state]), 'listing-studio-setup-checkpoint');
                    },
                ]);
                if (! ($result['email']['account_created']['attempted'] ?? false)
                    && ! ($result['email']['account_created']['sent'] ?? false)) {
                    LockedWrite::run(fn () => $setup->update([
                        'status' => 'needs_attention',
                        'last_error' => 'The account setup email is disabled or was not sent by the configured automation. Review messaging settings.',
                    ]), 'listing-studio-setup-disabled');

                    return;
                }
                $failed = collect([...array_values($result['email'] ?? []), $result['sms'] ?? []])
                    ->contains(fn ($channel) => ($channel['attempted'] ?? false) && ! ($channel['sent'] ?? false));
                if ($failed) {
                    throw new \RuntimeException('An account setup notification was not accepted.');
                }
                LockedWrite::run(fn () => $setup->update([
                    'status' => 'sent', 'sent_at' => now(), 'notification_state' => null, 'last_error' => null,
                ]), 'listing-studio-setup-complete');
            } catch (\Throwable $exception) {
                // Never persist exception text: provider messages may contain reset links.
                LockedWrite::run(fn () => $setup->update([
                    'status' => $setup->attempts >= 5 ? 'needs_attention' : 'failed',
                    'last_error' => 'Account setup delivery failed. Successful channels are preserved for retry.',
                ]), 'listing-studio-setup-failed');
                throw new \RuntimeException('Listing Studio account setup delivery failed.');
            }
        } finally {
            $lock->release();
        }
    }
}
