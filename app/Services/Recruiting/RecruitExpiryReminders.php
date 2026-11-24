<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitRoster;
use App\Models\User;
use App\Services\Crm\CrmNoticeService;
use Illuminate\Support\Carbon;

/**
 * Daily: tell recruiters when a hired photographer's FAA Part 107 licence or
 * insurance expires within 30 days (or has expired). Once per expiry date.
 */
class RecruitExpiryReminders
{
    public function __construct(private readonly CrmNoticeService $notices) {}

    public function run(): int
    {
        $days = (int) config('crm.expiry_reminder_days', 30);
        $horizon = now()->startOfDay()->addDays($days);
        $sent = 0;

        RecruitRoster::query()->where('active', true)
            ->where(fn ($q) => $q->where('part107_expiry', '<=', $horizon->toDateString())->orWhere('insurance_expiry', '<=', $horizon->toDateString()))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$sent, $horizon) {
                foreach ($rows as $row) {
                    foreach (['part107' => 'FAA Part 107 licence', 'insurance' => 'insurance'] as $key => $label) {
                        $expiry = $row->{$key.'_expiry'};
                        $reminded = $row->{$key.'_reminded_for'};
                        if (! $expiry || $expiry->gt($horizon) || ($reminded && $reminded->isSameDay($expiry))) {
                            continue;
                        }
                        $this->remind($row, $label, $expiry);
                        $row->forceFill([$key.'_reminded_for' => $expiry->toDateString()])->save();
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function remind(RecruitRoster $row, string $label, Carbon $expiry): void
    {
        $name = User::query()->whereKey($row->user_id)->value('name') ?? 'A photographer';
        $verb = $expiry->lt(now()->startOfDay()) ? 'expired on ' : 'expires on ';

        $this->notices->notifyRecruiters('recruit_expiry', $name.'’s '.$label.' '.$verb.$expiry->format('j M Y'), 'Ask them for the renewed document before their next shoot.', [
            'user_id' => $row->user_id, 'applicant_id' => $row->applicant_id, 'document' => $label, 'expires_on' => $expiry->toDateString(),
        ]);
    }
}
