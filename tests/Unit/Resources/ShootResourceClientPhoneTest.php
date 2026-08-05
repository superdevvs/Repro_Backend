<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The client's phone number reaches an assigned photographer only inside the
 * shoot-time window: two hours before the scheduled start, through the one hour
 * on-site buffer, plus two hours after it.
 *
 * @see \App\Services\Shoots\ShootClientContactVisibility
 */
class ShootResourceClientPhoneTest extends TestCase
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
            'phone' => '555-0100',
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

    private function clientPayloadFor(User $user): array
    {
        $request = Request::create('/api/shoots/' . $this->shoot->id);
        $request->setUserResolver(fn () => $user);

        return (new ShootResource($this->shoot->fresh()))->toArray($request)['client'];
    }

    #[Test]
    public function it_exposes_the_client_phone_to_the_assigned_photographer_inside_the_window(): void
    {
        $this->travelTo('2026-07-04 17:30:00');

        $this->assertSame('555-0100', $this->clientPayloadFor($this->photographer)['phone']);

        // Boundaries are inclusive: exactly two hours before, and exactly two
        // hours after the on-site buffer ends.
        $this->travelTo('2026-07-04 16:00:00');
        $this->assertSame('555-0100', $this->clientPayloadFor($this->photographer)['phone']);

        $this->travelTo('2026-07-04 21:00:00');
        $this->assertSame('555-0100', $this->clientPayloadFor($this->photographer)['phone']);
    }

    #[Test]
    public function it_withholds_the_client_phone_from_photographers_outside_the_window(): void
    {
        $this->travelTo('2026-07-04 15:59:00');
        $payload = $this->clientPayloadFor($this->photographer);
        $this->assertNull($payload['phone']);
        // The name stays visible so the photographer still knows who they meet.
        $this->assertSame($this->client->name, $payload['name']);

        $this->travelTo('2026-07-04 21:01:00');
        $this->assertNull($this->clientPayloadFor($this->photographer)['phone']);
    }

    #[Test]
    public function it_withholds_the_client_phone_from_photographers_not_assigned_to_the_shoot(): void
    {
        $this->travelTo('2026-07-04 17:30:00');
        $otherPhotographer = User::factory()->create(['role' => 'photographer']);

        $this->assertNull($this->clientPayloadFor($otherPhotographer)['phone']);
    }

    #[Test]
    public function it_always_exposes_the_client_phone_to_admins_and_never_to_editors(): void
    {
        $this->travelTo('2026-07-10 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);

        $this->assertSame('555-0100', $this->clientPayloadFor($admin)['phone']);
        $this->assertNull($this->clientPayloadFor($editor)['phone']);
    }

    #[Test]
    public function it_exposes_the_phone_to_the_owning_client_only(): void
    {
        $this->travelTo('2026-07-10 09:00:00');
        $otherClient = User::factory()->create(['role' => 'client']);

        $this->assertSame('555-0100', $this->clientPayloadFor($this->client)['phone']);
        $this->assertNull($this->clientPayloadFor($otherClient)['phone']);
    }
}
