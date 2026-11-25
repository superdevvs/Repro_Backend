<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmDripCampaignVersion;
use App\Models\Crm\CrmDripEnrollment;
use App\Models\Crm\CrmDripStep;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmActivityLogger;
use App\Services\Crm\CrmNoticeService;
use App\Support\LockedWrite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drip campaigns: building (admins), enrolling (anyone who can edit the
 * contact), stopping, and the scheduled run that sends due steps.
 *
 * - Editing a live drip creates a draft version; publishing it changes only
 *   future steps. Enrolled contacts keep their place (the last send day reached).
 * - One active drip per contact: enrolling in a new one ends the old one.
 * - A drip stops on reply, unsubscribe, bounce, a booked shoot or Lost, and the
 *   rep gets a notice.
 */
class CrmDripService
{
    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmActivityLogger $activity,
        private readonly CrmNoticeService $notices,
        private readonly CrmEmailSender $sender,
    ) {}

    /** Editable copy of the steps. Creates a draft version from the published one if needed. */
    public function draft(CrmDripCampaign $campaign, User $actor): CrmDripCampaignVersion
    {
        $this->assertManager($actor);
        if ($campaign->draft_version_id && ($existing = CrmDripCampaignVersion::query()->find($campaign->draft_version_id))) {
            return $existing;
        }

        return DB::transaction(function () use ($campaign) {
            $next = (int) CrmDripCampaignVersion::query()->where('campaign_id', $campaign->id)->max('version_no') + 1;
            $draft = CrmDripCampaignVersion::create(['campaign_id' => $campaign->id, 'version_no' => $next]);
            foreach ($campaign->publishedVersion?->steps ?? [] as $step) {
                CrmDripStep::create(['campaign_version_id' => $draft->id, 'send_day' => $step->send_day, 'template_id' => $step->template_id, 'label' => $step->label]);
            }
            $campaign->forceFill(['draft_version_id' => $draft->id])->save();

            return $draft;
        });
    }

    /** @param  array<int, array{send_day: int, template_id?: ?int, label?: ?string}>  $steps */
    public function saveSteps(CrmDripCampaign $campaign, array $steps, User $actor, ?string $name = null): CrmDripCampaignVersion
    {
        $draft = $this->draft($campaign, $actor);
        $maxDay = CrmDripCampaign::LENGTHS[$campaign->length_key] ?? 365;

        foreach ($steps as $step) {
            $day = (int) $step['send_day'];
            if ($day < 0 || $day > $maxDay) {
                throw ValidationException::withMessages(['steps' => 'Send days must fall within the drip length ('.$maxDay.' days).']);
            }
            if (! empty($step['template_id'])) {
                $template = CrmEmailTemplate::query()->find($step['template_id']);
                if (! $template || ! $template->approved_version_id || $template->status === 'retired') {
                    throw ValidationException::withMessages(['steps' => 'Each step must use an approved template.']);
                }
            }
        }

        DB::transaction(function () use ($draft, $steps, $campaign, $name) {
            CrmDripStep::query()->where('campaign_version_id', $draft->id)->delete();
            foreach ($steps as $step) {
                CrmDripStep::create([
                    'campaign_version_id' => $draft->id,
                    'send_day' => (int) $step['send_day'],
                    'template_id' => $step['template_id'] ?? null,
                    'label' => $step['label'] ?? null,
                ]);
            }
            if ($name) {
                $campaign->forceFill(['name' => $name])->save();
            }
        });

        return $draft->fresh();
    }

    public function publish(CrmDripCampaign $campaign, User $actor): CrmDripCampaign
    {
        $this->assertManager($actor);
        $campaign->refresh();
        $draft = $campaign->draft_version_id ? CrmDripCampaignVersion::query()->find($campaign->draft_version_id) : null;
        if (! $draft) {
            throw ValidationException::withMessages(['steps' => 'There are no changes to publish.']);
        }
        if ($draft->steps()->whereNotNull('template_id')->doesntExist()) {
            throw ValidationException::withMessages(['steps' => 'Add at least one approved template before publishing.']);
        }
        $draft->forceFill(['published_at' => now(), 'published_by' => $actor->id])->save();
        $campaign->forceFill([
            'published_version_id' => $draft->id,
            'draft_version_id' => null,
            'status' => $campaign->status === 'paused' ? 'paused' : 'live',
        ])->save();

        // Future sends for enrolled contacts follow the new steps from their current place.
        CrmDripEnrollment::query()->where('campaign_id', $campaign->id)->where('status', 'active')->get()
            ->each(fn (CrmDripEnrollment $e) => $this->scheduleNext($e));

        return $campaign;
    }

    public function setStatus(CrmDripCampaign $campaign, string $status, User $actor): CrmDripCampaign
    {
        $this->assertManager($actor);
        if (! in_array($status, ['live', 'paused', 'retired'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose live, paused or retired.']);
        }
        if ($status === 'live' && ! $campaign->published_version_id) {
            throw ValidationException::withMessages(['status' => 'Publish the steps before turning this drip on.']);
        }
        $campaign->forceFill(['status' => $status])->save();
        if ($status === 'retired') {
            CrmDripEnrollment::query()->where('campaign_id', $campaign->id)->where('status', 'active')->get()
                ->each(fn (CrmDripEnrollment $e) => $this->stopEnrollment($e, 'campaign_retired', null));
        }

        return $campaign;
    }

    public function enrol(CrmContact $contact, CrmDripCampaign $campaign, ?User $actor, bool $viaRobbie = false): CrmDripEnrollment
    {
        if ($actor && ! $this->access->can($actor, 'crm-drips', 'enrol')) {
            throw new AuthorizationException('You cannot enrol contacts in drips.');
        }
        if ($campaign->status !== 'live') {
            throw ValidationException::withMessages(['campaign_id' => 'This drip is not live.']);
        }
        if (! app(CrmMarketingConsent::class)->allows($contact) || ! $contact->email || $contact->email_opt_out_at || in_array($contact->email_status, ['bounced', 'complained'], true)) {
            throw ValidationException::withMessages(['campaign_id' => 'This contact cannot receive email.']);
        }
        if ($contact->isLost()) {
            throw ValidationException::withMessages(['campaign_id' => 'Reopen the contact before enrolling it.']);
        }

        $enrollment = LockedWrite::run(fn () => DB::transaction(function () use ($contact, $campaign, $actor, $viaRobbie) {
            $current = CrmDripEnrollment::query()->where('active_contact_id', $contact->id)->first();
            if ($current) {
                $this->stopEnrollment($current, 'replaced', $actor, false);
            }

            return CrmDripEnrollment::create([
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'active_contact_id' => $contact->id,
                'status' => 'active',
                'started_at' => now(),
                'last_sent_day' => -1,
                'enrolled_by' => $actor?->id,
                'via_robbie' => $viaRobbie,
            ]);
        }), 'crm.drip.enrol');

        $this->scheduleNext($enrollment);
        $this->activity->log($contact, 'drip_enrolled', $actor, 'Enrolled in '.$campaign->name, ['campaign_id' => $campaign->id, 'enrollment_id' => $enrollment->id], $viaRobbie);

        return $enrollment;
    }

    public function stopForContact(CrmContact $contact, string $reason, ?User $actor = null): ?CrmDripEnrollment
    {
        $enrollment = CrmDripEnrollment::query()->where('active_contact_id', $contact->id)->first();
        if (! $enrollment) {
            return null;
        }

        return $this->stopEnrollment($enrollment, $reason, $actor);
    }

    public function stopEnrollment(CrmDripEnrollment $enrollment, string $reason, ?User $actor, bool $notify = true): CrmDripEnrollment
    {
        if ($enrollment->status !== 'active') {
            return $enrollment;
        }
        $enrollment->forceFill(['status' => $reason === 'completed' ? 'completed' : 'stopped', 'stop_reason' => $reason, 'stopped_at' => now(), 'active_contact_id' => null, 'next_send_at' => null])->save();

        $contact = CrmContact::unscopedForSystem()->find($enrollment->contact_id);
        $campaign = CrmDripCampaign::query()->find($enrollment->campaign_id);
        $labels = [
            'reply' => 'they replied', 'unsubscribe' => 'they unsubscribed', 'bounce' => 'their email bounced', 'complaint' => 'they marked an email as spam',
            'booked' => 'a shoot was booked', 'lost' => 'the contact was marked lost', 'manual' => 'stopped by hand', 'replaced' => 'moved to another drip',
            'delivery_review' => 'delivery needs review', 'consent_withdrawn' => 'marketing consent is missing',
            'completed' => 'all steps were sent', 'campaign_retired' => 'the drip was retired', 'no_sender' => 'the contact has no rep who can send email',
        ];
        if ($contact) {
            $this->activity->log($contact, 'drip_stopped', $actor, ($campaign?->name ?? 'Drip').' stopped: '.($labels[$reason] ?? $reason), ['reason' => $reason, 'enrollment_id' => $enrollment->id]);
            // The rep is told, unless they caused the stop themselves.
            if ($notify && $contact->assigned_rep_id && $reason !== 'manual' && $reason !== 'replaced' && (int) $contact->assigned_rep_id !== (int) $actor?->id) {
                $this->notices->notify($contact->assigned_rep_id, 'drip_stopped', ($campaign?->name ?? 'Drip').' stopped for '.$contact->name, 'Because '.($labels[$reason] ?? $reason).'.', $contact->id);
            }
        }

        return $enrollment;
    }

    /** Work out the next due step from the published version and the last send day reached. */
    public function scheduleNext(CrmDripEnrollment $enrollment): void
    {
        $campaign = CrmDripCampaign::query()->find($enrollment->campaign_id);
        $next = $campaign?->publishedVersion?->steps()->where('send_day', '>', (int) ($enrollment->last_sent_day ?? -1))->first();
        if (! $next) {
            $this->stopEnrollment($enrollment, 'completed', null, false);

            return;
        }
        $at = $enrollment->started_at->copy()->addDays($next->send_day)->setTimeFrom($enrollment->started_at);
        if ($next->send_day > 0) {
            $at->setTime(8, 0); // later steps go out at 8 am
        }
        $enrollment->forceFill(['next_send_at' => $at->lt(now()) ? now() : $at, 'claimed_at' => null])->save();
    }

    /** Send every due step. Claims each enrolment in a short write, sends outside it. */
    public function runDue(int $limit = 200): array
    {
        $counts = ['sent' => 0, 'skipped' => 0, 'stopped' => 0, 'failed' => 0];
        // Outbound hold: claim nothing, so held steps stay due for after the release.
        if (! config('crm.enabled') || config('crm.outbound_hold') || $this->sender->transport()->name() === 'log') {
            return $counts;
        }

        $due = CrmDripEnrollment::query()->where('status', 'active')->where('next_send_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(15)))
            ->orderBy('next_send_at')->limit($limit)->pluck('id');

        foreach ($due as $id) {
            $claimed = LockedWrite::run(fn () => CrmDripEnrollment::query()->whereKey($id)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(15)))
                ->update(['claimed_at' => now()]), 'crm.drip.claim');
            if (! $claimed) {
                continue;
            }
            $enrollment = CrmDripEnrollment::query()->find($id);
            $outcome = $this->sendStep($enrollment);
            $counts[$outcome]++;
        }

        return $counts;
    }

    private function sendStep(CrmDripEnrollment $enrollment): string
    {
        $contact = CrmContact::unscopedForSystem()->find($enrollment->contact_id);
        $campaign = CrmDripCampaign::query()->find($enrollment->campaign_id);
        if (! $contact || ! $campaign || $campaign->status === 'retired') {
            $this->stopEnrollment($enrollment, 'campaign_retired', null, false);

            return 'stopped';
        }
        if ($campaign->status === 'paused') {
            $enrollment->forceFill(['claimed_at' => null, 'next_send_at' => now()->addDay()->setTime(8, 0)])->save();

            return 'skipped';
        }

        $step = $campaign->publishedVersion?->steps()->where('send_day', '>', (int) ($enrollment->last_sent_day ?? -1))->first();
        if (! $step) {
            $this->stopEnrollment($enrollment, 'completed', null, false);

            return 'stopped';
        }

        $advance = function () use ($enrollment, $step) {
            $enrollment->forceFill(['last_sent_day' => $step->send_day])->save();
            $this->scheduleNext($enrollment);
        };

        $template = $step->template;
        if (! $template || ! $template->approvedVersion || $template->status === 'retired') {
            $advance(); // placeholder step: nothing to send

            return 'skipped';
        }

        $sender = $contact->assigned_rep_id ? User::query()->find($contact->assigned_rep_id) : null;
        if (! $sender) {
            $this->stopEnrollment($enrollment, 'no_sender', null);

            return 'stopped';
        }

        try {
            $this->sender->send([
                'contact' => $contact,
                'sender' => $sender,
                'source_type' => 'drip',
                'source_id' => $enrollment->id,
                'template_version' => $template->approvedVersion,
                'idempotency_key' => 'crm-drip-'.$enrollment->id.'-'.$step->send_day,
                'marketing' => true,
                'counts_as_contact' => false,
                'vars' => [],
            ])->requireProviderAcceptance();
            $enrollment->forceFill(['steps_sent' => $enrollment->steps_sent + 1])->save();
            $advance();

            return 'sent';
        } catch (CrmSendBlocked $e) {
            if (in_array($e->reason, ['uncertain', 'blocked', 'failed', 'queued', 'no_consent'], true)) {
                $this->stopEnrollment($enrollment, $e->reason === 'no_consent' ? 'consent_withdrawn' : 'delivery_review', null);

                return 'stopped';
            }
            if (in_array($e->reason, ['opted_out', 'bounced', 'no_email'], true)) {
                $this->stopEnrollment($enrollment, $e->reason === 'opted_out' ? 'unsubscribe' : 'bounce', null);

                return 'stopped';
            }
            if ($e->reason === 'signature') {
                // The rep cannot send yet: try again tomorrow and tell them once.
                if ($enrollment->next_send_at && $enrollment->next_send_at->gt(now()->subDay())) {
                    $this->notices->notify($sender->id, 'drip_waiting', 'A drip email to '.$contact->name.' is waiting for your approved signature.', null, $contact->id);
                }
                $enrollment->forceFill(['claimed_at' => null, 'next_send_at' => now()->addDay()->setTime(8, 0)])->save();

                return 'skipped';
            }
            $enrollment->forceFill(['claimed_at' => null, 'next_send_at' => now()->addHours(6)])->save();

            return 'failed';
        }
    }

    private function assertManager(User $user): void
    {
        if (! $this->access->can($user, 'crm-drips', 'manage')) {
            throw new AuthorizationException('Only admins build drip campaigns.');
        }
    }
}
