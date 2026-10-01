<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardActionRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_and_workflow_preserve_pending_requests_and_clear_resolved_dates(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_at' => null, 'time' => '10:00', 'timezone' => 'America/New_York',
            'cancellation_requested_at' => now()->subHour(),
            'cancellation_reason' => 'Sellers requested cancellation',
            'hold_requested_at' => now()->subMinutes(30),
            'hold_requested_by' => $admin->id,
            'hold_reason' => 'Waiting for staging',
        ]));

        $payload = $this->actingAs($admin)->getJson('/api/dashboard/overview')->assertOk()->json('data');
        $upcoming = collect($payload['upcoming_shoots'])->firstWhere('id', $shoot->id);
        $workflow = collect($payload['workflow']['columns'])->flatMap(fn ($column) => $column['shoots'])->firstWhere('id', $shoot->id);
        foreach ([$upcoming, $workflow] as $summary) {
            $this->assertNotNull($summary);
            $this->assertNotNull($summary['cancellation_requested_at']);
            $this->assertSame('Sellers requested cancellation', $summary['cancellation_reason']);
            $this->assertNotNull($summary['hold_requested_at']);
            $this->assertSame($admin->id, $summary['hold_requested_by']);
            $this->assertSame('Waiting for staging', $summary['hold_reason']);
        }

        $shoot->forceFill(['cancellation_requested_at' => null, 'hold_requested_at' => null, 'hold_requested_by' => null])->saveQuietly();
        Cache::flush();
        $resolved = collect($this->getJson('/api/dashboard/overview')->assertOk()->json('data.upcoming_shoots'))->firstWhere('id', $shoot->id);
        $this->assertNull($resolved['cancellation_requested_at']);
        $this->assertNull($resolved['hold_requested_at']);
        $this->assertNull($resolved['hold_requested_by']);
        $this->assertSame('Sellers requested cancellation', $resolved['cancellation_reason']);
    }
}
