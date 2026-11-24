<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use Illuminate\Support\Carbon;

/**
 * Nightly, rule-based: how long since a client's last shoot compared with
 * their usual gap. Healthy up to 1.5x the usual gap, slowing up to 3x, then
 * at risk. Needs three completed shoots to know a usual gap.
 */
class CrmClientHealth
{
    public const HEALTHY = 'healthy';
    public const SLOWING = 'slowing';
    public const AT_RISK = 'at_risk';
    public const NEW = 'new';

    public function __construct(
        private readonly CrmClientShootStats $shoots,
        private readonly CrmNoticeService $notices,
    ) {}

    /** @return array{scored: int, at_risk: int} */
    public function run(): array
    {
        $scored = 0;
        $atRisk = 0;
        CrmContact::unscopedForSystem()->whereNotNull('client_user_id')->where('stage', '!=', CrmContact::STAGE_LOST)
            ->orderBy('id')->chunkById(300, function ($contacts) use (&$scored, &$atRisk) {
                foreach ($contacts as $contact) {
                    $before = $contact->health;
                    $this->score($contact);
                    $scored++;
                    if ($contact->health === self::AT_RISK && $before !== self::AT_RISK) {
                        $atRisk++;
                        if ($contact->assigned_rep_id) {
                            $this->notices->notify((int) $contact->assigned_rep_id, 'client_at_risk', $contact->name.' hasn’t booked in a while',
                                'Usually every '.$contact->usual_gap_days.' days; last shoot '.$contact->last_shoot_on?->format('j M').'.', $contact->id);
                        }
                    }
                }
            });

        return ['scored' => $scored, 'at_risk' => $atRisk];
    }

    public function score(CrmContact $contact): void
    {
        $dates = collect($this->shoots->completedDates((int) $contact->client_user_id))
            ->map(fn ($d) => Carbon::parse($d)->startOfDay())->sort()->values();
        $last = $dates->last();
        $gap = null;
        if ($dates->count() >= 3) {
            $gaps = $dates->sliding(2)->map(fn ($pair) => $pair->first()->diffInDays($pair->last()))->filter(fn ($d) => $d > 0)->sort()->values();
            $gap = $gaps->isEmpty() ? null : (int) max(1, round($gaps->median()));
        }
        $health = self::NEW;
        if ($gap && $last) {
            $since = $last->diffInDays(now()->startOfDay());
            $healthy = (float) config('crm.health_slowing_factor', 1.5);
            $risk = (float) config('crm.health_at_risk_factor', 3.0);
            $health = $since <= $gap * $healthy ? self::HEALTHY : ($since <= $gap * $risk ? self::SLOWING : self::AT_RISK);
        } elseif (! $last) {
            $health = null;
        }

        $changes = ['usual_gap_days' => $gap, 'last_shoot_on' => $last?->toDateString(), 'health' => $health];
        if ($contact->health !== $health) {
            $changes['health_changed_at'] = now();
        }
        $contact->forceFill($changes);
        if ($contact->isDirty()) {
            $contact->saveQuietly();
        }
    }
}
