<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmCompany;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmNotice;
use App\Models\Crm\CrmQuote;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Crm\CrmClientHealth;
use App\Services\Crm\Email\CrmSignatureService;
use Illuminate\Support\Carbon;

class CrmFeaturesTest extends CrmTestCase
{
    private function signed(User $user): User
    {
        $sig = app(CrmSignatureService::class)->submit($user, ['name' => $user->name, 'title' => 'Sales', 'sender_email' => 'u'.$user->id.'@reprophotos.com']);
        app(CrmSignatureService::class)->approve($sig, User::query()->where('role', 'superadmin')->first() ?? $this->superAdmin());

        return $user;
    }

    public function test_companies_link_automatically_and_show_only_the_viewers_contacts(): void
    {
        $a = $this->rep();
        $b = $this->rep();
        $c = $this->rep();
        $mine = $this->contactFor($a, ['company' => 'Whitfield & Rowe Realty']);
        $theirs = $this->contactFor($b, ['company' => 'Whitfield & Rowe Realty, LLC']);
        $this->assertNotNull($mine->company_id);
        $this->assertSame($mine->company_id, $theirs->company_id);
        $this->assertSame(1, CrmCompany::query()->count());

        $this->actAs($a);
        $this->getJson('/api/crm/companies')->assertOk()->assertJsonPath('data.0.contacts', 1);
        $this->getJson("/api/crm/companies/{$mine->company_id}")->assertOk()->assertJsonCount(1, 'contacts')->assertJsonPath('contacts.0.id', $mine->id);
        $this->patchJson("/api/crm/companies/{$mine->company_id}", ['name' => 'W&R'])->assertForbidden();

        $this->actAs($c);
        $this->assertSame([], $this->getJson('/api/crm/companies')->json('data'));
        $this->getJson("/api/crm/companies/{$mine->company_id}")->assertNotFound();

        $this->actAs($this->admin());
        $this->getJson("/api/crm/companies/{$mine->company_id}")->assertJsonCount(2, 'contacts');
        $this->patchJson("/api/crm/companies/{$mine->company_id}", ['name' => 'Whitfield Rowe Group'])->assertOk();
        $this->assertSame('Whitfield Rowe Group', $theirs->fresh()->company);
    }

    public function test_client_health_uses_the_usual_gap(): void
    {
        $rep = $this->rep();
        $client = User::factory()->create(['role' => 'client', 'email' => 'regular@x.com']);
        $contact = $this->contactFor($rep, ['email' => 'regular@x.com', 'stage' => CrmContact::STAGE_CLIENT_POOL]);
        $contact->forceFill(['client_user_id' => $client->id])->save();
        foreach ([130, 100, 70] as $daysAgo) {
            Shoot::factory()->create(['client_id' => $client->id, 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED, 'completed_at' => now()->subDays($daysAgo)]);
        }

        $this->artisan('crm:nightly')->assertSuccessful();
        $contact->refresh();
        $this->assertSame(30, $contact->usual_gap_days);
        $this->assertSame(CrmClientHealth::COOLING, $contact->health); // 70 days: past 45, within 90

        Carbon::setTestNow(now()->addDays(25));
        $this->artisan('crm:nightly')->assertSuccessful();
        $this->assertSame(CrmClientHealth::AT_RISK, $contact->fresh()->health);
        $this->assertSame(1, CrmNotice::query()->where('user_id', $rep->id)->where('type', 'client_at_risk')->count());
        Carbon::setTestNow();

        $this->actAs($rep);
        $this->getJson('/api/crm/home')->assertOk()->assertJsonPath('at_risk.0.id', $contact->id);
    }

    public function test_goals_are_set_by_admins_and_reports_follow_the_owner_scope(): void
    {
        $rep = $this->rep();
        $other = $this->rep();
        $mine = $this->contactFor($rep, ['stage' => CrmContact::STAGE_INQUIRY]);
        $this->contactFor($other, ['stage' => CrmContact::STAGE_INQUIRY]);
        app(\App\Services\Crm\CrmStageService::class)->move($mine, CrmContact::STAGE_BOOKED, $rep);

        $this->actAs($rep);
        $this->putJson('/api/crm/goals', ['user_id' => $rep->id, 'month' => now()->format('Y-m'), 'targets' => ['booked' => 5]])->assertForbidden();
        $report = $this->getJson('/api/crm/reports')->assertOk()->json();
        $this->assertSame(1, collect($report['funnel'])->firstWhere('stage', 'booked')['entered']);
        $this->assertSame(1, collect($report['funnel'])->firstWhere('stage', 'outreach')['entered'] + collect($report['funnel'])->firstWhere('stage', 'inquiry')['entered']);
        $this->assertSame([], $report['by_rep']);

        $this->actAs($this->admin());
        $this->putJson('/api/crm/goals', ['user_id' => $rep->id, 'month' => now()->format('Y-m'), 'targets' => ['booked' => 5, 'new_contacts' => 20]])->assertOk();
        $this->assertCount(2, $this->getJson('/api/crm/reports')->json('by_rep'));

        $this->actAs($rep);
        $goals = collect($this->getJson('/api/crm/goals')->assertOk()->json('people.0.goals'))->keyBy('metric');
        $this->assertSame(5, $goals['booked']['target']);
        $this->assertSame(1, $goals['booked']['actual']);
        $this->assertSame(1, $goals['new_contacts']['actual']);
    }

    public function test_quote_discount_approval_send_and_client_acceptance(): void
    {
        $rep = $this->signed($this->rep());
        $admin = $this->admin();
        $service = Service::factory()->create(['name' => 'HDR photos', 'price' => 200]);
        $contact = $this->contactFor($rep, ['email' => 'dana@x.com', 'stage' => CrmContact::STAGE_INQUIRY]);
        $this->actAs($rep);

        $this->getJson('/api/crm/quotes/catalog')->assertOk()->assertJsonFragment(['name' => 'HDR photos']);
        $id = $this->postJson("/api/crm/contacts/{$contact->id}/quotes", ['items' => [['service_id' => $service->id, 'quantity' => 2, 'unit_price' => 1], ['name' => 'Twilight add-on', 'unit_price' => 100]], 'discount_percent' => 15])
            ->assertCreated()->assertJsonPath('quote.subtotal', 500)->assertJsonPath('quote.total', 425)->assertJsonPath('quote.needs_approval', true)->json('quote.id');

        $this->postJson("/api/crm/quotes/{$id}/send")->assertUnprocessable();
        $this->assertSame('pending_approval', CrmQuote::query()->find($id)->status);
        $this->assertSame(1, CrmNotice::query()->where('user_id', $admin->id)->where('type', 'quote_approval')->count());
        $this->postJson("/api/crm/quotes/{$id}/approve")->assertForbidden();

        $this->actAs($this->rep());
        $this->getJson("/api/crm/quotes/{$id}")->assertNotFound();

        $this->actAs($admin);
        $this->postJson("/api/crm/quotes/{$id}/approve")->assertOk()->assertJsonPath('quote.status', 'approved');

        $this->actAs($rep);
        $this->postJson("/api/crm/quotes/{$id}/send")->assertOk()->assertJsonPath('quote.status', 'sent');
        $this->assertSame(CrmContact::STAGE_ESTIMATE, $contact->fresh()->stage);
        $send = CrmEmailSend::query()->where('source_type', 'quote')->firstOrFail();
        $this->assertStringContainsString('$425.00', $send->html);
        $this->assertStringNotContainsString('Unsubscribe', $send->html);
        $quote = CrmQuote::query()->findOrFail($id);
        $this->assertSame('planned', CrmEvent::query()->find($quote->follow_up_event_id)->status);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/public/crm/quotes/{$quote->public_token}")->assertOk()->assertJsonPath('quote.total', 425)->assertJsonPath('quote.can_respond', true);
        $this->postJson("/api/public/crm/quotes/{$quote->public_token}/accept")->assertOk()->assertJsonPath('quote.status', 'accepted');
        $this->assertSame(CrmContact::STAGE_BOOKED, $contact->fresh()->stage);
        $this->assertSame('cancelled', CrmEvent::query()->find($quote->follow_up_event_id)->status);
        $this->assertSame(1, CrmNotice::query()->where('user_id', $rep->id)->where('type', 'quote_accepted')->count());
    }

    public function test_sent_quotes_expire_overnight(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep);
        $quote = CrmQuote::query()->create(['contact_id' => $contact->id, 'number' => 'Q-1', 'status' => 'sent', 'public_token' => str_repeat('a', 48), 'valid_until' => now()->subDay()->toDateString(), 'sent_at' => now()->subDays(40)]);

        $this->artisan('crm:nightly')->assertSuccessful();
        $this->assertSame('expired', $quote->fresh()->status);
    }

    public function test_conversation_timeline_and_sms_consent(): void
    {
        $rep = $this->signed($this->rep());
        $contact = $this->contactFor($rep, ['email' => 'dana@x.com', 'phone' => '(301) 555-0142']);
        $this->actAs($rep);
        CrmEmailSend::query()->create(['contact_id' => $contact->id, 'sender_user_id' => $rep->id, 'source_type' => 'one_off', 'from_email' => 'a@reprophotos.com', 'to_email' => 'dana@x.com', 'subject' => 'Hello', 'html' => '<p>x</p>', 'text' => 'x', 'transport' => 'log', 'status' => 'logged', 'sent_at' => now()]);

        $this->getJson("/api/crm/contacts/{$contact->id}/conversation")->assertOk()->assertJsonPath('items.0.channel', 'email')->assertJsonPath('sms.consent', false);
        $this->postJson("/api/crm/contacts/{$contact->id}/sms-consent", ['consent' => true])->assertUnprocessable();
        $this->postJson("/api/crm/contacts/{$contact->id}/sms-consent", ['consent' => true, 'source' => 'verbal'])->assertOk()->assertJsonPath('sms.consent', true)->assertJsonPath('sms.source', 'verbal');

        $this->actAs($this->rep());
        $this->getJson("/api/crm/contacts/{$contact->id}/conversation")->assertNotFound();
    }
}
