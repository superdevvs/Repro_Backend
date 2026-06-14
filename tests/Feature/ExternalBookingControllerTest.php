<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalShootRequestedJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExternalBookingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_booking_queues_requested_shoot_side_effects_job(): void
    {
        Queue::fake([ProcessExternalShootRequestedJob::class]);

        config(['services.external_booking.api_key' => 'external-booking-test-key']);

        $service = Service::factory()->create([
            'name' => 'Exterior HDR Photos',
            'price' => 185.00,
        ]);

        $response = $this->withHeaders([
            'X-API-Key' => 'external-booking-test-key',
        ])->postJson('/api/external/book-shoot', [
            'client_name' => 'External Booking Client',
            'client_email' => 'external-booking-client@example.com',
            'client_phone' => '2025550199',
            'address' => '901 External Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $service->id, 'quantity' => 1],
            ],
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time' => '10:30',
            'source' => 'lovable',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Shoot request submitted successfully. It will be reviewed by our team.')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.is_new_client', true)
            ->assertJsonPath('data.account_created', true)
            ->assertJsonPath('data.is_guest_booking', false)
            ->assertJsonPath('data.account_setup_required', true);

        $shootId = (int) $response->json('data.shoot_id');

        $this->assertDatabaseHas('shoots', [
            'id' => $shootId,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'created_by' => 'External (lovable)',
        ]);

        $client = User::query()->where('email', 'external-booking-client@example.com')->first();
        $this->assertNotNull($client);
        $this->assertSame('client', $client->role);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'external-booking-client@example.com',
        ]);

        $this->assertDatabaseHas('client_email_verification_tokens', [
            'user_id' => $client->id,
            'issued_context' => 'external_booking',
        ]);

        Queue::assertPushed(ProcessExternalShootRequestedJob::class, function (ProcessExternalShootRequestedJob $job) use ($shootId) {
            return $job->shootId === $shootId
                && $job->afterCommit === true;
        });
    }

    public function test_external_booking_can_opt_out_of_creating_dashboard_account(): void
    {
        Queue::fake([ProcessExternalShootRequestedJob::class]);

        config(['services.external_booking.api_key' => 'external-booking-test-key']);

        $service = Service::factory()->create([
            'name' => 'Exterior HDR Photos',
            'price' => 185.00,
        ]);

        $response = $this->withHeaders([
            'X-API-Key' => 'external-booking-test-key',
        ])->postJson('/api/external/book-shoot', [
            'client_name' => 'External Guest Client',
            'client_email' => 'external-guest-client@example.com',
            'client_phone' => '2025550101',
            'address' => '902 External Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $service->id, 'quantity' => 1],
            ],
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '11:00',
            'source' => 'lovable',
            'create_account' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.is_new_client', false)
            ->assertJsonPath('data.account_created', false)
            ->assertJsonPath('data.account_setup_required', false)
            ->assertJsonPath('data.is_guest_booking', true);

        $shootId = (int) $response->json('data.shoot_id');
        $clientId = (int) $response->json('data.client_id');

        $this->assertDatabaseHas('shoots', [
            'id' => $shootId,
            'client_id' => $clientId,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'created_by' => 'External (lovable)',
        ]);

        $client = User::query()->find($clientId);
        $this->assertNotNull($client);
        $this->assertSame('external-guest-client@example.com', $client->email);
        $this->assertTrue((bool) ($client->metadata['guest_booking'] ?? false));
        $this->assertTrue((bool) ($client->metadata['dashboard_account_opted_out'] ?? false));
        $this->assertNotNull($client->locked_at);

        $this->assertDatabaseHas('shoot_ghost_users', [
            'shoot_id' => $shootId,
            'user_id' => $clientId,
        ]);

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'external-guest-client@example.com',
        ]);

        $this->assertSame(0, DB::table('client_email_verification_tokens')->where('user_id', $clientId)->count());

        Queue::assertPushed(ProcessExternalShootRequestedJob::class, function (ProcessExternalShootRequestedJob $job) use ($shootId) {
            return $job->shootId === $shootId
                && $job->afterCommit === true;
        });
    }
}
