<?php

namespace App\Listeners;

use App\Services\Messaging\AutomationService;
use Illuminate\Auth\Events\Verified;

class TriggerAccountVerifiedAutomation
{
    public function __construct(private readonly AutomationService $automationService)
    {
    }

    public function handle(Verified $event): void
    {
        $user = $event->user;
        if (!$user) {
            return;
        }

        $this->automationService->handleEvent(
            'ACCOUNT_VERIFIED',
            $this->automationService->buildUserContext($user)
        );
    }
}
