<?php

namespace App\Services\Crm\Robbie;

use App\Models\Crm\CrmCampaign;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDuplicateAlert;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmQuote;
use App\Models\Recruiting\RecruitApplicant;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmClientHealth;

/**
 * The CRM lines in the top-bar Robbie strip. Rule-based SQL only, no model
 * calls, and every count runs through the owner scope.
 */
class CrmInsights
{
    public function __construct(private readonly CrmAccess $access) {}

    /** @return array<int, array{key: string, tone: string, count: int, text: string, href: string, prompt: string}> */
    public function for(User $user): array
    {
        $out = [];
        $add = function (string $key, string $tone, int $count, string $one, string $many, string $href, string $prompt) use (&$out) {
            if ($count > 0) {
                $out[] = ['key' => $key, 'tone' => $tone, 'count' => $count, 'text' => str_replace('{n}', (string) $count, $count === 1 ? $one : $many), 'href' => $href, 'prompt' => $prompt];
            }
        };

        $add('overdue', 'warning', CrmEvent::query()->visible($user)->where('assigned_user_id', $user->id)->where('status', 'planned')->where('starts_at', '<', now()->startOfDay())->count(),
            '1 follow-up is overdue', '{n} follow-ups are overdue', '/crm/follow-ups?filter=overdue', 'Which overdue follow-ups should I do first?');

        $add('first_contact', 'warning', CrmContact::query()->whereIn('stage', [CrmContact::STAGE_OUTREACH, CrmContact::STAGE_INQUIRY])
            ->whereNull('last_contacted_at')->whereNotNull('first_contact_due_at')->where('first_contact_due_at', '<', now())
            ->when(! $this->access->canViewAll($user), fn ($q) => $q->where('assigned_rep_id', $user->id))->count(),
            '1 new lead is past the first-contact target', '{n} new leads are past the first-contact target', '/crm/contacts?filter=first_contact_due', 'Who are my new leads waiting for a first call?');

        $add('today', 'info', CrmEvent::query()->visible($user)->where('assigned_user_id', $user->id)->where('status', 'planned')->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            '1 follow-up today', '{n} follow-ups today', '/crm/follow-ups', 'Plan my day');

        $add('at_risk', 'warning', CrmContact::query()->where('health', CrmClientHealth::AT_RISK)->where('stage', '!=', CrmContact::STAGE_LOST)->count(),
            '1 client hasn’t booked in a while', '{n} clients haven’t booked in a while', '/crm/client-pool?health=at_risk', 'Which clients are at risk and what should I say to them?');

        $days = (int) config('crm.quote_follow_up_days', 5);
        $add('quotes', 'info', CrmQuote::query()->where('status', 'sent')->where('sent_at', '<', now()->subDays($days))->count(),
            '1 quote is waiting for an answer', '{n} quotes are waiting for an answer', '/crm/quotes?status=sent', 'Which quotes need a follow-up?');

        if ($this->access->can($user, 'crm-contacts', 'assign')) {
            $add('unassigned', 'warning', CrmContact::query()->whereNull('assigned_rep_id')->where('stage', '!=', CrmContact::STAGE_LOST)->count(),
                '1 lead is waiting for a rep', '{n} leads are waiting for a rep', '/crm/unassigned', 'Suggest reps for the unassigned leads');
            $add('duplicates', 'info', CrmDuplicateAlert::query()->whereNull('resolved_at')->count(),
                '1 duplicate needs a look', '{n} duplicates need a look', '/crm/unassigned?tab=duplicates', 'Show me the open duplicate alerts');
        }
        if ($this->access->can($user, 'crm-campaigns', 'approve')) {
            $add('campaigns', 'info', CrmCampaign::query()->where('status', 'submitted')->count(),
                '1 campaign is waiting for review', '{n} campaigns are waiting for review', '/crm/email?tab=campaigns&status=submitted', 'Summarise the campaigns waiting for review');
        }
        if ($this->access->can($user, 'crm-templates', 'approve')) {
            $add('templates', 'info', \App\Models\Crm\CrmEmailTemplateVersion::query()->where('status', 'pending')->distinct()->count('template_id'),
                '1 template is waiting for review', '{n} templates are waiting for review', '/crm/email?tab=templates&status=submitted', 'Which templates are waiting for review?');
        }
        if ($this->access->can($user, 'crm-quotes', 'approve')) {
            $add('quote_approvals', 'info', CrmQuote::query()->where('status', 'pending_approval')->count(),
                '1 quote discount needs approval', '{n} quote discounts need approval', '/crm/quotes?status=pending_approval', 'Show the quotes waiting for discount approval');
        }
        if ($this->access->can($user, 'crm-recruiting', 'view')) {
            $add('applicants', 'info', RecruitApplicant::query()->where('stage', 'applied')->where('created_at', '>=', now()->subDays(7))->count(),
                '1 new applicant this week', '{n} new applicants this week', '/crm/recruiting', 'Who applied this week?');
        }

        return $out;
    }
}
