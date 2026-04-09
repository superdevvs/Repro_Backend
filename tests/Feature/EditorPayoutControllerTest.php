<?php

namespace Tests\Feature;

use App\Models\EditorPayout;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EditorPayoutControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_view_editor_earnings_queue_and_detail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 10:00:00'));

        $admin = User::factory()->admin()->create();
        $editor = User::factory()->create([
            'role' => 'editor',
            'name' => 'Eva Cutter',
            'email' => 'eva@example.com',
            'metadata' => [
                'service_rates' => [
                    [
                        'service_id' => '1',
                        'service_name' => '25 Photos',
                        'rate' => 4.5,
                    ],
                ],
                'photo_edit_rate' => 4.5,
            ],
        ]);

        $service = Service::factory()->create([
            'id' => 1,
            'name' => '25 Photos',
        ]);

        $shoot = $this->createEditedShoot($editor, $service);

        Sanctum::actingAs($admin);

        $queueResponse = $this->getJson('/api/admin/editors/earnings');
        $queueResponse->assertOk();
        $queueResponse->assertJsonPath('data.0.editor.name', 'Eva Cutter');
        $queueResponse->assertJsonPath('data.0.total_earned', 112.5);
        $queueResponse->assertJsonPath('summary.unpaid_amount', 112.5);

        $detailResponse = $this->getJson('/api/admin/editors/' . $editor->id . '/earnings-detail');
        $detailResponse->assertOk();
        $detailResponse->assertJsonPath('data.editor.name', 'Eva Cutter');
        $detailResponse->assertJsonPath('data.summary.total_earned', 112.5);
        $detailResponse->assertJsonPath('data.line_items.0.service_name', '25 Photos');
        $detailResponse->assertJsonPath('data.line_items.0.quantity_snapshot', 25);
        $detailResponse->assertJsonPath('data.line_items.0.rate_snapshot', 4.5);

        $this->assertDatabaseHas('editor_payouts', [
            'editor_id' => $editor->id,
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'quantity_snapshot' => 25,
        ]);
    }

    public function test_admin_can_mark_editor_earnings_paid_without_recomputing_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 10:00:00'));

        $admin = User::factory()->admin()->create();
        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => [
                'service_rates' => [
                    [
                        'service_id' => '101',
                        'service_name' => 'Video Edit',
                        'rate' => 80,
                    ],
                ],
                'video_edit_rate' => 80,
            ],
        ]);

        $service = Service::factory()->create([
            'id' => 101,
            'name' => 'Video Edit',
        ]);

        $this->createEditedShoot($editor, $service, 2);

        Sanctum::actingAs($admin);
        $detailResponse = $this->getJson('/api/admin/editors/' . $editor->id . '/earnings-detail');
        $payoutId = $detailResponse->json('data.line_items.0.id');

        $response = $this->postJson('/api/admin/editors/payouts/mark-paid', [
            'payout_ids' => [$payoutId],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.updated_count', 1);
        $response->assertJsonPath('data.total_paid', 160);

        $this->assertDatabaseHas('editor_payouts', [
            'id' => $payoutId,
            'is_paid' => true,
            'paid_by' => $admin->id,
            'rate_snapshot' => 80.00,
            'payout_amount' => 160.00,
        ]);

        $editor->update([
            'metadata' => [
                'service_rates' => [
                    [
                        'service_id' => '101',
                        'service_name' => 'Video Edit',
                        'rate' => 120,
                    ],
                ],
                'video_edit_rate' => 120,
            ],
        ]);

        $this->assertDatabaseHas('editor_payouts', [
            'id' => $payoutId,
            'rate_snapshot' => 80.00,
            'payout_amount' => 160.00,
        ]);
    }

    public function test_editor_can_view_their_own_earnings_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 10:00:00'));

        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => [
                'service_rates' => [
                    [
                        'service_id' => '202',
                        'service_name' => 'Virtual Staging',
                        'rate' => 35,
                    ],
                ],
                'virtual_staging_rate' => 35,
            ],
        ]);
        $service = Service::factory()->create([
            'id' => 202,
            'name' => 'Virtual Staging',
        ]);

        $this->createEditedShoot($editor, $service);

        Sanctum::actingAs($editor);

        $response = $this->getJson('/api/editor/earnings');
        $response->assertOk();
        $response->assertJsonPath('data.editor.id', $editor->id);
        $response->assertJsonPath('data.summary.total_earned', 35);
    }

    private function createEditedShoot(User $editor, Service $service, int $quantity = 1): Shoot
    {
        $client = User::factory()->create(['role' => 'client']);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'editor_id' => $editor->id,
            'editing_completed_at' => '2026-04-03 15:00:00',
            'completed_at' => '2026-04-03 15:30:00',
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
        ]);

        $shoot->services()->attach($service->id, [
            'price' => 125,
            'quantity' => $quantity,
            'editor_id' => $editor->id,
            'editing_completed_at' => '2026-04-03 15:00:00',
        ]);

        return $shoot->fresh();
    }
}
