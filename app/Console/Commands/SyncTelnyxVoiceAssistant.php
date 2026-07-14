<?php

namespace App\Console\Commands;

use App\Services\TelnyxAi\TelnyxAssistantSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncTelnyxVoiceAssistant extends Command
{
    protected $signature = 'voice:sync-telnyx-assistant
        {--apply : Create a new non-main Telnyx assistant version}
        {--route-canary : Route VOICE_CANARY_NUMBERS to the newly-created version}
        {--remove-canary : Remove VOICE_CANARY_NUMBERS from Telnyx version routing}
        {--version-name= : Optional name for the new assistant version}';

    protected $description = 'Inspect or create a consent-safe Telnyx assistant version with the RePro voice tools.';

    public function handle(TelnyxAssistantSyncService $sync): int
    {
        try {
            if ($this->option('remove-canary')) {
                if ($this->option('apply') || $this->option('route-canary')) {
                    $this->error('--remove-canary cannot be combined with --apply or --route-canary.');

                    return self::INVALID;
                }
                $result = $sync->removeCanaryRoute();
                $this->info('Canary rollback status: '.$result['status']);

                return self::SUCCESS;
            }

            if ($this->option('route-canary') && ! $this->option('apply')) {
                $this->error('--route-canary requires --apply.');

                return self::INVALID;
            }

            $result = $sync->sync((bool) $this->option('apply'), $this->option('version-name') ?: null);
            if ($this->option('route-canary')) {
                $versionId = (string) ($result['created_version_id'] ?? '');
                if ($versionId === '') {
                    throw new \RuntimeException('Telnyx did not return the created version ID; no routing change was made.');
                }
                $result['canary'] = $sync->routeCanary($versionId);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Check', 'Value'], [
            ['Mode', $result['applied'] ? 'applied' : 'dry-run'],
            ['Assistant', $result['assistant_id']],
            ['Current version', $result['current_version_id'] ?: 'unknown'],
            ['New version', $result['created_version_id'] ?? 'not created'],
            ['Promoted to main', 'no'],
            ['Desired tools', implode(', ', $result['desired_tools'])],
            ['Missing tools', implode(', ', $result['missing_tools']) ?: 'none'],
            ['Removed legacy webhooks', implode(', ', $result['removed_webhook_tools']) ?: 'none'],
            ['Automatic recording', $result['automatic_recording_will_be_disabled'] ? 'will be disabled' : 'already disabled'],
            ['Canary routing', isset($result['canary']) ? 'configured for '.count($result['canary']['targets']).' target(s)' : 'unchanged'],
        ]);

        if (! $result['applied']) {
            $this->info('Dry-run only. Use --apply to create a non-main version after reviewing the diff.');
        } else {
            $this->info('Created a non-main assistant version. No live traffic routing was changed.');
        }

        return self::SUCCESS;
    }
}
