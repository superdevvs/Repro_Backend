<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportLegacyShootRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_previews_then_preserves_pending_details_without_notifications_and_replays_safely(): void
    {
        Bus::fake();
        Notification::fake();
        $input = [
            'company_id' => 1626, 'request_id' => 215319,
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'rep_id' => User::factory()->create(['role' => 'salesRep'])->id,
            'photographer_id' => User::factory()->create(['role' => 'photographer'])->id,
            'service_id' => Service::factory()->create()->id,
            'address' => '28003 Gosling Court', 'city' => 'Mechanicsville', 'state' => 'MD', 'zip' => '20659',
            'preferred_date' => '2026-10-08', 'preferred_time' => '15:00', 'timezone' => 'America/New_York',
            'base_quote' => 100, 'tax_amount' => 6, 'total_quote' => 106,
            'notes' => 'Exterior photos now; interior work later.', 'property_details' => ['sqft' => 4033],
        ];
        $path = tempnam(sys_get_temp_dir(), 'legacy-request-');
        try {
            file_put_contents($path, json_encode($input));
            $this->artisan('import:legacy-shoot-request', ['path' => $path])->assertSuccessful();
            $this->assertDatabaseCount('shoots', 0);
            $this->assertDatabaseCount('external_booking_submissions', 0);
            $this->artisan('import:legacy-shoot-request', ['path' => $path, '--apply' => true])->assertSuccessful();
            $shoot = Shoot::withoutGlobalScopes()->firstOrFail();
            $this->assertSame('requested', $shoot->status);
            $this->assertSame('requested', $shoot->workflow_status);
            $this->assertEquals(106, $shoot->total_quote);
            $this->assertEquals(6, $shoot->tax_amount);
            $this->assertSame('2026-10-08 19:00:00', $shoot->scheduled_at->format('Y-m-d H:i:s'));
            $this->assertSame($input['notes'], $shoot->shoot_notes);
            $this->assertEquals($input['rep_id'], $shoot->rep_id);
            $this->assertEquals(100, $shoot->services->first()->pivot->price);
            $this->assertTrue($shoot->external_booking_payload['legacy_migration']['notifications_suppressed']);
            $this->artisan('import:legacy-shoot-request', ['path' => $path, '--apply' => true])->assertSuccessful();
            $this->assertDatabaseCount('shoots', 1);
            $this->assertDatabaseCount('external_booking_submissions', 1);
            Bus::assertNothingDispatched();
            Notification::assertNothingSent();
            $input['total_quote'] = 107;
            $input['tax_amount'] = 7;
            file_put_contents($path, json_encode($input));
            try {
                $this->artisan('import:legacy-shoot-request', ['path' => $path, '--apply' => true])->run();
                $this->fail('A changed recovery payload must not overwrite the original request.');
            } catch (ValidationException $exception) {
                $this->assertSame(409, $exception->status);
            }
            $this->assertDatabaseCount('shoots', 1);
            $this->assertEquals(106, $shoot->fresh()->total_quote);
        } finally {
            unlink($path);
        }
    }
}
