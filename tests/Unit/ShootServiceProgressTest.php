<?php

namespace Tests\Unit;

use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\ShootUnit;
use App\Services\Aryeo\AryeoCatalog;
use App\Services\Shoots\ShootServiceProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootServiceProgressTest extends TestCase
{
    private function line(string $name = 'Photos', string $intake = 'photo', array $attributes = []): ShootService
    {
        $service = new Service(['name' => $name, 'upload_intake_type' => $intake, 'requires_editing' => $intake !== 'none']);
        $service->setRelation('category', new Category(['name' => $name]));
        return (new ShootService(['workflow_status' => 'pending', 'delivery_status' => 'not_started', ...$attributes]))->forceFill(['id' => 11])
            ->setRelation('service', $service)->setRelation('unit', null);
    }

    private function progress(ShootService $line, array $shoot = []): string
    {
        return app(ShootServiceProgress::class)->forItem(new Shoot(['status' => 'delivered', 'workflow_status' => 'delivered', ...$shoot]), $line);
    }

    public static function rowStates(): array
    {
        return [
            'pending on delivered shoot' => ['pending', 'not_started', 'pending'],
            'scheduled is still pending' => ['scheduled', 'not_started', 'pending'],
            'editing' => ['in_progress', 'not_started', 'in_editing'],
            'ready is not delivered' => ['ready', 'ready', 'ready'],
            'delivered' => ['delivered', 'delivered', 'delivered'],
            'delivery source' => ['in_progress', 'delivered', 'delivered'],
            'cancelled is not delivered' => ['cancelled', 'cancelled', 'cancelled'],
        ];
    }

    #[DataProvider('rowStates')]
    public function test_distinguishes_per_service_workflow_states(string $workflow, string $delivery, string $expected): void
    {
        $this->assertSame($expected, $this->progress($this->line(attributes: ['workflow_status' => $workflow, 'delivery_status' => $delivery])));
    }

    public function test_video_pending_editing_and_submitted_use_the_actual_video_assignment_lane(): void
    {
        $line = $this->line('Video', 'video');
        $this->assertSame('pending', $this->progress($line, ['status' => 'editing', 'workflow_status' => 'editing']));
        $line->editor_id = 7;
        $this->assertSame('in_editing', $this->progress($line, ['status' => 'editing', 'workflow_status' => 'editing']));
        $line->editing_completed_at = now();
        $this->assertSame('ready', $this->progress($line));
    }

    public function test_completing_photos_does_not_complete_the_video_half_of_a_package(): void
    {
        $line = $this->line('Photos & Video', 'photo_video', ['editor_id' => 7, 'video_editor_id' => 8, 'editing_completed_at' => now()]);
        $this->assertSame('in_editing', $this->progress($line, ['workflow_status' => 'editing']));
        $line->video_editing_completed_at = now();
        $this->assertSame('ready', $this->progress($line));
    }

    public function test_floorplans_draft_until_output_is_ready_and_are_not_falsely_delivered(): void
    {
        $line = $this->line('2D Floor plans', 'none');
        $this->assertSame('pending', $this->progress($line));
        $this->assertSame('drafting', $this->progress($line, ['cubicasa_status' => 'processing']));
        $this->assertSame('ready', $this->progress($line, ['iguide_floorplans' => [['url' => 'https://example.test/plan.pdf']]]));
        $line->delivery_status = 'delivered';
        $this->assertSame('delivered', $this->progress($line));
    }

    public function test_tour_processing_and_failed_provider_states(): void
    {
        $line = $this->line('Zillow 3D w/Floor plans', 'none');
        $this->assertSame('processing', $this->progress($line, ['cubicasa_status' => 'processing']));
        $this->assertSame('needs_attention', $this->progress($line, ['cubicasa_status' => 'failed']));
        $this->assertSame('ready', $this->progress($line, ['tour_links' => ['zillow_3d' => 'https://www.zillow.com/view-imx/example']]));
    }

    public function test_unit_and_line_provider_states_do_not_leak(): void
    {
        $line = $this->line('2D Floor plans', 'none', ['shoot_unit_id' => 2]);
        $unit = new ShootUnit(['provider_data' => ['cubicasa_service_line_id' => 99, 'cubicasa_floorplans' => [['url' => 'other-plan']],
            'lines' => [11 => ['cubicasa_status' => 'processing']]]]);
        $line->setRelation('unit', $unit);
        $this->assertSame('drafting', $this->progress($line, ['cubicasa_status' => 'ready', 'cubicasa_floorplans' => [['url' => 'whole-shoot-plan']]]));
        $unit->provider_data = [];
        $this->assertSame('pending', $this->progress($line, ['cubicasa_status' => 'processing']));
    }

    public function test_on_hold_does_not_undo_a_service_already_delivered(): void
    {
        $line = $this->line();
        $this->assertSame('on_hold', $this->progress($line, ['workflow_status' => 'on_hold']));
        $line->delivery_status = 'delivered';
        $this->assertSame('delivered', $this->progress($line, ['workflow_status' => 'on_hold']));
    }

    private function showcaseFixture(array $blockers = []): array
    {
        $ready = ['media_version' => 'version-current', 'blockers' => $blockers, 'assets' => [['id' => 1, 'type' => 'photos']], 'tours' => [['id' => 'zillow_3d']]];
        $this->mock(AryeoCatalog::class)->shouldReceive('readiness')->andReturn($ready);
        return [new Shoot(['status' => 'delivered']), $this->line('Zillow SHOWCASE Premium', 'photo_video'),
            new AryeoRequest(['request_id' => 'request-1', 'listing_id' => 'listing-1', 'discovery' => ['required' => []]]), $ready];
    }

    public function test_showcase_received_processing_and_waiting_for_links_and_plans(): void
    {
        [$shoot, $line, $order] = $this->showcaseFixture(['missing_tours', 'missing_floorplans']);
        $progress = app(ShootServiceProgress::class);
        $this->assertSame('received', $progress->forItem($shoot, $line));
        $this->assertSame('awaiting_assets', $progress->forItem($shoot, $line, $order));
        $this->assertSame('processing', $progress->forItem($shoot, $line, $order, new AryeoJob(['status' => 'running'])));
        $this->assertSame('needs_attention', $progress->forItem($shoot, $line, $order, new AryeoJob(['status' => 'failed'])));
    }

    public function test_only_a_current_verified_showcase_delivery_is_delivered(): void
    {
        [$shoot, $line, $order] = $this->showcaseFixture();
        $progress = app(ShootServiceProgress::class);
        $job = new AryeoJob(['status' => 'completed', 'media_version' => 'version-current', 'steps' => ['delivery' => 'success']]);
        $this->assertSame('received', $progress->forItem($shoot, $line, $order, $job));
        $job->receipt = ['listing_id' => 'listing-1', 'request_id' => 'request-1', 'media_version' => 'version-current',
            'verified_at' => now()->toIso8601String(), 'asset_ids' => [1], 'tour_ids' => ['zillow_3d']];
        $this->assertSame('delivered', $progress->forItem($shoot, $line, $order, $job));
        $job->status = 'followup_pending';
        $this->assertSame('delivered', $progress->forItem($shoot, $line, $order, $job));
        $job->media_version = 'version-old';
        $this->assertSame('received', $progress->forItem($shoot, $line, $order, $job));
        $job->media_version = 'version-current';
        $receipt = $job->receipt;
        $receipt['request_id'] = 'other-request';
        $job->receipt = $receipt;
        $this->assertSame('received', $progress->forItem($shoot, $line, $order, $job));
    }

    public function test_manual_showcase_receipt_requires_current_inventory_and_exact_assets(): void
    {
        [$shoot, $line, $order] = $this->showcaseFixture();
        $order->inventory_checked_at = now();
        $receipt = ['mode' => 'supervised', 'operation_id' => 'manual-1', 'listing_id' => 'listing-1', 'media_version' => 'version-current',
            'verified_at' => now()->toIso8601String(), 'asset_ids' => [1], 'tour_ids' => ['zillow_3d']];
        $inventory = ['complete' => true, 'assets' => [['id' => 'remote-1', 'type' => 'photos', 'dashboard_asset_id' => 1, 'delivered' => true],
            ['id' => 'remote-tour', 'type' => 'tours', 'delivered' => true]], 'manual_delivery_receipt' => $receipt];
        $order->inventory = $inventory;
        $this->assertSame('delivered', app(ShootServiceProgress::class)->forItem($shoot, $line, $order));
        $inventory['manual_delivery_receipt']['asset_ids'] = [999];
        $order->inventory = $inventory;
        $this->assertSame('received', app(ShootServiceProgress::class)->forItem($shoot, $line, $order));
    }
}
