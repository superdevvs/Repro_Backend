<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\Messaging\MessagingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryStuckQueuedMessages extends Command
{
    protected $signature = 'messages:retry-stuck
        {--minutes=5 : Minimum queued age in minutes before a message is considered stuck}
        {--max-attempts=3 : Maximum retry attempts before failing the original queued message}
        {--limit=100 : Maximum number of stuck queued messages to scan in one run}
        {--dry-run : Print eligible stuck queued messages without retrying them}';

    protected $description = 'Retry stuck queued transactional email messages and fail exhausted rows.';

    public function handle(MessagingService $messagingService): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);

        $messages = Message::query()
            ->where('channel', 'EMAIL')
            ->where('direction', 'OUTBOUND')
            ->where('status', 'QUEUED')
            ->whereNull('scheduled_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $retried = 0;
        $failed = 0;
        $pending = 0;
        $skipped = 0;

        foreach ($messages as $message) {
            if (!$this->isEligible($message)) {
                $skipped++;
                $this->line(sprintf(
                    'skip message=%d reason=ineligible to=%s source=%s',
                    $message->id,
                    $message->to_address ?? '(missing)',
                    $message->send_source ?? 'unknown'
                ));
                continue;
            }

            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $attempt = (int) ($metadata['retry_stuck_attempts'] ?? 0) + 1;
            $attemptedAt = now()->toIso8601String();

            if ($dryRun) {
                $this->line(sprintf(
                    'audit message=%d attempt=%d to=%s source=%s created_at=%s',
                    $message->id,
                    $attempt,
                    $message->to_address,
                    $message->send_source ?? 'unknown',
                    optional($message->created_at)->toIso8601String() ?? 'unknown'
                ));
                continue;
            }

            if ($attempt > $maxAttempts) {
                $message->update([
                    'status' => 'FAILED',
                    'failed_at' => now(),
                    'error_message' => 'Stuck queued email exceeded retry-stuck max attempts before another retry could be attempted.',
                    'metadata' => array_merge($metadata, [
                        'retry_stuck_attempts' => $attempt - 1,
                        'retry_stuck_last_attempted_at' => $attemptedAt,
                        'retry_stuck_last_error' => 'Retry budget exhausted before dispatch.',
                    ]),
                ]);

                $failed++;
                $this->warn(sprintf('failed message=%d reason=retry_budget_exhausted', $message->id));
                continue;
            }

            $message->update([
                'metadata' => array_merge($metadata, [
                    'retry_stuck_attempts' => $attempt,
                    'retry_stuck_last_attempted_at' => $attemptedAt,
                ]),
            ]);

            try {
                $messagingService->dispatchStoredEmailMessage($message->fresh(['channelConfig']));

                $message->refresh();
                $message->update([
                    'metadata' => array_merge(is_array($message->metadata) ? $message->metadata : [], [
                        'retry_stuck_attempts' => $attempt,
                        'retry_stuck_last_attempted_at' => $attemptedAt,
                        'retry_stuck_recovered_at' => now()->toIso8601String(),
                    ]),
                ]);

                $retried++;
                $this->info(sprintf('retried message=%d status=%s', $message->id, $message->status));
            } catch (\Throwable $exception) {
                $message->refresh();

                $failureMetadata = array_merge(is_array($message->metadata) ? $message->metadata : [], [
                    'retry_stuck_attempts' => $attempt,
                    'retry_stuck_last_attempted_at' => $attemptedAt,
                    'retry_stuck_last_error' => $exception->getMessage(),
                ]);

                if ($attempt >= $maxAttempts) {
                    $message->update([
                        'status' => 'FAILED',
                        'failed_at' => $message->failed_at ?? now(),
                        'error_message' => $exception->getMessage(),
                        'metadata' => $failureMetadata,
                    ]);

                    $failed++;
                    $this->warn(sprintf('failed message=%d error=%s', $message->id, $exception->getMessage()));
                    continue;
                }

                $message->update([
                    'status' => 'QUEUED',
                    'failed_at' => null,
                    'error_message' => null,
                    'metadata' => $failureMetadata,
                ]);

                $pending++;
                Log::warning('Queued email retry attempt failed but will remain queued for another retry.', [
                    'message_id' => $message->id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'to_address' => $message->to_address,
                    'send_source' => $message->send_source,
                    'error' => $exception->getMessage(),
                ]);
                $this->line(sprintf('pending message=%d retryable_error=%s', $message->id, $exception->getMessage()));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'summary retried=%d failed=%d pending=%d skipped=%d scanned=%d dry_run=%s',
            $retried,
            $failed,
            $pending,
            $skipped,
            $messages->count(),
            $dryRun ? 'yes' : 'no'
        ));

        return self::SUCCESS;
    }

    protected function isEligible(Message $message): bool
    {
        $to = is_string($message->to_address) ? trim($message->to_address) : '';

        return $to !== ''
            && filter_var($to, FILTER_VALIDATE_EMAIL) !== false
            && strtoupper((string) $message->channel) === 'EMAIL'
            && strtoupper((string) $message->direction) === 'OUTBOUND'
            && strtoupper((string) $message->status) === 'QUEUED'
            && $message->scheduled_at === null
            && strtoupper((string) $message->provider) !== 'INTERNAL';
    }
}
