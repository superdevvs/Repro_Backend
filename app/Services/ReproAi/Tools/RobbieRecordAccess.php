<?php

namespace App\Services\ReproAi\Tools;

use App\Models\MessageThread;
use App\Models\Shoot;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Invoices\InvoiceAuthorizationService;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootNotesAccessService;
use Illuminate\Database\Eloquent\Builder;

/** Record access for Robbie tools; public knowledge never passes through here. */
class RobbieRecordAccess
{
    public function __construct(
        private readonly ShootAuthorizationSupport $shoots,
        private readonly ShootNotesAccessService $notes,
        private readonly InvoiceAuthorizationService $invoices,
    ) {}

    public function actor(array $context): ?User
    {
        // Signed-in identity wins over all model/context role and account claims.
        if (($user = auth()->user()) instanceof User) {
            return $user;
        }
        if (strtoupper((string) ($context['channel'] ?? '')) === 'VOICE') {
            $call = VoiceCall::query()->find($context['voice_call_id'] ?? null);
            if (! $call?->verified_at || ! $call->caller_user_id || $call->ended_at !== null
                || in_array(strtolower((string) $call->status), ['completed', 'failed', 'missed', 'cancelled', 'canceled', 'busy', 'no-answer', 'no_answer', 'ended'], true)) {
                return null;
            }

            $user = $call->callerUser;

            return $user?->isAccountEligibleForAuthentication() ? $user : null;
        }
        if (strtoupper((string) ($context['channel'] ?? '')) === 'SMS') {
            $thread = MessageThread::query()->where('channel', 'SMS')->find($context['sms_thread_id'] ?? null);
            $inbound = $thread?->messages()->where('channel', 'SMS')->where('direction', 'INBOUND')
                ->find($context['sms_message_id'] ?? null);
            if (! $inbound) {
                return null;
            }
            $resolved = app(SmsContextResolverService::class)->resolveByE164($inbound->from_address);

            $user = ($resolved['identified'] ?? false) ? $resolved['user'] : null;

            return $user?->isAccountEligibleForAuthentication() ? $user : null;
        }

        // A bare user_id, role or caller-supplied verification flag is not identity.
        return null;
    }

    public function query(?User $actor): Builder
    {
        return $this->shoots->scopeAccessibleShootMedia(Shoot::query(), $actor);
    }

    public function find(mixed $id, ?User $actor, array $relations = []): ?Shoot
    {
        $shoot = $this->query($actor)->with($relations)->find($id);

        return $shoot && $this->canRead($shoot, $actor) ? $shoot : null;
    }

    public function canRead(Shoot $shoot, ?User $actor): bool
    {
        return $actor !== null && $this->shoots->canAccessShootMedia($shoot, $actor);
    }

    public function isStaff(?User $actor): bool
    {
        return $this->shoots->hasRole($actor, ['admin', 'superadmin', 'editing_manager']);
    }

    public function canChangeBooking(Shoot $shoot, ?User $actor): bool
    {
        return $this->canRead($shoot, $actor) && ($this->isStaff($actor)
            || ($this->shoots->isClientUser($actor) && (string) $shoot->client_id === (string) $actor->id));
    }

    public function canChangeNote(Shoot $shoot, ?User $actor, string $field): bool
    {
        return $actor !== null && $this->canRead($shoot, $actor) && $this->notes->canUpdateScalar($shoot, $actor, $field);
    }

    public function description(Shoot $shoot, ?User $actor): ?string
    {
        if (! $actor || ! $this->notes->canRead($shoot, $actor)) {
            return null;
        }

        return $this->isStaff($actor) ? ($shoot->notes ?? $shoot->shoot_notes) : $shoot->shoot_notes;
    }

    public function canReadBilling(Shoot $shoot, ?User $actor): bool
    {
        return $this->invoices->canViewShootInvoice($shoot, $actor);
    }
}
