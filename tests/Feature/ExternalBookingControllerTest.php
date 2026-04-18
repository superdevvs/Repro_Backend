<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalShootRequestedJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonPath('data.status', 'requested');

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

        Queue::assertPushed(ProcessExternalShootRequestedJob::class, function (ProcessExternalShootRequestedJob $job) use ($shootId) {
            return $job->shootId === $shootId
                && $job->afterCommit === true;
        });
    }
}
