<?php

namespace App\Console\Commands;

use App\Services\CubiCasaService;
use Illuminate\Console\Command;

/**
 * Register/update REPro's CubiCasa webhook URL via PATCH /companies/webhook.
 * Idempotent — safe to re-run after every deploy.
 */
class RegisterCubiCasaWebhookCommand extends Command
{
    protected $signature = 'cubicasa:register-webhook';
    protected $description = 'Register the configured CUBICASA_WEBHOOK_URL with CubiCasa via PATCH /companies/webhook.';

    public function handle(CubiCasaService $cubicasa): int
    {
        $this->info('Environment : ' . $cubicasa->getEnvironment());
        $this->info('Base URL    : ' . $cubicasa->getBaseUrl());
        $this->info('Webhook URL : ' . $cubicasa->getWebhookUrl());

        if (!$cubicasa->hasCredentials()) {
            $this->error('CUBICASA_API_KEY is not set. Add it to .env and re-run config:cache.');
            return self::FAILURE;
        }

        $result = $cubicasa->registerWebhook();
        if (!empty($result['ok'])) {
            $this->info('Registered: ' . ($result['url'] ?? '?'));
            return self::SUCCESS;
        }

        $this->error('CubiCasa returned ' . ($result['status'] ?? '?') . ': ' . ($result['message'] ?? '(no message)'));
        return self::FAILURE;
    }
}
