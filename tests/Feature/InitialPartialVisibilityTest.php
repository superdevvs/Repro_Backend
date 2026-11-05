<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Services\Shoots\{ActiveWorkQuery, ShootActiveWorkLifecycle, ShootStatusFilter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue};
use Tests\TestCase;

class InitialPartialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
    }

    public function test_initial_partial_marker_uses_editing_filter_and_original_calendar_date(): void
    {
        $shoot = Shoot::factory()->create(['status' => 'active', 'workflow_status' => 'active', 'scheduled_date' => '2026-11-03',
            'active_work_date' => '2026-11-05', 'active_work_started_at' => '2026-11-05 10:00:00', 'active_work_completed_at' => null,
            'active_work_is_reopened' => false, 'admin_verified_at' => null, 'completed_at' => null]);
        $active = Shoot::whereKey($shoot->id); app(ShootStatusFilter::class)->apply($active, ['active']);
        $editing = Shoot::whereKey($shoot->id); app(ShootStatusFilter::class)->apply($editing, ['editing']);
        $this->assertFalse($active->exists());
        $this->assertTrue($editing->exists());
        $this->assertFalse(app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot));
        $workDate = Shoot::whereKey($shoot->id)->selectRaw('CASE WHEN '.ActiveWorkQuery::openSql().' THEN active_work_date ELSE scheduled_date END AS work_date')->first()->work_date;
        $this->assertSame('2026-11-03', \Carbon\Carbon::parse($workDate)->toDateString());
    }

    public function test_query_predicate_matches_canonical_cycle_for_legacy_initial_and_reopened_rows(): void
    {
        foreach ([[null, null, false], [null, '2026-11-02 10:00:00', true], [false, '2026-11-02 10:00:00', false], [true, null, true]] as [$flag, $firstDelivery, $open]) {
            $shoot = Shoot::factory()->create(['active_work_started_at' => '2026-11-05 10:00:00', 'active_work_completed_at' => null,
                'active_work_is_reopened' => $flag, 'admin_verified_at' => $firstDelivery, 'completed_at' => null]);
            $this->assertSame($open, app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot));
            $this->assertSame($open, Shoot::whereKey($shoot->id)->whereRaw(ActiveWorkQuery::openSql())->exists());
            $this->assertSame(! $open, Shoot::whereKey($shoot->id)->whereRaw(ActiveWorkQuery::closedSql())->exists());
        }
    }

    public function test_financial_fee_cannot_create_editing_work_or_keep_a_delivered_order_in_the_editor_queue(): void
    {
        $editor=\App\Models\User::factory()->create(['role' => 'editor']);
        $shoot=Shoot::factory()->create(['status'=>'delivered','workflow_status'=>'delivered','editor_id'=>$editor->id,
            'admin_verified_at'=>now()->subDay(),'completed_at'=>now()->subDay()]);
        $catalog=\App\Models\Service::factory()->create(['financial_only'=>true,'requires_editing'=>true]);
        $fee=$shoot->serviceItems()->create(['service_id'=>$catalog->id,'price'=>60,'quantity'=>1,'editor_id'=>$editor->id,
            'workflow_status'=>'pending','delivery_status'=>'not_started']);
        $this->assertFalse($fee->is_deliverable);
        $this->assertFalse(app(\App\Services\Shoots\ShootEditingAssignmentService::class)->serviceRequiresEditing($fee));
        $this->actingAs($editor)->getJson('/api/shoots?tab=completed&no_cache=1')->assertOk()->assertJsonPath('meta.count',0);
        $this->assertSame('delivered',$shoot->fresh()->workflow_status);
    }
}
