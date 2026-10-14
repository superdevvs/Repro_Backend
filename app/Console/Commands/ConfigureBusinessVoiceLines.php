<?php

namespace App\Console\Commands;

use App\Models\SmsNumber;
use App\Models\User;
use App\Models\VoiceLinePolicy;
use App\Services\RolePermissionService;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Services\Voice\VoiceGreetingService;
use App\Services\Voice\VoiceLinePolicyService;
use App\Support\LockedWrite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Explicit cutover preparation only. Does not submit/activate ports or attach provider numbers. */
class ConfigureBusinessVoiceLines extends Command
{
    protected $signature = 'voice:configure-business-lines {office : Validated office MP3 path} {closed : Validated after-hours MP3 path} {--support-email= : Verified active Cherisea account for the main line} {--canary= : Separate owned test number}';
    protected $description = 'Configure approved business line groups, Robbie support, and private greetings';

    public function handle(VoiceGreetingService $greetings, VoiceNumberSettingsResolver $numbers, RolePermissionService $permissions): int
    {
        $support = User::whereRaw('LOWER(email) = ?', [strtolower(trim((string) $this->option('support-email')))])->first();
        if (! $support || ! $support->isAccountEligibleForAuthentication() || ! in_array($support->role, ['admin', 'superadmin'], true)) {
            $this->error('Specify the verified active support staff email.');

            return self::FAILURE;
        }
        $canary = $numbers->normalize((string) $this->option('canary'));
        if ($canary && (! preg_match('/^\+1\d{10}$/', $canary) || in_array($canary, ['+12027803332', '+12028681663', '+18886567627', '+18888041663'], true))) {
            $this->error('The canary must be a separate owned US number.');

            return self::FAILURE;
        }
        foreach (['office', 'closed'] as $kind) {
            if (! is_file((string) $this->argument($kind))) {
                $this->error('Both supplied greeting files are required.');

                return self::FAILURE;
            }
        }
        $office = $greetings->store($this->argument('office'));
        $closed = $greetings->store($this->argument('closed'));
        $lines = [
            ['+12027803332', 'Editing', 'Asia/Kolkata', ['editing_manager', 'superadmin'], [], 'staff_voicemail'],
            ['+12028681663', 'Main', 'America/New_York', ['superadmin'], [$support->id], 'staff_voicemail'],
            ['+18886567627', 'Support', 'UTC', [], [], 'robbie'],
        ];
        if ($canary) {
            $lines[] = [$canary, 'Voice readiness test', 'Asia/Kolkata', ['superadmin'], [], 'staff_voicemail'];
        }
        LockedWrite::run(fn () => DB::transaction(function () use ($lines, $numbers, $permissions, $office, $closed): void {
            foreach ($lines as [$phone, $label, $timezone, $roles, $ids, $mode]) {
                if ($numbers->matching($phone)->isEmpty()) {
                    SmsNumber::create(['phone_number' => $phone, 'provider' => 'TELNYX', 'label' => $label,
                        'owner_type' => 'SYSTEM', 'is_default' => false, 'voice_ai_enabled' => false, 'sms_ai_enabled' => false]);
                }
                SmsNumber::whereIn('id', $numbers->matching($phone)->modelKeys())->update(['voice_ai_enabled' => $mode === 'robbie']);
                VoiceLinePolicy::updateOrCreate(['phone_number' => $phone], ['mode' => $mode, 'recipient_role' => $roles[0] ?? null, 'recipient_roles' => $roles,
                    'recipient_ids' => $ids, 'timezone' => $timezone,
                    'weekly_hours' => $mode === 'robbie' ? [] : array_merge(array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], [['start' => '09:00', 'end' => '18:00']]), ['sat' => [], 'sun' => []]),
                    'office_greeting' => $office, 'closed_greeting' => $closed]);
            }
            VoiceLinePolicy::updateOrCreate(['phone_number' => '+18888041663'], ['mode' => 'sms_only', 'timezone' => 'UTC',
                'recipient_role' => null, 'recipient_roles' => [], 'recipient_ids' => [], 'weekly_hours' => [], 'office_greeting' => null, 'closed_greeting' => null]);
            SmsNumber::whereIn('id', $numbers->matching('+18888041663')->modelKeys())->update(['voice_ai_enabled' => false]);
            $editing = User::where('role', 'editing_manager')->orWhereJsonContains('secondary_roles', 'editing_manager')->get()
                ->filter(fn ($u) => $u->isAccountEligibleForAuthentication() && $u->role !== 'superadmin');
            foreach ($editing as $user) {
                $overrides = $user->permission_overrides ?? [];
                $required = ['voice-calls-view', 'voice-calls-operate'];
                $permissions->updateUserOverrides($user, ['allow' => array_values(array_unique(array_merge($overrides['allow'] ?? [], $required))),
                    'deny' => array_values(array_diff($overrides['deny'] ?? [], $required))]);
            }
        }), 'voice.business-lines.configure');
        foreach (VoiceLinePolicy::whereIn('phone_number', array_column($lines, 0))->get() as $policy) {
            $readiness = app(VoiceLinePolicyService::class)->present($policy);
            $this->line($policy->phone_number.' '.$policy->timezone.' recipients='.count($readiness['staff']).' registered='.(int) $readiness['readiness']['device_registration']);
        }
        $this->info('Policies saved. Provider assignment, device audio tests and port activation remain separate.');

        return self::SUCCESS;
    }
}
