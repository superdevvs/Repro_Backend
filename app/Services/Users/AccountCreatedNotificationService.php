<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Facades\Log;

class AccountCreatedNotificationService
{
    public function __construct(private readonly MessagingService $messagingService) {}

    /** @return array{attempted: bool, sent: bool, error: ?string} */
    public function sendSms(User $user, ?User $actor = null): array
    {
        $rawPhone = trim((string) ($user->phonenumber ?: $user->phone));
        $result = ['attempted' => $rawPhone !== '', 'sent' => false, 'error' => null];

        if ($rawPhone === '') {
            return $result;
        }

        try {
            $phone = $this->normalizePhone($rawPhone);
            $this->messagingService->sendSms([
                'to' => $phone,
                'body_text' => sprintf(
                    'R/E Pro Photos: Your %s account has been created. Check %s for your setup and verification links. Sign in at %s',
                    $this->roleLabel($user->role),
                    $user->email,
                    rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/')
                ),
                'send_source' => 'ACCOUNT_CREATED',
                'contact_phone' => $phone,
                'contact_email' => $user->email,
                'contact_name' => $user->name,
                'contact_type' => $this->normalizeRole($user->role),
                'contact_user_id' => $user->id,
                'contact_account_id' => $user->id,
                'related_account_id' => $user->id,
                'user_id' => $actor?->id ?? $user->id,
            ]);
            $result['sent'] = true;
        } catch (\Throwable $exception) {
            $result['error'] = $exception->getMessage();
            Log::warning('Failed to send account creation SMS', [
                'user_id' => $user->id,
                'role' => $this->normalizeRole($user->role),
                'error_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }

        return $result;
    }

    public function emailWasSentTo(array $dispatch, string $email): bool
    {
        $expected = strtolower(trim($email));

        return collect($dispatch['email_sent_to'] ?? [])->contains(
            fn ($recipient) => strtolower(trim((string) $recipient)) === $expected
        );
    }

    public function normalizeRole(?string $role): string
    {
        $normalized = strtolower(str_replace(['_', '-', ' '], '', (string) $role));

        return match ($normalized) {
            'salesrep' => 'sales_rep',
            'editingmanager' => 'editing_manager',
            default => strtolower((string) $role),
        };
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return str_starts_with(trim($phone), '+') ? trim($phone) : '+'.$digits;
    }

    private function roleLabel(?string $role): string
    {
        return ucwords(str_replace('_', ' ', $this->normalizeRole($role)));
    }
}
