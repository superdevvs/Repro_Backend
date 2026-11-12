<?php

namespace App\Services\MobileAssistant;

use App\Models\User;
use App\Services\Messaging\SmsInboxPolicy;
use App\Services\RolePermissionService;

/** Discovery is a hint. Target-specific domain authorization still runs on every read and write. */
final class AssistantCapabilities
{
    public const ROLES = ['superadmin', 'admin', 'editing_manager', 'salesRep', 'photographer', 'editor', 'client'];

    public function __construct(private RolePermissionService $permissions) {}

    public function ensureEnabled(User $actor): void
    {
        abort_unless(config('mobile-assistant.enabled'), 503, 'Siri integration is not enabled yet.');
        $pilot = config('mobile-assistant.pilot_user_ids', []);
        abort_unless($pilot === [] || in_array((int) $actor->id, $pilot, true), 403, 'Siri integration is not enabled for this account.');
        abort_unless($actor->isAccountEligibleForAuthentication() && in_array($this->role($actor), self::ROLES, true), 403);
    }

    public function role(User $actor): string
    {
        return $this->permissions->normalizedUserRoles($actor)->first() ?? '';
    }

    public function hasRole(User $actor, array $roles): bool
    {
        return $this->permissions->normalizedUserRoles($actor)->intersect($roles)->isNotEmpty();
    }

    public function can(User $actor, string $resource, string $action = 'view'): bool
    {
        return $actor->isAccountEligibleForAuthentication() && $this->permissions->userCan($actor, $resource, $action);
    }

    public function actions(User $actor): array
    {
        $role = $this->role($actor);
        $shoots = $this->can($actor, 'shoots');
        $office = $this->hasRole($actor, ['superadmin', 'admin', 'editing_manager', 'salesRep']);
        $availability = $this->can($actor, 'availability') && ($office || $role === 'photographer');
        $calling = $this->can($actor, 'voice-calls') && $this->can($actor, 'voice-calls', 'operate');
        $sms = app(SmsInboxPolicy::class)->canSms($actor);
        $email = in_array($role, ['superadmin', 'admin', 'editing_manager'], true) && $this->can($actor, 'messaging-email');
        $allowed = [
            'briefing' => [$shoots, 'read', true], 'shoot.search' => [$shoots, 'read', false],
            'shoot.details' => [$shoots, 'read', false], 'shoot.media' => [$shoots, 'review', false],
            'work.queue' => [$shoots, 'read', false], 'directions' => [$shoots, 'read', true],
            'availability.read' => [$availability, 'read', false], 'availability.create' => [$availability, 'confirm', false],
            'availability.photographers' => [$availability, 'read', false],
            'note.create' => [$shoots && in_array($role, ['superadmin', 'admin', 'salesRep', 'photographer', 'client'], true), 'confirm', false],
            'shoot.hold' => [$shoots && $office && $this->can($actor, 'shoots', 'manage'), 'confirm', false],
            'shoot.reschedule' => [$shoots && $office && $this->can($actor, 'shoots', 'update'), 'confirm', false],
            'shoot.resume' => [$shoots && $office && $this->can($actor, 'shoots', 'update'), 'confirm', false],
            'booking.review' => [$this->can($actor, 'book-shoot', 'create') && ($role === 'client' || $office && $this->can($actor, 'shoots', 'update')), 'review', false],
            'shoot.change_request' => [$shoots && $role === 'client', 'review', false],
            'shoot.submit' => [$shoots && in_array($role, ['photographer', 'editor', 'admin', 'superadmin', 'editing_manager'], true), 'review', false],
            'shoot.delivery' => [$shoots && in_array($role, ['admin', 'superadmin', 'editing_manager'], true), 'review', false],
            'call.read' => [$this->can($actor, 'voice-calls'), 'read', false], 'call.start' => [$calling, 'handoff', false],
            'call.contacts' => [$calling, 'read', false], 'call.lines' => [$calling, 'read', false],
            'sms.read' => [$sms, 'read', false], 'sms.send' => [$sms, 'confirm', false],
            'sms.conversations' => [$sms, 'read', false],
            'email.read' => [$email, 'read', false], 'email.send' => [$email && $this->can($actor, 'messaging-compose', 'create'), 'confirm', false],
            'email.accounts' => [$email, 'read', false],
            'support.read' => [$this->can($actor, 'support'), 'read', false], 'support.create' => [$this->can($actor, 'support'), 'confirm', false],
            'invoices.read' => [$this->can($actor, 'invoices'), 'read', false],
            'earnings.read' => [$role === 'photographer', 'read', false],
            'reports.read' => [$role === 'superadmin' && $this->can($actor, 'reports'), 'read', false],
            'robbie.ask' => [$this->can($actor, 'robbie'), 'read', false],
        ];
        $result = [];
        foreach ($allowed as $id => [$enabled, $mode, $lockSafe]) {
            if ($enabled) {
                $result[] = ['id' => $id, 'mode' => $mode, 'lock_safe' => $lockSafe];
            }
        }

        return $result;
    }

    public function require(User $actor, string $action): array
    {
        $this->ensureEnabled($actor);
        $capability = collect($this->actions($actor))->firstWhere('id', $action);
        abort_unless($capability, 403, 'This action is not available for your account.');

        return $capability;
    }

    public function scopeHash(User $actor): string
    {
        return hash('sha256', json_encode([$actor->id, $this->permissions->effectivePayloadForUser($actor), $actor->account_status, $actor->locked_at]));
    }

    public function payload(User $actor): array
    {
        $this->ensureEnabled($actor);
        $role = $this->role($actor);

        return [
            'schema_version' => 1, 'owner_id' => (string) $actor->id, 'role' => $role,
            'secondary_roles' => $this->permissions->normalizedUserRoles($actor)->slice(1)->values()->all(),
            'scope_version' => $this->scopeHash($actor), 'actions' => $this->actions($actor),
            'default_scope' => match ($role) {
                'photographer', 'editor' => 'assigned', 'salesRep' => 'assigned_clients', 'client' => 'own', default => 'workspace',
            },
            'briefing_title' => match ($role) {
                'editor' => 'Your editing work', 'editing_manager' => 'Production briefing', 'salesRep' => 'Your clients',
                'client' => 'Your bookings', 'photographer' => 'Your shoots', default => 'Workspace briefing',
            },
            'timezone' => $this->timezone(),
        ];
    }

    public function timezone(): string
    {
        $value = \App\Support\Timezone::canonical((string) config('schedule.calendar_timezone', 'America/New_York'));

        return in_array($value, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $value : 'America/New_York';
    }
}
