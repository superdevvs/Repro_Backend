<?php

namespace App\Services\TelnyxAi;

use App\Models\Contact;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Progressive Context Hydration for voice calls.
 *
 *  - Tier 1 (instant): loaded before the greeting; compact identity + recent activity.
 *  - Tier 2 (behavioral): loaded lazily on importance / keyword / sentiment triggers.
 *  - Tier 3 (full 360): loaded on operator request, VIP flag, or active escalation.
 *
 * Everything is stored as flat Robbie Context Objects under
 * voice_calls.metadata.memory.{tier1,tier2,tier3}; never raw DB rows.
 */
class VoiceMemoryService
{
    // Importance-scoring weights (sensible defaults; tunable in settings).
    private const W_UNPAID = 0.15;       // per USD of unpaid invoice total
    private const W_COMPLAINTS = 12.0;   // per recent complaint
    private const W_VIP = 30.0;
    private const W_LUXURY_SHOOT = 10.0;
    private const W_LIFETIME = 6.0;      // * log10(lifetime spend)
    private const W_RECENCY = 0.5;       // - per day since last complaint

    private const FRESHNESS_DAYS = 180;
    private const TIER2_THRESHOLD = 45;

    public function __construct(private readonly VoiceSettingsService $settings)
    {
    }

    /**
     * Load Tier 1 instant context and persist it. Returns the context object.
     *
     * @param array{user:?User,contact:?Contact,identified:bool,phone_e164:string} $resolved
     */
    public function loadTier1(VoiceCall $call, array $resolved): array
    {
        $user = $resolved['user'] ?? null;
        $contact = $resolved['contact'] ?? null;
        $name = (string) ($user?->name ?? $contact?->name ?? '');

        $tier1 = [
            'caller_first_name' => $this->firstName($name),
            'caller_name' => $name ?: null,
            'phone_e164' => $resolved['phone_e164'] ?? $call->from_phone,
            'language_hint' => 'en',
            'identified' => (bool) ($resolved['identified'] ?? false),
            'recent_calls' => $this->recentCalls($call),
            'open_shoots' => $this->openShoots($user, $contact),
            'unpaid_invoices' => $this->unpaidInvoices($user, $contact),
            'preferred_photographer' => $this->preferredPhotographer($user, $contact),
            'active_issue' => $this->activeIssue($call),
            'loaded_at' => now()->toIso8601String(),
        ];

        $this->persistTier($call, 'tier1', $tier1);

        return $tier1;
    }

    /**
     * Load Tier 2 behavioral signals if not already present.
     */
    public function loadTier2(VoiceCall $call, array $resolved = []): array
    {
        $existing = $this->tier($call, 'tier2');
        if ($existing) {
            return $existing;
        }

        $user = $resolved['user'] ?? $call->callerUser;
        $contact = $resolved['contact'] ?? $call->callerContact;

        $tier2 = [
            'sms_threads' => $this->recentSmsThreads($contact, $user),
            'recent_escalations' => $this->recentEscalations($call),
            'mood_history' => $this->moodHistory($call),
            'churn_risk_score' => $this->churnRisk($user, $contact),
            'prior_callbacks' => $this->priorCallbacks($call),
            'unresolved_complaints' => $this->unresolvedComplaints($call),
            'reasons' => $this->tier2Reasons($call, $user, $contact),
            'loaded_at' => now()->toIso8601String(),
        ];

        $this->persistTier($call, 'tier2', $this->applyFreshness($tier2));

        return $tier2;
    }

    /**
     * Load Tier 3 full 360 on demand.
     */
    public function loadTier3(VoiceCall $call, array $resolved = []): array
    {
        $existing = $this->tier($call, 'tier3');
        if ($existing) {
            return $existing;
        }

        $user = $resolved['user'] ?? $call->callerUser;
        $contact = $resolved['contact'] ?? $call->callerContact;

        $tier3 = [
            'lifetime_spend_usd' => $this->lifetimeSpend($user, $contact),
            'total_shoots' => $this->totalShoots($user, $contact),
            'staff_notes' => $this->staffNotes($contact, $user),
            'nps' => null,
            'payment_trends' => $this->paymentTrends($user, $contact),
            'recent_payments' => $this->recentPayments($user, $contact),
            'open_quotes' => [],
            'reasons' => ['operator_request'],
            'loaded_at' => now()->toIso8601String(),
        ];

        $this->persistTier($call, 'tier3', $tier3);

        return $tier3;
    }

    /**
     * Decide whether Tier 2 should auto-load now, based on importance score and
     * behavioral triggers.
     *
     * @param array<string,mixed> $signals
     */
    public function shouldAutoLoadTier2(VoiceCall $call, array $signals = []): bool
    {
        if ($this->tier($call, 'tier2')) {
            return false;
        }

        if (($signals['keyword_hit'] ?? false)
            || ($signals['negative_sentiment'] ?? false)
            || ($signals['human_transfer_requested'] ?? false)
            || (int) ($signals['duration_seconds'] ?? 0) > 90
        ) {
            return true;
        }

        return $this->importanceScore($call) >= self::TIER2_THRESHOLD;
    }

    /**
     * Compute a 0..100 importance score from Tier 1 facts.
     */
    public function importanceScore(VoiceCall $call): float
    {
        $tier1 = $this->tier($call, 'tier1') ?? [];
        $unpaidTotal = (float) ($tier1['unpaid_invoices']['total'] ?? 0);
        $complaints = (int) (($tier1['active_issue'] ?? null) ? 1 : 0);
        $vip = (bool) ($tier1['vip_flag'] ?? false);
        $luxury = (bool) (collect($tier1['open_shoots'] ?? [])->contains(fn ($s) => ($s['luxury'] ?? false)));
        $lifetime = max(1, (float) ($tier1['lifetime_spend_usd'] ?? 0));

        $score = self::W_UNPAID * $unpaidTotal
            + self::W_COMPLAINTS * $complaints
            + ($vip ? self::W_VIP : 0)
            + ($luxury ? self::W_LUXURY_SHOOT : 0)
            + self::W_LIFETIME * log10($lifetime);

        return round(max(0, min(100, $score)), 1);
    }

    /**
     * Resolve greeting tokens like {caller_first_name}, {next_shoot_date}.
     */
    public function resolveGreetingTokens(string $template, VoiceCall $call): string
    {
        $tier1 = $this->tier($call, 'tier1') ?? [];
        $nextShoot = $tier1['open_shoots'][0]['date'] ?? '';
        $replacements = [
            '{caller_first_name}' => $tier1['caller_first_name'] ?? 'there',
            '{caller_name}' => $tier1['caller_name'] ?? '',
            '{next_shoot_date}' => $nextShoot,
        ];

        return strtr($template, $replacements);
    }

    public function tier(VoiceCall $call, string $tier): ?array
    {
        $value = $call->metadata['memory'][$tier] ?? null;
        return is_array($value) ? $value : null;
    }

    // ---- persistence -------------------------------------------------------

    private function persistTier(VoiceCall $call, string $tier, array $payload): void
    {
        $metadata = $call->metadata ?? [];
        $memory = is_array($metadata['memory'] ?? null) ? $metadata['memory'] : [];
        $memory[$tier] = $payload;
        $metadata['memory'] = $memory;
        $call->forceFill(['metadata' => $metadata])->save();
    }

    // ---- Tier 1 builders ---------------------------------------------------

    private function recentCalls(VoiceCall $call): array
    {
        return VoiceCall::query()
            ->where('id', '!=', $call->id)
            ->where(function ($q) use ($call) {
                $q->where('from_phone', $call->from_phone)
                    ->orWhere('to_phone', $call->from_phone);
            })
            ->latest()
            ->limit(5)
            ->get(['id', 'created_at', 'intent', 'disposition'])
            ->map(fn (VoiceCall $c) => [
                'id' => $c->id,
                'date' => optional($c->created_at)->toDateString(),
                'intent' => $c->intent,
                'disposition' => $c->disposition,
            ])
            ->all();
    }

    private function openShoots(?User $user, ?Contact $contact): array
    {
        if (!$this->tableExists('shoots')) {
            return [];
        }

        $query = \DB::table('shoots')->whereNotIn('status', ['delivered', 'cancelled', 'declined']);
        $this->scopeToCaller($query, $user, $contact);

        return collect($query->orderByDesc('id')->limit(5)->get())
            ->map(fn ($s) => [
                'id' => $s->id ?? null,
                'date' => $s->scheduled_date ?? $s->created_at ?? null,
                'status' => $s->status ?? null,
                'luxury' => false,
            ])
            ->all();
    }

    private function unpaidInvoices(?User $user, ?Contact $contact): array
    {
        if (!$this->tableExists('invoices')) {
            return ['count' => 0, 'total' => 0];
        }

        $query = \DB::table('invoices')->whereNotIn('status', ['paid', 'void', 'cancelled']);
        $this->scopeToCaller($query, $user, $contact);

        $rows = $query->get();
        $total = 0;
        foreach ($rows as $row) {
            $total += (float) ($row->balance ?? $row->total ?? $row->amount_due ?? 0);
        }

        return ['count' => $rows->count(), 'total' => round($total, 2)];
    }

    private function preferredPhotographer(?User $user, ?Contact $contact): ?string
    {
        return null;
    }

    private function activeIssue(VoiceCall $call): ?array
    {
        $issue = VoiceCall::query()
            ->where('id', '!=', $call->id)
            ->where('from_phone', $call->from_phone)
            ->whereIn('disposition', ['callback_needed', 'handoff_to_staff'])
            ->latest()
            ->first(['id', 'escalation_reason', 'created_at']);

        if (!$issue) {
            return null;
        }

        return [
            'voice_call_id' => $issue->id,
            'reason' => $issue->escalation_reason,
            'opened_at' => optional($issue->created_at)->toIso8601String(),
        ];
    }

    // ---- Tier 2 builders ---------------------------------------------------

    private function recentSmsThreads(?Contact $contact, ?User $user): array
    {
        return [];
    }

    private function recentEscalations(VoiceCall $call): array
    {
        return VoiceCall::query()
            ->where('from_phone', $call->from_phone)
            ->whereNotNull('escalation_reason')
            ->latest()
            ->limit(3)
            ->get(['id', 'escalation_reason', 'created_at'])
            ->map(fn (VoiceCall $c) => [
                'id' => $c->id,
                'reason' => $c->escalation_reason,
                'event_at' => optional($c->created_at)->toIso8601String(),
            ])
            ->all();
    }

    private function moodHistory(VoiceCall $call): array
    {
        return VoiceCall::query()
            ->where('from_phone', $call->from_phone)
            ->whereNotNull('metadata')
            ->latest()
            ->limit(5)
            ->get(['id', 'metadata', 'created_at'])
            ->map(fn (VoiceCall $c) => [
                'id' => $c->id,
                'mood' => $c->metadata['intel_final']['customer_mood'] ?? ($c->metadata['intel_live']['customer_mood'] ?? null),
                'event_at' => optional($c->created_at)->toIso8601String(),
            ])
            ->filter(fn ($m) => $m['mood'] !== null)
            ->values()
            ->all();
    }

    private function churnRisk(?User $user, ?Contact $contact): float
    {
        return 0.0;
    }

    private function priorCallbacks(VoiceCall $call): int
    {
        if (!$this->tableExists('scheduled_voice_calls')) {
            return 0;
        }

        return (int) \DB::table('scheduled_voice_calls')
            ->where('target_phone', $call->from_phone)
            ->count();
    }

    private function unresolvedComplaints(VoiceCall $call): int
    {
        return VoiceCall::query()
            ->where('from_phone', $call->from_phone)
            ->where('disposition', 'callback_needed')
            ->count();
    }

    private function tier2Reasons(VoiceCall $call, ?User $user, ?Contact $contact): array
    {
        $reasons = [];
        if ($this->recentCalls($call)) {
            $reasons[] = 'repeat_caller';
        }
        if (($this->unpaidInvoices($user, $contact)['total'] ?? 0) > 0) {
            $reasons[] = 'open_invoice';
        }
        if ($this->unresolvedComplaints($call) > 0) {
            $reasons[] = 'recent_complaint';
        }
        return array_values(array_unique($reasons));
    }

    // ---- Tier 3 builders ---------------------------------------------------

    private function lifetimeSpend(?User $user, ?Contact $contact): float
    {
        if (!$this->tableExists('invoices')) {
            return 0.0;
        }

        $query = \DB::table('invoices')->where('status', 'paid');
        $this->scopeToCaller($query, $user, $contact);

        $total = 0;
        foreach ($query->get() as $row) {
            $total += (float) ($row->total ?? $row->amount ?? 0);
        }
        return round($total, 2);
    }

    private function totalShoots(?User $user, ?Contact $contact): int
    {
        if (!$this->tableExists('shoots')) {
            return 0;
        }
        $query = \DB::table('shoots');
        $this->scopeToCaller($query, $user, $contact);
        return (int) $query->count();
    }

    private function staffNotes(?Contact $contact, ?User $user): array
    {
        return [];
    }

    private function paymentTrends(?User $user, ?Contact $contact): array
    {
        return [];
    }

    private function recentPayments(?User $user, ?Contact $contact): array
    {
        return [];
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Drop facts older than the freshness window unless they are unresolved
     * escalations or open invoices.
     */
    private function applyFreshness(array $tier2): array
    {
        $cutoff = now()->subDays(self::FRESHNESS_DAYS);

        $tier2['mood_history'] = array_values(array_filter(
            $tier2['mood_history'] ?? [],
            function ($m) use ($cutoff) {
                try {
                    return Carbon::parse($m['event_at'] ?? null)->greaterThanOrEqualTo($cutoff);
                } catch (\Throwable $e) {
                    return true;
                }
            }
        ));

        return $tier2;
    }

    private function scopeToCaller($query, ?User $user, ?Contact $contact): void
    {
        $query->where(function ($q) use ($user, $contact) {
            $matched = false;
            if ($user && Schema::hasColumn($query->from, 'user_id')) {
                $q->orWhere('user_id', $user->id);
                $matched = true;
            }
            if ($user && Schema::hasColumn($query->from, 'client_id')) {
                $q->orWhere('client_id', $user->id);
                $matched = true;
            }
            if ($contact && Schema::hasColumn($query->from, 'contact_id')) {
                $q->orWhere('contact_id', $contact->id);
                $matched = true;
            }
            if (!$matched) {
                // No linkable column: force empty result rather than match all.
                $q->whereRaw('1 = 0');
            }
        });
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function firstName(string $name): string
    {
        return trim(explode(' ', trim($name))[0] ?? '') ?: 'there';
    }
}
