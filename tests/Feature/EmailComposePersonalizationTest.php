<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class EmailComposePersonalizationTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create([
            'type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'Test mail',
            'from_email' => 'team@example.test',
            'is_default' => true, 'owner_scope' => 'GLOBAL',
        ]);
        $provider = Mockery::mock(LocalSmtpProvider::class);
        $provider->shouldReceive('send')->andReturnUsing(function (MessageChannel $channel, array $payload): string {
            $this->sent[] = $payload;

            return 'personalized-fixture-'.count($this->sent);
        });
        $this->app->instance(LocalSmtpProvider::class, $provider);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_bulk_send_uses_each_destination_identity_and_safe_fallbacks_instead_of_shared_variables(): void
    {
        $alex = $this->photographer('Alex Rivera');
        $priya = $this->photographer('Priya Shah');
        $unnamed = $this->photographer('   ');
        $template = $this->template();
        $source = $template->fresh()->getAttributes();

        $this->postJson('/api/messaging/email/compose', [
            'to' => [strtoupper($alex->email), $priya->email, 'unknown@example.test', $unnamed->email],
            'template_id' => $template->id,
            'variables' => [
                'recipient' => ['name' => 'Wrong Person'], 'recipient_name' => 'Wrong Person',
                'recipient_first_name' => 'Wrong', 'recipient_last_name' => 'Person',
                'recipient_email' => 'wrong@example.test', 'recipient_type' => 'client',
                'greeting' => 'Hi Wrong',
            ],
        ])->assertOk()->assertJsonPath('sent', 4)->assertJsonPath('failed', 0);

        foreach (['Alex', 'Priya', 'there', 'there'] as $index => $first) {
            $this->assertSame('Thanks, '.$first, $this->sent[$index]['subject']);
            $this->assertStringContainsString('Hello '.$first.'.', $this->sent[$index]['html']);
            $this->assertStringContainsString('Hi '.$first, $this->sent[$index]['text']);
            $this->assertStringNotContainsString('Wrong', $this->sent[$index]['html']);
            $this->assertStringNotContainsString('{{', $this->sent[$index]['html']);
        }
        $this->assertStringContainsString('Alex Rivera / Rivera / '.$alex->email, $this->sent[0]['text']);
        $this->assertStringContainsString('Priya Shah / Shah / '.$priya->email, $this->sent[1]['text']);
        $this->assertStringContainsString('there /  / unknown@example.test', $this->sent[2]['text']);
        $this->assertSame(strtolower($alex->email), $this->sent[0]['to']);
        $this->assertSame($source, $template->fresh()->getAttributes());
    }

    public function test_single_recipient_names_are_html_escaped_but_subject_and_plain_text_stay_readable(): void
    {
        $person = $this->photographer('A<b>&" Example');
        $template = $this->template();

        $this->postJson('/api/messaging/email/compose', [
            'to' => $person->email, 'template_id' => $template->id,
        ])->assertOk()->assertJsonPath('to_address', $person->email)->assertJsonMissingPath('sent');

        $this->assertCount(1, $this->sent);
        $this->assertSame('Thanks, A<b>&"', $this->sent[0]['subject']);
        $this->assertStringContainsString('Hello A&lt;b&gt;&amp;&quot;.', $this->sent[0]['html']);
        $this->assertStringNotContainsString('A<b>', $this->sent[0]['html']);
        $this->assertStringContainsString('Hi A<b>&"', $this->sent[0]['text']);
    }

    public function test_related_account_tokens_remain_about_the_client_while_greeting_names_the_recipient(): void
    {
        $person = $this->photographer('Priya Shah');
        $client = User::factory()->create(['role' => 'client', 'name' => 'Casey Client']);
        $template = $this->template([
            'body_html' => '<html><body><p>Hi {{recipient_first_name}}; client {{client_first_name}}; assigned {{photographer_name}}.</p>{{recipient_manage_copy}}<p>{{recipient_note}}</p></body></html>',
        ]);

        $this->postJson('/api/messaging/email/compose', [
            'to' => $person->email, 'template_id' => $template->id,
            'related_account_id' => $client->id,
            'variables' => ['photographer' => ['name' => 'Alex Assigned'], 'recipient_manage_copy' => '<p>Custom management instructions.</p>', 'recipient_note' => 'Bring your tripod.'],
        ])->assertOk();

        $this->assertStringContainsString('Hi Priya; client Casey; assigned Alex Assigned.', $this->sent[0]['html']);
        $this->assertStringContainsString('<p>Custom management instructions.</p>', $this->sent[0]['html']);
        $this->assertStringContainsString('<p>Bring your tripod.</p>', $this->sent[0]['html']);
    }

    public function test_bulk_scheduling_stores_individual_bodies_and_dispatches_those_exact_snapshots(): void
    {
        $alex = $this->photographer('Alex Rivera');
        $priya = $this->photographer('Priya Shah');
        $template = $this->template();

        $this->postJson('/api/messaging/email/schedule', [
            'to' => [$alex->email, $priya->email, 'unknown@example.test'],
            'template_id' => $template->id, 'scheduled_at' => now()->addHour()->toIso8601String(),
            'variables' => ['recipient_first_name' => 'Shared'],
        ])->assertOk()->assertJsonPath('scheduled', 3)->assertJsonPath('failed', 0);

        $this->assertCount(0, $this->sent);
        $messages = Message::query()->orderBy('id')->get();
        $this->assertSame(['Thanks, Alex', 'Thanks, Priya', 'Thanks, there'], $messages->pluck('subject')->all());
        $alex->update(['name' => 'Renamed After Scheduling']);
        $template->update(['body_html' => '<html><body>Changed after scheduling</body></html>']);
        $this->travel(2)->hours();
        foreach ($messages as $message) {
            app(MessagingService::class)->dispatchScheduledMessage($message);
        }

        $this->assertCount(3, $this->sent);
        foreach ($messages as $index => $message) {
            $this->assertSame($message->body_html, $this->sent[$index]['html']);
            $this->assertSame($message->body_text, $this->sent[$index]['text']);
            $this->assertSame('SENT', $message->fresh()->status);
        }
    }

    public function test_single_scheduled_email_is_personalized_before_it_is_stored(): void
    {
        $person = $this->photographer('Priya Shah');
        $template = $this->template();

        $this->postJson('/api/messaging/email/schedule', [
            'to' => $person->email, 'template_id' => $template->id,
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertOk()->assertJsonPath('subject', 'Thanks, Priya')->assertJsonPath('status', 'SCHEDULED');

        $message = Message::query()->sole();
        $this->assertStringContainsString('Hello Priya.', $message->body_html);
        $this->assertCount(0, $this->sent);
        $this->travel(2)->hours();
        app(MessagingService::class)->dispatchScheduledMessage($message);
        $this->assertSame('Thanks, Priya', $this->sent[0]['subject']);
        $this->assertSame($message->body_html, $this->sent[0]['html']);
    }

    public function test_edited_section_template_drafts_render_from_unmodified_source_for_each_recipient(): void
    {
        $alex = $this->photographer('Alex Rivera');
        $priya = $this->photographer('Priya Shah');
        $template = $this->template([
            'editor_document_json' => ['version' => 2, 'format' => 'sections', 'sections' => [
                ['id' => 'original', 'type' => 'text', 'body' => 'Original {{recipient_first_name}}'],
            ]],
        ]);
        $source = $template->fresh()->getAttributes();

        $this->postJson('/api/messaging/email/compose', [
            'to' => [$alex->email, $priya->email], 'template_id' => $template->id,
            'subject' => 'Edited for {{recipient_first_name}}',
            'body_html' => '<div>Edited {{recipient_first_name}} &amp; team</div>',
            'body_text' => 'Edited {{recipient_name}}',
        ])->assertOk()->assertJsonPath('sent', 2);

        foreach (['Alex', 'Priya'] as $index => $first) {
            $this->assertSame('Edited for '.$first, $this->sent[$index]['subject']);
            $this->assertStringContainsString('Edited '.$first.' &amp; team', $this->sent[$index]['html']);
            $this->assertStringNotContainsString('Original ', $this->sent[$index]['html']);
        }
        $this->assertSame('Edited Alex Rivera', $this->sent[0]['text']);
        $this->assertSame('Edited Priya Shah', $this->sent[1]['text']);
        $this->assertSame($source, $template->fresh()->getAttributes());
    }

    private function photographer(string $name): User
    {
        return User::factory()->create(['role' => 'photographer', 'name' => $name]);
    }

    private function template(array $attributes = []): MessageTemplate
    {
        return MessageTemplate::create(array_replace([
            'channel' => 'EMAIL', 'scope' => 'GLOBAL', 'name' => 'Thanksgiving fixture',
            'subject' => 'Thanks, {{recipient_first_name}}',
            'body_html' => '<html><body><p>Hello {{recipient_first_name}}.</p><p>{{recipient_name}}</p></body></html>',
            'body_text' => '{{greeting}}. {{recipient_name}} / {{recipient_last_name}} / {{recipient_email}}',
            'editor_document_json' => ['version' => 2, 'format' => 'html', 'sections' => []],
            'is_active' => true,
        ], $attributes));
    }
}
