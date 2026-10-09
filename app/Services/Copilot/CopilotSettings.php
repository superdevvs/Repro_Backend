<?php

namespace App\Services\Copilot;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RolePermissionService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CopilotSettings
{
    public const KEY = 'integrations.repro_copilot';

    public const FEATURES = [
        'shoots' => ['Shoot search', 'Search, browse and inspect permitted shoots.'],
        'operations' => ['Operations brief', 'Identify recorded workflow and media blockers.'],
        'booking' => ['Booking', 'Find clients, services and availability; prepare booking requests.'],
        'reschedule' => ['Rescheduling', 'Prepare appointment changes for review.'],
        'notes' => ['Shoot notes', 'Prepare changes to permitted note fields.'],
        'watches' => ['Shoot watches', 'Follow changes with Repro in-app alerts every five minutes.'],
        'finance' => ['Accounting reports', 'Read authorized collections and payout reports.'],
        'studio' => ['Studio status', 'Inspect authorized processing progress.'],
        'marketing' => ['Listing and client insights', 'Return property facts and booking trends for drafts.'],
        'support' => ['Workflow help', 'Search role-appropriate Repro instructions.'],
    ];

    private const TOOLS = [
        'search' => 'shoots', 'fetch' => 'shoots', 'list_shoots' => 'shoots',
        'operations_brief' => 'operations', 'get_services' => 'booking', 'find_availability' => 'booking',
        'prepare_booking' => 'booking', 'prepare_reschedule' => 'reschedule', 'prepare_note' => 'notes',
        'prepare_watch' => 'watches', 'list_watches' => 'watches', 'finance_report' => 'finance',
        'studio_status' => 'studio', 'listing_pack' => 'marketing', 'client_insights' => 'marketing',
        'support_guide' => 'support',
    ];

    public function current(): array
    {
        $value = DB::table('settings')->where('key', self::KEY)->value('value');
        $stored = $value ? json_decode($value, true) : [];
        $stored = is_array($stored) ? $stored : [];

        return ['enabled' => (bool) ($stored['enabled'] ?? true),
            'allow_changes' => (bool) ($stored['allow_changes'] ?? true),
            'listing_url' => $stored['listing_url'] ?? '',
            'features' => array_replace(array_fill_keys(array_keys(self::FEATURES), true),
                array_intersect_key(is_array($stored['features'] ?? null) ? $stored['features'] : [], self::FEATURES))];
    }

    public function version(?array $settings = null): string
    {
        return hash('sha256', json_encode($settings ?? $this->current(), JSON_THROW_ON_ERROR));
    }

    public function canManage(User $user): bool
    {
        return in_array(strtolower($user->role ?? ''), ['admin', 'superadmin'], true)
            && app(RolePermissionService::class)->userCan($user, 'integrations', 'edit');
    }

    public function enabled(?string $feature = null): bool
    {
        $settings = $this->current();

        return $settings['enabled'] && ($feature === null || ($settings['features'][$feature] ?? false));
    }

    public function allowsTool(string $name, ?array $settings = null): bool
    {
        $settings ??= $this->current();
        if (! $settings['enabled']) {
            return false;
        }
        if (($name === 'commit_action' || str_starts_with($name, 'prepare_')) && ! $settings['allow_changes']) {
            return false;
        }

        return ! isset(self::TOOLS[$name]) || (bool) $settings['features'][self::TOOLS[$name]];
    }

    public function assertEnabled(): void
    {
        abort_unless($this->enabled(), 403, 'Repro Copilot is turned off. Ask an administrator to enable it in Settings > Integrations.');
    }

    public function assertTool(string $name): void
    {
        abort_unless($this->allowsTool($name), 403, 'This Copilot feature is turned off or reviewed changes are disabled.');
    }

    public function assertAction(string $kind): void
    {
        $tool = match ($kind) { 'booking' => 'prepare_booking', 'reschedule' => 'prepare_reschedule',
            'note' => 'prepare_note', 'watch' => 'prepare_watch', default => 'commit_action' };
        $this->assertTool($tool);
    }

    public function save(array $input, string $version, User $actor): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($input, $version, $actor) {
            if (! hash_equals($this->version(), $version)) {
                throw new ConflictHttpException('Copilot settings changed. Reload before saving.');
            }
            $before = $this->current();
            DB::table('settings')->upsert([['key' => self::KEY, 'value' => json_encode($input, JSON_THROW_ON_ERROR),
                'type' => 'json', 'description' => 'Repro Copilot integration controls', 'created_at' => now(), 'updated_at' => now()]],
                ['key'], ['value', 'type', 'description', 'updated_at']);
            app(AuditLogService::class)->record('copilot.settings_updated', $actor, null, ['before' => $before, 'after' => $input]);
        }), 'copilot-settings');
    }
}
