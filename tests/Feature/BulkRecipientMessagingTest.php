<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\SmsGroup;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class BulkRecipientMessagingTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
    }

    public function test_admin_can_send_one_email_to_several_people(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/email/compose', [
            'to' => ['one@example.com', 'two@example.com'],
            'subject' => 'Schedule update',
            'body_text' => 'The shoot time moved.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('sent', 2);
        $response->assertJsonPath('failed', 0);

        $addresses = Message::query()->orderBy('to_address')->pluck('to_address')->all();
        $this->assertSame(['one@example.com', 'two@example.com'], $addresses);
        $this->assertSame(2, Message::query()->where('status', 'SENT')->count());
    }

    public function test_single_email_recipient_stays_a_normal_message(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/email/compose', [
            'to' => 'solo@example.com',
            'subject' => 'Hello',
            'body_text' => 'Just you.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('to_address', 'solo@example.com');
        $response->assertJsonMissingPath('sent');
    }

    public function test_sms_directory_lists_people_with_phone_numbers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'role' => 'photographer',
            'name' => 'Casey Photographer',
            'phonenumber' => '2025550144',
            'phone' => null,
        ]);
        User::factory()->create([
            'role' => 'client',
            'name' => 'No Phone Client',
            'phonenumber' => null,
            'phone' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/messaging/sms/recipients?search=Casey');

        $response->assertOk();
        $response->assertJsonFragment([
            'name' => 'Casey Photographer',
            'phone' => '+12025550144',
        ]);
        $response->assertJsonMissing(['name' => 'No Phone Client']);
    }

    public function test_admin_can_text_a_saved_group_and_skip_opted_out_members(): void
    {
        $this->bindTelnyxProviderMock();
        $this->createDefaultSmsNumber();

        $admin = User::factory()->create(['role' => 'admin']);
        $reachable = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Reachable Photographer',
            'phonenumber' => '2025550101',
        ]);
        Contact::create([
            'name' => 'Opted Out',
            'phone' => '+12025550199',
            'type' => 'client',
            'sms_opt_out' => true,
        ]);

        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/messaging/sms/groups', [
            'name' => 'Weekend crew',
            'members' => [
                ['user_id' => $reachable->id],
                ['phone' => '(202) 555-0199', 'name' => 'Opted Out'],
            ],
        ]);

        $created->assertCreated();
        $groupId = $created->json('id');
        $this->assertNotNull($groupId);
        $this->assertSame(2, SmsGroup::query()->find($groupId)?->members()->count());

        $response = $this->postJson('/api/messaging/sms/send', [
            'group_ids' => [$groupId],
            'body_text' => 'Call time is 8am.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('sent', 1);
        $response->assertJsonPath('failed', 1);
        $this->assertSame(1, Message::query()->where('channel', 'SMS')->where('status', 'SENT')->count());
        $this->assertSame('+12025550101', Message::query()->where('status', 'SENT')->value('to_address'));
    }

    public function test_sms_send_accepts_several_numbers_at_once(): void
    {
        $this->bindTelnyxProviderMock();
        $this->createDefaultSmsNumber();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/sms/send', [
            'to' => ['2025550108', '2025550109'],
            'body_text' => 'Both of you are confirmed.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('sent', 2);
        $addresses = Message::query()->orderBy('to_address')->pluck('to_address')->all();
        $this->assertSame(['+12025550108', '+12025550109'], $addresses);
    }

    private function createDefaultEmailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }

    private function createDefaultSmsNumber(): SmsNumber
    {
        return SmsNumber::create([
            'provider' => 'TELNYX',
            'phone_number' => '+18883426998',
            'label' => 'Telnyx Toll-Free',
            'telnyx_phone_number_id' => 'pn-uuid-test',
            'messaging_profile_id' => 'mp-uuid-test',
            'owner_type' => 'GLOBAL',
            'is_default' => true,
        ]);
    }

    private function bindTelnyxProviderMock(): void
    {
        $mock = Mockery::mock(TelnyxSmsProvider::class);
        $sequence = 0;
        $mock->shouldReceive('send')->andReturnUsing(function () use (&$sequence) {
            $sequence++;

            return 'telnyx-bulk-'.$sequence;
        });
        $this->app->instance(TelnyxSmsProvider::class, $mock);
    }
}
