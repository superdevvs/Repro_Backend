<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageThread;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailSearchTest extends TestCase
{
    use RefreshDatabase;

    private function email(array $attributes = []): Message
    {
        return Message::create([
            'channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'status' => 'SENT',
            'from_address' => 'office@example.test', 'to_address' => 'customer@example.test',
            'subject' => 'Ordinary subject', 'body_text' => 'Ordinary body', 'send_source' => 'MANUAL',
            ...$attributes,
        ]);
    }

    private function search(string $search, array $filters = [])
    {
        return $this->getJson('/api/messaging/email/messages?'.http_build_query(['search' => $search, ...$filters]));
    }

    public function test_search_finds_historical_subject_body_and_sender_recipient_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $subject = $this->email(['subject' => 'Older kitchen revision', 'created_at' => now()->subMonths(2)]);
        $body = $this->email(['body_text' => 'Please replace the kitchen window.']);
        $sender = $this->email(['from_address' => 'kitchen-team@example.test']);
        $recipient = $this->email(['to_address' => 'kitchen-client@example.test']);
        $name = $this->email(['sender_display_name' => 'Kitchen Coordinator']);
        $cc = $this->email(['cc_addresses_json' => ['kitchen-copy@example.test']]);
        $this->email();
        $this->email(['channel' => 'SMS', 'body_text' => 'Kitchen SMS']);

        $response = $this->search('  KITCHEN  ')->assertOk()->assertJsonPath('total', 6);
        $this->assertEqualsCanonicalizing([$subject->id, $body->id, $sender->id, $recipient->id, $name->id, $cc->id], array_column($response->json('data'), 'id'));
        $this->search('missing phrase')->assertOk()->assertJsonCount(0, 'data');
        $this->search('   ')->assertOk()->assertJsonPath('total', 7);
    }

    public function test_contact_identity_and_html_only_readable_text_are_searchable_without_matching_hidden_markup(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $contact = Contact::create(['name' => 'Élodie Homeowner', 'email' => 'owner@example.test', 'type' => 'client']);
        $thread = MessageThread::create(['channel' => 'EMAIL', 'contact_id' => $contact->id]);
        $email = $this->email([
            'thread_id' => $thread->id, 'body_text' => null,
            'body_html' => '<head><style>.secretcss {color: red;}</style></head><p>Kitchen <strong>window</strong> &amp; garden</p><p>Next room</p>',
        ]);
        $staff = User::factory()->create(['role' => 'admin', 'name' => 'Legacy Coordinator']);
        $legacy = $this->email(['created_by' => $staff->id, 'sender_display_name' => null]);

        foreach (['ÉLODIE', 'owner@example.test', 'kitchen window & garden', 'garden next room'] as $search) {
            $this->search($search)->assertOk()->assertJsonPath('data.0.id', $email->id)->assertJsonPath('total', 1);
        }
        $this->search('secretcss')->assertOk()->assertJsonPath('total', 0);
        $this->search('strong')->assertOk()->assertJsonPath('total', 0);
        $this->search('legacy coordinator')->assertOk()->assertJsonPath('data.0.id', $legacy->id)->assertJsonPath('total', 1);
    }

    public function test_percent_underscore_and_backslash_are_literal_search_characters(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $literal = $this->email(['subject' => '100% draft_final C:\\photos']);
        $this->email(['subject' => '1000 draftXfinal C:photos']);
        foreach (['100%', 'draft_final', 'C:\\photos', '%', '_'] as $search) {
            $this->search($search)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $literal->id);
        }
    }

    public function test_search_filters_before_pagination_and_preserves_account_source_and_status(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $channel = MessageChannel::create(['type' => 'EMAIL', 'provider' => 'RESEND', 'display_name' => 'Office']);
        $match = ['subject' => 'Window revision', 'message_channel_id' => $channel->id, 'send_source' => 'MANUAL'];
        $first = $this->email($match);
        $second = $this->email($match);
        $third = $this->email($match);
        $this->email([...$match, 'status' => 'FAILED']);
        $this->email([...$match, 'send_source' => 'AUTOMATION']);
        $this->email([...$match, 'message_channel_id' => null]);
        $this->email(['subject' => 'Unrelated']);

        $filters = ['channel_id' => $channel->id, 'send_source' => 'MANUAL', 'status' => 'SENT', 'per_page' => 2];
        $this->search('window', $filters)->assertOk()->assertJsonPath('total', 3)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.id', $third->id)->assertJsonPath('data.1.id', $second->id);
        $this->search('window', [...$filters, 'page' => 2])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }

    public function test_email_search_does_not_expand_visibility_or_reintroduce_support_messages(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $client = User::factory()->create(['role' => 'client']);
        $visible = $this->email(['subject' => 'Searchable window', 'created_by' => $manager->id]);
        $otherStaff = $this->email(['subject' => 'Searchable window colleague']);
        $imported = $this->email(['subject' => 'Searchable window imported', 'created_by' => $manager->id]);
        $ticket = SupportTicket::create(['requester_id' => $client->id, 'request_key' => (string) Str::uuid(), 'subject' => 'Imported support', 'category' => 'other']);
        $ticket->messages()->create(['author_id' => $client->id, 'request_key' => (string) Str::uuid(), 'body' => 'Imported', 'source_message_id' => $imported->id]);

        $this->actingAs($manager, 'sanctum');
        $this->search('window')->assertOk()->assertJsonPath('total', 2)->assertDontSee('window imported');
        $manager->update(['permission_overrides' => ['deny' => ['messaging-email-view'], 'allow' => []]]);
        $this->actingAs($manager->fresh(), 'sanctum');
        $this->search('window')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $response = $this->search('window')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing([$visible->id, $otherStaff->id], array_column($response->json('data'), 'id'));
        $this->actingAs($client, 'sanctum');
        $this->search('window')->assertForbidden();
    }

    public function test_search_validates_malformed_filters(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->getJson('/api/messaging/email/messages?search[]=window')->assertUnprocessable();
        $this->search('window', ['page' => 0])->assertUnprocessable();
        $this->search('window', ['per_page' => 101])->assertUnprocessable();
    }
}
