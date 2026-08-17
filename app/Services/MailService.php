<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\CarbonInterface;
use App\Models\ClientEmailVerificationToken;
use App\Models\MessageTemplate;
use App\Models\Invoice;
use App\Models\PhotographerEquipment;
use App\Models\User;
use App\Models\Shoot;
use App\Models\Payment;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\ShootEmailMatrix;
use App\Services\Messaging\TemplateRenderer;
use App\Services\SystemEmails\EmailContextBuilder;
use App\Services\SystemEmails\SystemEmailOrchestrator;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\EmailHealthService;
use App\Support\SupportContact;

class MailService
{
    private const SHOOT_DELIVERED_SUBJECT = 'Your Shoot Has Been Delivered';
    private const SHOOT_REMINDER_SUBJECT = 'Shoot Reminder: 24 Hours to Go';
    private const SHOOT_REMOVED_SUBJECT = 'Photo Shoot Removed from Schedule';
    private const SHOOT_REQUEST_DECLINED_SUBJECT = 'Your Shoot Request Was Declined';
    private const SHOOT_CANCELLED_SUBJECT = 'Your Shoot Has Been Cancelled';
    private const SHOOT_CANCELLATION_REQUESTED_SUBJECT = 'Shoot Cancellation Request Received';
    private const SHOOT_PAID_SUBJECT = 'Payment Confirmed for Your Shoot';
    private const PHOTOGRAPHER_CHANGED_SUBJECT = 'Photographer Assignment Updated';

    public function __construct(
        private readonly ClientEmailVerificationLinkService $clientEmailVerificationLinkService,
        private readonly SystemEmailOrchestrator $systemEmailOrchestrator,
        private readonly EmailContextBuilder $emailContextBuilder,
    ) {
    }

    /**
     * Send account created email
     */
    public function sendAccountCreatedEmail(
        User $user,
        string $resetLink,
        ?string $verificationLink = null,
        ?string $equipmentVerificationLink = null,
        int $pendingEquipmentCount = 0,
        bool $includePasswordCreationLink = false
    ): bool
    {
        try {
            $accountPasswordLink = $includePasswordCreationLink
                ? $this->passwordCreationLink($resetLink)
                : $resetLink;

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($user),
                'links' => [
                    'reset_password' => $accountPasswordLink,
                    'verification' => $verificationLink,
                    'equipment_verification' => $equipmentVerificationLink,
                    'dashboard' => rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/'),
                ],
                'meta' => [
                    'recipient_type' => $this->accountRecipientType($user),
                    'pending_equipment_count' => $pendingEquipmentCount,
                    'include_password_creation_link' => $includePasswordCreationLink,
                    'event_version' => sha1($accountPasswordLink . '|' . ($verificationLink ?? '') . '|' . ($equipmentVerificationLink ?? '') . '|' . $pendingEquipmentCount . '|' . (int) $includePasswordCreationLink),
                ],
            ]);

            $this->dispatchProtectedEmail('ACCOUNT_CREATED', $payload, $user->email, [], [], [
                'related_account_id' => $user->id,
                'enforce_email_health_gate' => false,
            ]);

            Log::info('Account created email sent', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send account created email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    public function sendPhotographerEquipmentVerificationEmail(User $photographer, int $pendingEquipmentCount = 0): bool
    {
        try {
            $equipmentVerificationLink = $this->equipmentVerificationLink($photographer);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($photographer),
                'account' => $this->formatUserData($photographer),
                'links' => [
                    'equipment_verification' => $equipmentVerificationLink,
                    'dashboard' => rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/'),
                ],
                'meta' => [
                    'recipient_type' => 'photographer',
                    'pending_equipment_count' => $pendingEquipmentCount,
                    'event_version' => 'equipment_verification_' . now()->timestamp,
                ],
            ]);

            $this->dispatchProtectedEmail('PHOTOGRAPHER_EQUIPMENT_VERIFICATION', $payload, $photographer->email, [], [], [
                'related_account_id' => $photographer->id,
            ], [
                'force' => true,
            ]);

            Log::info('Photographer equipment verification email sent', [
                'user_id' => $photographer->id,
                'email' => $photographer->email,
                'pending_equipment_count' => $pendingEquipmentCount,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to send photographer equipment verification email', [
                'user_id' => $photographer->id,
                'email' => $photographer->email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function sendPhotographerEquipmentApprovedEmail(User $photographer, PhotographerEquipment $equipment): bool
    {
        try {
            $dashboardUrl = rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/');
            $equipmentUrl = $this->equipmentVerificationLink($photographer);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($photographer),
                'account' => $this->formatUserData($photographer),
                'links' => [
                    'dashboard' => $dashboardUrl,
                    'equipment' => $equipmentUrl,
                ],
                'meta' => [
                    'recipient_type' => 'photographer',
                    'equipment_id' => $equipment->id,
                    'equipment_name' => $equipment->name,
                    'equipment_serial_number' => $equipment->serial_number,
                    'verified_at' => $equipment->verified_at,
                    'event_version' => sha1($equipment->id . '|' . optional($equipment->verified_at)->timestamp),
                ],
            ]);

            $this->dispatchProtectedEmail('PHOTOGRAPHER_EQUIPMENT_APPROVED', $payload, $photographer->email, [], [], [
                'related_account_id' => $photographer->id,
            ], [
                'idempotency_key' => 'photographer-equipment-approved-' . $equipment->id . '-' . optional($equipment->verified_at)->timestamp,
                'canonical_metadata' => [
                    'equipment_id' => $equipment->id,
                ],
            ]);

            Log::info('Photographer equipment approved email sent', [
                'user_id' => $photographer->id,
                'email' => $photographer->email,
                'equipment_id' => $equipment->id,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to send photographer equipment approved email', [
                'user_id' => $photographer->id,
                'email' => $photographer->email,
                'equipment_id' => $equipment->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function sendPhotographerEquipmentRejectedEmail(User $photographer, PhotographerEquipment $equipment): bool
    {
        try {
            $dashboardUrl = rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/');
            $equipmentUrl = $this->equipmentVerificationLink($photographer);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($photographer),
                'account' => $this->formatUserData($photographer),
                'links' => [
                    'dashboard' => $dashboardUrl,
                    'equipment' => $equipmentUrl,
                ],
                'meta' => [
                    'recipient_type' => 'photographer',
                    'equipment_id' => $equipment->id,
                    'equipment_name' => $equipment->name,
                    'equipment_serial_number' => $equipment->serial_number,
                    'rejected_at' => $equipment->rejected_at,
                    'rejection_reason' => $equipment->rejection_reason,
                    'event_version' => sha1($equipment->id . '|' . optional($equipment->rejected_at)->timestamp . '|' . ($equipment->rejection_reason ?? '')),
                ],
            ]);

            $this->dispatchProtectedEmail('PHOTOGRAPHER_EQUIPMENT_REJECTED', $payload, $photographer->email, [], [], [
                'related_account_id' => $photographer->id,
            ], [
                'idempotency_key' => 'photographer-equipment-rejected-' . $equipment->id . '-' . optional($equipment->rejected_at)->timestamp,
                'canonical_metadata' => [
                    'equipment_id' => $equipment->id,
                ],
            ]);

            Log::info('Photographer equipment rejected email sent', [
                'user_id' => $photographer->id,
                'email' => $photographer->email,
                'equipment_id' => $equipment->id,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to send photographer equipment rejected email', [
                'user_id' => $photographer->id,
                'email' => $photographer->email,
                'equipment_id' => $equipment->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function equipmentVerificationLink(?User $photographer = null): string
    {
        $query = ['tab' => 'equipments', 'verify' => 'equipment'];

        if ($photographer !== null) {
            $query['photographer_id'] = (string) $photographer->id;
        }

        return rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/')
            . '/photographer-account?' . http_build_query($query);
    }

    private function accountRecipientType(User $user): string
    {
        return match (strtolower(str_replace(['-', '_', ' '], '', (string) $user->role))) {
            'client' => 'client',
            'photographer' => 'photographer',
            'salesrep' => 'rep',
            'editor' => 'editor',
            'admin', 'superadmin', 'editingmanager' => 'admin',
            default => 'other',
        };
    }

    public function generateClientEmailVerificationLink(User $user, array $context = []): string
    {
        return $this->clientEmailVerificationLinkService->buildUrl($user, null, $context);
    }

    public function sendClientEmailVerificationEmail(User $user, array $context = []): bool
    {
        try {
            $verificationToken = $context['verification_token'] ?? null;
            $verificationLink = $context['verification_link'] ?? null;

            if (!$verificationToken instanceof ClientEmailVerificationToken) {
                $verificationToken = $this->clientEmailVerificationLinkService->issueVerificationToken($user, $context);
            }

            if (!is_string($verificationLink) || trim($verificationLink) === '') {
                $verificationLink = $this->clientEmailVerificationLinkService->buildUrlForIssuedToken($user, $verificationToken);
            }

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($user),
                'links' => [
                    'verification' => $verificationLink,
                    'dashboard' => rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/'),
                ],
                'meta' => [
                    'recipient_type' => $this->accountRecipientType($user),
                    'event_version' => 'verification_token_' . $verificationToken->id,
                    'verification_token_id' => $verificationToken->id,
                    'verification_expires_at' => $verificationToken->expires_at?->toIso8601String(),
                    'verification_issued_context' => $verificationToken->issued_context,
                ],
            ]);

            $this->dispatchProtectedEmail('CLIENT_EMAIL_VERIFICATION', $payload, $user->email, [], [], [
                'related_account_id' => $user->id,
            ], [
                'idempotency_key' => sprintf('CLIENT_EMAIL_VERIFICATION:%d:%d', $user->id, $verificationToken->id),
                'canonical_metadata' => [
                    'verification_token_id' => $verificationToken->id,
                    'verification_issued_context' => $verificationToken->issued_context,
                    'verification_expires_at' => $verificationToken->expires_at?->toIso8601String(),
                ],
            ]);

            Log::info('Account email verification email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
                'verification_token_id' => $verificationToken->id,
                'issued_context' => $verificationToken->issued_context,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to send account email verification email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function sendClientEmailVerifiedEmail(User $user, array $context = []): bool
    {
        try {
            $dashboardUrl = rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/');
            $verificationTokenId = $context['verification_token_id'] ?? null;
            $eventVersion = $verificationTokenId !== null
                ? 'verification_confirmed_' . $verificationTokenId
                : 'verification_confirmed_' . sha1(strtolower((string) $user->email));

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($user),
                'links' => [
                    'dashboard' => $dashboardUrl,
                    'settings' => $dashboardUrl . '/settings',
                ],
                'meta' => [
                    'recipient_type' => $this->accountRecipientType($user),
                    'event_version' => $eventVersion,
                    'verification_token_id' => $verificationTokenId,
                ],
            ]);

            $this->dispatchProtectedEmail('CLIENT_EMAIL_VERIFIED', $payload, $user->email, [], [], [
                'related_account_id' => $user->id,
            ], [
                'idempotency_key' => sprintf(
                    'CLIENT_EMAIL_VERIFIED:%d:%s',
                    $user->id,
                    $verificationTokenId !== null ? (string) $verificationTokenId : sha1(strtolower((string) $user->email))
                ),
                'canonical_metadata' => [
                    'verification_token_id' => $verificationTokenId,
                ],
            ]);

            Log::info('Client email verified confirmation email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
                'verification_token_id' => $verificationTokenId,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to send client email verified confirmation email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'verification_token_id' => $context['verification_token_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send shoot scheduled email
     */
    public function sendShootScheduledEmail(
        User $user,
        Shoot $shoot,
        string $paymentLink,
        ?bool $shouldNotifyPhotographer = true
    ): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $isDirectPhotographer = $this->isPhotographerRecipient($user, $shoot);
            $sentPrimaryRecipient = $this->sendShootScheduledEmailToRecipient(
                $user,
                $shoot,
                $shootData,
                $isDirectPhotographer ? '' : $paymentLink,
                $isDirectPhotographer,
                $clientCcEmails
            );
            $sentAssignedPhotographer = false;

            if (
                $shouldNotifyPhotographer !== false
                && $this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_SCHEDULED)
            ) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $sentAssignedPhotographer = $this->sendShootScheduledEmailToRecipient(
                        $photographer,
                        $shoot,
                        $shootData,
                        '',
                        true
                    ) || $sentAssignedPhotographer;
                }
            }

            return $sentPrimaryRecipient || $sentAssignedPhotographer;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot scheduled email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    public function sendAssignedPhotographerShootScheduledEmails(Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $sentAssignedPhotographer = false;

            foreach ($this->resolveAssignedPhotographers($shoot) as $photographer) {
                $sentAssignedPhotographer = $this->sendShootScheduledEmailToRecipient(
                    $photographer,
                    $shoot,
                    $shootData,
                    '',
                    true
                ) || $sentAssignedPhotographer;
            }

            return $sentAssignedPhotographer;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot scheduled emails to assigned photographers', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendShootScheduledEmailToRecipient(
        User $recipient,
        Shoot $shoot,
        object $shootData,
        string $paymentLink,
        bool $isPhotographer,
        array $cc = []
    ): bool
    {
        try {
            $normalizedEmail = $this->normalizeDeliverableEmail($recipient->email);
            $recipientShootData = $isPhotographer
                ? $this->formatShootData($shoot, $recipient, 'photographer')
                : $shootData;

            if ($normalizedEmail === null) {
                $this->logSkippedShootEmailDelivery(
                    'SHOOT_SCHEDULED',
                    $shoot,
                    $recipient,
                    $isPhotographer ? 'photographer' : 'client'
                );

                return false;
            }

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($recipient),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $recipientShootData,
                'links' => [
                    'payment' => $paymentLink,
                    'dashboard' => $recipientShootData->dashboard_url ?? null,
                ],
                'meta' => [
                    'recipient_type' => $isPhotographer ? 'photographer' : 'client',
                    'is_photographer' => $isPhotographer,
                    'role_context' => $isPhotographer ? 'photographer' : 'client',
                    'shoot_service_ids' => $recipientShootData->service_item_ids ?? [],
                    'event_version' => $shoot->updated_at?->toIso8601String() ?? $shoot->id,
                ],
            ]);

            $this->dispatchProtectedEmail('SHOOT_SCHEDULED', $payload, $normalizedEmail, $cc, [], [
                'related_shoot_id' => $shoot->id,
                'related_account_id' => $isPhotographer ? null : $recipient->id,
            ], [
                'idempotency_key' => sprintf(
                    'SHOOT_SCHEDULED:%d:%d:%s:%s',
                    $shoot->id,
                    $recipient->id,
                    $isPhotographer ? 'photographer' : 'client',
                    $this->serviceScopeHash($recipientShootData)
                ),
            ]);

            if ($isPhotographer) {
                Log::info('Shoot scheduled email sent to photographer', [
                    'photographer_id' => $recipient->id,
                    'shoot_id' => $shoot->id,
                    'email' => $normalizedEmail,
                ]);
            } else {
                Log::info('Shoot scheduled email sent', [
                    'user_id' => $recipient->id,
                    'shoot_id' => $shoot->id,
                    'email' => $normalizedEmail,
                    'is_photographer' => false,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot scheduled email to recipient', [
                'user_id' => $recipient->id,
                'shoot_id' => $shoot->id,
                'email' => $recipient->email,
                'recipient_type' => $isPhotographer ? 'photographer' : 'client',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send shoot updated email
     */
    public function sendShootUpdatedEmail(
        User $user,
        Shoot $shoot,
        ?string $changesSummary = null,
        ?bool $notifyClient = null,
        ?bool $notifyPhotographer = null
    ): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'service', 'services']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $normalizedChangesSummary = $this->normalizeChangeSummaryText($changesSummary);
            $shouldNotifyClient = $notifyClient !== false;
            $shouldNotifyPhotographer = $notifyPhotographer !== false;
            $isPrimaryRecipientPhotographer = $this->isPhotographerRecipient($user, $shoot);
            $primaryShootData = $isPrimaryRecipientPhotographer
                ? $this->formatShootData($shoot, $user, 'photographer')
                : $shootData;
            $sentClient = false;
            $sentPhotographer = false;
            
            if ($shouldNotifyClient) {
                $normalizedEmail = $this->normalizeDeliverableEmail($user->email);

                if ($normalizedEmail === null) {
                    $this->logSkippedShootEmailDelivery('SHOOT_UPDATED', $shoot, $user, 'client');
                } else {
                    $payload = $this->buildProtectedEmailPayload([
                        'recipient' => $this->formatUserData($user),
                        'account' => $this->formatUserData($shoot->client),
                        'shoot' => $primaryShootData,
                        'meta' => [
                            'recipient_type' => $isPrimaryRecipientPhotographer ? 'photographer' : 'client',
                            'is_photographer' => $isPrimaryRecipientPhotographer,
                            'role_context' => $isPrimaryRecipientPhotographer ? 'photographer' : 'client',
                            'shoot_service_ids' => $primaryShootData->service_item_ids ?? [],
                            'changes_summary' => $normalizedChangesSummary,
                            'event_version' => sha1($normalizedChangesSummary . '|' . ($shoot->updated_at?->toIso8601String() ?? $shoot->id)),
                        ],
                    ]);
                    $this->dispatchProtectedEmail('SHOOT_UPDATED', $payload, $normalizedEmail, $clientCcEmails, [], $this->automatedClientPayload($user, [
                        'related_shoot_id' => $shoot->id,
                    ]), [
                        'idempotency_key' => sprintf('SHOOT_UPDATED:%d:%d:client:%s', $shoot->id, $user->id, sha1($normalizedChangesSummary)),
                    ]);
                    $sentClient = true;
                    
                    Log::info('Shoot updated email sent', [
                        'user_id' => $user->id,
                        'shoot_id' => $shoot->id,
                        'email' => $normalizedEmail
                    ]);
                }
            } else {
                Log::info('Shoot updated email skipped for client', [
                    'user_id' => $user->id,
                    'shoot_id' => $shoot->id,
                    'email' => $user->email
                ]);
            }

            // Also send to photographer if assigned
            if (
                $shouldNotifyPhotographer
                && $this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_UPDATED)
            ) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $normalizedEmail = $this->normalizeDeliverableEmail($photographer->email);
                    $photographerShootData = $this->formatShootData($shoot, $photographer, 'photographer');

                    if ($normalizedEmail === null) {
                        $this->logSkippedShootEmailDelivery('SHOOT_UPDATED', $shoot, $photographer, 'photographer');
                        continue;
                    }

                    $payload = $this->buildProtectedEmailPayload([
                        'recipient' => $this->formatUserData($photographer),
                        'account' => $this->formatUserData($shoot->client),
                        'shoot' => $photographerShootData,
                        'meta' => [
                            'recipient_type' => 'photographer',
                            'is_photographer' => true,
                            'role_context' => 'photographer',
                            'shoot_service_ids' => $photographerShootData->service_item_ids ?? [],
                            'changes_summary' => $normalizedChangesSummary,
                            'event_version' => sha1($normalizedChangesSummary . '|' . ($shoot->updated_at?->toIso8601String() ?? $shoot->id)),
                        ],
                    ]);
                    $this->dispatchProtectedEmail('SHOOT_UPDATED', $payload, $normalizedEmail, [], [], [
                        'related_shoot_id' => $shoot->id,
                    ], [
                        'idempotency_key' => sprintf(
                            'SHOOT_UPDATED:%d:%d:photographer:%s:%s',
                            $shoot->id,
                            $photographer->id,
                            $this->serviceScopeHash($photographerShootData),
                            sha1($normalizedChangesSummary)
                        ),
                    ]);
                    $sentPhotographer = true;
                    Log::info('Shoot updated email sent to photographer', [
                        'photographer_id' => $photographer->id,
                        'shoot_id' => $shoot->id,
                        'email' => $normalizedEmail,
                    ]);
                }
            } elseif ($this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_UPDATED)) {
                Log::info('Shoot updated email skipped for photographer', [
                    'shoot_id' => $shoot->id,
                    'excluded_user_id' => $user->id,
                ]);
            }
            
            if (!$shouldNotifyClient && !$shouldNotifyPhotographer) {
                return true;
            }

            return $sentClient || $sentPhotographer;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot updated email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    private function normalizeDeliverableEmail(?string $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }

        $normalized = strtolower(trim($email));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    private function logSkippedShootEmailDelivery(
        string $sendSource,
        Shoot $shoot,
        User $recipient,
        string $recipientType
    ): void {
        Log::warning('Skipping shoot email delivery because recipient has no deliverable email.', [
            'send_source' => $sendSource,
            'trigger_type' => $sendSource,
            'shoot_id' => $shoot->id,
            'recipient_type' => $recipientType,
            'client_id' => $recipientType === 'client' ? $recipient->id : null,
            'photographer_id' => $recipientType === 'photographer' ? $recipient->id : null,
            'email' => $recipient->email,
        ]);
    }

    public function sendShootReminderEmail(
        User $user,
        Shoot $shoot,
        ?CarbonInterface $scheduledAt = null,
        array $tags = [],
        ?bool $shouldNotifyPhotographer = true,
        array $serviceItemIds = []
    ): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $isDirectPhotographer = $this->isPhotographerRecipient($user, $shoot);
            $shootData = $this->formatShootData(
                $shoot,
                $user,
                $isDirectPhotographer ? 'photographer' : null,
                $serviceItemIds
            );
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'meta' => [
                    'recipient_type' => $isDirectPhotographer ? 'photographer' : 'client',
                    'is_photographer' => $isDirectPhotographer,
                    'role_context' => $isDirectPhotographer ? 'photographer' : 'client',
                    'shoot_service_ids' => $shootData->service_item_ids ?? [],
                    'scheduled_at' => $scheduledAt?->toIso8601String(),
                    'event_version' => $scheduledAt?->toIso8601String() ?? $shoot->scheduled_at?->toIso8601String() ?? $shoot->id,
                ],
            ]);

            $this->dispatchProtectedEmail('SHOOT_REMINDER', $payload, $user->email, $clientCcEmails, $tags, $this->automatedClientPayload($isDirectPhotographer ? null : $user, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf(
                    'SHOOT_REMINDER:%d:%d:%s:%s',
                    $shoot->id,
                    $user->id,
                    $this->serviceScopeHash($shootData),
                    $scheduledAt?->toIso8601String() ?? 'default'
                ),
            ]);

            if (
                $shouldNotifyPhotographer !== false
                && $this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_REMINDER)
            ) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $photographerShootData = $this->formatShootData($shoot, $photographer, 'photographer', $serviceItemIds);
                    $payload = $this->buildProtectedEmailPayload([
                        'recipient' => $this->formatUserData($photographer),
                        'account' => $this->formatUserData($shoot->client),
                        'shoot' => $photographerShootData,
                        'meta' => [
                            'recipient_type' => 'photographer',
                            'is_photographer' => true,
                            'role_context' => 'photographer',
                            'shoot_service_ids' => $photographerShootData->service_item_ids ?? [],
                            'scheduled_at' => $scheduledAt?->toIso8601String(),
                            'event_version' => $scheduledAt?->toIso8601String() ?? $shoot->scheduled_at?->toIso8601String() ?? $shoot->id,
                        ],
                    ]);

                    $this->dispatchProtectedEmail('SHOOT_REMINDER', $payload, $photographer->email, [], $tags, [
                        'related_shoot_id' => $shoot->id,
                    ], [
                        'idempotency_key' => sprintf(
                            'SHOOT_REMINDER:%d:%d:%s:%s',
                            $shoot->id,
                            $photographer->id,
                            $this->serviceScopeHash($photographerShootData),
                            $scheduledAt?->toIso8601String() ?? 'default'
                        ),
                    ]);
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot reminder email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send shoot removed email
     */
    public function sendShootRemovedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'meta' => [
                    'recipient_type' => 'client',
                    'event_version' => $shoot->updated_at?->toIso8601String() ?? $shoot->id,
                ],
            ]);
            $this->dispatchProtectedEmail('SHOOT_REMOVED', $payload, $user->email, $clientCcEmails, [], $this->automatedClientPayload($user, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf('SHOOT_REMOVED:%d:%d:client', $shoot->id, $user->id),
            ]);
            
            Log::info('Shoot removed email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);

            if ($this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_REMOVED)) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $payload = $this->buildProtectedEmailPayload([
                        'recipient' => $this->formatUserData($photographer),
                        'account' => $this->formatUserData($shoot->client),
                        'shoot' => $shootData,
                        'meta' => [
                            'recipient_type' => 'photographer',
                            'event_version' => $shoot->updated_at?->toIso8601String() ?? $shoot->id,
                        ],
                    ]);
                    $this->dispatchProtectedEmail('SHOOT_REMOVED', $payload, $photographer->email, [], [], [
                        'related_shoot_id' => $shoot->id,
                    ], [
                        'idempotency_key' => sprintf('SHOOT_REMOVED:%d:%d:photographer', $shoot->id, $photographer->id),
                    ]);
                    Log::info('Shoot removed email sent to photographer', [
                        'photographer_id' => $photographer->id,
                        'shoot_id' => $shoot->id,
                        'email' => $photographer->email,
                    ]);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot removed email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    public function sendShootRequestDeclinedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $declineReason = trim((string) ($shoot->declined_reason ?? ''));
            if ($declineReason === '') {
                $declineReason = 'No reason was provided.';
            }

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'meta' => [
                    'recipient_type' => 'client',
                    'decline_reason' => $declineReason,
                    'event_version' => sha1($declineReason),
                ],
            ]);

            $this->dispatchProtectedEmail('SHOOT_REQUEST_DECLINED', $payload, $user->email, [], [], $this->automatedClientPayload($user, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf('SHOOT_REQUEST_DECLINED:%d:%d:%s', $shoot->id, $user->id, sha1($declineReason)),
            ]);

            Log::info('Shoot request declined email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot request declined email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendShootRequestedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'meta' => [
                    'recipient_type' => 'client',
                    'is_admin' => false,
                    'event_version' => $shoot->created_at?->toIso8601String() ?? $shoot->id,
                ],
            ]);

            $this->dispatchProtectedEmail('SHOOT_REQUESTED', $payload, $user->email, $clientCcEmails, [], $this->automatedClientPayload($user, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf('SHOOT_REQUESTED:%d:%d:client', $shoot->id, $user->id),
            ]);

            Log::info('Shoot requested email sent to client', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to send shoot requested email to client', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function sendShootRequestedAdminNotificationEmails(Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $admins = User::query()
                ->whereIn('role', ['admin', 'superadmin'])
                ->get();

            if ($admins->isEmpty()) {
                Log::warning('No admins found to send shoot requested fallback notification.', [
                    'shoot_id' => $shoot->id,
                ]);

                return false;
            }

            $sent = false;

            foreach ($admins as $admin) {
                $payload = $this->buildProtectedEmailPayload([
                    'recipient' => $this->formatUserData($admin),
                    'account' => $this->formatUserData($shoot->client),
                    'shoot' => $shootData,
                    'meta' => [
                        'recipient_type' => 'admin',
                        'is_admin' => true,
                        'event_version' => $shoot->created_at?->toIso8601String() ?? $shoot->id,
                    ],
                ]);

                $this->dispatchProtectedEmail('SHOOT_REQUESTED', $payload, $admin->email, [], [], [
                    'related_shoot_id' => $shoot->id,
                ], [
                    'idempotency_key' => sprintf('SHOOT_REQUESTED:%d:%d:admin', $shoot->id, $admin->id),
                ]);

                $sent = true;
            }

            Log::info('Shoot requested fallback emails sent to admins.', [
                'shoot_id' => $shoot->id,
                'admin_count' => $admins->count(),
            ]);

            return $sent;
        } catch (\Throwable $exception) {
            Log::error('Failed to send shoot requested fallback emails to admins.', [
                'shoot_id' => $shoot->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send shoot cancellation requested email
     */
    public function sendShootCancellationRequestedEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $isPhotographer = $this->isPhotographerRecipient($user, $shoot);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'meta' => [
                    'recipient_type' => $isPhotographer ? 'photographer' : 'client',
                    'is_photographer' => $isPhotographer,
                    'cancellation_reason' => $shoot->cancellation_reason,
                    'event_version' => sha1((string) $shoot->cancellation_reason),
                ],
            ]);
            $this->dispatchProtectedEmail('SHOOT_CANCELLATION_REQUESTED', $payload, $user->email, $clientCcEmails, [], $this->automatedClientPayload($isPhotographer ? null : $user, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf('SHOOT_CANCELLATION_REQUESTED:%d:%d:%s', $shoot->id, $user->id, sha1((string) $shoot->cancellation_reason)),
            ]);

            Log::info('Shoot cancellation requested email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'is_photographer' => $isPhotographer,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot cancellation requested email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send shoot ready email
     */
    public function sendShootReadyEmail(
        User $user,
        Shoot $shoot,
        array $serviceItemIds = [],
        bool $isFullOrderDelivery = true
    ): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category', 'payments']) ?? $shoot;
            $shootData = $this->formatShootData($shoot, $user, 'client', $serviceItemIds);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $paymentLink = $this->shouldShowShootReadyPaymentLink($shoot)
                ? $this->generatePaymentLink($shoot)
                : null;
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'links' => [
                    'payment' => $paymentLink,
                ],
                'meta' => [
                    'recipient_type' => 'client',
                    'role_context' => 'client',
                    'shoot_service_ids' => $shootData->service_item_ids ?? [],
                    'is_full_order_delivery' => $isFullOrderDelivery,
                    'event_version' => $shoot->updated_at?->toIso8601String() ?? $shoot->id,
                ],
            ]);
            $this->dispatchProtectedEmail('SHOOT_DELIVERED', $payload, $user->email, $clientCcEmails, [], $this->automatedClientPayload($user, [
                'related_shoot_id' => $shoot->id,
                // The delivered email is a mandatory transactional notification:
                // bypass the email-health gate so it is never silently
                // suppressed. The idempotency key below still prevents duplicates.
                'enforce_email_health_gate' => false,
            ]), [
                'idempotency_key' => sprintf(
                    'SHOOT_DELIVERED:%d:%d:%s:%s',
                    $shoot->id,
                    $user->id,
                    $isFullOrderDelivery ? 'full' : 'partial',
                    $this->serviceScopeHash($shootData)
                ),
            ]);
            
            Log::info('Shoot ready email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send shoot ready email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send payment confirmation email
     */
    public function sendPaymentConfirmationEmail(User $user, Shoot $shoot, Payment $payment): bool
    {
        try {
            $shootData = $this->formatShootData($shoot);
            $paymentData = $this->formatPaymentData($payment);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'payment' => $paymentData,
                'meta' => [
                    'recipient_type' => 'client',
                    'event_version' => $payment->transaction_id ?: $payment->id,
                ],
            ]);
            $this->dispatchProtectedEmail('PAYMENT_CONFIRMATION', $payload, $user->email, $clientCcEmails, [], $this->automatedClientPayload($user, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf('PAYMENT_CONFIRMATION:%d:%d:%s', $shoot->id, $user->id, $payment->transaction_id ?: $payment->id),
            ]);
            
            Log::info('Payment confirmation email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'email' => $user->email
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payment confirmation email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send terms accepted email
     */
    public function sendTermsAcceptedEmail(User $user): bool
    {
        try {
            $html = view('emails.terms_accepted', [
                'user' => $user,
            ])->render();
            $this->sendViaCakemail(
                $user->email,
                'Terms/Conditions Accepted',
                $html,
                'TERMS_ACCEPTED',
                [],
                [],
                $this->automatedClientPayload($user, [
                    'enforce_email_health_gate' => false,
                ])
            );
            
            Log::info('Terms accepted email sent', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send terms accepted email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Capture shoot snapshot
     */
    public function captureShootSnapshot(Shoot $shoot): array
    {
        $shoot = $shoot->fresh(['client', 'photographer', 'service', 'services', 'ghostUsers']) ?? $shoot;
        $shoot->loadMissing(['client', 'photographer', 'service', 'services', 'ghostUsers']);

        $propertyDetails = $this->normalizePropertyDetails($shoot->property_details);

        return [
            'status' => $shoot->status,
            'workflow_status' => $shoot->workflow_status,
            'scheduled_at' => $shoot->scheduled_at?->toISOString(),
            'scheduled_date' => $shoot->scheduled_date?->toDateString(),
            'time' => $shoot->time,
            'timezone' => $shoot->timezone,
            'location' => $this->formatFullAddress($shoot) ?: 'TBD',
            'client_name' => $shoot->client?->name,
            'photographer_name' => $shoot->photographer?->name,
            'base_quote' => (float) ($shoot->base_quote ?? 0),
            'discount_type' => $shoot->discount_type,
            'discount_value' => $shoot->discount_value,
            'discount_amount' => (float) ($shoot->discount_amount ?? 0),
            'tax_amount' => (float) ($shoot->tax_amount ?? 0),
            'total_quote' => (float) ($shoot->total_quote ?? 0),
            'shoot_type' => $shoot->shoot_type,
            'product_status' => $shoot->product_status,
            'listing_type' => $shoot->listing_type,
            'property_status' => $shoot->property_status,
            'mls_id' => $shoot->mls_id,
            'mls_image_width' => $shoot->mls_image_width,
            'iguide_property_id' => $shoot->iguide_property_id,
            'iguide_work_order_id' => $shoot->iguide_work_order_id,
            'shoot_notes' => $shoot->shoot_notes,
            'company_notes' => $shoot->company_notes,
            'photographer_notes' => $shoot->photographer_notes,
            'editor_notes' => $shoot->editor_notes,
            'is_private_listing' => (bool) ($shoot->is_private_listing ?? false),
            'is_listing_hidden' => (bool) ($shoot->is_listing_hidden ?? false),
            'is_featured' => (bool) ($shoot->is_featured ?? false),
            'featured_homepage_title' => $shoot->featured_homepage_title,
            'featured_homepage_location' => $shoot->featured_homepage_location,
            'featured_homepage_subtitle' => $shoot->featured_homepage_subtitle,
            'featured_homepage_cta_label' => $shoot->featured_homepage_cta_label,
            'featured_homepage_cta_href' => $shoot->featured_homepage_cta_href,
            'ghost_users' => $shoot->ghostUsers
                ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => $user->name])
                ->sortBy('id')
                ->values()
                ->all(),
            'tour_links' => $this->normalizeArrayForComparison($shoot->tour_links ?? []),
            'services' => $this->formatServicesForComparison($shoot),
            'property_details' => [
                'bedrooms' => $propertyDetails['bedrooms'] ?? $propertyDetails['beds'] ?? null,
                'bathrooms' => $propertyDetails['bathrooms'] ?? $propertyDetails['baths'] ?? null,
                'sqft' => $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? null,
                'presence_option' => $propertyDetails['presenceOption'] ?? null,
                'access_contact_name' => $propertyDetails['accessContactName'] ?? null,
                'access_contact_phone' => $propertyDetails['accessContactPhone'] ?? null,
                'lockbox_code' => $propertyDetails['lockboxCode'] ?? null,
                'lockbox_location' => $propertyDetails['lockboxLocation'] ?? null,
            ],
        ];
    }

    /**
     * Build shoot change summary
     */
    public function buildShootChangeSummary(array $before, Shoot $shoot): array
    {
        $shoot = $shoot->fresh(['client', 'photographer', 'service', 'services', 'ghostUsers']) ?? $shoot;
        $shoot->loadMissing(['client', 'photographer', 'service', 'services', 'ghostUsers']);

        $afterPropertyDetails = $this->normalizePropertyDetails($shoot->property_details);
        $changes = [];

        $this->addChangeLine(
            $changes,
            'Status',
            $this->formatStatusValue($before['status'] ?? null),
            $this->formatStatusValue($shoot->status)
        );

        $this->addChangeLine(
            $changes,
            'Workflow Status',
            $this->formatStatusValue($before['workflow_status'] ?? null),
            $this->formatStatusValue($shoot->workflow_status)
        );

        $this->addChangeLine(
            $changes,
            'Schedule',
            $this->formatScheduleValue(
                $before['scheduled_date'] ?? null,
                $before['time'] ?? null,
                $before['scheduled_at'] ?? null
            ),
            $this->formatScheduleValue(
                $shoot->scheduled_date?->toDateString(),
                $shoot->time,
                $shoot->scheduled_at?->toISOString()
            )
        );

        $this->addChangeLine(
            $changes,
            'Timezone',
            $this->normalizeChangeText($before['timezone'] ?? null),
            $this->normalizeChangeText($shoot->timezone)
        );

        $this->addChangeLine(
            $changes,
            'Location',
            $before['location'] ?? 'TBD',
            $this->formatFullAddress($shoot) ?: 'TBD'
        );

        $this->addChangeLine(
            $changes,
            'Services',
            $this->formatServiceSummary($before['services'] ?? []),
            $this->formatServiceSummary($this->formatServicesForComparison($shoot))
        );

        $this->addChangeLine(
            $changes,
            'Client',
            $this->normalizeChangeText($before['client_name'] ?? null),
            $this->normalizeChangeText($shoot->client?->name)
        );

        $this->addChangeLine(
            $changes,
            'Photographer',
            $this->normalizeChangeText($before['photographer_name'] ?? null),
            $this->normalizeChangeText($shoot->photographer?->name)
        );

        $this->addChangeLine(
            $changes,
            'Base Quote',
            $this->formatCurrency($before['base_quote'] ?? 0),
            $this->formatCurrency($shoot->base_quote ?? 0)
        );

        $this->addChangeLine(
            $changes,
            'Discount Type',
            $this->formatStatusValue($before['discount_type'] ?? null),
            $this->formatStatusValue($shoot->discount_type)
        );

        $this->addChangeLine(
            $changes,
            'Discount Value',
            $this->formatDiscountValue($before['discount_type'] ?? null, $before['discount_value'] ?? null),
            $this->formatDiscountValue($shoot->discount_type, $shoot->discount_value)
        );

        $this->addChangeLine(
            $changes,
            'Discount Amount',
            $this->formatCurrency($before['discount_amount'] ?? 0),
            $this->formatCurrency($shoot->discount_amount ?? 0)
        );

        $this->addChangeLine(
            $changes,
            'Tax',
            $this->formatCurrency($before['tax_amount'] ?? 0),
            $this->formatCurrency($shoot->tax_amount ?? 0)
        );

        $this->addChangeLine(
            $changes,
            'Total',
            $this->formatCurrency($before['total_quote'] ?? 0),
            $this->formatCurrency($shoot->total_quote ?? 0)
        );

        $this->addChangeLine(
            $changes,
            'Shoot Type',
            $this->formatStatusValue($before['shoot_type'] ?? null),
            $this->formatStatusValue($shoot->shoot_type)
        );

        $this->addChangeLine(
            $changes,
            'Product Status',
            $this->formatStatusValue($before['product_status'] ?? null),
            $this->formatStatusValue($shoot->product_status)
        );

        $this->addChangeLine(
            $changes,
            'Listing Type',
            $this->formatStatusValue($before['listing_type'] ?? null),
            $this->formatStatusValue($shoot->listing_type)
        );

        $this->addChangeLine(
            $changes,
            'Property Status',
            $this->formatStatusValue($before['property_status'] ?? null),
            $this->formatStatusValue($shoot->property_status)
        );

        $this->addChangeLine(
            $changes,
            'MLS ID',
            $this->normalizeChangeText($before['mls_id'] ?? null),
            $this->normalizeChangeText($shoot->mls_id)
        );

        $this->addChangeLine(
            $changes,
            'MLS Image Width',
            $this->formatNumberValue($before['mls_image_width'] ?? null),
            $this->formatNumberValue($shoot->mls_image_width)
        );

        $this->addChangeLine(
            $changes,
            'iGUIDE Property ID',
            $this->normalizeChangeText($before['iguide_property_id'] ?? null),
            $this->normalizeChangeText($shoot->iguide_property_id)
        );

        $this->addChangeLine(
            $changes,
            'iGUIDE Work Order ID',
            $this->normalizeChangeText($before['iguide_work_order_id'] ?? null),
            $this->normalizeChangeText($shoot->iguide_work_order_id)
        );

        $this->addChangeLine(
            $changes,
            'Shoot Notes',
            $this->normalizeChangeText($before['shoot_notes'] ?? null),
            $this->normalizeChangeText($shoot->shoot_notes)
        );

        $this->addChangeLine(
            $changes,
            'Company Notes',
            $this->normalizeChangeText($before['company_notes'] ?? null),
            $this->normalizeChangeText($shoot->company_notes)
        );

        $this->addChangeLine(
            $changes,
            'Photographer Notes',
            $this->normalizeChangeText($before['photographer_notes'] ?? null),
            $this->normalizeChangeText($shoot->photographer_notes)
        );

        $this->addChangeLine(
            $changes,
            'Editor Notes',
            $this->normalizeChangeText($before['editor_notes'] ?? null),
            $this->normalizeChangeText($shoot->editor_notes)
        );

        $this->addChangeLine(
            $changes,
            'Bedrooms',
            $this->formatNumberValue($before['property_details']['bedrooms'] ?? null),
            $this->formatNumberValue($afterPropertyDetails['bedrooms'] ?? $afterPropertyDetails['beds'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Bathrooms',
            $this->formatNumberValue($before['property_details']['bathrooms'] ?? null, 1),
            $this->formatNumberValue($afterPropertyDetails['bathrooms'] ?? $afterPropertyDetails['baths'] ?? null, 1)
        );

        $this->addChangeLine(
            $changes,
            'Square Footage',
            $this->formatSquareFootage($before['property_details']['sqft'] ?? null),
            $this->formatSquareFootage($afterPropertyDetails['sqft'] ?? $afterPropertyDetails['squareFeet'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Access Type',
            $this->formatStatusValue($before['property_details']['presence_option'] ?? null),
            $this->formatStatusValue($afterPropertyDetails['presenceOption'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Access Contact Name',
            $this->normalizeChangeText($before['property_details']['access_contact_name'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['accessContactName'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Access Contact Phone',
            $this->normalizeChangeText($before['property_details']['access_contact_phone'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['accessContactPhone'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Lockbox Code',
            $this->normalizeChangeText($before['property_details']['lockbox_code'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['lockboxCode'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Lockbox Location',
            $this->normalizeChangeText($before['property_details']['lockbox_location'] ?? null),
            $this->normalizeChangeText($afterPropertyDetails['lockboxLocation'] ?? null)
        );

        $this->addChangeLine(
            $changes,
            'Private Listing',
            $this->formatBooleanValue($before['is_private_listing'] ?? false),
            $this->formatBooleanValue((bool) ($shoot->is_private_listing ?? false))
        );

        $this->addChangeLine(
            $changes,
            'Listing Hidden',
            $this->formatBooleanValue($before['is_listing_hidden'] ?? false),
            $this->formatBooleanValue((bool) ($shoot->is_listing_hidden ?? false))
        );

        $this->addChangeLine(
            $changes,
            'Featured',
            $this->formatBooleanValue($before['is_featured'] ?? false),
            $this->formatBooleanValue((bool) ($shoot->is_featured ?? false))
        );

        foreach ([
            'featured_homepage_title' => 'Featured Homepage Title',
            'featured_homepage_location' => 'Featured Homepage Location',
            'featured_homepage_subtitle' => 'Featured Homepage Subtitle',
            'featured_homepage_cta_label' => 'Featured Homepage CTA Label',
            'featured_homepage_cta_href' => 'Featured Homepage CTA Link',
        ] as $field => $label) {
            $this->addChangeLine(
                $changes,
                $label,
                $this->normalizeChangeText($before[$field] ?? null),
                $this->normalizeChangeText($shoot->{$field})
            );
        }

        $this->addChangeLine(
            $changes,
            'Shared Client Access',
            $this->formatUserSummary($before['ghost_users'] ?? []),
            $this->formatUserSummary($shoot->ghostUsers
                ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => $user->name])
                ->sortBy('id')
                ->values()
                ->all())
        );

        if (($before['tour_links'] ?? []) !== $this->normalizeArrayForComparison($shoot->tour_links ?? [])) {
            $changes[] = 'Tour Links: Updated';
        }

        return [
            'summary' => implode("\n", $changes),
            'html' => $this->buildChangeSummaryHtml($changes),
            'lines' => $changes,
        ];
    }

    /**
     * Format shoot data for email templates
     */
    private function formatShootData(
        Shoot $shoot,
        ?User $recipient = null,
        ?string $roleContext = null,
        array $serviceItemIds = []
    ): object
    {
        $shoot->loadMissing(['client', 'photographer', 'rep', 'services.category']);

        $fullAddress = $this->formatFullAddress($shoot);
        $propertyDetails = $this->normalizePropertyDetails($shoot->property_details);

        $formattedTime = null;
        if ($shoot->time) {
            try {
                $formattedTime = \Carbon\Carbon::parse($shoot->time)->format('g:i A');
            } catch (\Exception $e) {
                $formattedTime = $shoot->time;
            }
        }

        $dateStr = 'TBD';
        if ($shoot->scheduled_date) {
            $dateStr = $shoot->scheduled_date->format('M j, Y');
        }

        $notesText = $this->formatNotes($shoot);
        $serviceRows = $this->formatDetailedServices($shoot, $recipient, $roleContext, $serviceItemIds);
        $assignedPhotographers = $this->formatAssignedPhotographers($shoot, $serviceRows);
        $packageRows = ($recipient || !empty($serviceItemIds))
            ? $this->formatPackagesFromServiceRows($serviceRows)
            : $this->formatPackages($shoot);
        $resolvedServiceItemIds = collect($serviceRows)
            ->pluck('shoot_service_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $paymentStatus = $this->resolveShootPaymentStatus($shoot);
        $totalPaid = $shoot->relationLoaded('payments')
            ? $shoot->calculateCanonicalTotalPaid()
            : (float) ($shoot->total_paid ?? 0);
        $remainingBalance = max((float) ($shoot->total_quote ?? 0) - $totalPaid, 0);

        return (object) [
            'id' => $shoot->id,
            'location' => $fullAddress ?: 'TBD',
            'date' => $dateStr,
            'time' => $formattedTime ?? 'TBD',
            'status' => $shoot->status,
            'status_label' => $this->formatStatusValue($shoot->status),
            'primary_photographer' => $shoot->photographer?->name,
            'photographer' => $shoot->photographer ? $shoot->photographer->name : 'TBD',
            'photographers' => $assignedPhotographers,
            'photographers_label' => !empty($assignedPhotographers) ? implode(', ', $assignedPhotographers) : 'TBD',
            'client_name' => $shoot->client ? $shoot->client->name : 'N/A',
            'client_email' => $shoot->client?->email,
            'client_phone' => $shoot->client?->phonenumber,
            'rep_name' => $shoot->rep?->name,
            'notes' => $notesText,
            'notes_lines' => $this->splitLines($notesText),
            'company_notes_lines' => $this->splitLines($shoot->company_notes),
            'photographer_notes_lines' => $this->splitLines($shoot->photographer_notes),
            'total' => $shoot->base_quote ?? 0,
            'tax' => $shoot->tax_amount ?? 0,
            'tax_rate' => $shoot->tax_percent ?? 0,
            'grand_total' => $shoot->total_quote ?? 0,
            'formatted_subtotal' => $this->formatCurrency($shoot->base_quote ?? 0),
            'formatted_tax' => $this->formatCurrency($shoot->tax_amount ?? 0),
            'formatted_grand_total' => $this->formatCurrency($shoot->total_quote ?? 0),
            'payment_status' => $paymentStatus,
            'remaining_balance' => $remainingBalance,
            'formatted_remaining_balance' => $this->formatCurrency($remainingBalance),
            'packages' => $packageRows,
            'services' => $serviceRows,
            'service_items' => $serviceRows,
            'service_item_ids' => $resolvedServiceItemIds,
            'service_category' => $shoot->service_category ?? 'Standard',
            'property_highlights' => $this->buildPropertyHighlightRows($propertyDetails),
            'access_details' => $this->buildAccessRows($propertyDetails),
            'company_notes' => $shoot->company_notes,
            'photographer_notes' => $shoot->photographer_notes,
            'dashboard_url' => 'https://reprodashboard.com',
            'website_url' => 'https://reprophotos.com',
            'property_prep_url' => 'https://reprophotos.com/tips-to-get-your-property-camera-ready/',
            'review_url' => 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews',
            // Source support contact from the canonical config (single source of truth)
            // so no shoot email can render a stale support phone/email. See QA #10.
            'support_email' => config('mail.contact_address', 'contact@reprophotos.com'),
            'support_phone' => SupportContact::PHONE_DISPLAY,
            'bypass_paywall' => (bool) ($shoot->bypass_paywall ?? false),
            'is_private_listing' => (bool) ($shoot->is_private_listing ?? false),
        ];
    }

    /**
     * Format notes for email display - extract content only
     */
    private function formatNotes(Shoot $shoot): string
    {
        $noteContents = [];

        // Check shoot_notes field first
        if (!empty($shoot->shoot_notes)) {
            $noteContents[] = $shoot->shoot_notes;
        }

        // Check notes relationship
        if ($shoot->relationLoaded('notes') && $shoot->notes) {
            foreach ($shoot->notes as $note) {
                if (!empty($note->content) && $note->visibility === 'client_visible') {
                    $noteContents[] = $note->content;
                }
            }
        } elseif (!$shoot->relationLoaded('notes')) {
            // Load notes if not loaded
            $shoot->load('notes');
            if ($shoot->notes) {
                foreach ($shoot->notes as $note) {
                    if (!empty($note->content) && $note->visibility === 'client_visible') {
                        $noteContents[] = $note->content;
                    }
                }
            }
        }

        return !empty($noteContents) ? implode("\n", $noteContents) : '';
    }

    /**
     * Format payment data for email templates
     */
    private function formatPaymentData(Payment $payment): object
    {
        return (object) [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency ?? 'USD',
            'status' => $payment->status,
            'payment_method' => $payment->payment_method ?? 'Card',
            'transaction_id' => $payment->transaction_id,
            'created_at' => $payment->created_at->format('M j, Y g:i A')
        ];
    }

    private function formatInvoiceData(Invoice $invoice): object
    {
        $invoice->loadMissing(['items', 'shoot', 'photographer', 'salesRep']);

        return (object) [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'total' => $invoice->total,
            'total_amount' => $invoice->total_amount ?? $invoice->total,
            'amount_paid' => $invoice->amount_paid,
            'issue_date' => $invoice->issue_date,
            'due_date' => $invoice->due_date,
            'approved_at' => $invoice->approved_at,
            'rejected_at' => $invoice->rejected_at,
            'modified_at' => $invoice->modified_at,
            'modification_notes' => $invoice->modification_notes,
            'rejection_reason' => $invoice->rejection_reason,
            'billing_period_start' => $invoice->billing_period_start,
            'billing_period_end' => $invoice->billing_period_end,
            'shoot_id' => $invoice->shoot_id,
            'items' => $invoice->items
                ? $invoice->items->map(fn ($item) => (object) [
                    'id' => $item->id,
                    'description' => $item->description,
                    'type' => $item->type,
                    'total_amount' => $item->total_amount,
                ])->all()
                : [],
        ];
    }

    /**
     * Format packages for email display
     */
    private function formatPackages(Shoot $shoot): array
    {
        $packages = [];
        
        // Load services relationship if not already loaded
        if (!$shoot->relationLoaded('services')) {
            $shoot->load('services');
        }
        
        // Get all services from the shoot (many-to-many relationship)
        if ($shoot->services && $shoot->services->count() > 0) {
            foreach ($shoot->services as $service) {
                $servicePrice = (float) ($service->pivot->price ?? $service->price ?? 0);
                $quantity = (int) ($service->pivot->quantity ?? 1);
                $serviceName = $service->name ?? $service->service_name ?? 'Service';
                
                $packages[] = [
                    'name' => $serviceName . ($quantity > 1 ? " x{$quantity}" : ''),
                    'price' => $servicePrice * $quantity
                ];
            }
        } elseif ($shoot->service) {
            // Fallback to single service relationship (legacy)
            $packages[] = [
                'name' => $shoot->service->name ?? 'Photography Service',
                'price' => $shoot->base_quote ?? 0
            ];
        } elseif ($shoot->service_category) {
            // Fallback to service category
            $categoryNames = [
                'P' => 'Photography Package',
                'iGuide' => 'iGuide Virtual Tour',
                'Video' => 'Video Package'
            ];
            
            $packages[] = [
                'name' => $categoryNames[$shoot->service_category] ?? $shoot->service_category,
                'price' => $shoot->base_quote ?? 0
            ];
        }
        
        // If still no packages, add a generic one based on quote
        if (empty($packages) && ($shoot->base_quote ?? 0) > 0) {
            $packages[] = [
                'name' => 'Photography Services',
                'price' => $shoot->base_quote
            ];
        }
        
        return $packages;
    }

    private function formatDetailedServices(
        Shoot $shoot,
        ?User $recipient = null,
        ?string $roleContext = null,
        array $serviceItemIds = []
    ): array
    {
        $shoot->loadMissing(['services.category']);
        $serviceItemScope = collect($serviceItemIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $servicePhotographerIds = collect($shoot->services ?? [])
            ->pluck('pivot.photographer_id')
            ->filter()
            ->unique()
            ->values();

        $servicePhotographers = $servicePhotographerIds->isNotEmpty()
            ? User::whereIn('id', $servicePhotographerIds)->get()->keyBy('id')
            : collect();

        $rows = [];

        foreach ($shoot->services ?? [] as $service) {
            $shootServiceId = (int) ($service->pivot->id ?? 0);
            if ($serviceItemScope->isNotEmpty() && (!$shootServiceId || !$serviceItemScope->contains($shootServiceId))) {
                continue;
            }

            $quantity = (int) ($service->pivot->quantity ?? 1);
            $unitPrice = (float) ($service->pivot->price ?? $service->price ?? 0);
            $lineTotal = $unitPrice * $quantity;
            $resolvedPhotographerId = $service->pivot->photographer_id ?? $shoot->photographer_id;
            $resolvedEditorId = $service->pivot->editor_id ?? $shoot->editor_id;

            if (
                $recipient
                && $roleContext === 'photographer'
                && (!$resolvedPhotographerId || (int) $resolvedPhotographerId !== (int) $recipient->id)
            ) {
                continue;
            }

            if (
                $recipient
                && $roleContext === 'editor'
                && (!$resolvedEditorId || (int) $resolvedEditorId !== (int) $recipient->id)
            ) {
                continue;
            }

            $resolvedPhotographer = null;

            if ($resolvedPhotographerId) {
                if ($service->pivot->photographer_id && $servicePhotographers->has($service->pivot->photographer_id)) {
                    $resolvedPhotographer = $servicePhotographers->get($service->pivot->photographer_id);
                } elseif ($shoot->photographer && (int) $shoot->photographer->id === (int) $resolvedPhotographerId) {
                    $resolvedPhotographer = $shoot->photographer;
                } elseif ($servicePhotographers->has($resolvedPhotographerId)) {
                    $resolvedPhotographer = $servicePhotographers->get($resolvedPhotographerId);
                }
            }

            $meta = [];
            if (!empty($service->category?->name)) {
                $meta[] = $service->category->name;
            }
            if ($quantity > 1) {
                $meta[] = 'Qty ' . $quantity;
            }
            if ($unitPrice > 0) {
                $meta[] = $this->formatCurrency($unitPrice) . ' each';
            }

            $serviceName = $service->name ?? $service->service_name ?? 'Service';
            $scheduledAt = $service->pivot->scheduled_at ?? $shoot->scheduled_at;
            $formattedSchedule = $this->formatServiceSchedule($scheduledAt);

            $rows[] = [
                'shoot_service_id' => $shootServiceId ?: null,
                'name' => $serviceName,
                'display_name' => $serviceName . ($quantity > 1 ? " x{$quantity}" : ''),
                'quantity' => $quantity,
                'category' => $service->category?->name,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'formatted_total' => $this->formatCurrency($lineTotal),
                'photographer_name' => $resolvedPhotographer?->name,
                'photographer_id' => $resolvedPhotographerId ? (int) $resolvedPhotographerId : null,
                'editor_id' => $resolvedEditorId ? (int) $resolvedEditorId : null,
                'scheduled_at' => $scheduledAt instanceof \DateTimeInterface ? $scheduledAt->format(DATE_ATOM) : $scheduledAt,
                'schedule' => $formattedSchedule,
                'formatted_schedule' => $formattedSchedule,
                'workflow_status' => $service->pivot->workflow_status ?? null,
                'delivery_status' => $service->pivot->delivery_status ?? null,
                'payment_status' => null,
                'meta' => implode(' | ', $meta),
            ];
        }

        if (empty($rows) && (!$recipient || !$roleContext) && $serviceItemScope->isEmpty()) {
            foreach ($this->formatPackages($shoot) as $package) {
                $rows[] = [
                    'name' => $package['name'],
                    'display_name' => $package['name'],
                    'quantity' => 1,
                    'category' => null,
                    'unit_price' => (float) ($package['price'] ?? 0),
                    'line_total' => (float) ($package['price'] ?? 0),
                    'formatted_total' => $this->formatCurrency($package['price'] ?? 0),
                    'photographer_name' => $shoot->photographer?->name,
                    'meta' => '',
                ];
            }
        }

        return $rows;
    }

    private function formatPackagesFromServiceRows(array $serviceRows): array
    {
        return collect($serviceRows)
            ->map(fn (array $service) => [
                'name' => $service['display_name'] ?? $service['name'] ?? 'Service',
                'price' => (float) ($service['line_total'] ?? $service['unit_price'] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function formatAssignedPhotographers(Shoot $shoot, array $serviceRows): array
    {
        return collect($serviceRows)
            ->pluck('photographer_name')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function formatServiceSchedule(mixed $scheduledAt): string
    {
        if (!$scheduledAt) {
            return 'TBD';
        }

        try {
            $date = $scheduledAt instanceof \DateTimeInterface
                ? \Carbon\Carbon::instance($scheduledAt)
                : \Carbon\Carbon::parse((string) $scheduledAt);

            return $date->format('M j, Y \a\t g:i A');
        } catch (\Throwable) {
            return (string) $scheduledAt;
        }
    }

    private function serviceScopeHash(object $shootData): string
    {
        $ids = collect($shootData->service_item_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->sort()
            ->values();

        return $ids->isEmpty()
            ? 'all'
            : sha1($ids->implode(','));
    }

    private function isPhotographerRecipient(User $user, Shoot $shoot): bool
    {
        return $this->resolveAssignedPhotographers($shoot)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->contains((int) $user->id);
    }

    private function shouldSendAssignedPhotographerEmails(Shoot $shoot, User $primaryRecipient, string $event): bool
    {
        return ShootEmailMatrix::includesPhotographer($event)
            && !$this->isPhotographerRecipient($primaryRecipient, $shoot);
    }

    private function resolveAssignedPhotographers(Shoot $shoot, ?int $excludeUserId = null): Collection
    {
        $shoot->loadMissing(['photographer', 'services']);

        $services = collect($shoot->services ?? []);
        $hasServices = $services->isNotEmpty();
        $hasServicesWithoutPhotographer = $services->contains(fn ($service) => empty($service->pivot->photographer_id));
        $parentPhotographerId = ($shoot->photographer_id || $shoot->photographer?->id)
            && (!$hasServices || $hasServicesWithoutPhotographer)
                ? ($shoot->photographer_id ?? $shoot->photographer?->id)
                : null;

        $photographerIds = collect([$parentPhotographerId])
            ->merge(
                $services
                    ->pluck('pivot.photographer_id')
                    ->filter()
            )
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($photographerIds->isEmpty()) {
            return collect();
        }

        $photographers = User::query()
            ->whereIn('id', $photographerIds->all())
            ->whereNotNull('email')
            ->get()
            ->keyBy('id');

        if ($shoot->photographer && $shoot->photographer->email) {
            $photographers->put($shoot->photographer->id, $shoot->photographer);
        }

        return $photographerIds
            ->map(fn ($id) => $photographers->get((int) $id))
            ->filter(fn ($user) => $user instanceof User)
            ->reject(fn (User $user) => $excludeUserId !== null && (int) $user->id === $excludeUserId)
            ->unique('id')
            ->values();
    }

    private function buildPropertyHighlightRows(array $propertyDetails): array
    {
        $rows = [];

        $beds = $propertyDetails['bedrooms'] ?? $propertyDetails['beds'] ?? null;
        $baths = $propertyDetails['bathrooms'] ?? $propertyDetails['baths'] ?? null;
        $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? null;

        if ($beds !== null && $beds !== '') {
            $rows[] = ['label' => 'Bedrooms', 'value' => $this->formatNumberValue($beds)];
        }

        if ($baths !== null && $baths !== '') {
            $rows[] = ['label' => 'Bathrooms', 'value' => $this->formatNumberValue($baths, 1)];
        }

        if ($sqft !== null && $sqft !== '') {
            $rows[] = ['label' => 'Square Footage', 'value' => number_format((float) $sqft)];
        }

        return $rows;
    }

    private function buildAccessRows(array $propertyDetails): array
    {
        $rows = [];
        $mappedRows = [
            'Access Type' => $propertyDetails['presenceOption'] ?? null,
            'Access Contact' => $propertyDetails['accessContactName'] ?? null,
            'Access Phone' => $propertyDetails['accessContactPhone'] ?? null,
            'Lockbox Code' => $propertyDetails['lockboxCode'] ?? null,
            'Lockbox Location' => $propertyDetails['lockboxLocation'] ?? null,
        ];

        foreach ($mappedRows as $label => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $rows[] = ['label' => $label, 'value' => (string) $value];
            }
        }

        return $rows;
    }

    private function splitLines(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', trim($value)))
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeChangeSummaryText(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== ''
            ? $trimmed
            : 'Please review updated details in the dashboard.';
    }

    private function resolveShootPaymentStatus(Shoot $shoot): string
    {
        $paymentStatus = Str::lower(trim((string) ($shoot->payment_status ?? '')));

        if (in_array($paymentStatus, ['paid', 'unpaid', 'partial'], true)) {
            return $paymentStatus;
        }

        if (!$shoot->relationLoaded('payments')) {
            $shoot->loadMissing('payments');
        }

        $totalPaid = $shoot->calculateCanonicalTotalPaid();
        $totalQuote = (float) ($shoot->total_quote ?? 0);

        if ($totalQuote <= 0.01) {
            return 'paid';
        }

        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        return $totalPaid >= $totalQuote ? 'paid' : 'partial';
    }

    private function shouldShowShootReadyPaymentLink(Shoot $shoot): bool
    {
        if ((bool) ($shoot->bypass_paywall ?? false)) {
            return false;
        }

        $totalQuote = (float) ($shoot->total_quote ?? 0);
        $totalPaid = $shoot->relationLoaded('payments')
            ? $shoot->calculateCanonicalTotalPaid()
            : (float) ($shoot->total_paid ?? 0);

        if (max($totalQuote - $totalPaid, 0) <= 0.01) {
            return false;
        }

        return in_array($this->resolveShootPaymentStatus($shoot), ['unpaid', 'partial'], true);
    }

    public function generatePaymentLink(Shoot $shoot): string
    {
        return app(\App\Services\Payments\PublicPaymentAccessTokenService::class)
            ->buildPublicUrl($shoot);
    }

    public function generateStoredPasswordResetLink(User $user): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        return $this->generatePasswordResetLink($user, $token);
    }

    public function generateStoredPasswordCreationLink(User $user): string
    {
        return $this->passwordCreationLink($this->generateStoredPasswordResetLink($user));
    }

    public function passwordCreationLink(string $resetLink): string
    {
        $fragment = '';
        $urlWithoutFragment = $resetLink;

        if (str_contains($urlWithoutFragment, '#')) {
            [$urlWithoutFragment, $fragment] = explode('#', $urlWithoutFragment, 2);
            $fragment = '#' . $fragment;
        }

        $baseUrl = $urlWithoutFragment;
        $queryString = '';

        if (str_contains($urlWithoutFragment, '?')) {
            [$baseUrl, $queryString] = explode('?', $urlWithoutFragment, 2);
        }

        parse_str($queryString, $query);
        $query['mode'] = 'create';

        return $baseUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }

    /**
     * Generate password reset link with token
     */
    public function generatePasswordResetLink(User $user, ?string $token = null): string
    {
        $frontendUrl = config('app.frontend_url', 'https://reprodashboard.com');
        if (!$token) {
            $token = \Illuminate\Support\Str::random(64);
        }
        return "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($user->email);
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(User $user, string $resetLink): bool
    {
        try {
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($user),
                'links' => [
                    'reset_password' => $resetLink,
                    'dashboard' => rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/'),
                ],
                'meta' => [
                    'recipient_type' => match (strtolower((string) $user->role)) {
                        'client' => 'client',
                        'photographer' => 'photographer',
                        'salesrep', 'sales_rep' => 'rep',
                        'admin', 'superadmin' => 'admin',
                        default => 'other',
                    },
                    'token_hash' => sha1($resetLink),
                ],
            ]);

            $this->dispatchProtectedEmail('PASSWORD_RESET', $payload, $user->email, [], [], $this->automatedClientPayload($user, [
                'enforce_email_health_gate' => false,
            ]), [
                'idempotency_key' => sprintf('PASSWORD_RESET:%d:%s', $user->id, sha1($resetLink)),
            ]);
            
            Log::info('Password reset email sent', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send weekly sales report email
     */
    public function sendWeeklySalesReportEmail(User $salesRep, array $reportData): bool
    {
        try {
            $period = $reportData['period'];
            $weekLabel = "Week {$period['week_number']}, {$period['year']}";

            $html = view('emails.weekly_sales_report', [
                'salesRep' => $salesRep,
                'report' => $reportData,
                'weekLabel' => $weekLabel,
            ])->render();
            $this->sendViaCakemail($salesRep->email, "Weekly Sales Report - {$weekLabel}", $html, 'WEEKLY_SALES_REPORT');
            
            Log::info('Weekly sales report email sent', [
                'sales_rep_id' => $salesRep->id,
                'email' => $salesRep->email,
                'period' => $reportData['period'] ?? null,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send weekly sales report email', [
                'sales_rep_id' => $salesRep->id,
                'email' => $salesRep->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice generated email
     */
    public function sendInvoiceGeneratedEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $invoice->loadMissing(['photographer', 'salesRep', 'items']);

            $recipient = $invoice->photographer ?? $invoice->salesRep;
            if (!$recipient || empty($recipient->email)) {
                Log::warning('Cannot send invoice email: recipient not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            if (!$recipient) {
                Log::warning('Cannot send invoice pending approval email: payee not found', [
                    'invoice_id' => $invoice->id,
                ]);

                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";
            $recipientRole = $invoice->photographer ? 'photographer' : 'sales rep';
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($recipient),
                'invoice' => $this->formatInvoiceData($invoice),
                'meta' => [
                    'recipient_type' => $recipientRole === 'photographer' ? 'photographer' : 'rep',
                    'recipient_role' => $recipientRole,
                    'period' => $period,
                    'event_version' => $invoice->updated_at?->toIso8601String() ?? $invoice->id,
                ],
            ]);

            $this->dispatchProtectedEmail('INVOICE_GENERATED', $payload, $recipient->email, [], [], [
                'related_invoice_id' => $invoice->id,
                'related_account_id' => $recipient->id,
            ], [
                'idempotency_key' => sprintf('INVOICE_GENERATED:%d:%d:%s', $invoice->id, $recipient->id, sha1($period)),
            ]);
            
            Log::info('Invoice generated email sent', [
                'invoice_id' => $invoice->id,
                'recipient_id' => $recipient->id,
                'email' => $recipient->email,
                'recipient_role' => $invoice->photographer ? 'photographer' : 'sales_rep',
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice generated email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice pending approval email to admins
     */
    public function sendInvoicePendingApprovalEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $admins = User::whereIn('role', ['admin', 'superadmin'])->get();
            
            if ($admins->isEmpty()) {
                Log::warning('No admins found to send invoice approval email', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $recipient = $this->resolveInvoicePayee($invoice);
            $roleLabel = $this->resolveInvoicePayeeLabel($invoice);
            $roleHeading = $this->resolveInvoicePayeeHeading($invoice);
            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";

            foreach ($admins as $admin) {
                $payload = $this->buildProtectedEmailPayload([
                    'recipient' => $this->formatUserData($admin),
                    'invoice' => $this->formatInvoiceData($invoice),
                    'meta' => [
                        'recipient_type' => 'admin',
                        'period' => $period,
                        'role_label' => $roleLabel,
                        'role_heading' => $roleHeading,
                        'payee_name' => $recipient?->name,
                        'event_version' => $invoice->modified_at?->toIso8601String() ?? $invoice->updated_at?->toIso8601String() ?? $invoice->id,
                    ],
                ]);

                $this->dispatchProtectedEmail('INVOICE_PENDING_APPROVAL', $payload, $admin->email, [], [], [
                    'related_invoice_id' => $invoice->id,
                    'related_account_id' => $admin->id,
                ], [
                    'idempotency_key' => sprintf('INVOICE_PENDING_APPROVAL:%d:%d:%s', $invoice->id, $admin->id, sha1($period)),
                ]);
            }
            
            Log::info('Invoice pending approval emails sent', [
                'invoice_id' => $invoice->id,
                'admin_count' => $admins->count()
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice pending approval emails', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice approved email
     */
    public function sendInvoiceApprovedEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $recipient = $this->resolveInvoicePayee($invoice);
            $roleLabel = $this->resolveInvoicePayeeLabel($invoice);
            if (!$recipient) {
                Log::warning('Cannot send invoice approved email: payee not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($recipient),
                'invoice' => $this->formatInvoiceData($invoice),
                'meta' => [
                    'recipient_type' => $invoice->sales_rep_id ? 'rep' : 'photographer',
                    'period' => $period,
                    'role_label' => $roleLabel,
                    'event_version' => $invoice->approved_at?->toIso8601String() ?? $invoice->updated_at?->toIso8601String() ?? $invoice->id,
                ],
            ]);
            $this->dispatchProtectedEmail('INVOICE_APPROVED', $payload, $recipient->email, [], [], [
                'related_invoice_id' => $invoice->id,
                'related_account_id' => $recipient->id,
            ], [
                'idempotency_key' => sprintf('INVOICE_APPROVED:%d:%d:%s', $invoice->id, $recipient->id, sha1($period)),
            ]);
            
            Log::info('Invoice approved email sent', [
                'invoice_id' => $invoice->id,
                'recipient_id' => $recipient->id,
                'email' => $recipient->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice approved email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice rejected email
     */
    public function sendInvoiceRejectedEmail(\App\Models\Invoice $invoice): bool
    {
        try {
            $recipient = $this->resolveInvoicePayee($invoice);
            $roleLabel = $this->resolveInvoicePayeeLabel($invoice);
            if (!$recipient) {
                Log::warning('Cannot send invoice rejected email: payee not found', [
                    'invoice_id' => $invoice->id
                ]);
                return false;
            }

            $period = "{$invoice->billing_period_start->format('M j')} - {$invoice->billing_period_end->format('M j, Y')}";
            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($recipient),
                'invoice' => $this->formatInvoiceData($invoice),
                'meta' => [
                    'recipient_type' => $invoice->sales_rep_id ? 'rep' : 'photographer',
                    'period' => $period,
                    'role_label' => $roleLabel,
                    'event_version' => $invoice->rejected_at?->toIso8601String() ?? $invoice->updated_at?->toIso8601String() ?? $invoice->id,
                ],
            ]);
            $this->dispatchProtectedEmail('INVOICE_REJECTED', $payload, $recipient->email, [], [], [
                'related_invoice_id' => $invoice->id,
                'related_account_id' => $recipient->id,
            ], [
                'idempotency_key' => sprintf('INVOICE_REJECTED:%d:%d:%s', $invoice->id, $recipient->id, sha1($period)),
            ]);
            
            Log::info('Invoice rejected email sent', [
                'invoice_id' => $invoice->id,
                'recipient_id' => $recipient->id,
                'email' => $recipient->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send invoice rejected email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    private function resolveInvoicePayee(\App\Models\Invoice $invoice): ?User
    {
        return $invoice->photographer ?: $invoice->salesRep;
    }

    private function resolveInvoicePayeeLabel(\App\Models\Invoice $invoice): string
    {
        return $invoice->sales_rep_id ? 'sales rep' : 'photographer';
    }

    private function resolveInvoicePayeeHeading(\App\Models\Invoice $invoice): string
    {
        return $invoice->sales_rep_id ? 'Sales Rep' : 'Photographer';
    }

    /**
     * Send shoot paid email (when admin marks as paid)
     */
    public function sendShootPaidEmail(User $user, Shoot $shoot, float $amount): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);
            
            if (!empty($user->email)) {
                $payload = $this->buildProtectedEmailPayload([
                    'recipient' => $this->formatUserData($user),
                    'account' => $this->formatUserData($shoot->client),
                    'shoot' => $shootData,
                    'meta' => [
                        'recipient_type' => 'client',
                        'amount' => $amount,
                        'event_version' => sha1((string) $amount),
                    ],
                ]);
                $this->dispatchProtectedEmail('SHOOT_PAID', $payload, $user->email, $clientCcEmails, [], $this->automatedClientPayload($user, [
                    'related_shoot_id' => $shoot->id,
                ]), [
                    'idempotency_key' => sprintf('SHOOT_PAID:%d:%d:%s', $shoot->id, $user->id, sha1((string) $amount)),
                ]);
                
                Log::info('Shoot paid email sent', [
                    'user_id' => $user->id,
                    'shoot_id' => $shoot->id,
                    'email' => $user->email,
                    'amount' => $amount
                ]);
            } else {
                Log::warning('Shoot paid email skipped because recipient email is missing', [
                    'user_id' => $user->id,
                    'shoot_id' => $shoot->id,
                    'amount' => $amount,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send shoot paid email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email ?? null,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send shoot cancelled email
     */
    public function sendShootCancelledEmail(User $user, Shoot $shoot): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $clientCcEmails = $this->resolveShootCcEmailsForRecipient($shoot, $user);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'meta' => [
                    'recipient_type' => 'client',
                    'event_version' => $shoot->updated_at?->toIso8601String() ?? $shoot->id,
                ],
            ]);
            $this->dispatchProtectedEmail('SHOOT_CANCELLED', $payload, $user->email, $clientCcEmails, [], $this->automatedClientPayload($user, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf('SHOOT_CANCELLED:%d:%d:client', $shoot->id, $user->id),
            ]);

            Log::info('Shoot cancelled email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
            ]);

            if ($this->shouldSendAssignedPhotographerEmails($shoot, $user, ShootEmailMatrix::SHOOT_CANCELLED)) {
                foreach ($this->resolveAssignedPhotographers($shoot, $user->id) as $photographer) {
                    $payload = $this->buildProtectedEmailPayload([
                        'recipient' => $this->formatUserData($photographer),
                        'account' => $this->formatUserData($shoot->client),
                        'shoot' => $shootData,
                        'meta' => [
                            'recipient_type' => 'photographer',
                            'event_version' => $shoot->updated_at?->toIso8601String() ?? $shoot->id,
                        ],
                    ]);
                    $this->dispatchProtectedEmail('SHOOT_CANCELLED', $payload, $photographer->email, [], [], [
                        'related_shoot_id' => $shoot->id,
                    ], [
                        'idempotency_key' => sprintf('SHOOT_CANCELLED:%d:%d:photographer', $shoot->id, $photographer->id),
                    ]);
                    Log::info('Shoot cancelled email sent to photographer', [
                        'photographer_id' => $photographer->id,
                        'shoot_id' => $shoot->id,
                        'email' => $photographer->email,
                    ]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send shoot cancelled email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendPhotographerChangedEmail(
        User $user,
        Shoot $shoot,
        ?User $previousPhotographer = null,
        ?string $changesSummary = null
    ): bool {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $normalizedChangesSummary = $this->normalizeChangeSummaryText($changesSummary);
            $isAssignedAfterChange = $this->resolveAssignedPhotographers($shoot)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->contains((int) $user->id);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'meta' => [
                    'recipient_type' => 'photographer',
                    'changes_summary' => $normalizedChangesSummary,
                    'previous_photographer' => $this->formatUserData($previousPhotographer),
                    'is_assigned_after_change' => $isAssignedAfterChange,
                    'event_version' => sha1($normalizedChangesSummary . '|' . ($shoot->updated_at?->toIso8601String() ?? $shoot->id)),
                ],
            ]);

            $this->dispatchProtectedEmail('PHOTOGRAPHER_CHANGED', $payload, $user->email, [], [], [
                'related_shoot_id' => $shoot->id,
            ], [
                'idempotency_key' => sprintf('PHOTOGRAPHER_CHANGED:%d:%d:%s', $shoot->id, $user->id, sha1($normalizedChangesSummary)),
            ]);

            Log::info('Photographer changed email sent', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email,
                'is_assigned_after_change' => $isAssignedAfterChange,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send photographer changed email', [
                'user_id' => $user->id,
                'shoot_id' => $shoot->id,
                'email' => $user->email ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send role changed email to user
     */
    public function sendRoleChangedEmail(
        User $user,
        string $oldRole,
        string $newRole,
        array $oldSecondaryRoles = [],
        array $newSecondaryRoles = []
    ): bool {
        try {
            $oldRoleLabel = $this->formatRoleLabel($oldRole);
            $newRoleLabel = $this->formatRoleLabel($newRole);

            $secondaryRolesLabels = collect($newSecondaryRoles ?? [])
                ->map(fn ($role) => $this->formatRoleLabel((string) $role))
                ->filter()
                ->values()
                ->all();

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($user),
                'account' => $this->formatUserData($user),
                'meta' => [
                    'recipient_type' => $newRole,
                    'old_role_label' => $oldRoleLabel,
                    'new_role_label' => $newRoleLabel,
                    'secondary_roles' => $secondaryRolesLabels,
                    'event_version' => sha1($oldRole . '|' . $newRole . '|' . implode(',', $oldSecondaryRoles) . '|' . implode(',', $newSecondaryRoles)),
                ],
            ]);

            $this->dispatchProtectedEmail('ROLE_CHANGED', $payload, $user->email, [], [], [
                'related_account_id' => $user->id,
                'enforce_email_health_gate' => false,
            ], [
                'idempotency_key' => sprintf('ROLE_CHANGED:%d:%s', $user->id, sha1($oldRole . '|' . $newRole)),
            ]);

            Log::info('Role changed email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
                'old_role' => $oldRole,
                'new_role' => $newRole,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send role changed email', [
                'user_id' => $user->id,
                'email' => $user->email ?? null,
                'old_role' => $oldRole,
                'new_role' => $newRole,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send cancellation fee invoice email to client
     */
    public function sendCancellationFeeInvoiceEmail(User $client, \App\Models\Invoice $invoice): bool
    {
        try {
            $shoot = $invoice->shoot;
            $address = $shoot ? ($this->formatFullAddress($shoot) ?: ($shoot->address ?? 'Property')) : 'Property';
            $clientCcEmails = $shoot ? $this->resolveShootCcEmailsForRecipient($shoot, $client) : $this->sanitizeEmailAddresses($client->shoot_cc_emails ?? [], $client->email);

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($client),
                'account' => $this->formatUserData($client),
                'invoice' => $this->formatInvoiceData($invoice),
                'shoot' => $shoot ? $this->formatShootData($shoot) : [],
                'meta' => [
                    'recipient_type' => 'client',
                    'address' => $address,
                    'event_version' => $invoice->updated_at?->toIso8601String() ?? $invoice->id,
                ],
            ]);
            $this->dispatchProtectedEmail('CANCELLATION_FEE_INVOICE', $payload, $client->email, $clientCcEmails, [], $this->automatedClientPayload($client, [
                'related_invoice_id' => $invoice->id,
                'related_shoot_id' => $shoot?->id,
            ]), [
                'idempotency_key' => sprintf('CANCELLATION_FEE_INVOICE:%d:%d', $invoice->id, $client->id),
            ]);
            
            Log::info('Cancellation fee invoice email sent', [
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'email' => $client->email
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send cancellation fee invoice email', [
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'email' => $client->email,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    private function buildProtectedEmailPayload(array $sections): array
    {
        return $this->emailContextBuilder->build($sections);
    }

    private function formatUserData(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'company_name' => $user->company_name,
            'phonenumber' => $user->phonenumber,
            'role' => $user->role,
            'timezone' => $user->timezone,
        ];
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $extraPayload
     * @param  array<string, mixed>  $options
     */
    private function dispatchProtectedEmail(
        string $emailAlias,
        array $payload,
        string $to,
        array $cc = [],
        array $tags = [],
        array $extraPayload = [],
        array $options = []
    ): bool {
        $result = $this->systemEmailOrchestrator->send($emailAlias, $payload, [
            'to' => $to,
            'cc' => $this->sanitizeEmailAddresses($cc, $to),
            'related_account_id' => $extraPayload['related_account_id'] ?? null,
            'related_shoot_id' => $extraPayload['related_shoot_id'] ?? null,
            'related_invoice_id' => $extraPayload['related_invoice_id'] ?? null,
            'send_source' => $emailAlias,
            'contact_email' => $to,
            'contact_name' => $payload['recipient']['name'] ?? 'Recipient',
            'contact_type' => $payload['meta']['recipient_type'] ?? 'other',
            'tags_json' => $tags !== [] ? array_values($tags) : null,
        ], [
            'enforce_email_health_gate' => $extraPayload['enforce_email_health_gate'] ?? true,
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'force' => $options['force'] ?? false,
            'canonical_metadata' => $options['canonical_metadata'] ?? [],
        ]);

        return $result['sent'] || $result['duplicate'];
    }

    /**
     * Send an email via CakeMail API through MessagingService
     */
    private function addChangeLine(array &$changes, string $label, ?string $before, ?string $after): void
    {
        $before = trim((string) $before);
        $after = trim((string) $after);
        if ($before === $after || ($before === '' && $after === '')) {
            return;
        }
        if ($before === '' && $after !== '') {
            $changes[] = "{$label}: {$after}";
        } elseif ($before !== '' && $after === '') {
            $changes[] = "{$label}: removed (was {$before})";
        } else {
            $changes[] = "{$label}: {$before} → {$after}";
        }
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function buildChangeSummaryHtml(array $changes): string
    {
        $filteredChanges = array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            $changes
        ), fn ($line) => $line !== ''));

        if ($filteredChanges === []) {
            return '<p>Please review updated details in the dashboard.</p>';
        }

        $parsedChanges = array_map(
            fn (string $line) => $this->parseChangeSummaryLine($line),
            $filteredChanges
        );

        $comparisonChanges = array_values(array_filter(
            $parsedChanges,
            fn (array $change) => ($change['type'] ?? '') === 'comparison'
        ));
        $singleChanges = array_values(array_filter(
            $parsedChanges,
            fn (array $change) => ($change['type'] ?? '') === 'single'
        ));
        $textChanges = array_values(array_filter(
            $parsedChanges,
            fn (array $change) => ($change['type'] ?? '') === 'text'
        ));

        $html = '';

        if ($comparisonChanges !== [] || $singleChanges !== []) {
            foreach ($comparisonChanges as $change) {
                $label = e((string) ($change['label'] ?? 'Updated Detail'));
                $beforeHtml = $this->buildChangeSummaryBeforeHtml(
                    (string) ($change['label'] ?? ''),
                    (string) ($change['before'] ?? ''),
                    (string) ($change['after'] ?? '')
                );
                $afterValue = trim((string) ($change['after'] ?? ''));
                $afterHtml = e($afterValue !== '' ? $afterValue : 'Not set');

                $html .= <<<HTML
<div class="change-summary-block" style="margin:0 0 12px; padding:16px 18px; border:1px solid #dbe6f3; border-radius:14px; background-color:#f8fbff;">
    <div style="margin:0 0 12px; font-size:14px; line-height:1.5; color:#10233b; font-weight:800;">{$label}</div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td width="50%" style="padding-right:8px; vertical-align:top;">
                <div style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Before</div>
                <div style="margin:0; font-size:14px; line-height:1.7; color:#2d4769;">{$beforeHtml}</div>
            </td>
            <td width="50%" style="padding-left:8px; vertical-align:top;">
                <div style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">After</div>
                <div style="margin:0; font-size:14px; line-height:1.7; color:#10233b; font-weight:700;">{$afterHtml}</div>
            </td>
        </tr>
    </table>
</div>
HTML;
            }

            foreach ($singleChanges as $change) {
                $label = e((string) ($change['label'] ?? 'Updated Detail'));
                $value = trim((string) ($change['value'] ?? ''));
                $valueHtml = e($value !== '' ? $value : 'Not set');

                $html .= <<<HTML
<div class="change-summary-block" style="margin:0 0 12px; padding:16px 18px; border:1px solid #dbe6f3; border-radius:14px; background-color:#f8fbff;">
    <div style="margin:0 0 4px; font-size:14px; line-height:1.5; color:#10233b; font-weight:800;">{$label}</div>
    <div style="margin:0; font-size:14px; line-height:1.7; color:#10233b; font-weight:700;">{$valueHtml}</div>
</div>
HTML;
            }
        }

        foreach ($textChanges as $change) {
            $text = e((string) ($change['text'] ?? ''));
            $html .= '<p class="change-summary-block" style="margin:0 0 12px; font-size:14px; line-height:1.7; color:#2d4769;">' . $text . '</p>';
        }

        return $html !== ''
            ? $html
            : '<p>Please review updated details in the dashboard.</p>';
    }

    /**
     * @return array<string, string>
     */
    private function parseChangeSummaryLine(string $line): array
    {
        $line = trim($line);

        if (!str_contains($line, ':')) {
            return [
                'type' => 'text',
                'text' => $line,
            ];
        }

        [$label, $value] = explode(':', $line, 2);
        $label = trim($label);
        $value = trim($value);

        if (preg_match('/^removed\s+\(was\s+(.+)\)$/i', $value, $matches)) {
            return [
                'type' => 'comparison',
                'label' => $label,
                'before' => trim((string) ($matches[1] ?? '')),
                'after' => 'Removed',
            ];
        }

        if (preg_match('/\s(?:→|->)\s/u', $value) === 1) {
            [$before, $after] = preg_split('/\s*(?:→|->)\s*/u', $value, 2);

            return [
                'type' => 'comparison',
                'label' => $label,
                'before' => trim((string) $before),
                'after' => trim((string) $after),
            ];
        }

        return [
            'type' => 'single',
            'label' => $label,
            'value' => $value,
        ];
    }

    private function buildChangeSummaryBeforeHtml(string $label, string $before, string $after): string
    {
        $before = trim($before);
        $after = trim($after);

        if ($before === '') {
            return e('Not set');
        }

        if (strcasecmp($after, 'Removed') === 0) {
            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($before) . '</span>';
        }

        if (strcasecmp($label, 'Services') !== 0) {
            return e($before);
        }

        $beforeItems = array_values(array_filter(array_map(
            fn ($item) => trim((string) $item),
            preg_split('/\s*,\s*/', $before) ?: []
        ), fn ($item) => $item !== ''));

        if ($beforeItems === []) {
            return e($before);
        }

        $afterCounts = [];
        foreach (preg_split('/\s*,\s*/', $after) ?: [] as $item) {
            $normalizedItem = trim((string) $item);
            if ($normalizedItem === '') {
                continue;
            }

            $key = Str::lower($normalizedItem);
            $afterCounts[$key] = ($afterCounts[$key] ?? 0) + 1;
        }

        $parts = array_map(function (string $item) use (&$afterCounts) {
            $key = Str::lower($item);
            $remaining = (int) ($afterCounts[$key] ?? 0);

            if ($remaining > 0) {
                $afterCounts[$key] = $remaining - 1;

                return e($item);
            }

            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($item) . '</span>';
        }, $beforeItems);

        return implode(', ', $parts);
    }

    private function formatStatusValue(?string $value): string
    {
        if (!$value) return '';
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function formatScheduleValue(?string $date, ?string $time, ?string $scheduledAt): string
    {
        $parts = [];
        if ($date) {
            try {
                $parts[] = \Carbon\Carbon::parse($date)->format('M j, Y');
            } catch (\Exception $e) {
                $parts[] = $date;
            }
        } elseif ($scheduledAt) {
            try {
                $parts[] = \Carbon\Carbon::parse($scheduledAt)->format('M j, Y');
            } catch (\Exception $e) {
                $parts[] = $scheduledAt;
            }
        }
        if ($time) {
            try {
                $parts[] = \Carbon\Carbon::parse($time)->format('g:i A');
            } catch (\Exception $e) {
                $parts[] = $time;
            }
        }
        return implode(' at ', $parts) ?: 'TBD';
    }


    private function formatFullAddress(Shoot $shoot): string
    {
        $parts = array_filter([
            trim((string) ($shoot->address ?? '')),
            trim((string) ($shoot->city ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($shoot->state ?? '')),
                trim((string) ($shoot->zip ?? '')),
            ]))),
        ]);

        return implode(', ', $parts);
    }

    private function formatServicesForComparison(Shoot $shoot): array
    {
        $shoot->loadMissing('services');
        if (!$shoot->services || $shoot->services->isEmpty()) {
            return [];
        }

        $photographerNamesById = User::query()
            ->whereIn('id', $shoot->services
                ->map(fn ($service) => $service->pivot?->photographer_id)
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->pluck('name', 'id');

        $editorNamesById = User::query()
            ->whereIn('id', $shoot->services
                ->map(fn ($service) => $service->pivot?->editor_id)
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->pluck('name', 'id');

        return $shoot->services->map(function ($s) {
            $scheduledAt = $s->pivot?->scheduled_at;

            return [
                'id' => $s->id,
                'name' => $s->name ?? $s->service_name ?? 'Service',
                'price' => (float) ($s->pivot->price ?? $s->price ?? 0),
                'quantity' => (int) ($s->pivot->quantity ?? 1),
                'photographer_id' => $s->pivot?->photographer_id ? (int) $s->pivot->photographer_id : null,
                'photographer_name' => null,
                'editor_id' => $s->pivot?->editor_id ? (int) $s->pivot->editor_id : null,
                'editor_name' => null,
                'scheduled_at' => $scheduledAt instanceof CarbonInterface
                    ? $scheduledAt->toISOString()
                    : ($scheduledAt ? (string) $scheduledAt : null),
                'workflow_status' => $s->pivot?->workflow_status,
                'delivery_status' => $s->pivot?->delivery_status,
                'is_deliverable' => $s->pivot?->is_deliverable === null ? null : (bool) $s->pivot?->is_deliverable,
            ];
        })->map(function (array $service) use ($photographerNamesById, $editorNamesById) {
            if ($service['photographer_id']) {
                $service['photographer_name'] = (string) ($photographerNamesById->get($service['photographer_id']) ?: 'Photographer #' . $service['photographer_id']);
            }
            if ($service['editor_id']) {
                $service['editor_name'] = (string) ($editorNamesById->get($service['editor_id']) ?: 'Editor #' . $service['editor_id']);
            }

            return $service;
        })->sortBy('id')->values()->toArray();
    }

    private function formatServiceSummary(array $services): string
    {
        if (empty($services)) return 'None';
        return collect($services)->map(function ($s) {
            $name = $s['name'] ?? 'Service';
            $qty = $s['quantity'] ?? 1;
            $price = $s['price'] ?? 0;
            $line = $name;
            if ($qty > 1) $line .= " x{$qty}";
            if ($price > 0) $line .= ' ($' . number_format($price * $qty, 2) . ')';
            if (!empty($s['photographer_name'])) {
                $line .= ' - Photographer: ' . $s['photographer_name'];
            }
            if (!empty($s['editor_name'])) {
                $line .= ' - Editor: ' . $s['editor_name'];
            }
            if (!empty($s['scheduled_at'])) {
                $line .= ' - Time: ' . $this->formatDateTimeValue($s['scheduled_at']);
            }
            if (!empty($s['workflow_status'])) {
                $line .= ' - Workflow: ' . $this->formatStatusValue($s['workflow_status']);
            }
            if (!empty($s['delivery_status'])) {
                $line .= ' - Delivery: ' . $this->formatStatusValue($s['delivery_status']);
            }
            if (array_key_exists('is_deliverable', $s) && $s['is_deliverable'] !== null) {
                $line .= ' - Deliverable: ' . $this->formatBooleanValue((bool) $s['is_deliverable']);
            }
            return $line;
        })->implode(', ');
    }

    private function formatUserSummary(array $users): string
    {
        if (empty($users)) {
            return 'None';
        }

        return collect($users)
            ->map(fn (array $user) => trim((string) ($user['name'] ?? 'User #' . ($user['id'] ?? ''))))
            ->filter()
            ->values()
            ->implode(', ');
    }

    private function normalizeChangeText(?string $value): string
    {
        if ($value === null || trim($value) === '') return '';
        return trim($value);
    }

    private function formatCurrency($value): string
    {
        $num = (float) ($value ?? 0);
        return '$' . number_format($num, 2);
    }

    private function formatDiscountValue($type, $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $formatted = number_format((float) $value, 2);

        return ((string) $type === 'percent') ? "{$formatted}%" : '$' . $formatted;
    }

    private function formatDateTimeValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('M j, Y \a\t g:i A');
        } catch (\Exception $e) {
            return (string) $value;
        }
    }

    private function formatNumberValue($value, int $decimals = 0): string
    {
        if ($value === null || $value === '') return '';
        return number_format((float) $value, $decimals);
    }

    private function formatSquareFootage($value): string
    {
        if ($value === null || $value === '') return '';
        return number_format((int) $value) . ' sqft';
    }

    private function formatBooleanValue(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    private function normalizePropertyDetails($pd): array
    {
        if (is_string($pd)) {
            $pd = json_decode($pd, true) ?? [];
        }
        return is_array($pd) ? $pd : [];
    }

    private function normalizeArrayForComparison($value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeArrayForComparison($item);
            }
        }

        return $value;
    }

    private function renderWeeklyInvoiceGeneratedTemplate(\App\Models\Invoice $invoice, $recipient, string $recipientRole, string $period): ?array
    {
        $template = MessageTemplate::query()
            ->where('slug', 'weekly-invoice-generated')
            ->where('channel', 'EMAIL')
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return null;
        }

        // Resolve the shared system tokens (company_email, company_phone, portal_url,
        // ...) first; rendering with only the invoice-specific values blanked out the
        // support contact line in this email.
        $variables = app(\App\Services\Messaging\TemplateVariableResolver::class)->resolve([
            'invoice' => $invoice,
            'recipient' => $recipient,
        ]);

        return app(TemplateRenderer::class)->render($template, array_merge($variables, [
            'recipient_name' => $recipient->name ?? 'there',
            'recipient_role' => $recipientRole,
            'billing_period' => $period,
            'invoice_number' => $invoice->invoice_number ?: 'Pending assignment',
            'invoice_status' => Str::headline((string) ($invoice->status ?? 'draft')),
            'invoice_total' => $this->formatCurrency($invoice->total_amount ?? $invoice->total ?? 0),
            'invoice_items_html' => $this->buildInvoiceItemsHtml($invoice),
            'invoice_items_text' => $this->buildInvoiceItemsText($invoice),
            'dashboard_url' => 'https://reprodashboard.com',
            'invoice_next_step' => 'Open the dashboard to review the invoice, confirm line items, and add any missing expenses before approval moves forward.',
            'approval_note' => 'Changes made after generation may trigger a fresh approval review before payout is finalized.',
        ]));
    }

    private function buildInvoiceItemsHtml(\App\Models\Invoice $invoice): string
    {
        if (!$invoice->items || $invoice->items->isEmpty()) {
            return '<p style="margin: 0;">Line items will appear here once charges or expenses are attached to the invoice.</p>';
        }

        return $invoice->items->map(function ($item) {
            $type = e(Str::headline((string) ($item->type ?? 'line item')));
            $description = e((string) ($item->description ?? 'Line item'));
            $amount = e($this->formatCurrency($item->total_amount ?? 0));

            return <<<HTML
<div class="info-row">
    <span class="info-label">{$type}</span>
    {$description}
    <strong style="float: right;">{$amount}</strong>
</div>
HTML;
        })->implode("\n");
    }

    private function buildInvoiceItemsText(\App\Models\Invoice $invoice): string
    {
        if (!$invoice->items || $invoice->items->isEmpty()) {
            return '- No line items have been attached yet.';
        }

        return $invoice->items->map(function ($item) {
            $type = Str::headline((string) ($item->type ?? 'line item'));
            $description = trim((string) ($item->description ?? 'Line item'));
            $amount = $this->formatCurrency($item->total_amount ?? 0);

            return "- {$description} ({$type}): {$amount}";
        })->implode("\n");
    }

    private function sendViaCakemail(
        ?string $to,
        string $subject,
        string $html,
        string $sendSource,
        array $cc = [],
        array $tags = [],
        array $extraPayload = []
    ): void
    {
        if (!is_string($to) || trim($to) === '') {
            throw new \InvalidArgumentException('Recipient email is required to send mail.');
        }

        $relatedAccountId = $extraPayload['related_account_id'] ?? null;
        $relatedShootId = $extraPayload['related_shoot_id'] ?? null;
        $relatedInvoiceId = $extraPayload['related_invoice_id'] ?? null;
        $enforceEmailHealthGate = $extraPayload['enforce_email_health_gate'] ?? true;
        if ($relatedAccountId && $enforceEmailHealthGate !== false) {
            $recipient = User::query()->find($relatedAccountId);
            $blockedReason = app(EmailHealthService::class)->automatedSendBlockedReason($recipient, $sendSource);

            if ($blockedReason !== null) {
                Log::warning('Automated email send blocked by email health state.', [
                    'send_source' => $sendSource,
                    'related_account_id' => $relatedAccountId,
                    'email' => $to,
                    'reason' => $blockedReason,
                ]);

                throw new \RuntimeException($blockedReason);
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $payload = [
            'to' => $to,
            'cc' => $this->sanitizeEmailAddresses($cc, $to),
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'send_source' => $sendSource,
            'sender_name' => 'R/E Pro Photos',
        ];

        if ($tags !== []) {
            $payload['tags_json'] = array_values($tags);
        }

        if ($relatedAccountId) {
            $payload['related_account_id'] = $relatedAccountId;
        }

        if ($relatedShootId) {
            $payload['related_shoot_id'] = $relatedShootId;
        }

        if ($relatedInvoiceId) {
            $payload['related_invoice_id'] = $relatedInvoiceId;
        }

        $messagingService = app(MessagingService::class);
        $messagingService->sendEmail($payload);
    }

    private function resolveShootCcEmailsForRecipient(Shoot $shoot, ?User $recipient = null): array
    {
        $shoot->loadMissing('client');
        $client = $shoot->client;

        if (!$client) {
            return [];
        }

        if ($recipient) {
            $recipientId = $recipient->id ? (int) $recipient->id : null;
            $clientId = $client->id ? (int) $client->id : null;
            $recipientEmail = strtolower(trim((string) ($recipient->email ?? '')));
            $clientEmail = strtolower(trim((string) ($client->email ?? '')));

            if (($recipientId !== null && $clientId !== null && $recipientId !== $clientId)
                && ($recipientEmail === '' || $clientEmail === '' || $recipientEmail !== $clientEmail)) {
                return [];
            }
        }

        return $this->sanitizeEmailAddresses($client->shoot_cc_emails ?? [], $client->email);
    }

    /**
     * @param  mixed  $emails
     * @return array<int, string>
     */
    private function sanitizeEmailAddresses(mixed $emails, ?string $exclude = null): array
    {
        $excluded = is_string($exclude) ? strtolower(trim($exclude)) : null;

        return collect(is_array($emails) ? $emails : [])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->reject(fn ($email) => $excluded !== null && $email === $excluded)
            ->unique()
            ->values()
            ->all();
    }

    private function automatedClientPayload(?User $recipient, array $extraPayload = []): array
    {
        if ($recipient instanceof User && $recipient->role === 'client') {
            $extraPayload['related_account_id'] = $recipient->id;
        }

        return $extraPayload;
    }

    /**
     * Notify admins/sales rep that a client (or staff) submitted an offline
     * payment intent that needs review.
     */
    public function sendOfflinePaymentIntentSubmittedEmail(Shoot $shoot, Payment $payment, ?User $submittedBy = null): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            $shootData = $this->formatShootData($shoot);
            $details = is_array($payment->payment_details) ? $payment->payment_details : [];
            $methodLabel = match ((string) $payment->payment_method) {
                'check' => 'Cheque',
                'cash' => 'Cash',
                default => ucfirst((string) $payment->payment_method),
            };
            $dashboardUrl = rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/');
            $shootUrl = $dashboardUrl . '/shoots/' . $shoot->id;

            $recipients = collect()
                ->merge(User::query()->whereIn('role', ['admin', 'superadmin'])->get());
            if ($shoot->rep instanceof User) {
                $recipients = $recipients->push($shoot->rep);
            }
            $recipients = $recipients
                ->filter(fn ($u) => $u instanceof User && filter_var($u->email, FILTER_VALIDATE_EMAIL))
                ->unique(fn (User $u) => strtolower((string) $u->email))
                ->values();

            if ($recipients->isEmpty()) {
                Log::warning('No admins/reps available for offline payment intent notification', [
                    'shoot_id' => $shoot->id,
                    'payment_id' => $payment->id,
                ]);
                return false;
            }

            $sent = false;
            foreach ($recipients as $recipient) {
                $payload = $this->buildProtectedEmailPayload([
                    'recipient' => $this->formatUserData($recipient),
                    'account' => $this->formatUserData($shoot->client),
                    'shoot' => $shootData,
                    'payment' => $this->formatPaymentData($payment),
                    'links' => [
                        'shoot' => $shootUrl,
                        'dashboard' => $dashboardUrl,
                    ],
                    'meta' => [
                        'recipient_type' => $recipient->role === 'admin' || $recipient->role === 'superadmin' ? 'admin' : 'rep',
                        'amount' => (float) $payment->amount,
                        'payment_method_label' => $methodLabel,
                        'check_number' => $details['check_number'] ?? null,
                        'payment_date' => $details['payment_date'] ?? null,
                        'notes' => $details['notes'] ?? null,
                        'submitted_by_name' => $submittedBy?->name,
                        'submitted_by_role' => $submittedBy?->role,
                        'shoot_address' => $shoot->address,
                        'event_version' => sprintf('intent_%d_submitted', $payment->id),
                    ],
                ]);
                $this->dispatchProtectedEmail('OFFLINE_PAYMENT_INTENT_SUBMITTED', $payload, $recipient->email, [], [], [
                    'related_shoot_id' => $shoot->id,
                ], [
                    'idempotency_key' => sprintf('OFFLINE_PAYMENT_INTENT_SUBMITTED:%d:%d', $payment->id, $recipient->id),
                ]);
                $sent = true;
            }

            Log::info('Offline payment intent submitted email dispatched', [
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'recipient_count' => $recipients->count(),
            ]);

            return $sent;
        } catch (\Throwable $e) {
            Log::error('Failed to send offline payment intent submitted email', [
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Notify the client that an admin declined their offline payment intent.
     */
    public function sendOfflinePaymentIntentDeclinedEmail(Shoot $shoot, Payment $payment, ?string $reason = null): bool
    {
        try {
            $shoot = $shoot->fresh(['client', 'photographer', 'rep', 'services.category']) ?? $shoot;
            if (!$shoot->client || !filter_var($shoot->client->email, FILTER_VALIDATE_EMAIL)) {
                Log::warning('Skipping offline payment intent declined email: missing client email', [
                    'shoot_id' => $shoot->id,
                    'payment_id' => $payment->id,
                ]);
                return false;
            }

            $shootData = $this->formatShootData($shoot);
            $methodLabel = match ((string) $payment->payment_method) {
                'check' => 'Cheque',
                'cash' => 'Cash',
                default => ucfirst((string) $payment->payment_method),
            };
            $dashboardUrl = rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/');
            $shootUrl = $dashboardUrl . '/shoots/' . $shoot->id;

            $payload = $this->buildProtectedEmailPayload([
                'recipient' => $this->formatUserData($shoot->client),
                'account' => $this->formatUserData($shoot->client),
                'shoot' => $shootData,
                'payment' => $this->formatPaymentData($payment),
                'links' => [
                    'shoot' => $shootUrl,
                    'dashboard' => $dashboardUrl,
                ],
                'meta' => [
                    'recipient_type' => 'client',
                    'amount' => (float) $payment->amount,
                    'payment_method_label' => $methodLabel,
                    'decline_reason' => $reason,
                    'shoot_address' => $shoot->address,
                    'event_version' => sprintf('intent_%d_declined', $payment->id),
                ],
            ]);

            $this->dispatchProtectedEmail('OFFLINE_PAYMENT_INTENT_DECLINED', $payload, $shoot->client->email, [], [], $this->automatedClientPayload($shoot->client, [
                'related_shoot_id' => $shoot->id,
            ]), [
                'idempotency_key' => sprintf('OFFLINE_PAYMENT_INTENT_DECLINED:%d', $payment->id),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send offline payment intent declined email', [
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
