<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\Email\CrmHtmlSanitizer;
use App\Services\Crm\Email\CrmSignatureService;
use App\Services\Crm\Email\CrmSuppression;
use App\Services\Crm\Email\CrmTemplateService;

class CrmEmailTest extends CrmTestCase
{
    private function approvedSignature(User $user): void
    {
        $sig = app(CrmSignatureService::class)->submit($user, ['name' => $user->name, 'title' => 'Sales Representative', 'phone' => '(202) 555-0117', 'sender_email' => 'maya@reprophotos.com']);
        app(CrmSignatureService::class)->approve($sig, $this->superAdmin());
    }

    private function approvedTemplate(string $body = '<p>Hi {{first_name}}, here is a sample gallery for {{market}}.</p>'): CrmEmailTemplate
    {
        $admin = $this->admin();
        $template = app(CrmTemplateService::class)->create('Follow-up after a call', 'one_off', ['subject' => 'Photos for {{company}}', 'body_html' => $body, 'cta_label' => 'See samples', 'cta_url' => 'https://reprophotos.com/projects'], $admin);
        $version = app(CrmTemplateService::class)->submit($template, $admin);
        app(CrmTemplateService::class)->approve($version, $admin);

        return $template->fresh();
    }

    public function test_signature_flow_only_super_admin_approves_and_last_approved_stays_in_use(): void
    {
        $rep = $this->actAs($this->rep(['email' => 'maya@reprophotos.com', 'name' => 'Maya Torres']));
        $this->postJson('/api/crm/signatures/me', ['name' => 'Maya Torres', 'title' => 'Sales', 'sender_email' => 'maya@gmail.com'])->assertUnprocessable();
        $id = $this->postJson('/api/crm/signatures/me', ['name' => 'Maya Torres', 'title' => 'Sales Associate', 'phone' => '202'])->assertCreated()->json('signature.id');
        $this->getJson('/api/crm/signatures/me')->assertJsonPath('send_blocker', 'Your signature is waiting for approval, so you can’t send email yet.');

        $this->actAs($this->admin());
        $this->postJson("/api/crm/signatures/{$id}/approve")->assertForbidden();
        $this->getJson('/api/crm/signatures/pending')->assertForbidden();

        $this->actAs($this->superAdmin());
        $this->getJson('/api/crm/signatures/pending')->assertOk()->assertJsonPath('data.0.change', 'New signature');
        $this->postJson("/api/crm/signatures/{$id}/approve")->assertOk();

        $this->actAs($rep);
        $newId = $this->postJson('/api/crm/signatures/me', ['name' => 'Maya Torres', 'title' => 'Sales Representative', 'phone' => '202'])->assertCreated()->json('signature.id');
        $me = $this->getJson('/api/crm/signatures/me')->assertOk();
        $this->assertSame($id, $me->json('approved.id'));
        $this->assertSame($newId, $me->json('latest.id'));
        $this->assertNull($me->json('send_blocker'));
    }

    public function test_templates_are_versioned_and_emails_use_the_approved_version(): void
    {
        $template = $this->approvedTemplate();
        $approvedId = $template->approved_version_id;
        $admin = $this->actAs($this->admin());

        $this->putJson("/api/crm/templates/{$template->id}", ['subject' => 'New subject', 'body_html' => '<p>New body</p>'])->assertOk()
            ->assertJsonPath('template.has_pending_changes', true)
            ->assertJsonPath('template.approved.id', $approvedId);

        $this->actAs($this->rep());
        $this->putJson("/api/crm/templates/{$template->id}", ['subject' => 'x', 'body_html' => '<p>x</p>'])->assertForbidden();
        $this->assertSame([$template->id], collect($this->getJson('/api/crm/templates')->json('data'))->pluck('id')->all());
    }

    public function test_one_off_send_records_exact_content_with_unsubscribe_and_signature(): void
    {
        $rep = $this->rep(['email' => 'maya@reprophotos.com', 'name' => 'Maya Torres']);
        $this->approvedSignature($rep);
        $template = $this->approvedTemplate();
        $contact = $this->contactWithMarketingOptIn($rep, ['name' => 'Dana Whitfield', 'email' => 'dana@whitfieldrowe.com', 'company' => 'Whitfield & Rowe', 'market_city' => 'Bethesda']);
        $this->actAs($rep);

        $this->getJson("/api/crm/contacts/{$contact->id}/emails/compose")->assertOk()->assertJsonPath('blocker', null)->assertJsonPath('transport', 'log');
        $response = $this->postJson("/api/crm/contacts/{$contact->id}/emails", ['template_id' => $template->id, 'idempotency_key' => 'abc'])->assertCreated();
        $this->postJson("/api/crm/contacts/{$contact->id}/emails", ['template_id' => $template->id, 'idempotency_key' => 'abc'])->assertCreated();

        $this->assertSame(1, CrmEmailSend::query()->count());
        $send = CrmEmailSend::query()->firstOrFail();
        $this->assertSame('logged', $response->json('send.status'));
        $this->assertSame('maya@reprophotos.com', $send->from_email);
        $this->assertSame('Photos for Whitfield &amp; Rowe', e($send->subject) === $send->subject ? $send->subject : e($send->subject));
        $this->assertStringContainsString('Hi Dana', $send->html);
        $this->assertStringContainsString('Bethesda', $send->html);
        $this->assertStringContainsString('/unsubscribe/', $send->html);
        $this->assertStringContainsString('Sales Representative', $send->html);
        $this->assertNull($contact->fresh()->last_contacted_at);
        $this->assertDatabaseMissing('crm_activities', ['contact_id' => $contact->id, 'type' => 'email_sent', 'subject_id' => $send->id]);

        $this->getJson("/api/crm/contacts/{$contact->id}/emails/{$send->id}")->assertOk()->assertJsonPath('send.recorded_only', true);
    }

    public function test_sending_is_blocked_without_signature_or_when_unsubscribed(): void
    {
        $rep = $this->rep(['email' => 'maya@reprophotos.com']);
        $template = $this->approvedTemplate();
        $contact = $this->contactFor($rep, ['email' => 'dana@x.com']);
        $this->actAs($rep);

        $this->postJson("/api/crm/contacts/{$contact->id}/emails", ['template_id' => $template->id])->assertUnprocessable()->assertJsonPath('reason', 'signature');

        $this->approvedSignature($rep);
        app(CrmSuppression::class)->suppress('DANA@x.com', 'unsubscribe', 'test');
        $this->actAs($rep);
        $this->postJson("/api/crm/contacts/{$contact->id}/emails", ['template_id' => $template->id])->assertUnprocessable()->assertJsonPath('reason', 'opted_out');
        $this->assertNotNull($contact->fresh()->email_opt_out_at);
        $this->assertSame(0, CrmEmailSend::query()->count());
    }

    public function test_edited_text_must_pass_the_content_check(): void
    {
        $rep = $this->rep(['email' => 'maya@reprophotos.com']);
        $this->approvedSignature($rep);
        $template = $this->approvedTemplate();
        $contact = $this->contactWithMarketingOptIn($rep, ['email' => 'dana@x.com']);
        $this->actAs($rep);

        $bad = $this->postJson("/api/crm/contacts/{$contact->id}/emails", ['template_id' => $template->id, 'body_html' => '<p>Our photos are guaranteed to sell your home 2x faster.</p>'])->assertUnprocessable();
        $this->assertFalse($bad->json('content_check.passed'));
        $this->assertNotEmpty($bad->json('content_check.issues'));

        $this->postJson("/api/crm/contacts/{$contact->id}/emails", ['template_id' => $template->id, 'body_html' => '<p>Thanks for the call. Here are twilight samples.</p>'])->assertCreated();
    }

    public function test_rep_cannot_read_another_reps_emails(): void
    {
        $other = $this->rep();
        $theirs = $this->contactFor($other, ['email' => 'x@x.com']);
        $send = CrmEmailSend::create(['contact_id' => $theirs->id, 'source_type' => 'one_off', 'from_email' => 'a@reprophotos.com', 'to_email' => 'x@x.com', 'subject' => 's', 'html' => 'h', 'text' => 't', 'transport' => 'log', 'status' => 'logged']);
        $this->actAs($this->rep());

        $this->getJson("/api/crm/contacts/{$theirs->id}/emails")->assertNotFound();
        $this->getJson("/api/crm/contacts/{$theirs->id}/emails/{$send->id}")->assertNotFound();
    }

    public function test_marketing_requires_matching_explicit_consent_and_live_postal_address(): void
    {
        $rep = $this->rep(['email' => 'maya@reprophotos.com']);
        $this->approvedSignature($rep);
        $template = $this->approvedTemplate();
        $contact = $this->contactFor($rep, ['email' => 'case@example.com', 'lead_source' => 'website']);
        $this->actAs($rep);
        $url = "/api/crm/contacts/{$contact->id}";
        $this->postJson($url.'/emails', ['template_id' => $template->id])->assertUnprocessable()->assertJsonPath('reason', 'no_consent');
        $this->postJson($url.'/marketing-consent', ['consent' => true, 'source' => 'written'])->assertOk()->assertJsonPath('marketing_email.consent', true);
        $this->fakeAcceptedCrmMail();
        config(['crm.email.company_address' => '']);
        $this->postJson($url.'/emails', ['template_id' => $template->id])->assertUnprocessable()->assertJsonPath('reason', 'missing_address');
        $this->patchJson($url, ['email' => 'different@example.com'])->assertOk();
        $this->getJson($url.'/conversation')->assertJsonPath('marketing_email.consent', false);
    }

    public function test_public_unsubscribe_and_resubscribe(): void
    {
        $contact = $this->contactFor($this->rep(), ['email' => 'dana@x.com']);
        $token = app(CrmSuppression::class)->token('dana@x.com');

        $this->getJson("/api/public/crm/unsubscribe/{$token}")->assertOk()->assertJsonPath('unsubscribed', false);
        $this->postJson("/api/public/crm/unsubscribe/{$token}")->assertOk()->assertJsonPath('unsubscribed', true);
        $this->assertNotNull($contact->fresh()->email_opt_out_at);
        $this->postJson("/api/public/crm/unsubscribe/{$token}/resubscribe")->assertOk();
        $this->assertNull($contact->fresh()->email_opt_out_at);

        $this->getJson('/api/public/crm/unsubscribe/not-a-token')->assertNotFound();
        $this->postJson("/api/public/crm/unsubscribe/{$token}/one-click")->assertOk();
        $this->assertTrue(app(CrmSuppression::class)->isSuppressed('Dana@X.com'));
    }

    public function test_sanitizer_keeps_formatting_and_drops_scripts(): void
    {
        $clean = CrmHtmlSanitizer::clean('<p onclick="x()">Hi <strong>there</strong><script>alert(1)</script><img src=x onerror=y><a href="javascript:bad()">bad</a> <a href="https://ok.com" style="x">ok</a></p><div>Kept text</div>');

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringContainsString('<strong>there</strong>', $clean);
        $this->assertStringContainsString('href="https://ok.com"', $clean);
        $this->assertStringContainsString('Kept text', $clean);
    }
}
