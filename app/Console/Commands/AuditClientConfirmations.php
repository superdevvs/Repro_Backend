<?php

namespace App\Console\Commands;

use App\Services\Messaging\ClientConfirmationRecoveryService;
use Illuminate\Console\Command;

class AuditClientConfirmations extends Command
{
    protected $signature = 'messaging:audit-client-confirmations';

    protected $description = 'List failed and skipped client scheduled-confirmation deliveries.';

    public function __construct(
        private readonly ClientConfirmationRecoveryService $clientConfirmationRecoveryService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->clientConfirmationRecoveryService->auditRows();

        if ($rows->isEmpty()) {
            $this->info('No failed or skipped client confirmation deliveries found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Delivery', 'Shoot', 'Client', 'Email', 'Workflow', 'Status', 'Reason', 'Attempts'],
            $rows->map(function ($delivery) {
                $shoot = $delivery->shoot;
                $client = $delivery->recipient ?? $shoot?->client;

                return [
                    'Delivery' => $delivery->id,
                    'Shoot' => $shoot?->id,
                    'Client' => $client?->id,
                    'Email' => $client?->email,
                    'Workflow' => $shoot?->workflow_status ?: $shoot?->status,
                    'Status' => $delivery->status,
                    'Reason' => $delivery->reason_code,
                    'Attempts' => $delivery->attempt_count,
                ];
            })->all()
        );

        return self::SUCCESS;
    }
}
