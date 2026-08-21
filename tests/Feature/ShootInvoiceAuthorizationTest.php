<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootInvoiceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_permission_is_independent_from_shoot_and_ghost_media_access(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $linkedViewer = User::factory()->create(['role' => 'client']);
        $ghost = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $owner->id,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $shoot->ghostUsers()->attach($ghost->id);
        $link = AccountLink::create([
            'main_account_id' => $linkedViewer->id,
            'linked_account_id' => $owner->id,
            'shared_details' => ['shoots' => true, 'invoices' => false],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($linkedViewer);
        $this->getJson("/api/shoots/{$shoot->id}/invoice")->assertForbidden();
        $this->assertSame(0, Invoice::count());

        Sanctum::actingAs($ghost);
        $this->getJson("/api/shoots/{$shoot->id}/invoice")->assertForbidden();
        $this->assertSame(0, Invoice::count());

        $link->update(['shared_details' => ['shoots' => false, 'invoices' => true]]);
        Sanctum::actingAs($linkedViewer);
        $this->getJson("/api/shoots/{$shoot->id}/invoice")->assertOk();
        $this->assertSame(1, Invoice::count());
    }

    public function test_owner_can_view_but_photographer_cannot_generate_client_invoice(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $owner->id,
            'photographer_id' => $photographer->id,
        ]);

        Sanctum::actingAs($photographer);
        $this->getJson("/api/shoots/{$shoot->id}/invoice")->assertForbidden();
        $this->assertSame(0, Invoice::count());

        Sanctum::actingAs($owner);
        $this->getJson("/api/shoots/{$shoot->id}/invoice")->assertOk();
        $this->assertSame(1, Invoice::count());
    }
}
