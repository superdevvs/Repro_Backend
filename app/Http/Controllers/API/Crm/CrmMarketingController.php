<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmCampaign;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmDripEnrollment;
use App\Models\User;
use App\Services\Crm\Email\CrmCampaignService;
use App\Services\Crm\Email\CrmDripPresenter;
use App\Services\Crm\Email\CrmDripService;
use App\Services\Crm\Email\CrmEmailRenderer;
use App\Services\Crm\Email\CrmSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Drip campaigns (build, enrol, stop) and custom campaigns (write, review, schedule). */
class CrmMarketingController extends CrmController
{
    public function __construct(
        private readonly CrmDripService $drips,
        private readonly CrmDripPresenter $dripPresent,
        private readonly CrmCampaignService $campaigns,
    ) {}

    // Drips

    public function drips(Request $request): JsonResponse
    {
        $manage = $this->access()->can($request->user(), 'crm-drips', 'manage');
        $items = CrmDripCampaign::query()->when(! $manage, fn ($q) => $q->where('status', 'live'))->orderBy('id')->get();

        return response()->json(['data' => $items->map(fn ($c) => $this->dripPresent->campaign($c))->all(), 'can_manage' => $manage]);
    }

    public function drip(Request $request, CrmDripCampaign $drip): JsonResponse
    {
        return response()->json(['drip' => $this->dripPresent->campaign($drip, true)]);
    }

    public function saveDrip(Request $request, CrmDripCampaign $drip): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'steps' => ['required', 'array', 'min:1', 'max:40'],
            'steps.*.send_day' => ['required', 'integer', 'min:0', 'max:365'],
            'steps.*.template_id' => ['nullable', 'integer'],
            'steps.*.label' => ['nullable', 'string', 'max:160'],
        ]);
        $this->drips->saveSteps($drip, $data['steps'], $this->actor($request), $data['name'] ?? null);

        return response()->json(['drip' => $this->dripPresent->campaign($drip->fresh(), true)]);
    }

    public function publishDrip(Request $request, CrmDripCampaign $drip): JsonResponse
    {
        $this->drips->publish($drip, $this->actor($request));

        return response()->json(['drip' => $this->dripPresent->campaign($drip->fresh(), true)]);
    }

    public function dripStatus(Request $request, CrmDripCampaign $drip): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:live,paused,retired']]);
        $this->drips->setStatus($drip, $data['status'], $this->actor($request));

        return response()->json(['drip' => $this->dripPresent->campaign($drip->fresh(), true)]);
    }

    /** Every step of the published (or draft) sequence rendered as the contact would see it. */
    public function previewDrip(Request $request, CrmDripCampaign $drip): JsonResponse
    {
        $version = $request->boolean('draft') && $drip->draftVersion ? $drip->draftVersion : $drip->publishedVersion;
        $renderer = app(CrmEmailRenderer::class);
        $user = $this->actor($request);
        $signature = app(CrmSignatureService::class)->currentApproved($user->id);
        $vars = $renderer->varsFor(null, $user, ['first_name' => 'Dana', 'company' => 'Whitfield & Rowe Realty', 'market' => 'Bethesda']);

        return response()->json(['steps' => ($version?->steps()->with('template.approvedVersion')->get() ?? collect())->map(function ($step) use ($renderer, $vars, $signature) {
            $v = $step->template?->approvedVersion;

            return [
                'send_day' => $step->send_day,
                'label' => $step->template?->name ?? $step->label,
                'placeholder' => $v === null,
                'rendered' => $v ? $renderer->render(['subject' => $v->subject, 'body_html' => $v->body_html, 'preheader' => $v->preheader, 'image_url' => $v->image_url, 'cta_label' => $v->cta_label, 'cta_url' => $v->cta_url], $vars, $renderer->signatureHtml($signature), '#unsubscribe') : null,
            ];
        })->all()]);
    }

    public function enrol(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-drips', 'enrol')) {
            return $denied;
        }
        $data = $request->validate(['campaign_id' => ['required', 'integer']]);
        $drip = CrmDripCampaign::query()->findOrFail($data['campaign_id']);
        $this->drips->enrol($contact, $drip, $this->actor($request));

        return response()->json(['drip' => $this->dripPresent->activeFor($contact)], 201);
    }

    public function bulkEnrol(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-drips', 'enrol')) {
            return $denied;
        }
        $data = $request->validate(['campaign_id' => ['required', 'integer'], 'ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        $drip = CrmDripCampaign::query()->findOrFail($data['campaign_id']);
        $enrolled = 0;
        $skipped = 0;
        foreach (CrmContact::query()->whereIn('id', $data['ids'])->get() as $contact) {
            try {
                $this->drips->enrol($contact, $drip, $this->actor($request));
                $enrolled++;
            } catch (\Illuminate\Validation\ValidationException) {
                $skipped++;
            }
        }

        return response()->json(['enrolled' => $enrolled, 'skipped' => $skipped]);
    }

    public function stopDrip(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $this->drips->stopForContact($contact, 'manual', $this->actor($request));

        return response()->json(['drip' => $this->dripPresent->activeFor($contact)]);
    }

    /** A reply the rep saw in their own inbox. Stops the drip like a tracked reply. */
    public function markReplied(Request $request, CrmContact $contact): JsonResponse
    {
        event(new \App\Events\Crm\CrmContactReplied($contact, null));
        app(\App\Services\Crm\CrmActivityLogger::class)->log($contact, 'email_reply', $this->actor($request), 'Marked as replied');

        return response()->json(['drip' => $this->dripPresent->activeFor($contact)]);
    }

    // Campaigns

    public function campaigns(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $approver = $this->access()->can($user, 'crm-campaigns', 'approve');
        $items = CrmCampaign::query()->when(! $approver, fn ($q) => $q->where('owner_id', $user->id))->orderByDesc('updated_at')->limit(200)->get();

        return response()->json(['data' => $items->map(fn ($c) => $this->campaignPayload($c))->all(), 'can_approve' => $approver]);
    }

    public function campaign(Request $request, CrmCampaign $campaign): JsonResponse
    {
        $this->assertCanSee($request, $campaign);

        return response()->json(['campaign' => $this->campaignPayload($campaign, true)]);
    }

    public static function campaignRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'max:160'],
            'template_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'subject' => ['sometimes', 'string', 'max:200'],
            'body_html' => ['sometimes', 'string', 'max:50000'],
            'image_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/storage/)#'],
            'audience' => ['sometimes', 'array'],
            'audience.segment' => ['nullable', 'string'],
            'audience.client_type' => ['nullable', 'string'],
            'audience.stage' => ['nullable', 'string'],
            'audience.market_state' => ['nullable', 'string', 'max:40'],
            'audience.rep_id' => ['nullable', 'integer'],
            'audience.contact_ids' => ['nullable', 'array', 'max:2000'],
            'audience.contact_ids.*' => ['integer'],
        ];
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        $data = $request->validate(self::campaignRules(true));
        $campaign = $this->campaigns->create($data, $this->actor($request));

        return response()->json(['campaign' => $this->campaignPayload($campaign, true)], 201);
    }

    public function updateCampaign(Request $request, CrmCampaign $campaign): JsonResponse
    {
        $this->assertCanSee($request, $campaign);
        $data = $request->validate(self::campaignRules(false));
        $this->campaigns->update($campaign, $data, $this->actor($request));

        return response()->json(['campaign' => $this->campaignPayload($campaign->fresh(), true)]);
    }

    public function campaignAction(Request $request, CrmCampaign $campaign, string $action): JsonResponse
    {
        $this->assertCanSee($request, $campaign);
        $actor = $this->actor($request);
        match ($action) {
            'submit' => $this->campaigns->submit($campaign, $actor),
            'approve' => $this->campaigns->approve($campaign, $actor),
            'send-back' => $this->campaigns->sendBack($campaign, $actor, (string) $request->validate(['comment' => ['required', 'string', 'max:1000']])['comment']),
            'schedule' => $this->campaigns->schedule($campaign, $request->validate(['at' => ['nullable', 'date']])['at'] ?? null, $actor),
            default => abort(404),
        };

        return response()->json(['campaign' => $this->campaignPayload($campaign->fresh(), true)]);
    }

    private function assertCanSee(Request $request, CrmCampaign $campaign): void
    {
        $user = $this->actor($request);
        abort_unless((int) $campaign->owner_id === (int) $user->id || $this->access()->can($user, 'crm-campaigns', 'approve'), 404);
    }

    private function campaignPayload(CrmCampaign $c, bool $full = false): array
    {
        $out = [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'owner' => $this->presenter()->user($c->owner_id),
            'subject' => $c->subject,
            'audience' => $c->audience ?? [],
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'sent_at' => $c->sent_at?->toIso8601String(),
            'recipient_count' => $c->recipient_count,
            'reviewed_by' => $this->presenter()->user($c->reviewed_by),
            'review_comment' => $c->review_comment,
            'content_check' => $c->content_check,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
        if ($full) {
            $owner = User::query()->find($c->owner_id);
            $renderer = app(CrmEmailRenderer::class);
            $signature = $owner ? app(CrmSignatureService::class)->currentApproved($owner->id) : null;
            $version = $c->template?->versions()->whereKey($c->template_version_id)->first();
            $out['template'] = ['id' => $c->template_id, 'name' => $c->template?->name];
            $out['body_html'] = $c->body_html;
            $out['image_url'] = $c->image_url;
            $out['from'] = $signature ? ['name' => $signature->fields['name'] ?? $owner?->name, 'email' => $signature->sender_email] : null;
            $out['audience_summary'] = $this->campaigns->audienceSummary($c);
            $out['rendered'] = $renderer->render([
                'subject' => $c->subject, 'body_html' => $c->body_html, 'image_url' => $c->image_url,
                'preheader' => $version?->preheader, 'cta_label' => $version?->cta_label, 'cta_url' => $version?->cta_url,
            ], $renderer->varsFor(null, $owner), $renderer->signatureHtml($signature), '#unsubscribe');
        }

        return $out;
    }
}
