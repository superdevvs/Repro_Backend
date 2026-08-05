<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shoot Overview (GET /api/shoots/{id}, served by ShootPresenter): the assigned
 * photographer sees the client's name always and the phone number only around
 * the appointment — two hours before the scheduled start, through the one hour
 * on-site buffer, plus two hours after it.
 *
 * @see \App\Services\Shoots\ShootClientContactVisibility
 */
class ShootOverviewClientPhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $photographer;
    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Overview Client',
            'phonenumber' => '202-555-0100',
        ]);
        $this->photographer = User::factory()->create(['role' => 'photographer']);
        // 2026-07-04 14:00 America/New_York === 18:00Z, so the window runs
        // 16:00Z (start - 2h) .. 21:00Z (start + 1h buffer + 2h).
        $this->shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'scheduled_at' => '2026-07-04 18:00:00',
            'scheduled_date' => '2026-07-04',
            'time' => '14:00',
            'timezone' => 'America/New_York',
        ]);
    }

    private function overviewClient(): array
    {
        Sanctum::actingAs($this->photographer);

        return $this->getJson("/api/shoots/{$this->shoot->id}")
            ->assertOk()
            ->json('data.client');
    }

    #[Test]
    public function the_photographer_sees_the_client_name_and_phone_inside_the_window(): void
    {
        $this->travelTo('2026-07-04 17:30:00');

        $client = $this->overviewClient();

        $this->assertSame('Overview Client', $client['name']);
        $this->assertSame('202-555-0100', $client['phone']);
    }

    #[Test]
    public function the_phone_is_withheld_before_and_after_the_window_while_the_name_remains(): void
    {
        $this->travelTo('2026-07-04 15:59:00');
        $beforeWindow = $this->overviewClient();
        $this->assertSame('Overview Client', $beforeWindow['name']);
        $this->assertNull($beforeWindow['phone']);

        $this->travelTo('2026-07-04 21:01:00');
        $this->assertNull($this->overviewClient()['phone']);
    }

    #[Test]
    public function the_window_boundaries_are_inclusive(): void
    {
        $this->travelTo('2026-07-04 16:00:00');
        $this->assertSame('202-555-0100', $this->overviewClient()['phone']);

        $this->travelTo('2026-07-04 21:00:00');
        $this->assertSame('202-555-0100', $this->overviewClient()['phone']);
    }
}
