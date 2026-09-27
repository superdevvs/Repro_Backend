<?php

namespace Tests\Feature\Auth;

use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePhoneNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_replacement_notifies_both_numbers_after_profile_is_saved(): void
    {
        $user = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550101']);
        $deliveries = [];
        $this->mock(MessagingService::class)->shouldReceive('sendSms')->twice()
            ->andReturnUsing(function (array $payload) use ($user, &$deliveries): Message {
                $this->assertSame('+12025550102', $user->fresh()->phonenumber);
                $deliveries[] = $payload;

                return new Message;
            });
        $this->withToken($user->createToken('profile')->plainTextToken)
            ->putJson('/api/profile', ['phone_number' => '+12025550102'])->assertOk();

        $this->assertSame(['+12025550101', '+12025550102'], array_column($deliveries, 'to'));
        $this->assertSame(['PHONE_NUMBER_CHANGED_PREVIOUS', 'PHONE_NUMBER_CHANGED_NEW'], array_column($deliveries, 'send_source'));
    }

    public function test_unchanged_or_formatting_only_phone_and_other_profile_edits_do_not_text(): void
    {
        $user = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550101']);
        $this->mock(MessagingService::class)->shouldReceive('sendSms')->never();
        $token = $user->createToken('profile')->plainTextToken;
        foreach ([['name' => 'Updated name'], ['phonenumber' => '+12025550101'], ['phone_number' => '(202) 555-0101']] as $payload) {
            $this->withToken($token)->putJson('/api/profile', $payload)->assertOk();
        }
    }
}
