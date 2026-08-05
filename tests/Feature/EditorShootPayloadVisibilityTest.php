<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 14.5 (Req 6.6): an editor's shoot payload exposes editing notes only and
 * never service pricing. This locks in the ShootPresenter role gating that nulls
 * client/company/photographer notes and every pricing field for editors while
 * preserving editor_notes.
 */
class EditorShootPayloadVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function editor_payload_hides_pricing_and_shows_only_editing_notes(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create(['name' => 'Photos', 'price' => 200]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'editor_id' => $editor->id,
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
            'shoot_notes' => 'Gate code 1234',
            'company_notes' => 'Internal dispatch note',
            'photographer_notes' => 'Bring a drone',
            'editor_notes' => 'Prioritize twilight tones',
            'base_quote' => 200,
            'total_quote' => 200,
        ]);
        // Per-service editor assignment so the editor sees the service line.
        $shoot->services()->attach($service->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => 90,
            'editor_id' => $editor->id,
        ]);

        $this->actingAs($editor);

        $presented = app(ShootPresenter::class)->transformShoot($shoot->fresh());

        // Only the editing note survives for an editor (Req 6.6).
        $this->assertSame('Prioritize twilight tones', $presented->editor_notes);
        $this->assertNull($presented->shoot_notes);
        $this->assertNull($presented->company_notes);
        $this->assertNull($presented->photographer_notes);

        // Editors never receive service pricing or photographer pay.
        $services = $presented->getAttribute('services');
        $this->assertNotEmpty($services, 'The assigned editor should see the service line.');
        foreach ($services as $svc) {
            $this->assertNull($svc['price'], 'Service price must be hidden from editors.');
            $this->assertNull($svc['photographer_pay'], 'Photographer pay must be hidden from editors.');
        }
    }
}
