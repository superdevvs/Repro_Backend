<?php

namespace App\Console\Commands;

use App\Services\Shoots\ProviderFloorplanRecovery;
use Illuminate\Console\Command;

class RecoverProviderFloorplansCommand extends Command
{
    protected $signature = 'floorplans:recover-provider-assets {--provider=all} {--shoot=} {--limit=100} {--dry-run}';
    protected $description = 'Recover missing provider floorplans and pending scans without changing scan or payment restrictions.';

    public function handle(ProviderFloorplanRecovery $recovery): int
    {
        $provider = (string) $this->option('provider');
        if (!in_array($provider, ['all', 'cubicasa', 'iguide'], true)) { $this->error('Unsupported provider.'); return self::INVALID; }
        foreach ($provider === 'all' ? ['cubicasa', 'iguide'] : [$provider] as $name) {
            $this->line(json_encode(['provider' => $name, 'dry_run' => (bool) $this->option('dry-run')] + $recovery->recover($name, max(1, (int) $this->option('limit')), $this->option('shoot') ? (int) $this->option('shoot') : null, (bool) $this->option('dry-run'))));
        }
        return self::SUCCESS;
    }
}
