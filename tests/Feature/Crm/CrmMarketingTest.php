<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmCampaign;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmDripEnrollment;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\Email\CrmDripService;
use App\Services\Crm\Email\CrmSignatureService;
use App\Services\Crm\Email\CrmSuppression;
use App\Services\Crm\Email\CrmTemplateService;
use Illuminate\Support\Carbon;

class CrmMarketingTest extends CrmTestCase
{
    private function signed(User $user): User
    {
        $sig = app(CrmSignatureService::class)->submit($user, ['name' => $user->name, 'title' => 'Sales', 'sender_email' => 'rep'.$user->id.'@reprophotos.com']);
        app(CrmSignatureService::class)->approve($sig, User::query()->where('role', 'superadmin')->first() ?? $this->superAdmin());

        return $user;
    }

    private function template(string $name, string $kind = 'drip', string $body = '<p>Hello {{first_name}}</p>'): CrmEmailTemplate
    {
        $admin = User::query()->where('role', 'admin')->first() ?? $this->admin();
        $t = app(CrmTemplateService::class)->create($name, $kind, ['subject' => $name, 'body_html' => $body], $admin);
        app(CrmTemplateService::class)->approve(app(CrmTemplateService::class)->submit($t, $admin), $admin);

        return $t->fresh();
    }

    private function liveDrip(): CrmDripCampaign
    {
        $admin = User::query()->where('role', 'admin')->first() ?? $this->admin();
        $drip = CrmDripCampaign::query()->where('length_key', '12_weeks')->firstOrFail();
        app(CrmDripService::class)->saveSteps($drip, [
            ['send_day' => 0, 'template_id' => $this->template('Welcome')->id],
            ['send_day' => 7, 'template_id' => $this->template('Twilight')->id],
            ['send_day' => 14, 'label' => 'Placeholder step 3'],
            ['send_day' => 28, 'template_id' => $this->template('Gallery')->id],
        ], $admin);
        app(CrmDripService::class)->publish($drip, $admin);

        return $drip->fresh();
    }

    public function test_three_drips_ship_with_placeholder_steps_and_only_admins_build(): void
    {
        $this->assertSame(3, CrmDripCampaign::query()->count());
        $drip = CrmDripCampaign::query()->firstOrFail();
        $this->actAs($this->rep());
        $this->assertSame([], $this->getJson('/api/crm/drips')->assertOk()->json('data'));
        $this->putJson("/api/crm/drips/{$drip->id}", ['steps' => [['send_day' => 0]]])->assertForbidden();

        $this->actAs($this->admin());
        $this->assertCount(3, $this->getJson('/api/crm/drips')->json('data'));
        $this->getJson("/api/crm/drips/{$drip->id}")->assertOk()->assertJsonPath('drip.draft_steps.0.status', 'placeholder');
        $this->postJson("/api/crm/drips/{$drip->id}/publish")->assertUnprocessable();
    }

    public function test_drip_sends_due_steps_skips_placeholders_and_keeps_one_active_drip(): void
    {
        $rep = $this->signed($this->rep());
        $drip = $this->liveDrip();
        $contact = $this->contactFor($rep, ['email' => 'dana@x.com']);
        $this->actAs($rep);

        $this->postJson("/api/crm/contacts/{$contact->id}/drip", ['campaign_id' => $drip->id])->assertCreated();
        $this->artisan('crm:send-due')->assertSuccessful();
        $this->assertSame(1, CrmEmailSend::query()->where('source_type', 'drip')->count());

        Carbon::setTestNow(now()->addDays(7)->setTime(9, 0));
        $this->artisan('crm:send-due')->assertSuccessful();
        $this->assertSame(2, CrmEmailSend::query()->where('source_type', 'drip')->count());

        Carbon::setTestNow(now()->addDays(7));
        $this->artisan('crm:send-due')->assertSuccessful(); // day 14 is a placeholder
        $this->assertSame(2, CrmEmailSend::query()->where('source_type', 'drip')->count());
        $this->assertSame(14, CrmDripEnrollment::query()->firstOrFail()->last_sent_day);

        // Enrolling in another drip ends the first.
        $other = CrmDripCampaign::query()->where('length_key', '6_months')->firstOrFail();
        app(CrmDripService::class)->saveSteps($other, [['send_day' => 0, 'template_id' => $this->template('Nurture 1')->id]], $this->admin());
        app(CrmDripService::class)->publish($other, User::query()->where('role', 'admin')->first());
        $this->actAs($rep);
        $this->postJson("/api/crm/contacts/{$contact->id}/drip", ['campaign_id' => $other->id])->assertCreated();
        $this->assertSame(1, CrmDripEnrollment::query()->where('status', 'active')->count());
        $this->assertSame('replaced', CrmDripEnrollment::query()->where('campaign_id', $drip->id)->value('stop_reason'));
        Carbon::setTestNow();
    }

    public function test_editing_a_live_drip_changes_only_future_steps(): void
    {
        $rep = $this->signed($this->rep());
        $drip = $this->liveDrip();
        $contact = $this->contactFor($rep, ['email' => 'dana@x.com']);
        app(CrmDripService::class)->enrol($contact, $drip, $rep);
        $this->artisan('crm:send-due');

        $admin = User::query()->where('role', 'admin')->first();
        app(CrmDripService::class)->saveSteps($drip, [
            ['send_day' => 0, 'template_id' => $this->template('Welcome v2')->id],
            ['send_day' => 10, 'template_id' => $this->template('New day 10')->id],
        ], $admin);
        app(CrmDripService::class)->publish($drip, $admin);

        $enrollment = CrmDripEnrollment::query()->firstOrFail();
        $this->assertSame(0, $enrollment->last_sent_day);
        $this->assertSame(10, (int) round($enrollment->started_at->copy()->startOfDay()->diffInDays($enrollment->next_send_at->copy()->startOfDay())));
    }

    public function test_drip_stops_on_booked_lost_unsubscribe_and_reply_and_rep_is_told(): void
    {
        $rep = $this->signed($this->rep());
        $drip = $this->liveDrip();
        $a = $this->contactFor($rep, ['email' => 'a@x.com']);
        $b = $this->contactFor($rep, ['email' => 'b@x.com']);
        $c = $this->contactFor($rep, ['email' => 'c@x.com']);
        $d = $this->contactFor($rep, ['email' => 'd@x.com']);
        foreach ([$a, $b, $c, $d] as $contact) {
            app(CrmDripService::class)->enrol($contact, $drip, $rep);
        }

        app(\App\Services\Crm\CrmStageService::class)->move($a, CrmContact::STAGE_BOOKED, null, 'system');
        app(\App\Services\Crm\CrmStageService::class)->markLost($b, 'price', null, $rep);
        app(CrmSuppression::class)->suppress('c@x.com', 'unsubscribe', 'link');
        $this->actAs($rep);
        $this->postJson("/api/crm/contacts/{$d->id}/replied")->assertOk();

        $reasons = CrmDripEnrollment::query()->orderBy('id')->pluck('stop_reason')->all();
        $this->assertSame(['booked', 'lost', 'unsubscribe', 'reply'], $reasons);
        $this->assertSame(3, \App\Models\Crm\CrmNotice::query()->where('user_id', $rep->id)->where('type', 'drip_stopped')->count());
    }

    public function test_campaign_approval_flow_and_edit_after_approval_returns_to_review(): void
    {
        $rep = $this->signed($this->rep());
        $template = $this->template('Winter refresh', 'campaign');
        $mine = $this->contactFor($rep, ['email' => 'mine@x.com', 'segment' => 'residential']);
        $this->contactFor($this->rep(), ['email' => 'theirs@x.com', 'segment' => 'residential']);
        $this->actAs($rep);

        $id = $this->postJson('/api/crm/campaigns', ['name' => 'Winter', 'template_id' => $template->id, 'audience' => ['segment' => 'residential']])->assertCreated()->json('campaign.id');
        $this->getJson("/api/crm/campaigns/{$id}")->assertJsonPath('campaign.audience_summary.total', 1);
        $this->postJson("/api/crm/campaigns/{$id}/approve")->assertForbidden();
        $this->patchJson("/api/crm/campaigns/{$id}", ['body_html' => '<p>The fastest turnaround in DC, guaranteed.</p>'])->assertOk();
        $this->postJson("/api/crm/campaigns/{$id}/submit")->assertOk()->assertJsonPath('campaign.status', 'submitted')->assertJsonPath('campaign.content_check.passed', false);

        $admin = $this->actAs($this->admin());
        $this->postJson("/api/crm/campaigns/{$id}/send-back", ['comment' => 'Remove the claims'])->assertOk()->assertJsonPath('campaign.status', 'sent_back');

        $this->actAs($rep);
        $this->patchJson("/api/crm/campaigns/{$id}", ['body_html' => '<p>Next-day delivery on twilight shoots.</p>'])->assertOk();
        $this->postJson("/api/crm/campaigns/{$id}/submit")->assertOk();
        $this->actAs($admin);
        $this->postJson("/api/crm/campaigns/{$id}/approve")->assertOk()->assertJsonPath('campaign.status', 'approved');

        $this->actAs($rep);
        $this->patchJson("/api/crm/campaigns/{$id}", ['subject' => 'Changed'])->assertOk()->assertJsonPath('campaign.status', 'submitted');
        $this->actAs($admin);
        $this->postJson("/api/crm/campaigns/{$id}/approve")->assertOk();
        $this->actAs($rep);
        $this->postJson("/api/crm/campaigns/{$id}/schedule")->assertOk()->assertJsonPath('campaign.status', 'scheduled');

        $this->artisan('crm:send-due')->assertSuccessful();
        $campaign = CrmCampaign::query()->findOrFail($id);
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->recipient_count);
        $this->assertSame(['mine@x.com'], CrmEmailSend::query()->where('source_type', 'campaign')->pluck('to_email')->all());
        $this->assertSame($mine->id, CrmEmailSend::query()->where('source_type', 'campaign')->value('contact_id'));
    }

    public function test_reps_see_only_their_own_campaigns(): void
    {
        $rep = $this->rep();
        $other = $this->rep();
        $template = $this->template('T', 'campaign');
        $campaign = CrmCampaign::create(['name' => 'Theirs', 'owner_id' => $other->id, 'template_id' => $template->id, 'template_version_id' => $template->approved_version_id, 'subject' => 's', 'body_html' => '<p>x</p>', 'audience' => [], 'status' => 'draft']);
        $this->actAs($rep);

        $this->assertSame([], $this->getJson('/api/crm/campaigns')->json('data'));
        $this->getJson("/api/crm/campaigns/{$campaign->id}")->assertNotFound();
    }
}
