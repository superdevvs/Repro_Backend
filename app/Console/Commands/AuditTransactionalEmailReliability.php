<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AuditTransactionalEmailReliability extends Command
{
    protected $signature = 'messaging:audit-transactional-email
        {--hours=168 : Look back this many hours for recent failed or stuck outbound email rows}
        {--limit=100 : Maximum rows to print per section}';

    protected $description = 'Audit transactional email failures, skips, and live shoots tied to clients missing email.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subHours($hours);

        $missingEmailShoots = Shoot::query()
            ->with('client')
            ->whereIn('workflow_status', [
                Shoot::STATUS_REQUESTED,
                Shoot::STATUS_SCHEDULED,
                Shoot::STATUS_UPLOADED,
                Shoot::STATUS_EDITING,
                Shoot::STATUS_READY,
            ])
            ->whereHas('client', function ($query): void {
                $query->where(function ($inner): void {
                    $inner->whereNull('email')
                        ->orWhere('email', '')
                        ->orWhere('email', ' ');
                });
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $recentMessageFailures = Message::query()
            ->where('channel', 'EMAIL')
            ->where('direction', 'OUTBOUND')
            ->whereIn('status', ['FAILED', 'QUEUED'])
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $deliveryIssues = ShootEmailDelivery::query()
            ->with(['shoot', 'recipient'])
            ->whereIn('status', [
                ShootEmailDelivery::STATUS_FAILED,
                ShootEmailDelivery::STATUS_SKIPPED,
            ])
            ->orderByDesc('last_attempted_at')
            ->limit($limit)
            ->get();

        $this->info('Transactional email reliability audit');
        $this->line('window_hours=' . $hours . ' limit=' . $limit);
        $this->newLine();

        $this->renderMissingEmailShoots($missingEmailShoots);
        $this->newLine();
        $this->renderRecentMessageFailures($recentMessageFailures);
        $this->newLine();
        $this->renderDeliveryIssues($deliveryIssues);

        $providerLikeFailures = $recentMessageFailures->filter(function (Message $message): bool {
            $error = strtolower((string) $message->error_message);

            return str_contains($error, 'cakemail')
                || str_contains($error, 'smtp')
                || str_contains($error, 'not configured')
                || str_contains($error, 'unauthorized')
                || str_contains($error, 'forbidden')
                || str_contains($error, 'timeout');
        })->count();

        if ($providerLikeFailures > 0) {
            Log::warning('Transactional email audit found repeated provider/config failures.', [
                'window_hours' => $hours,
                'count' => $providerLikeFailures,
            ]);
        }

        if ($missingEmailShoots->count() > 0 || $deliveryIssues->where('reason_code', ShootEmailDelivery::REASON_MISSING_EMAIL)->count() > 0) {
            Log::warning('Transactional email audit found skipped sends caused by missing recipient data.', [
                'window_hours' => $hours,
                'live_shoots_with_missing_client_email' => $missingEmailShoots->count(),
                'delivery_rows_missing_email' => $deliveryIssues->where('reason_code', ShootEmailDelivery::REASON_MISSING_EMAIL)->count(),
            ]);
        }

        return self::SUCCESS;
    }

    private function renderMissingEmailShoots($shoots): void
    {
        $this->info('Live shoots tied to clients missing email');

        if ($shoots->isEmpty()) {
            $this->line('none');
            return;
        }

        foreach ($shoots as $shoot) {
            $this->line(sprintf(
                'kind=missing_email shoot_id=%d client_id=%s workflow_status=%s status=%s reason=missing_primary_email address="%s"',
                $shoot->id,
                $shoot->client?->id ?? 'null',
                $shoot->workflow_status ?? 'unknown',
                $shoot->status ?? 'unknown',
                trim((string) ($shoot->address ?? ''))
            ));
        }
    }

    private function renderRecentMessageFailures($messages): void
    {
        $this->info('Recent failed or stuck outbound email rows');

        if ($messages->isEmpty()) {
            $this->line('none');
            return;
        }

        foreach ($messages as $message) {
            $this->line(sprintf(
                'kind=message status=%s message_id=%d source=%s shoot_id=%s recipient_type=%s reason="%s" to=%s',
                $message->status,
                $message->id,
                $message->send_source ?? 'unknown',
                $message->related_shoot_id ?? 'null',
                $this->resolveMessageRecipientType($message),
                trim((string) ($message->error_message ?? 'stuck_queued')),
                trim((string) ($message->to_address ?? ''))
            ));
        }
    }

    private function renderDeliveryIssues($deliveries): void
    {
        $this->info('Recovery ledger failures and skips');

        if ($deliveries->isEmpty()) {
            $this->line('none');
            return;
        }

        foreach ($deliveries as $delivery) {
            $this->line(sprintf(
                'kind=delivery status=%s delivery_id=%d source=%s shoot_id=%s recipient_type=%s reason=%s recipient_user_id=%s',
                $delivery->status,
                $delivery->id,
                $delivery->source ?? 'unknown',
                $delivery->shoot_id ?? 'null',
                $delivery->recipient_type ?? 'unknown',
                $delivery->reason_code ?? 'none',
                $delivery->recipient_user_id ?? 'null'
            ));
        }
    }

    private function resolveMessageRecipientType(Message $message): string
    {
        return $message->related_account_id !== null ? 'client' : 'unknown';
    }
}
