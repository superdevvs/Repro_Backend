<?php

namespace App\Services\Messaging;

use App\Models\Shoot;
use App\Services\MailService;

class ShootSummaryNotificationService
{
    public function __construct(
        private readonly ShootSummaryEligibility $eligibility,
        private readonly MailService $mail,
    ) {}

    /** Return false when eligibility is not met; throw if an eligible send failed. */
    public function sendIfEligible(int $shootId): bool
    {
        $shoot = Shoot::query()->with(['client', 'payments.refunds'])->find($shootId);
        if (! $shoot || ! $this->eligibility->canSend($shoot) || ! $shoot->client?->email) {
            return false;
        }

        if (! $this->mail->sendShootSummaryEmail($shoot->client, $shoot)) {
            throw new \RuntimeException('The shoot summary email was not accepted for delivery.');
        }

        return true;
    }
}
