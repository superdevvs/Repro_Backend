<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\BracketModeResolver;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootServiceItemSupport;
use App\Services\Shoots\UploadIntakeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Naming a booked service is presentation; deciding who may work it is workflow.
 *
 * These were the same code path. The service-item payload is narrowed by editing
 * eligibility, and the gallery used it to label subgroups, so a file an editor was
 * allowed to see but not allowed to edit had no resolvable name and rendered as
 * "Service #<id>". Splitting the two is what these tests pin — along with the
 * guarantee that splitting them did not widen what an editor may actually do.
 *
 * The second half covers the provider integrations. Upload intake and provider
 * eligibility are independent predicates, and excluding floor-plan / iGuide work
 * from raw camera intake must not disable the automations that deliver it.
 */
class ServicePresentationAndProviderEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name): Category
    {
        return Category::query()->firstOrCreate(['name' => $name]);
    }

    private function service(array $attributes): Service
    {
        return Service::query()->create(array_merge([
            'description' => 'Fixture',
            'price' => 100,
            'delivery_time' => 24,
            'pricing_type' => 'fixed',
        ], $attributes));
    }

    private function item(
        Shoot $shoot,
        Service $service,
        ?int $bracketMode = null,
        ?int $editorId = null
    ): ShootService {
        return ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => $bracketMode,
            'editor_id' => $editorId,
        ]);
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ], $attributes));
    }

    /** Editable photo work. */
    private function hdrService(): Service
    {
        return $this->service([
            'name' => '10 Exterior HDR Photos',
            'category_id' => $this->category('Photos')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => true,
            'photo_count' => 10,
            'requires_editing' => true,
        ]);
    }

    /**
     * Real photo capture that the editing lanes never touch. This is the row that
     * produced "Service #<id>": its files reach the editor, its name did not.
     */
    private function droneService(): Service
    {
        return $this->service([
            'name' => '10-12 Drone Photos Package',
            'category_id' => $this->category('Drone')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => false,
            'photo_count' => 10,
            'quantity' => 10,
            'requires_editing' => false,
        ]);
    }

    private function floorPlanService(): Service
    {
        return $this->service([
            'name' => '2D Floor plans',
            'category_id' => $this->category('Floor Plans')->id,
            'upload_intake_type' => Service::INTAKE_NONE,
            'uses_hdr_brackets' => false,
            'photo_count' => null,
            'requires_editing' => false,
        ]);
    }

    // ---------------------------------------------------------------- labels

    public function test_presentation_names_every_booked_service_including_non_editing_ones(): void
    {
        $shoot = $this->shoot();
        $this->item($shoot, $this->hdrService(), 5);
        $this->item($shoot, $this->droneService());
        $this->item($shoot, $this->floorPlanService());

        $presentation = collect(app(ShootServiceItemSupport::class)->presentation($shoot->fresh()));

        $this->assertSame(
            ['10 Exterior HDR Photos', '10-12 Drone Photos Package', '2D Floor plans'],
            $presentation->pluck('name')->sort()->values()->all()
        );

        // Keyed by execution row so the gallery can resolve a file's shoot_service_id.
        foreach ($presentation as $row) {
            $this->assertNotNull($row['shoot_service_id']);
            $this->assertSame($row['shoot_service_id'], $row['shootServiceId']);
            $this->assertNotNull($row['name']);
        }
    }

    public function test_presentation_carries_no_pricing_or_assignment_data(): void
    {
        // Naming is not a permission, so this projection is never role-filtered.
        // That is only safe while it stays free of anything worth withholding.
        $shoot = $this->shoot();
        $this->item($shoot, $this->hdrService(), 5);

        $row = app(ShootServiceItemSupport::class)->presentation($shoot->fresh())[0];

        foreach ([
            'price', 'subtotal', 'paid_amount', 'balance_due', 'payment_status',
            'photographer_pay', 'photographer_id', 'editor_id', 'workflow_status',
            'delivery_status',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, $forbidden.' must not be exposed');
        }

        // An exact allowlist, so adding a field to this projection has to be a
        // deliberate decision rather than something that leaks in with a refactor.
        $this->assertEqualsCanonicalizing(
            ['shoot_service_id', 'shootServiceId', 'service_id', 'serviceId', 'name', 'serviceName'],
            array_keys($row)
        );
    }

    public function test_editor_receives_real_names_while_service_items_stay_filtered(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->shoot(['editor_id' => $editor->id]);

        $hdr = $this->hdrService();
        $drone = $this->droneService();
        // The editor is assigned the photo lane row; the drone row is nobody's to edit.
        $hdrItem = $this->item($shoot, $hdr, 5, $editor->id);
        $droneItem = $this->item($shoot, $drone);

        $response = $this->actingAs($editor)->getJson('/api/shoots/'.$shoot->id);
        $response->assertOk();

        $payload = $response->json('data') ?? $response->json();

        $presentation = collect($payload['servicePresentation'] ?? [])->keyBy('shoot_service_id');
        $serviceItems = collect($payload['serviceItems'] ?? [])->keyBy('shoot_service_id');

        // The fix: the drone row is nameable.
        $this->assertSame('10-12 Drone Photos Package', $presentation[$droneItem->id]['name'] ?? null);
        $this->assertSame('10 Exterior HDR Photos', $presentation[$hdrItem->id]['name'] ?? null);

        // Permissions unchanged: the operational payload still excludes it.
        $this->assertTrue($serviceItems->has($hdrItem->id), 'editable service must remain');
        $this->assertFalse(
            $serviceItems->has($droneItem->id),
            'non-editing service must stay out of the operational payload'
        );
    }

    public function test_editor_service_and_file_permissions_are_unchanged_by_the_label_fix(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->shoot(['editor_id' => $editor->id]);

        $hdr = $this->hdrService();
        $drone = $this->droneService();
        $this->item($shoot, $hdr, 5, $editor->id);
        $this->item($shoot, $drone, null, $editor->id);

        $visible = app(ShootEditingAssignmentService::class)
            ->filterServicesForEditor($shoot->fresh(['services']), $editor)
            ->pluck('name')
            ->all();

        // Still exactly the editable service: the eligibility rule was not touched.
        $this->assertSame(['10 Exterior HDR Photos'], $visible);
        $this->assertNotContains('10-12 Drone Photos Package', $visible);
    }

    public function test_admin_payload_also_exposes_presentation(): void
    {
        // The gallery uses one label source for every role.
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->shoot();
        $droneItem = $this->item($shoot, $this->droneService());

        $payload = $this->actingAs($admin)->getJson('/api/shoots/'.$shoot->id)->assertOk();
        $body = $payload->json('data') ?? $payload->json();

        $presentation = collect($body['servicePresentation'] ?? [])->keyBy('shoot_service_id');
        $this->assertSame('10-12 Drone Photos Package', $presentation[$droneItem->id]['name'] ?? null);
    }

    // ------------------------------------------------------- raw intake gate

    public function test_floor_plan_and_iguide_stay_out_of_raw_intake_and_owe_nothing(): void
    {
        $shoot = $this->shoot();
        $hdrItem = $this->item($shoot, $this->hdrService(), 5);
        $planItem = $this->item($shoot, $this->floorPlanService());
        $iguideItem = $this->item($shoot, $this->service([
            'name' => 'Premium iGuide with Floor plans',
            'category_id' => $this->category('3D/360 Tours')->id,
            'upload_intake_type' => Service::INTAKE_NONE,
            'uses_hdr_brackets' => false,
            'photo_count' => null,
            'requires_editing' => false,
        ]));

        $intake = app(UploadIntakeResolver::class);
        $brackets = app(BracketModeResolver::class);

        $this->assertTrue($intake->supportsPhotoIntake($hdrItem->fresh()));
        $this->assertFalse($intake->supportsPhotoIntake($planItem->fresh()));
        $this->assertFalse($intake->supportsPhotoIntake($iguideItem->fresh()));

        // Neither contributes a fake raw expectation.
        $this->assertSame(0, $brackets->expectedRawForService($planItem->fresh()));
        $this->assertSame(0, $brackets->expectedRawForService($iguideItem->fresh()));
        // 10 finals at 5x, and nothing added by the two provider rows.
        $this->assertSame(50, $brackets->expectedRawForShoot($shoot->fresh()));

        $eligible = $intake->eligibleItemsForLane($shoot->fresh(), Service::LANE_PHOTO)
            ->pluck('service_id')
            ->all();
        $this->assertSame([$hdrItem->service_id], $eligible);
    }

    // ------------------------------------------- provider automation intact

    public function test_a_floor_plan_service_excluded_from_raw_intake_is_still_provider_eligible(): void
    {
        // The whole point of the split: intake is a capability, provider
        // eligibility is a product question. A floor plan owes no camera raw and
        // is still the thing both providers exist to deliver.
        $shoot = $this->shoot();
        $plan = $this->floorPlanService();
        $item = $this->item($shoot, $plan);

        $this->assertFalse(app(UploadIntakeResolver::class)->supportsPhotoIntake($item->fresh()));

        $fresh = $shoot->fresh(['services']);
        $this->assertTrue($fresh->hasCubiCasaEligibleService());
        $this->assertTrue($fresh->hasIguideEligibleService());
    }

    public function test_cubicasa_order_is_still_dispatched_for_a_non_intake_floor_plan_service(): void
    {
        // Asserted at the dispatch boundary with a faked queue: no HTTP call and
        // therefore no billable provider order.
        Queue::fake();

        $shoot = $this->shoot([
            'scheduled_at' => now()->addDays(3),
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
        ]);
        $this->item($shoot, $this->floorPlanService());

        // Re-save so the observer sees a lifecycle transition on a now-eligible shoot.
        $shoot->fresh()->forceFill(['workflow_status' => Shoot::STATUS_SCHEDULED])->save();
        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(4)])->save();

        Queue::assertPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_no_cubicasa_order_for_a_photo_only_shoot(): void
    {
        Queue::fake();

        $shoot = $this->shoot(['scheduled_at' => now()->addDays(3)]);
        $this->item($shoot, $this->hdrService(), 5);

        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(4)])->save();

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_an_hdr_and_iguide_package_splits_photo_intake_from_provider_delivery(): void
    {
        // One catalogue row, one execution row, two independent answers.
        $shoot = $this->shoot();
        $bundle = $this->service([
            'name' => 'HDR Photos & Premium iGuide',
            'category_id' => $this->category('Packages')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => true,
            'photo_count' => 30,
            'requires_editing' => true,
        ]);
        $item = $this->item($shoot, $bundle, 5);

        // Photo half: a real raw upload target with real expectations.
        $intake = app(UploadIntakeResolver::class);
        $this->assertTrue($intake->supportsPhotoIntake($item->fresh()));
        $this->assertSame(150, app(BracketModeResolver::class)->expectedRawForService($item->fresh()));

        // iGuide half: still routed to the dedicated provider workflow.
        $this->assertTrue($shoot->fresh(['services'])->hasIguideEligibleService());
    }

    public function test_provider_eligibility_does_not_depend_on_intake_capability(): void
    {
        // Same service name, flipped intake capability: provider eligibility must
        // not move. This is the regression that would fire if someone rewired
        // provider eligibility onto upload_intake_type.
        foreach ([Service::INTAKE_NONE, Service::INTAKE_PHOTO] as $intakeType) {
            $shoot = $this->shoot();
            $this->item($shoot, $this->service([
                'name' => '2D Floor plans '.$intakeType,
                'category_id' => $this->category('Floor Plans')->id,
                'upload_intake_type' => $intakeType,
                'requires_editing' => false,
            ]));

            $fresh = $shoot->fresh(['services']);
            $this->assertTrue($fresh->hasCubiCasaEligibleService(), $intakeType);
            $this->assertTrue($fresh->hasIguideEligibleService(), $intakeType);
        }
    }
}
