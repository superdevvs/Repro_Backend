<?php

namespace App\Services\Crm\Email;

use App\Http\Controllers\API\Crm\CrmContactController;
use App\Models\Crm\CrmCampaign;
use App\Models\Crm\CrmCampaignRecipient;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmNoticeService;
use App\Support\LockedWrite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Custom campaigns: Draft → Submitted → Approved or Sent back → Scheduled → Sent.
 *
 * - Built from an approved branded template; only subject, body and image change.
 * - The audience is resolved with the campaign owner's visibility, so a rep's
 *   campaign can only ever reach that rep's own contacts, whoever approves it.
 * - Any edit after approval returns the campaign to Submitted.
 */
class CrmCampaignService
{
    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmContentCheck $check,
        private readonly CrmEmailSender $sender,
        private readonly CrmNoticeService $notices,
    ) {}

    public function create(array $data, User $owner): CrmCampaign
    {
        if (! $this->access->can($owner, 'crm-campaigns', 'create')) {
            throw new AuthorizationException('You cannot write campaigns.');
        }
        $template = $this->approvedTemplate((int) $data['template_id']);
        $version = $template->approvedVersion;

        return CrmCampaign::create([
            'name' => $data['name'],
            'owner_id' => $owner->id,
            'template_id' => $template->id,
            'template_version_id' => $version->id,
            'subject' => $data['subject'] ?? $version->subject,
            'body_html' => CrmHtmlSanitizer::clean($data['body_html'] ?? $version->body_html),
            'image_url' => $data['image_url'] ?? $version->image_url,
            'audience' => $this->normalizeAudience($data['audience'] ?? []),
            'status' => 'draft',
        ]);
    }

    public function update(CrmCampaign $campaign, array $data, User $actor): CrmCampaign
    {
        $this->assertOwnerOrApprover($campaign, $actor);
        if (in_array($campaign->status, ['sending', 'sent', 'needs_review', 'skipped'], true)) {
            throw ValidationException::withMessages(['status' => 'A sent campaign cannot be changed.']);
        }
        $changes = [];
        foreach (['name', 'subject', 'image_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (array_key_exists('body_html', $data)) {
            $changes['body_html'] = CrmHtmlSanitizer::clean($data['body_html']);
        }
        if (array_key_exists('audience', $data)) {
            $changes['audience'] = $this->normalizeAudience($data['audience']);
        }
        $campaign->forceFill($changes);
        $contentChanged = $campaign->isDirty(['subject', 'body_html', 'image_url', 'audience']);
        if ($contentChanged && in_array($campaign->status, ['approved', 'scheduled'], true)) {
            // Any edit after approval goes back for review.
            $campaign->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'scheduled_at' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            $campaign->content_check = $this->runCheck($campaign);
        }
        $campaign->save();

        return $campaign;
    }

    public function submit(CrmCampaign $campaign, User $actor): CrmCampaign
    {
        $this->assertOwnerOrApprover($campaign, $actor);
        if (! in_array($campaign->status, ['draft', 'sent_back'], true)) {
            throw ValidationException::withMessages(['status' => 'Only drafts can be submitted.']);
        }
        if ($this->audienceQuery($campaign)->count() === 0) {
            throw ValidationException::withMessages(['audience' => 'The audience is empty.']);
        }
        $campaign->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'content_check' => $this->runCheck($campaign)])->save();

        User::query()->whereIn('role', ['admin', 'superadmin'])->get()
            ->filter(fn (User $u) => $u->id !== $actor->id && $this->access->can($u, 'crm-campaigns', 'approve'))
            ->each(fn (User $u) => $this->notices->notify($u->id, 'campaign_submitted', $actor->name.' submitted “'.$campaign->name.'” for review'));

        return $campaign;
    }

    public function approve(CrmCampaign $campaign, User $reviewer): CrmCampaign
    {
        $this->assertApprover($reviewer);
        if ($campaign->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'Only submitted campaigns can be approved.']);
        }
        $campaign->forceFill(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => null])->save();
        $this->notices->notify($campaign->owner_id, 'campaign_approved', '“'.$campaign->name.'” was approved', 'Schedule it or send it now.');

        return $campaign;
    }

    public function sendBack(CrmCampaign $campaign, User $reviewer, string $comment): CrmCampaign
    {
        $this->assertApprover($reviewer);
        if ($campaign->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'Only submitted campaigns can be sent back.']);
        }
        $campaign->forceFill(['status' => 'sent_back', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => $comment])->save();
        $this->notices->notify($campaign->owner_id, 'campaign_sent_back', '“'.$campaign->name.'” needs changes', $comment);

        return $campaign;
    }

    public function schedule(CrmCampaign $campaign, ?string $at, User $actor): CrmCampaign
    {
        $this->assertOwnerOrApprover($campaign, $actor);
        if (! in_array($campaign->status, ['approved', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => 'Only approved campaigns can be scheduled.']);
        }
        $when = $at ? \Illuminate\Support\Carbon::parse($at) : now();
        $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $when->lt(now()) ? now() : $when])->save();

        return $campaign;
    }

    /** Contacts the campaign will reach, using the owner's visibility. */
    public function audienceQuery(CrmCampaign $campaign): Builder
    {
        $owner = User::query()->find($campaign->owner_id);
        $query = CrmContact::unscopedForSystem();
        if (! $this->access->canViewAll($owner)) {
            $query->where('assigned_rep_id', $owner?->id ?? 0);
        }
        $audience = $campaign->audience ?? [];
        if (! empty($audience['contact_ids'])) {
            $query->whereIn('id', array_map('intval', $audience['contact_ids']));
        }
        $query = CrmContactController::filtered($query, [
            'stage' => $audience['stage'] ?? null,
            'segment' => $audience['segment'] ?? null,
            'client_type' => $audience['client_type'] ?? null,
            'market_state' => $audience['market_state'] ?? null,
            'rep_id' => $audience['rep_id'] ?? null,
            'include_lost' => false,
        ]);

        return $query->whereNotNull('email_normalized');
    }

    /** @return array{total: int, reachable: int, opted_out: int, bounced: int} */
    public function audienceSummary(CrmCampaign $campaign): array
    {
        $all = $this->audienceQuery($campaign)->get(['id', 'email_normalized', 'email_opt_out_at', 'email_status', 'marketing_consent_email', 'marketing_consent_at']);
        $suppressed = \App\Models\Crm\EmailSuppression::query()->whereIn('email_normalized', $all->pluck('email_normalized'))->pluck('email_normalized')->flip();
        $optedOut = $all->filter(fn ($c) => $c->email_opt_out_at || isset($suppressed[$c->email_normalized]))->count();
        $bounced = $all->filter(fn ($c) => in_array($c->email_status, ['bounced', 'complained'], true))->count();

        $consent = app(CrmMarketingConsent::class);
        $reachable = $all->filter(fn ($c) => $consent->allows($c) && ! $c->email_opt_out_at
            && ! isset($suppressed[$c->email_normalized]) && ! in_array($c->email_status, ['bounced', 'complained'], true))->count();

        return ['total' => $all->count(), 'reachable' => $reachable, 'opted_out' => $optedOut, 'bounced' => $bounced,
            'no_consent' => $all->reject(fn ($c) => $consent->allows($c))->count()];
    }

    /** Sends campaigns whose time has come. */
    public function runDue(): array
    {
        $counts = ['campaigns' => 0, 'sent' => 0, 'skipped' => 0];
        // Outbound hold: scheduled campaigns stay scheduled until the release.
        if (! config('crm.enabled') || config('crm.outbound_hold') || $this->sender->transport()->name() === 'log') {
            return $counts;
        }
        $available = fn ($q) => $q->where(fn ($q) => $q->where('status', 'scheduled')->where('scheduled_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(30)));
        $due = CrmCampaign::query()->where($available)->pluck('id');
        foreach ($due as $id) {
            $claimed = LockedWrite::run(fn () => CrmCampaign::query()->whereKey($id)->where($available)->update(['status' => 'sending', 'updated_at' => now()]), 'crm.campaign.claim');
            if (! $claimed) {
                continue;
            }
            $campaign = CrmCampaign::query()->find($id);
            $result = $this->sendNow($campaign);
            $counts['campaigns']++;
            $counts['sent'] += $result['sent'];
            $counts['skipped'] += $result['skipped'];
        }

        return $counts;
    }

    public function sendNow(CrmCampaign $campaign): array
    {
        $owner = User::query()->find($campaign->owner_id);
        $template = CrmEmailTemplate::query()->find($campaign->template_id);
        $version = $template?->versions()->whereKey($campaign->template_version_id)->first();

        foreach ($this->audienceQuery($campaign)->orderBy('id')->cursor() as $contact) {
            $recipient = CrmCampaignRecipient::query()->firstOrCreate(['campaign_id' => $campaign->id, 'contact_id' => $contact->id], ['status' => 'pending']);
            if ($recipient->status !== 'pending') {
                continue;
            }
            try {
                $send = $this->sender->send([
                    'contact' => $contact,
                    'sender' => $owner,
                    'source_type' => 'campaign',
                    'source_id' => $campaign->id,
                    'template_version' => $version,
                    'content' => [
                        'subject' => $campaign->subject, 'body_html' => $campaign->body_html, 'image_url' => $campaign->image_url,
                        'preheader' => $version?->preheader, 'cta_label' => $version?->cta_label, 'cta_url' => $version?->cta_url,
                    ],
                    'idempotency_key' => 'crm-campaign-'.$campaign->id.'-'.$contact->id,
                    'marketing' => true,
                    'counts_as_contact' => false,
                ]);
                $recipient->forceFill(['status' => $send->acceptedByProvider() ? 'sent' : $send->status,
                    'reason' => $send->error ? mb_substr($send->error, 0, 120) : null, 'send_id' => $send->id])->save();
            } catch (CrmSendBlocked $e) {
                $ineligible = in_array($e->reason, ['no_email', 'bounced', 'opted_out', 'no_consent'], true);
                $recipient->forceFill(['status' => $ineligible ? 'skipped' : 'blocked', 'reason' => mb_substr($e->getMessage(), 0, 120)])->save();
            }
        }

        ['sent' => $sent, 'skipped' => $skipped, 'unconfirmed' => $unconfirmed] = $this->deliverySummary($campaign);
        $status = $unconfirmed ? 'needs_review' : ($sent ? 'sent' : 'skipped');
        $campaign->forceFill(['status' => $status, 'sent_at' => $sent ? now() : null, 'recipient_count' => $sent])->save();
        $this->notices->notify($campaign->owner_id, 'campaign_sent', '“'.$campaign->name.'” was sent to '.$sent.' contact'.($sent === 1 ? '' : 's'), $skipped.' skipped. '.$unconfirmed.' need delivery review.');

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    public function deliverySummary(CrmCampaign $campaign): array
    {
        $counts = CrmCampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $sent = (int) $counts->get('sent', 0);
        $skipped = (int) $counts->get('skipped', 0);

        return ['sent' => $sent, 'skipped' => $skipped, 'unconfirmed' => (int) $counts->sum() - $sent - $skipped];
    }

    public function deliveryIssues(CrmCampaign $campaign): array
    {
        return CrmCampaignRecipient::query()->join('crm_contacts as c', 'c.id', '=', 'crm_campaign_recipients.contact_id')
            ->where('campaign_id', $campaign->id)->whereIn('c.id', CrmContact::query()->select('id'))
            ->whereNotIn('crm_campaign_recipients.status', ['sent', 'skipped', 'pending'])
            ->orderBy('crm_campaign_recipients.id')->limit(100)
            ->get(['c.id as contact_id', 'c.name', 'crm_campaign_recipients.status', 'crm_campaign_recipients.reason'])
            ->toArray();
    }

    public function runCheck(CrmCampaign $campaign): array
    {
        return $this->check->check($campaign->subject."\n".CrmHtmlSanitizer::toText($campaign->body_html), User::query()->find($campaign->owner_id));
    }

    private function approvedTemplate(int $id): CrmEmailTemplate
    {
        $template = CrmEmailTemplate::query()->whereKey($id)->whereNotNull('approved_version_id')->where('status', '!=', 'retired')->first();
        if (! $template) {
            throw ValidationException::withMessages(['template_id' => 'Start from an approved template.']);
        }

        return $template;
    }

    private function normalizeAudience(array $audience): array
    {
        return array_filter([
            'segment' => in_array($audience['segment'] ?? null, CrmContact::SEGMENTS, true) ? $audience['segment'] : null,
            'client_type' => in_array($audience['client_type'] ?? null, CrmContact::CLIENT_TYPES, true) ? $audience['client_type'] : null,
            'stage' => in_array($audience['stage'] ?? null, CrmContact::STAGES, true) && ($audience['stage'] ?? null) !== CrmContact::STAGE_LOST ? $audience['stage'] : null,
            'market_state' => isset($audience['market_state']) ? mb_substr((string) $audience['market_state'], 0, 40) : null,
            'rep_id' => isset($audience['rep_id']) ? (int) $audience['rep_id'] : null,
            'contact_ids' => ! empty($audience['contact_ids']) ? array_values(array_unique(array_map('intval', (array) $audience['contact_ids']))) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function assertOwnerOrApprover(CrmCampaign $campaign, User $user): void
    {
        if ((int) $campaign->owner_id !== (int) $user->id && ! $this->access->can($user, 'crm-campaigns', 'approve')) {
            throw new AuthorizationException('This campaign belongs to someone else.');
        }
    }

    private function assertApprover(User $user): void
    {
        if (! $this->access->can($user, 'crm-campaigns', 'approve')) {
            throw new AuthorizationException('Only an admin can approve campaigns.');
        }
    }
}
