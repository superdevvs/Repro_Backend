<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleMiddleware;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CubiCasaIntegrationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public static function nonOperatorRoles(): array
    {
        return [
            'owning client' => ['client', true, []],
            'other client' => ['client', false, []],
            'assigned photographer' => ['photographer', true, []],
            'unassigned photographer' => ['photographer', false, []],
            'assigned editor' => ['editor', true, []],
            'unassigned editor' => ['editor', false, []],
            'assigned sales rep' => ['salesRep', true, []],
            'assigned rep alias' => ['rep', true, []],
            'representative alias' => ['representative', true, []],
            // Match the existing unit-provider operator helper, which uses
            // the primary role even though RoleMiddleware accepts secondary roles.
            'secondary office role only' => ['photographer', true, ['editing_manager']],
        ];
    }

    #[DataProvider('nonOperatorRoles')]
    public function test_non_operators_cannot_mutate_provider_identity_or_start_provider_work(
        string $role,
        bool $assigned,
        array $secondaryRoles
    ): void {
        [$actor, $shoot] = $this->fixture($role, $assigned, $secondaryRoles);
        Sanctum::actingAs($actor);

        $this->assertAllMutationsDenied($shoot);
    }

    public function test_controller_guard_remains_effective_without_role_middleware(): void
    {
        [$actor, $shoot] = $this->fixture('client', true);
        $this->withoutMiddleware(RoleMiddleware::class);
        Sanctum::actingAs($actor);

        $this->assertAllMutationsDenied($shoot);
    }

    public function test_editing_manager_cannot_mutate_an_import_draft_it_cannot_access(): void
    {
        [$actor, $shoot] = $this->fixture('editing_manager');
        $shoot->forceFill(['status' => Shoot::STATUS_IMPORT_DRAFT])->save();
        Sanctum::actingAs($actor);

        // Private draft scope deliberately hides its existence from this role.
        $this->assertAllMutationsDenied($shoot, 404);
    }

    public function test_unauthenticated_requests_cannot_reach_provider_work(): void
    {
        [, $shoot] = $this->fixture('admin');
        $before = $shoot->getRawOriginal();
        $this->expectNoProviderCalls();

        foreach ($this->requests() as [$action, $payload]) {
            $this->postJson($this->url($shoot, $action), $payload)->assertUnauthorized();
        }

        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function operatorRoles(): array
    {
        return [
            'admin' => ['admin'],
            'superadmin' => ['superadmin'],
            'editing manager' => ['editing_manager'],
            'hyphenated editing manager' => ['editing-manager'],
            'spaced editing manager' => ['Editing Manager'],
        ];
    }

    #[DataProvider('operatorRoles')]
    public function test_existing_primary_operator_roles_can_save_identifiers_and_queue_sync(string $role): void
    {
        [$actor, $shoot] = $this->fixture($role);
        Sanctum::actingAs($actor);

        $this->postJson($this->url($shoot, 'identifiers'), [
            'cubicasa_order_id' => 'new-order',
            'cubicasa_external_id' => null,
        ])->assertOk()->assertJsonPath('shoot.cubicasa_order_id', 'new-order');

        $this->assertSame('new-order', $shoot->fresh()->cubicasa_order_id);
        $this->assertNull($shoot->fresh()->cubicasa_external_id);

        $this->postJson($this->url($shoot, 'sync'), ['async' => true])
            ->assertAccepted()
            ->assertJsonPath('mode', 'queued');

        Queue::assertPushed(\App\Jobs\SyncCubiCasaShootJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_operator_authorization_does_not_hide_validation_or_missing_shoot_errors(): void
    {
        [$actor, $shoot] = $this->fixture('admin');
        Sanctum::actingAs($actor);
        $this->expectNoProviderCalls();

        $this->postJson($this->url($shoot, 'identifiers'), [
            'cubicasa_order_id' => ['invalid'],
        ])->assertUnprocessable()->assertJsonValidationErrors('cubicasa_order_id');

        foreach (['sync', 'order', 'identifiers'] as $action) {
            $this->postJson('/api/integrations/shoots/999999999/cubicasa/'.$action)
                ->assertNotFound();
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function assertAllMutationsDenied(Shoot $shoot, int $status = 403): void
    {
        $before = $shoot->getRawOriginal();
        $this->expectNoProviderCalls();

        foreach ($this->requests() as [$action, $payload]) {
            $this->postJson($this->url($shoot, $action), $payload)->assertStatus($status);
        }

        // Authorization must precede identifier validation as well as persistence.
        $this->postJson($this->url($shoot, 'identifiers'), [
            'cubicasa_order_id' => ['invalid'],
        ])->assertStatus($status);

        $this->assertSame($before, Shoot::withoutGlobalScopes()->findOrFail($shoot->id)->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function fixture(string $role, bool $assigned = false, array $secondaryRoles = []): array
    {
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();

        $actor = User::factory()->create(['role' => $role, 'secondary_roles' => $secondaryRoles]);
        $attributes = [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'cubicasa_order_id' => 'original-order',
            'cubicasa_external_id' => 'original-external',
            'cubicasa_sync_status' => null,
            'cubicasa_sync_job_id' => null,
            'cubicasa_sync_started_at' => null,
            'cubicasa_idempotency_key' => null,
        ];
        if ($assigned && $role === 'client') {
            $attributes['client_id'] = $actor->id;
        } elseif ($assigned && $role === 'photographer') {
            $attributes['photographer_id'] = $actor->id;
        } elseif ($assigned && in_array($role, ['salesRep', 'rep', 'representative'], true)) {
            $attributes['rep_id'] = $actor->id;
        }
        $shoot = Shoot::factory()->create($attributes);
        if ($assigned && $role === 'editor') {
            $service = Service::factory()->create(['name' => 'HDR Photos']);
            $shoot->services()->attach($service->id, [
                'price' => 100,
                'quantity' => 1,
                'editor_id' => $actor->id,
            ]);
        }

        // Only effects of the requested actions belong to the assertions below.
        Queue::fake();

        return [$actor, $shoot->fresh()];
    }

    private function expectNoProviderCalls(): void
    {
        $this->mock(CubiCasaService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('syncShoot', 'createOrder', 'isSyncInProgress',
                'markSyncQueued', 'markSyncFailed', 'syncStatePayload');
        });
    }

    private function requests(): array
    {
        return [
            ['sync', ['async' => true]],
            ['order', []],
            ['identifiers', ['cubicasa_order_id' => 'unauthorized-order', 'cubicasa_external_id' => null]],
        ];
    }

    private function url(Shoot $shoot, string $action): string
    {
        return "/api/integrations/shoots/{$shoot->id}/cubicasa/{$action}";
    }
}

