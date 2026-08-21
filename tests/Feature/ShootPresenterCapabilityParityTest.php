<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootPresenterCapabilityParityTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function list_and_detail_presenter_payloads_share_verification_and_raw_submit_capability(): void
    {
        $client = User::factory()->unverified()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'raw_photo_count' => 1,
        ]);
        $this->actingAs($photographer);

        $presenter = app(ShootPresenter::class);
        $listPayload = $presenter->transformOperationalShoot($shoot->fresh(), false);
        $detailPayload = $presenter->transformShoot($shoot->fresh())->toArray();
        $resourceRequest = Request::create('/api/shoots/'.$shoot->id, 'GET');
        $resourceRequest->setUserResolver(fn () => $photographer);
        $resourcePayload = (new ShootResource($shoot->fresh()))->resolve($resourceRequest);

        foreach ([$listPayload, $detailPayload] as $payload) {
            $this->assertFalse($payload['client']['email_verified']);
            $this->assertFalse($payload['client']['emailVerified']);
            $this->assertArrayNotHasKey('email_health', $payload['client']);
            $this->assertArrayNotHasKey('email_verified_at', $payload['client']);
            $this->assertTrue($payload['can_submit_raw']);
            $this->assertTrue($payload['canSubmitRaw']);
            $this->assertFalse($payload['can_view_invoice']);
            $this->assertFalse($payload['canViewInvoice']);
        }

        $this->assertSame($resourcePayload['can_submit_raw'], $detailPayload['can_submit_raw']);
        $this->assertSame($resourcePayload['canSubmitRaw'], $detailPayload['canSubmitRaw']);
        $this->assertSame($resourcePayload['can_view_invoice'], $detailPayload['can_view_invoice']);
        $this->assertSame($resourcePayload['canViewInvoice'], $detailPayload['canViewInvoice']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function client_shoot_detail_omits_eager_loaded_user_diagnostics(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $rep = User::factory()->create([
            'role' => 'salesRep',
            'email_status' => 'bounced',
            'email_verified_at' => now(),
        ]);
        $verifier = User::factory()->create([
            'role' => 'admin',
            'email_status' => 'verified',
            'email_verified_at' => now(),
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $rep->id,
            'verified_by' => $verifier->id,
        ]);
        Sanctum::actingAs($client);

        $payload = $this->getJson('/api/shoots/'.$shoot->id)
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('rep', $payload);
        $this->assertArrayNotHasKey('created_by_user', $payload);
        $this->assertArrayNotHasKey('workflow_logs', $payload);
        $this->assertArrayNotHasKey('company_notes', $payload);
        $this->assertArrayNotHasKey('photographer_notes', $payload);
        $this->assertArrayNotHasKey('editor_notes', $payload);
        $this->assertSame($verifier->id, $payload['verified_by']);
        $this->assertIsNotArray($payload['verified_by']);
        $this->assertStringNotContainsString('email_health', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('email_verified_at', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
