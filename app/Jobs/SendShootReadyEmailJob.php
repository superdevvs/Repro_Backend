<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background ready/delivered email + automation event dispatch.
 *
 * Decoupled from FinalizeShootJob so a slow/failing mail provider can never
 * block the user-facing "delivered" transition.
 */
class SendShootReadyEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 120;

    public function __construct(
        public int $shootId,
        public ?int $shootServiceId = null,
        public bool $isFullOrderDelivery = true,
        public bool $fireAutomation = true
    ) {
        $this->onQueue('mail');
    }

    public function handle(MailService $mail, AutomationService $automation): void
    {
        /** @var Shoot|null $shoot */
        $shoot = Shoot::query()->find($this->shootId);
        if (!$shoot) {
            return;
        }

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
        $client = $shoot->client ?: User::find($shoot->client_id);

        $systemEmailAlreadySent = false;
        if ($client) {
            try {
                if ($this->shootServiceId) {
                    $mail->sendShootReadyEmail($client, $shoot, [$this->shootServiceId], $this->isFullOrderDelivery);
                    $systemEmailAlreadySent = $this->isFullOrderDelivery;
                } elseif ($this->isFullOrderDelivery) {
                    $mail->sendShootReadyEmail($client, $shoot);
                    $systemEmailAlreadySent = true;
                }
            } catch (\Throwable $e) {
                Log::warning('SendShootReadyEmailJob: ready email failed', [
                    'shoot_id' => $shoot->id,
                    'shoot_service_id' => $this->shootServiceId,
                    'error' => $e->getMessage(),
                ]);
                // Do not rethrow — we still want the automation event to fire
                // if configured. Email idempotency is handled upstream.
            }
        }

        if ($this->fireAutomation && $this->isFullOrderDelivery) {
            try {
                $context = $automation->buildShootContext($shoot);
                if ($shoot->rep) {
                    $context['rep'] = $shoot->rep;
                }
                $context['system_email_already_sent'] = $systemEmailAlreadySent;
                $automation->handleEvent('SHOOT_COMPLETED', $context);
            } catch (\Throwable $e) {
                Log::warning('SendShootReadyEmailJob: automation dispatch failed', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendShootReadyEmailJob exhausted retries', [
            'shoot_id' => $this->shootId,
            'error' => $exception->getMessage(),
        ]);
    }
}
