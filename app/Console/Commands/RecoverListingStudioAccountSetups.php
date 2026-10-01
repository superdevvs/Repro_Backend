<?php

namespace App\Console\Commands;

use App\Jobs\SendListingStudioAccountSetup;
use App\Models\ListingStudioAccountSetup;
use App\Support\LockedWrite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverListingStudioAccountSetups extends Command
{
    protected $signature = 'listing-studio:recover-account-setups {--limit=100}';

    protected $description = 'Recover pending Listing Studio account setup notifications';

    public function handle(): int
    {
        if (! config('listing-studio.stripe_enabled')) {
            return self::SUCCESS;
        }
        $limit = max(1, min(500, (int) $this->option('limit')));
        $stale = ListingStudioAccountSetup::where('status', 'processing')->where('updated_at', '<', now()->subMinutes(5));
        $uncertain = LockedWrite::run(fn () => $stale->update([
            'status' => 'needs_attention', 'last_error' => 'A worker stopped during setup delivery. Review provider delivery before retrying.',
        ]), 'listing-studio-setup-recovery');
        if ($uncertain) {
            Log::error('Listing Studio account setup deliveries need provider reconciliation.', ['count' => $uncertain]);
        }
        ListingStudioAccountSetup::whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)
            ->where('updated_at', '<', now()->subMinute())->oldest('id')->limit($limit)->pluck('id')
            ->each(fn ($id) => SendListingStudioAccountSetup::dispatch((int) $id));
        $this->info('Pending account setups have been queued.');

        return self::SUCCESS;
    }
}
