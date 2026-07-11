<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Facades\Log;

class PhoneNumberChangedNotificationService
{
    public function __construct(private readonly MessagingService $messagingService)
    {
    }

    /**
     * Notify both numbers after an existing phone number is replaced.
     *
     * @return array{attempted: bool, previous: array{sent: bool, error: ?string}, new: array{sent: bool, error: ?string}}
     */
    public function dispatch(User $user, string $previousPhone, string $newPhone, ?User $actor = null): array
    {
        $previous = $this->normalizePhone($previousPhone);
        $new = $this->normalizePhone($newPhone);
        $result = [
            'attempted' => false,
            'previous' => ['sent' => false, 'error' => null],
            'new' => ['sent' => false, 'error' => null],
        ];

        if (!$this->isValidE164($previous) || !$this->isValidE164($new) || $previous === $new) {
            return $result;
        }

        $result['attempted'] = true;
        $result['previous'] = $this->send(
            $user,
            $actor,
            $previous,
            "Did you just change your phone number? If not, please contact support immediately. — R/E Pro Dashboard",
            'PHONE_NUMBER_CHANGED_PREVIOUS'
        );
        $result['new'] = $this->send(
            $user,
            $actor,
            $new,
            'Success! Your phone number has been updated. — R/E Pro Dashboard',
            'PHONE_NUMBER_CHANGED_NEW'
        );

        return $result;
    }

    /** @return array{sent: bool, error: ?string} */
    private function send(User $user, ?User $actor, string $to, string $body, string $source): array
    {
        try {
            $this->messagingService->sendSms([
                'to' => $to,
                'body_text' => $body,
                'send_source' => $source,
                'contact_phone' => $to,
                'contact_email' => $user->email,
                'contact_name' => $user->name,
                'contact_type' => $this->normalizeRole((string) $user->role),
                'contact_user_id' => $user->id,
                'contact_account_id' => $user->id,
                'related_account_id' => $user->id,
                'user_id' => $actor?->id ?? $user->id,
            ]);

            return ['sent' => true, 'error' => null];
        } catch (\Throwable $exception) {
            Log::warning('Failed to send phone number change SMS', [
                'user_id' => $user->id,
                'send_source' => $source,
                'error' => $exception->getMessage(),
            ]);

            return ['sent' => false, 'error' => $exception->getMessage()];
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits === '' ? '' : '+'.$digits;
    }

    private function isValidE164(string $phone): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $phone);
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(str_replace(['_', '-', ' '], '', $role));

        return match ($normalized) {
            'salesrep' => 'sales_rep',
            'editingmanager' => 'editing_manager',
            default => strtolower(trim($role)),
        };
    }
}
