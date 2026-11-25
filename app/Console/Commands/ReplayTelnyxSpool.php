<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\Messaging\TelnyxWebhookController;
use App\Models\Message;
use App\Support\TelnyxMaintenanceSpool;
use Illuminate\Console\Command;
use Throwable;

/**
 * Replays Telnyx texts saved by CaptureTelnyxDuringMaintenance, oldest
 * arrival first, through the normal handlers with the saved arrival time.
 * Each Telnyx event id, and each inbound message id, is handled once.
 * Voice and Vapi events are never replayed (see telnyx:missed-calls).
 *
 * Run it with the www-data group and umask 002 (the deploy does both), so
 * files it creates stay writable by PHP-FPM.
 */
class ReplayTelnyxSpool extends Command
{
    protected $signature = 'telnyx:replay-spool {--dry-run : Count what is waiting and change nothing}';

    protected $description = 'Replay Telnyx texts saved during maintenance mode, in arrival order, once each.';

    public function handle(): int
    {
        umask(0002);
        if (($problem = $this->groupProblem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $spool = TelnyxMaintenanceSpool::fromConfig();
        if (! $spool->exists()) {
            $this->info('Telnyx spool is empty.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d text(s) waiting, %d call event(s) kept.',
                count($spool->entries(TelnyxMaintenanceSpool::TEXTS)), count($spool->entries(TelnyxMaintenanceSpool::CALLS))));

            return self::SUCCESS;
        }

        $lock = @fopen($spool->root().'/.replay.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->warn('Another Telnyx spool replay is running.');

            return self::SUCCESS;
        }
        try {
            $counts = $this->replay($spool);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $cutoff = now()->toImmutable()->subDays(max(1, (int) config('services.telnyx.maintenance_capture.keep_days', 14)));
        foreach ([TelnyxMaintenanceSpool::REPLAYED, TelnyxMaintenanceSpool::REJECTED, TelnyxMaintenanceSpool::CALLS] as $folder) {
            $spool->prune($folder, $cutoff);
        }

        $this->line(sprintf(
            'Telnyx spool: replayed %d, duplicates %d, set aside %d, failed %d; call events kept for telnyx:missed-calls: %d.',
            $counts['replayed'], $counts['duplicates'], $counts['set_aside'], $counts['failed'],
            count($spool->entries(TelnyxMaintenanceSpool::CALLS)),
        ));

        return $counts['set_aside'] + $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{replayed: int, duplicates: int, set_aside: int, failed: int} */
    private function replay(TelnyxMaintenanceSpool $spool): array
    {
        $counts = ['replayed' => 0, 'duplicates' => 0, 'set_aside' => 0, 'failed' => 0];
        $seenEvents = [];
        $seenMessages = [];
        foreach ($spool->entries(TelnyxMaintenanceSpool::TEXTS) as $entry) {
            $payload = $entry['readable'] && $this->verified($entry) ? json_decode($entry['body'], true) : null;
            if (! is_array($payload)) {
                $spool->move($entry['id'], TelnyxMaintenanceSpool::TEXTS, TelnyxMaintenanceSpool::REJECTED);
                $counts['set_aside']++;

                continue;
            }

            $eventId = (string) data_get($payload, 'data.id', '');
            $messageId = data_get($payload, 'data.event_type') === 'message.received'
                ? (string) data_get($payload, 'data.payload.id', '') : '';
            if (isset($seenEvents[$eventId]) || ($messageId !== ''
                && (isset($seenMessages[$messageId]) || Message::where('provider_message_id', $messageId)->exists()))) {
                $spool->move($entry['id'], TelnyxMaintenanceSpool::TEXTS, TelnyxMaintenanceSpool::REPLAYED);
                $counts['duplicates']++;

                continue;
            }

            try {
                app(TelnyxWebhookController::class)->replayVerified($entry['route'], $entry['route_key'], $payload, $entry['arrived_at']);
            } catch (Throwable $error) {
                // Left in texts/ for the next run. Never log bodies or numbers.
                report($error);
                $counts['failed']++;

                continue;
            }
            $seenEvents[$eventId] = true;
            if ($messageId !== '') {
                $seenMessages[$messageId] = true;
            }
            $spool->move($entry['id'], TelnyxMaintenanceSpool::TEXTS, TelnyxMaintenanceSpool::REPLAYED);
            $counts['replayed']++;
        }

        return $counts;
    }

    /** The saved signature still matches, and it was fresh when it arrived. */
    private function verified(array $entry): bool
    {
        $signature = $entry['headers']['telnyx-signature-ed25519'] ?? '';
        $timestamp = $entry['headers']['telnyx-timestamp'] ?? '';
        $tolerance = max(1, (int) config('services.telnyx.webhook_tolerance_seconds', 300));

        return in_array($entry['route'], ['messaging', 'status', 'shared-mms'], true)
            && ctype_digit($timestamp)
            && abs($entry['arrived_at']->getTimestamp() - (int) $timestamp) <= $tolerance
            && TelnyxMaintenanceSpool::telnyxSignatureValid($entry['body'], $signature, $timestamp)
            && is_string(data_get(json_decode($entry['body'], true), 'data.id'));
    }

    private function groupProblem(): ?string
    {
        $expected = (string) config('services.telnyx.maintenance_capture.replay_group', '');
        if ($expected === '' || ! function_exists('posix_getegid') || ! function_exists('posix_getgrgid')) {
            return null;
        }
        $actual = posix_getgrgid(posix_getegid())['name'] ?? (string) posix_getegid();

        return $actual === $expected ? null
            : "Run the replay with the {$expected} group (for example sg {$expected}); the current group is {$actual}.";
    }
}
