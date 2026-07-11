<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Facades\Log;

class AccountCreatedNotificationService
{
    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly MailService $mailService,
        private readonly AutomationService $automationService,
        private readonly ClientEmailVerificationLinkService $verificationLinks,
        private readonly EmailHealthService $emailHealthService,
    ) {}

    /**
     * Deliver all account-created channels independently.
     *
     * Options: issued_context, actor, include_password_creation_link,
     * pending_equipment_count, send_equipment_email, require_verification.
     *
     * @return array{email: array{account_created: array, verification: array, equipment: array}, sms: array, links: array}
     */
    public function dispatch(User $user, array $options = []): array
    {
        $actor = ($options['actor'] ?? null) instanceof User ? $options['actor'] : null;
        $context = (string) ($options['issued_context'] ?? 'account_created');
        $pendingEquipmentCount = max(0, (int) ($options['pending_equipment_count'] ?? 0));
        $isPhotographer = $this->normalizeRole($user->role) === 'photographer';
        $requiresVerification = array_key_exists('require_verification', $options)
            ? (bool) $options['require_verification']
            : $this->requiresVerification($user->role);
        $sendEquipmentEmail = $isPhotographer && (bool) ($options['send_equipment_email'] ?? true);

        $result = [
            'email' => [
                'account_created' => $this->channel(true),
                'verification' => $this->channel($requiresVerification),
                'equipment' => $this->channel($sendEquipmentEmail),
            ],
            'sms' => $this->channel($this->rawPhone($user) !== ''),
            'links' => ['password_setup' => null, 'verification' => null, 'equipment' => null],
        ];

        $resetLink = null;
        $verificationToken = null;
        try {
            $resetLink = $this->mailService->generateStoredPasswordResetLink($user);
            $result['links']['password_setup'] = $resetLink;
            if ($requiresVerification) {
                $verificationToken = $this->verificationLinks->issueVerificationToken($user, array_filter([
                    'issued_context' => $context,
                    'issued_by' => $actor?->id ?? $user->id,
                ]));
                $result['links']['verification'] = $this->verificationLinks->buildUrlForIssuedToken($user, $verificationToken);
            }
            if ($isPhotographer) {
                $result['links']['equipment'] = $this->mailService->equipmentVerificationLink($user);
            }

            $automationContext = $this->automationService->buildUserContext($user);
            $automationContext['client'] = $user;
            $automationContext['password_reset_link'] = $resetLink;
            $automationContext['include_password_creation_link'] = (bool) ($options['include_password_creation_link'] ?? false);
            $automationContext['verification_link'] = $result['links']['verification'];
            $automationContext['equipment_verification_link'] = $result['links']['equipment'];
            $automationContext['pending_equipment_count'] = $pendingEquipmentCount;

            $automation = $this->automationService->handleEvent('ACCOUNT_CREATED', $automationContext);
            $acceptedByAutomation = $this->emailWasSentTo($automation, $user->email);
            $sent = $acceptedByAutomation || $this->mailService->sendAccountCreatedEmail(
                $user,
                $resetLink,
                $result['links']['verification'],
                $result['links']['equipment'],
                $pendingEquipmentCount,
                (bool) ($options['include_password_creation_link'] ?? false)
            );
            $result['email']['account_created'] = $this->channel(true, $sent, $sent ? null : 'Provider did not accept the account-created email.');
        } catch (\Throwable $exception) {
            $result['email']['account_created'] = $this->failed($exception);
            $this->logFailure('email.account_created', $user, $exception);
        }

        if ($requiresVerification) {
            try {
                $sent = $this->mailService->sendClientEmailVerificationEmail($user, [
                    'issued_context' => $context,
                    'issued_by' => $actor?->id ?? $user->id,
                    'verification_token' => $verificationToken,
                    'verification_link' => $result['links']['verification'],
                ]);
                $result['email']['verification'] = $this->channel(true, $sent, $sent ? null : 'Provider did not accept the verification email.');
                if ($sent) {
                    $this->emailHealthService->markVerificationSent($user);
                }
            } catch (\Throwable $exception) {
                $result['email']['verification'] = $this->failed($exception);
                $this->logFailure('email.verification', $user, $exception);
            }
        }

        if ($sendEquipmentEmail) {
            try {
                $sent = $this->mailService->sendPhotographerEquipmentVerificationEmail($user, $pendingEquipmentCount);
                $result['email']['equipment'] = $this->channel(true, $sent, $sent ? null : 'Provider did not accept the equipment email.');
            } catch (\Throwable $exception) {
                $result['email']['equipment'] = $this->failed($exception);
                $this->logFailure('email.equipment', $user, $exception);
            }
        }

        $result['sms'] = $this->sendSms($user, $actor);

        return $result;
    }

    /** @return array{attempted: bool, sent: bool, error: ?string} */
    public function sendSms(User $user, ?User $actor = null): array
    {
        $rawPhone = $this->rawPhone($user);
        if ($rawPhone === '') {
            return $this->channel(false);
        }

        try {
            $phone = $this->normalizePhone($rawPhone);
            if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
                throw new \InvalidArgumentException('Phone number cannot be normalized to E.164.');
            }
            $this->messagingService->sendSms([
                'to' => $phone,
                'body_text' => sprintf('R/E Pro Photos: Your %s account has been created. Check %s for setup and verification links. Sign in at %s', $this->roleLabel($user->role), $user->email, rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/')),
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
            return $this->channel(true, true);
        } catch (\Throwable $exception) {
            $this->logFailure('sms', $user, $exception);
            return $this->failed($exception);
        }
    }

    public function requiresVerification(?string $role): bool
    {
        return !in_array($this->normalizeRole($role), ['admin', 'superadmin'], true);
    }

    public function emailWasSentTo(array $dispatch, string $email): bool
    {
        $expected = strtolower(trim($email));
        return collect($dispatch['email_sent_to'] ?? [])->contains(fn ($recipient) => strtolower(trim((string) $recipient)) === $expected);
    }

    public function normalizeRole(?string $role): string
    {
        $normalized = strtolower(str_replace(['_', '-', ' '], '', (string) $role));
        return match ($normalized) {
            'salesrep' => 'sales_rep',
            'editingmanager' => 'editing_manager',
            default => strtolower(trim((string) $role)),
        };
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10) return '+1'.$digits;
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return '+'.$digits;
        return str_starts_with(trim($phone), '+') ? '+'.$digits : '+'.$digits;
    }

    private function rawPhone(User $user): string
    {
        return trim((string) ($user->phonenumber ?: $user->phone));
    }

    private function channel(bool $attempted, bool $sent = false, ?string $error = null): array
    {
        return ['attempted' => $attempted, 'sent' => $sent, 'error' => $error];
    }

    private function failed(\Throwable $exception): array
    {
        return $this->channel(true, false, $this->safeError($exception));
    }

    private function logFailure(string $channel, User $user, \Throwable $exception): void
    {
        Log::warning('Account-created notification provider failure', [
            'channel' => $channel,
            'user_id' => $user->id,
            'role' => $this->normalizeRole($user->role),
            'provider_error_class' => $exception::class,
            'provider_error' => $this->safeError($exception),
        ]);
    }

    private function safeError(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        $message = preg_replace('/\b(Bearer\s+|api[_-]?key[=: ]+|token[=: ]+)[^\s,;]+/i', '$1[REDACTED]', $message) ?? 'Provider request failed.';
        return mb_substr($message, 0, 500);
    }

    private function roleLabel(?string $role): string
    {
        return ucwords(str_replace('_', ' ', $this->normalizeRole($role)));
    }
}
