<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\FakeEmailProvider;
use App\Services\Messaging\Providers\FakeSmsProvider;
use App\Services\Messaging\SmsNotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        FakeSmsProvider::reset();
        FakeEmailProvider::reset();
        Http::preventStrayRequests();
        Http::fake();
        SmsNumber::create(['phone_number' => '+12025550101', 'provider' => 'TELNYX', 'is_default' => true]);
    }

    public static function roles(): array
    {
        return [['client'], ['salesRep'], ['admin'], ['superadmin']];
    }

    #[DataProvider('roles')]
    public function test_all_texts_off_blocks_every_category_for_each_requested_role(string $role): void
    {
        $user = $this->recipient(['notificationSMS' => false], $role);
        foreach (SmsNotificationPreferences::CATEGORIES as $category) {
            $message = $this->send(['sms_category' => $category, 'contact_user_id' => $user->id]);
            $this->assertSame('BLOCKED', $message->status);
            $this->assertSame('notification_preferences', data_get($message->metadata, 'delivery.blocked_reason'));
            $this->assertSame($category, data_get($message->metadata, 'sms_notification.category'));
            $this->assertNull($message->sent_at);
            $this->assertNull($message->provider_message_id);
        }
        $this->assertSame([], FakeSmsProvider::sent());
        Http::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_partial_preference_updates_preserve_unrelated_and_nested_settings(string $role): void
    {
        $user = $this->recipient([
            'notificationEmail' => false, 'portfolioWebsite' => 'https://example.test',
            'notifications' => ['shootReminders' => false, 'paymentReminders' => true],
            'smsCategories' => ['payments' => false, 'other' => true],
        ], $role);
        $user->update(['metadata' => array_merge($user->metadata, ['retained' => ['private' => 'unchanged']])]);

        $this->withToken($user->createToken('notification-settings')->plainTextToken)->putJson('/api/profile', ['preferences' => [
            'notificationSMS' => false, 'smsCategories' => ['bookingUpdates' => false],
            'notifications' => ['weeklySummaries' => false],
        ]])->assertOk()->assertJsonPath('user.metadata.preferences.notificationSMS', false);

        $preferences = $user->fresh()->metadata['preferences'];
        $this->assertFalse($preferences['notificationEmail']);
        $this->assertSame('https://example.test', $preferences['portfolioWebsite']);
        $this->assertSame(['shootReminders' => false, 'paymentReminders' => true, 'weeklySummaries' => false], $preferences['notifications']);
        $this->assertSame(['payments' => false, 'other' => true, 'bookingUpdates' => false], $preferences['smsCategories']);
        $this->assertSame(['private' => 'unchanged'], $user->fresh()->metadata['retained']);

        $this->putJson('/api/profile', ['preferences' => ['notificationSMS' => true]])->assertOk();
        $this->assertFalse($user->fresh()->metadata['preferences']['smsCategories']['payments']);
    }

    public static function invalidPreferences(): array
    {
        return [
            [['notificationSMS' => 'yes'], 'preferences.notificationSMS'],
            [['notificationSMS' => null], 'preferences.notificationSMS'],
            [['smsCategories' => 'payments'], 'preferences.smsCategories'],
            [['smsCategories' => null], 'preferences.smsCategories'],
            [['smsCategories' => ['unknown' => false]], 'preferences.smsCategories'],
            [['smsCategories' => ['payments' => 'no']], 'preferences.smsCategories.payments'],
            [['smsCategories' => ['payments' => null]], 'preferences.smsCategories.payments'],
            [['smsCategories' => ['payments' => ['enabled' => true]]], 'preferences.smsCategories.payments'],
        ];
    }

    #[DataProvider('invalidPreferences')]
    public function test_invalid_settings_are_rejected_without_writing(array $preferences, string $field): void
    {
        $user = $this->recipient(['notificationSMS' => true]);
        $before = $user->metadata;
        $this->withToken($user->createToken('notification-settings')->plainTextToken)->putJson('/api/profile', ['preferences' => $preferences])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $user->fresh()->metadata);
    }

    public function test_category_off_blocks_matching_templates_but_keeps_other_texts_and_email(): void
    {
        $user = $this->recipient(['smsCategories' => ['payments' => false]]);
        $template = MessageTemplate::create(['name' => 'Payment SMS', 'slug' => 'payment-due-sms', 'channel' => 'SMS', 'body_text' => 'Payment due', 'is_active' => true]);
        $this->assertSame('BLOCKED', $this->send(['template_id' => $template->id])->status);
        $this->assertSame('SENT', $this->send(['notification_type' => 'shoot_scheduled'])->status);
        $email = app(MessagingService::class)->sendEmail(['to' => $user->email, 'body_text' => 'Email remains enabled', 'contact_user_id' => $user->id]);
        $this->assertSame('SENT', $email->status);
        $this->assertCount(1, FakeSmsProvider::sent());
        $this->assertCount(1, FakeEmailProvider::sent());
    }

    public function test_inline_automation_uses_trigger_instead_of_custom_or_missing_template(): void
    {
        $this->recipient(['smsCategories' => ['shootReminders' => false]]);
        $template = MessageTemplate::create(['name' => 'Custom SMS', 'slug' => 'my-custom-template', 'channel' => 'SMS', 'body_text' => 'Upcoming shoot', 'is_active' => true]);
        $this->assertSame('BLOCKED', $this->send(['automation_trigger' => 'SHOOT_REMINDER'])->status);
        $this->assertSame('BLOCKED', $this->send(['automation_trigger' => 'SHOOT_REMINDER', 'template_id' => $template->id])->status);
        $this->assertSame([], FakeSmsProvider::sent());
    }

    public function test_formatted_phone_only_sends_find_recipient_without_using_sender_or_related_account(): void
    {
        $recipient = $this->recipient(['notificationSMS' => false]);
        $recipient->update(['phonenumber' => '(202) 555-0188', 'phone' => null]);
        $this->assertSame('BLOCKED', $this->send()->status);
        $this->assertSame('SENT', $this->send([
            'to' => '+12025550189', 'user_id' => $recipient->id, 'related_account_id' => $recipient->id,
        ])->status);
        $this->assertCount(1, FakeSmsProvider::sent());
    }

    public function test_explicit_and_linked_contact_users_cover_alternate_numbers_without_phone_suffix_collisions(): void
    {
        $user = $this->recipient(['notificationSMS' => false]);
        $this->assertSame('BLOCKED', $this->send(['to' => '+12025550190', 'contact_user_id' => $user->id])->status);
        Contact::create(['name' => 'Alternate', 'phone' => '+1 (202) 555-0191', 'user_id' => $user->id]);
        $this->assertSame('BLOCKED', $this->send(['to' => '+12025550191'])->status);
        $this->assertSame('SENT', $this->send(['to' => '+442025550188'])->status);
        $this->assertCount(1, FakeSmsProvider::sent());
    }

    public function test_legacy_defaults_and_canonical_overrides_do_not_reset_disabled_choices(): void
    {
        $user = $this->recipient(['notificationSettings' => ['sms' => false]]);
        $this->assertSame('BLOCKED', $this->send()->status);
        $user->update(['metadata' => ['preferences' => ['notificationSMS' => true, 'notificationSettings' => ['sms' => false],
            'notifications' => ['shootReminders' => false, 'paymentReminders' => false]]]]);
        $this->assertSame('SENT', $this->send()->status);
        $this->assertSame('BLOCKED', $this->send(['sms_category' => 'shootReminders'])->status);
        $this->assertSame('BLOCKED', $this->send(['sms_category' => 'payments'])->status);
        $preferences = $user->metadata['preferences'];
        $preferences['smsCategories'] = ['payments' => true];
        $user->update(['metadata' => ['preferences' => $preferences]]);
        $this->assertSame('SENT', $this->send(['sms_category' => 'payments'])->status);
        $this->assertCount(2, FakeSmsProvider::sent());
    }

    public function test_stored_messages_recheck_current_preferences_before_sending(): void
    {
        $user = $this->recipient([]);
        $message = $this->send(['sms_category' => 'deliveryUpdates', 'metadata' => ['retained' => 'yes']]);
        $message->update(['status' => 'SCHEDULED', 'sent_at' => null, 'provider_message_id' => null]);
        FakeSmsProvider::reset();
        $user->update(['metadata' => ['preferences' => ['smsCategories' => ['deliveryUpdates' => false]]]]);

        $blocked = app(MessagingService::class)->dispatchStoredSmsMessage($message);
        $this->assertSame('BLOCKED', $blocked->status);
        $this->assertSame('deliveryUpdates', data_get($blocked->metadata, 'sms_notification.category'));
        $this->assertSame('yes', $blocked->metadata['retained']);
        $this->assertSame([], FakeSmsProvider::sent());

        $user->update(['metadata' => ['preferences' => ['smsCategories' => ['deliveryUpdates' => true]]]]);
        $this->assertSame('SENT', app(MessagingService::class)->dispatchStoredSmsMessage($message)->status);
        $this->assertCount(1, FakeSmsProvider::sent());
    }

    public function test_reenabling_preferences_does_not_clear_carrier_opt_out(): void
    {
        $user = $this->recipient(['notificationSMS' => false]);
        $user->update(['sms_opt_out' => true]);
        $this->withToken($user->createToken('notification-settings')->plainTextToken)->putJson('/api/profile', ['preferences' => ['notificationSMS' => true]])->assertOk();
        $this->assertTrue($user->fresh()->sms_opt_out);
        try {
            $this->send();
            $this->fail('Carrier opt-out should prevent delivery.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Recipient is opted out of SMS.', $exception->getMessage());
        }
        $this->assertSame([], FakeSmsProvider::sent());
    }

    private function recipient(array $preferences, string $role = 'client'): User
    {
        return User::factory()->create(['role' => $role, 'phonenumber' => '+12025550188', 'phone' => '+12025550188',
            'metadata' => ['preferences' => $preferences]]);
    }

    private function send(array $payload = []): \App\Models\Message
    {
        return app(MessagingService::class)->sendSms(array_merge(['to' => '+12025550188', 'body_text' => 'Notification'], $payload));
    }
}
