<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\SmsNumber;
use App\Models\SmsSharedGroup;
use App\Models\SmsSharedMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\VoiceCall;
use App\Models\VoiceLinePolicy;
use App\Models\VoiceVoicemail;
use App\Services\MobileNotifications\MobileNotificationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileNotificationContentTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = ['title' => 'R/E Pro', 'body' => 'You have a new update.'];

    private User $staff;

    private array $devices = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'mobile_notifications.enabled' => true]);
        $this->staff = User::factory()->create(['role' => 'admin']);
    }

    public function test_sms_and_email_alerts_show_authorized_text_without_metadata_or_html_fallback(): void
    {
        $sms = $this->sourceMessage('SMS', 'The photographer can arrive at noon.');
        $alert = $this->notificationAlert($this->staff, 'sms', $sms->id);
        $this->assertContains($alert['title'], ['New text message', 'Message from Alex Morgan']);
        $this->assertStringContainsString('The photographer can arrive at noon.', $alert['body']);

        $email = $this->sourceMessage('EMAIL', 'Please confirm the preferred arrival time.', ['subject' => 'Tomorrow’s shoot',
            'body_html' => '<p>NEVER_BODY_HTML</p>', 'metadata' => ['preview' => 'NEVER_METADATA']]);
        $payload = $this->notificationPayload($this->staff, 'email', $email->id);
        $this->assertSame('Tomorrow’s shoot', $payload['aps']['alert']['title']);
        $this->assertStringContainsString('Please confirm the preferred arrival time.', $payload['aps']['alert']['body']);
        $this->assertStringNotContainsString('NEVER_', json_encode($payload));
        $this->assertSame('messages', $payload['repro']['destination']);
        $this->assertArrayNotHasKey('badge', $payload['aps']);
    }

    public function test_shared_group_and_support_replies_show_text_but_internal_note_body_stays_private(): void
    {
        $number = SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550103', 'label' => 'Test line', 'is_default' => true]);
        $group = SmsSharedGroup::create(['sms_number_id' => $number->id, 'business_number' => $number->phone_number,
            'participant_key' => hash('sha256', 'content-test'), 'participants' => ['+12025550102'],
            'title' => 'Planning', 'created_by' => $this->staff->id]);
        $group->members()->attach($this->staff->id);
        $message = SmsSharedMessage::create(['group_id' => $group->id, 'direction' => 'INBOUND',
            'from_number' => '+12025550102', 'body_text' => 'We can meet at the property tomorrow.',
            'status' => 'DELIVERED', 'recipient_statuses' => []]);
        $alert = $this->notificationAlert($this->staff, 'shared_group', $message->id);
        $this->assertSame('New group message', $alert['title']);
        $this->assertStringContainsString('We can meet at the property tomorrow.', $alert['body']);

        $client = User::factory()->create(['role' => 'client']);
        $ticket = SupportTicket::create(['requester_id' => $client->id, 'request_key' => (string) Str::uuid(),
            'subject' => 'Photo delivery', 'category' => 'delivery', 'status' => 'open', 'priority' => 'normal', 'version' => 1]);
        $reply = $ticket->messages()->create(['author_id' => $this->staff->id, 'request_key' => (string) Str::uuid(),
            'body' => 'Your gallery is ready to review.', 'kind' => 'reply']);
        $alert = $this->notificationAlert($client, 'support', $reply->id);
        $this->assertSame('Support reply', $alert['title']);
        $this->assertStringContainsString('Your gallery is ready to review.', $alert['body']);
        $reply->update(['internal' => true, 'body' => 'INTERNAL_NOTE_ONLY']);
        $this->assertSame(self::GENERIC, $this->notificationAlert($client, 'support', $reply->id));
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $internal = $this->notificationAlert($manager, 'support', $reply->id);
        $this->assertStringContainsString('Photo delivery', $internal['title'].' '.$internal['body']);
        $this->assertStringNotContainsString('INTERNAL_NOTE_ONLY', json_encode($internal));
    }

    public function test_shoot_and_account_alerts_use_allowlisted_wording_instead_of_raw_descriptions(): void
    {
        $client = User::factory()->create(['role' => 'client', 'name' => 'Maya Chen']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'address' => '148 Maple Avenue']);
        $activity = ShootActivityLog::create(['shoot_id' => $shoot->id, 'action' => 'shoot_scheduled',
            'description' => 'NEVER_RAW_DESCRIPTION', 'metadata' => ['note' => 'NEVER_METADATA']]);
        $payload = $this->notificationPayload($client, 'shoot_activity', $activity->id);
        $this->assertSame('Shoot scheduled', $payload['aps']['alert']['title']);
        $this->assertStringContainsString('148 Maple Avenue', $payload['aps']['alert']['body']);
        $this->assertSame('notifications', $payload['repro']['destination']);
        $this->assertStringNotContainsString('NEVER_', json_encode($payload));

        $account = UserActivityLog::record($client, 'email_bounced', 'NEVER_RAW_TITLE', 'NEVER_RAW_DESCRIPTION',
            metadata: ['details' => 'NEVER_METADATA']);
        $alert = $this->notificationAlert($this->staff, 'account_activity', $account->id);
        $this->assertNotSame(self::GENERIC, $alert);
        $this->assertStringContainsString('Maya Chen', $alert['title'].' '.$alert['body']);
        $this->assertStringNotContainsString('NEVER_', json_encode($alert));
        $activity->update(['action' => 'unreviewed_private_action']);
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'shoot_activity', $activity->id));
    }

    public function test_voicemail_names_the_caller_without_transcript_recording_url_or_metadata(): void
    {
        $contact = Contact::create(['name' => 'Alex Morgan', 'phone' => '+12025550102', 'type' => 'client']);
        VoiceLinePolicy::create(['phone_number' => '+12025550103', 'mode' => 'staff_voicemail',
            'recipient_role' => null, 'recipient_roles' => [], 'recipient_ids' => [$this->staff->id], 'timezone' => 'UTC', 'weekly_hours' => []]);
        $call = VoiceCall::create(['direction' => 'INBOUND', 'status' => 'completed', 'caller_contact_id' => $contact->id,
            'from_phone' => $contact->phone, 'to_phone' => '+12025550103', 'transcript' => 'NEVER_TRANSCRIPT',
            'recording_url' => 'https://example.test/NEVER_RECORDING', 'metadata' => ['note' => 'NEVER_METADATA']]);
        $voicemail = VoiceVoicemail::create(['voice_call_id' => $call->id, 'phone_number' => '+12025550103',
            'recipient_ids' => [$this->staff->id], 'state' => 'saved', 'greeting_kind' => 'office', 'duration_seconds' => 42,
            'recording_path' => 'NEVER_PRIVATE_AUDIO', 'saved_at' => now(), 'expires_at' => now()->addDay()]);
        $payload = $this->notificationPayload($this->staff, 'voicemail', $voicemail->id);
        $this->assertSame('New voicemail', $payload['aps']['alert']['title']);
        $this->assertStringContainsString('Alex Morgan', $payload['aps']['alert']['body']);
        $this->assertStringNotContainsString('NEVER_', json_encode($payload));
        $this->assertSame('calls', $payload['repro']['destination']);
    }

    public function test_real_shoot_action_keys_have_meaningful_titles_without_raw_payment_or_review_details(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'address' => '148 Maple Avenue']);
        // These keys come from ShootActivityLogger, the reschedule controller,
        // payment controllers and FinalizeEditedUploadAction. Staff may see them;
        // this content change must not expand the narrower client action scope.
        foreach ([
            'rescheduled' => 'Shoot rescheduled',
            'reschedule_requested' => 'Reschedule requested',
            'shoot_declined' => 'Shoot declined',
            'shoot_updated' => 'Shoot updated',
            'shoot_resumed_from_hold' => 'Shoot resumed',
            'shoot_submitted_for_editing_review' => 'Edits ready for review',
            'shoot_submitted_edited' => 'Edits ready for review',
            'editor_assigned' => 'Editor assigned',
            'payment_received' => 'Payment received',
            'payment_completed' => 'Shoot fully paid',
            'payment_failed' => 'Payment failed',
            'payment_refunded' => 'Payment refunded',
            'payment_marked_paid' => 'Shoot marked paid',
            'shoot_finalized_delivered' => 'Photos delivered',
        ] as $action => $title) {
            $activity = ShootActivityLog::create(['shoot_id' => $shoot->id, 'action' => $action,
                'description' => 'NEVER_RAW_DESCRIPTION $1,250.00',
                'metadata' => ['amount' => 1250, 'review_note' => 'NEVER_METADATA']]);
            $alert = $this->notificationAlert($this->staff, 'shoot_activity', $activity->id);
            $this->assertSame($title, $alert['title'], $action);
            $this->assertStringContainsString('148 Maple Avenue', $alert['body'], $action);
            $this->assertStringNotContainsString('NEVER_', json_encode($alert), $action);
            $this->assertStringNotContainsString('1,250', json_encode($alert), $action);
            if (in_array($action, ['rescheduled', 'payment_received', 'shoot_submitted_for_editing_review'], true)) {
                $this->assertSame(self::GENERIC, $this->notificationAlert($client, 'shoot_activity', $activity->id), $action);
            }
        }
        $unknown = ShootActivityLog::create(['shoot_id' => $shoot->id, 'action' => 'reschedule', 'description' => 'NEVER_RAW_DESCRIPTION']);
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'shoot_activity', $unknown->id));
    }

    public function test_attachment_only_message_uses_canonical_attachments_and_never_metadata_hints(): void
    {
        $attachment = ['storage_path' => 'messages/test.jpg', 'name' => 'test.jpg', 'type' => 'image/jpeg'];
        $message = $this->sourceMessage('SMS', '', ['attachments_json' => [$attachment]]);
        $payload = $this->notificationPayload($this->staff, 'sms', $message->id);
        $this->assertSame(['title' => 'New message', 'body' => 'Open Messages to view the attachment.'], $payload['aps']['alert']);
        $this->assertSame('messages', $payload['repro']['destination']);
        $this->assertStringNotContainsString('test.jpg', json_encode($payload));

        $hintOnly = $this->sourceMessage('SMS', '', ['attachments_json' => [],
            'metadata' => ['attachments' => [$attachment], 'attachments_json' => [$attachment], 'media_count' => 1]]);
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'sms', $hintOnly->id));
        $other = User::factory()->create(['role' => 'admin']);
        $this->assertSame(self::GENERIC, $this->notificationAlert($other, 'sms', $message->id));
        $message->update(['attachments_json' => []]);
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'sms', $message->id));
        Http::assertNothingSent();
    }

    public function test_html_entities_controls_and_unicode_lengths_are_safe_in_both_alert_fields(): void
    {
        $body = '<p>Hello &amp; welcome.</p><script>NEVER_SCRIPT</script><style>NEVER_STYLE</style>'
            ."\x00\t\n\u{202E}".'<div>The photographer is on the way.</div>';
        $email = $this->sourceMessage('EMAIL', $body, ['subject' => '<b>Arrival &amp; access</b>']);
        $alert = $this->notificationAlert($this->staff, 'email', $email->id);
        $this->assertSame('Arrival & access', $alert['title']);
        $this->assertStringContainsString('Hello & welcome.', $alert['body']);
        $this->assertStringContainsString('The photographer is on the way.', $alert['body']);
        $this->assertStringNotContainsString('NEVER_', json_encode($alert));
        $this->assertDoesNotMatchRegularExpression('/[<>\p{Cc}\p{Cf}]/u', $alert['title'].' '.$alert['body']);

        $email->update(['subject' => str_repeat('界🏡', 60), 'body_text' => str_repeat('您好 🏡 ', 100)]);
        $long = $this->notificationAlert($this->staff, 'email', $email->id);
        $this->assertStringStartsWith('界🏡', $long['title']);
        $this->assertStringStartsWith('您好 🏡', $long['body']);
        $this->assertTrue(mb_check_encoding($long['title'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($long['body'], 'UTF-8'));
        $this->assertLessThanOrEqual(64, mb_strlen($long['title']));
        $this->assertLessThanOrEqual(180, mb_strlen($long['body']));
    }

    public function test_secrets_currency_and_urls_never_appear_in_notification_preview(): void
    {
        foreach ([
            ['Your verification code is 739204.', '739204'],
            ['Your one-time code is 739204.', '739204'],
            ['Password: hidden-value.', 'hidden-value'],
            ['The total is $1,250.00.', '1,250.00'],
            ['The total is 1,250.00 USD.', '1,250.00'],
            ['Visit https://example.test/reset?token=PRIVATE_URL_TOKEN to continue.', 'PRIVATE_URL_TOKEN'],
            ['Open /reset?token=abc12345 to continue.', 'abc12345'],
        ] as [$body, $private]) {
            $message = $this->sourceMessage('SMS', $body, ['metadata' => ['preview' => 'NEVER_METADATA']]);
            $payload = $this->notificationPayload($this->staff, 'sms', $message->id);
            $json = json_encode($payload);
            $this->assertStringNotContainsString($private, $json);
            $this->assertStringNotContainsString('NEVER_METADATA', $json);
            $this->assertStringNotContainsString('https:', $json);
            $this->assertNotEmpty($payload['aps']['alert']['body']);
        }
    }

    public function test_unknown_missing_and_empty_sources_use_the_exact_generic_fallback(): void
    {
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'unknown_kind', 'missing'));
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'email', '999999999'));
        $empty = $this->sourceMessage('SMS', " \t\n");
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'sms', $empty->id));
        $htmlOnly = $this->sourceMessage('EMAIL', '', ['body_html' => '<p>NEVER_HTML_FALLBACK</p>']);
        $this->assertSame(self::GENERIC, $this->notificationAlert($this->staff, 'email', $htmlOnly->id));
    }

    public function test_other_recipient_permission_revocation_push_preference_and_read_state_never_leak_text(): void
    {
        $email = $this->sourceMessage('EMAIL', 'The gallery is ready for your review.', ['subject' => 'Gallery update']);
        $delivery = $this->notificationDelivery($this->staff, 'email', $email->id);
        $transport = app(MobileNotificationTransport::class);
        $this->assertStringContainsString('gallery is ready', $transport->payload($delivery)['aps']['alert']['body']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->assertSame(self::GENERIC, $this->notificationAlert($other, 'email', $email->id));

        $this->staff->update(['permission_overrides' => ['allow' => [], 'deny' => ['messaging-email-view']]]);
        $this->assertSame(self::GENERIC, $transport->payload($delivery)['aps']['alert']);
        $this->staff->update(['permission_overrides' => null, 'metadata' => ['preferences' => ['notificationSettings' => ['push' => false]]]]);
        $this->assertSame(self::GENERIC, $transport->payload($delivery)['aps']['alert']);
        $this->staff->update(['metadata' => ['preferences' => ['notificationSettings' => ['push' => true]]]]);
        $this->assertStringContainsString('gallery is ready', $transport->payload($delivery)['aps']['alert']['body']);
        $email->thread->markReadForUser($this->staff->id);
        $this->assertSame(self::GENERIC, $transport->payload($delivery)['aps']['alert']);
        Http::assertNothingSent();
    }

    private function sourceMessage(string $channel, string $body, array $attributes = []): Message
    {
        $contact = Contact::create(['name' => 'Alex Morgan', 'phone' => '+12025550102', 'email' => 'alex@example.test', 'type' => 'client']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => $channel, 'status' => 'OPEN',
            'sms_inbox_active' => true, 'unread_for_user_ids_json' => [$this->staff->id]]);

        return Message::create(array_merge(['channel' => $channel, 'direction' => 'INBOUND',
            'provider' => $channel === 'SMS' ? 'TELNYX' : 'LOCAL_SMTP',
            'from_address' => $channel === 'SMS' ? '+12025550102' : 'alex@example.test',
            'to_address' => $channel === 'SMS' ? '+12025550103' : 'inbox@example.test',
            'body_text' => $body, 'status' => 'DELIVERED', 'thread_id' => $thread->id], $attributes));
    }

    private function notificationDelivery(User $user, string $kind, int|string $sourceID): MobileNotificationDelivery
    {
        $device = $this->devices[$user->id] ??= MobileNotificationDevice::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'personal_access_token_id' => $user->createToken('isolated-content-test')->accessToken->id,
            'token' => 'aabb', 'sequence' => 1, 'environment' => 'sandbox', 'permission' => 'authorized', 'expires_at' => now()->addDay()]);

        return MobileNotificationDelivery::firstOrCreate(['device_id' => $device->id, 'event_key' => $kind.':'.$sourceID.':'.$user->id],
            ['user_id' => $user->id, 'kind' => $kind, 'source_id' => (string) $sourceID, 'status' => 'queued', 'expires_at' => now()->addHour()]);
    }

    private function notificationPayload(User $user, string $kind, int|string $sourceID): array
    {
        return app(MobileNotificationTransport::class)->payload($this->notificationDelivery($user, $kind, $sourceID));
    }

    private function notificationAlert(User $user, string $kind, int|string $sourceID): array
    {
        return $this->notificationPayload($user, $kind, $sourceID)['aps']['alert'];
    }
}
