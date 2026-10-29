<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootUpdateEmailChangeSummaryTest extends TestCase
{
    use RefreshDatabase;

    public static function serviceSummarySchedules(): array
    {
        return [
            'reported October assignment change' => ['America/New_York', 'America/New_York', '2026-10-30 14:30:00', 'Oct 30, 2026 at 10:30 AM', 'Oct 30, 2026 at 10:30 AM', false],
            'winter offset' => ['America/New_York', 'America/New_York', '2026-11-02 15:30:00', 'Nov 2, 2026 at 10:30 AM', 'Nov 2, 2026 at 10:30 AM', false],
            'local date crosses midnight' => ['America/Los_Angeles', 'America/Los_Angeles', '2026-10-30 02:30:00', 'Oct 29, 2026 at 7:30 PM', 'Oct 29, 2026 at 7:30 PM', false],
            'each side uses its own timezone' => ['America/New_York', 'America/Chicago', '2026-10-30 14:30:00', 'Oct 30, 2026 at 10:30 AM', 'Oct 30, 2026 at 9:30 AM', false],
            'legacy wall clock stays unchanged' => [null, null, '2026-10-30 10:30:00', 'Oct 30, 2026 at 10:30 AM', 'Oct 30, 2026 at 10:30 AM', false],
            'explicit legacy snapshot does not adopt new timezone' => [null, 'America/New_York', '2026-10-30 14:30:00', 'Oct 30, 2026 at 2:30 PM', 'Oct 30, 2026 at 10:30 AM', false],
            'older snapshot without timezone uses shoot timezone' => ['America/New_York', 'America/New_York', '2026-10-30 14:30:00', 'Oct 30, 2026 at 10:30 AM', 'Oct 30, 2026 at 10:30 AM', true],
        ];
    }

    #[DataProvider('serviceSummarySchedules')]
    public function test_assignment_change_summary_formats_both_service_times_in_the_property_timezone(
        ?string $beforeTimezone, ?string $afterTimezone, string $storedTime,
        string $beforeLabel, string $afterLabel, bool $olderSnapshot
    ): void {
        config(['app.timezone' => 'UTC']);
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $previous = User::factory()->photographer()->create(['name' => 'Previous Photographer']);
        $current = User::factory()->photographer()->create(['name' => 'Current Photographer']);
        $service = Service::factory()->create(['name' => 'Exterior HDR Photos', 'price' => 100]);
        $shoot = Shoot::factory()->create(['timezone' => $beforeTimezone, 'photographer_id' => $previous->id]);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1,
            'photographer_id' => $previous->id, 'scheduled_at' => $storedTime]);
        $mail = app(MailService::class);
        $before = $mail->captureShootSnapshot($shoot);
        if ($olderSnapshot) unset($before['timezone']);

        $shoot->forceFill(['timezone' => $afterTimezone, 'photographer_id' => $current->id])->save();
        $shoot->services()->updateExistingPivot($service->id, ['photographer_id' => $current->id]);
        $summary = $mail->buildShootChangeSummary($before, $shoot);
        $old = 'Photographer: Previous Photographer - Time: '.$beforeLabel;
        $new = 'Photographer: Current Photographer - Time: '.$afterLabel;
        $this->assertStringContainsString($old, $summary['summary']);
        $this->assertStringContainsString($new, $summary['summary']);
        $html = view('emails.partials.change-summary', ['changesSummary' => $summary['summary']])->render();
        $this->assertStringContainsString($old, html_entity_decode(strip_tags($html)));
        $this->assertStringContainsString($new, html_entity_decode(strip_tags($html)));
        $this->assertSame($storedTime, $shoot->serviceItems()->sole()->getRawOriginal('scheduled_at'));
        $this->assertSame([], $mail->buildShootChangeSummary($mail->captureShootSnapshot($shoot), $shoot)['lines']);
    }

    public function test_shoot_update_email_summary_lists_schedule_service_and_editable_field_changes(): void
    {
        $client = User::factory()->create(['name' => 'Client One']);
        $oldSharedClient = User::factory()->create(['name' => 'Old Shared Client', 'role' => 'client']);
        $newSharedClient = User::factory()->create(['name' => 'New Shared Client', 'role' => 'client']);
        $oldPhotographer = User::factory()->photographer()->create(['name' => 'Old Photographer']);
        $newPhotographer = User::factory()->photographer()->create(['name' => 'New Photographer']);
        $oldEditor = User::factory()->create(['name' => 'Old Editor', 'role' => 'editor']);
        $newEditor = User::factory()->create(['name' => 'New Editor', 'role' => 'editor']);

        $hdr = Service::factory()->create(['name' => 'HDR Photos', 'price' => 175]);
        $floorPlan = Service::factory()->create(['name' => 'Floor Plan', 'price' => 125]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $oldPhotographer->id,
            'status' => 'scheduled',
            'workflow_status' => 'scheduled',
            'scheduled_date' => '2026-07-01',
            'scheduled_at' => '2026-07-01 14:00:00',
            'time' => '10:00',
            'timezone' => 'America/New_York',
            'address' => '100 Old St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 175,
            'discount_type' => null,
            'discount_value' => null,
            'discount_amount' => 0,
            'tax_amount' => 10,
            'total_quote' => 185,
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'product_status' => Shoot::PRODUCT_STATUS_HAS_PRODUCT,
            'listing_type' => 'for_sale',
            'property_status' => 'available',
            'mls_id' => 'MLS-OLD',
            'mls_image_width' => 2048,
            'iguide_property_id' => 'IG-PROP-OLD',
            'iguide_work_order_id' => 'IG-WO-OLD',
            'shoot_notes' => 'Old notes',
            'company_notes' => 'Old company notes',
            'photographer_notes' => 'Old photographer notes',
            'editor_notes' => 'Old editor notes',
            'is_private_listing' => false,
            'is_listing_hidden' => false,
            'is_featured' => false,
            'featured_homepage_title' => 'Old Featured Title',
            'featured_homepage_location' => 'Old Featured Location',
            'featured_homepage_subtitle' => 'Old Featured Subtitle',
            'featured_homepage_cta_label' => 'Old CTA',
            'featured_homepage_cta_href' => 'https://example.com/old',
            'property_details' => [
                'bedrooms' => 3,
                'bathrooms' => 2,
                'sqft' => 1200,
                'presenceOption' => 'owner_home',
                'accessContactName' => 'Old Contact',
                'accessContactPhone' => '202-555-0100',
                'lockboxCode' => '1234',
                'lockboxLocation' => 'Front door',
            ],
            'tour_links' => ['property_mls' => 'OLDMLS'],
        ]);
        $shoot->ghostUsers()->attach($oldSharedClient->id);

        $shoot->services()->attach($hdr->id, [
            'price' => 175,
            'quantity' => 1,
            'photographer_id' => $oldPhotographer->id,
            'editor_id' => $oldEditor->id,
            'scheduled_at' => '2026-07-01 14:00:00',
            'workflow_status' => 'scheduled',
            'delivery_status' => 'not_started',
            'is_deliverable' => true,
        ]);

        $mailService = app(MailService::class);
        $before = $mailService->captureShootSnapshot($shoot);

        $shoot->forceFill([
            'photographer_id' => $newPhotographer->id,
            'status' => 'confirmed',
            'workflow_status' => 'in_progress',
            'scheduled_date' => '2026-07-02',
            'scheduled_at' => '2026-07-02 19:30:00',
            'time' => '14:30',
            'timezone' => 'America/Chicago',
            'address' => '200 New Ave',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'base_quote' => 425,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'discount_amount' => 42.50,
            'tax_amount' => 20,
            'total_quote' => 402.50,
            'shoot_type' => Shoot::SHOOT_TYPE_PRICING_PENDING,
            'product_status' => Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT,
            'listing_type' => 'for_rent',
            'property_status' => 'pending',
            'mls_id' => 'MLS-NEW',
            'mls_image_width' => 4096,
            'iguide_property_id' => 'IG-PROP-NEW',
            'iguide_work_order_id' => 'IG-WO-NEW',
            'shoot_notes' => 'New notes',
            'company_notes' => 'New company notes',
            'photographer_notes' => 'New photographer notes',
            'editor_notes' => 'New editor notes',
            'is_private_listing' => true,
            'is_listing_hidden' => true,
            'is_featured' => true,
            'featured_homepage_title' => 'New Featured Title',
            'featured_homepage_location' => 'New Featured Location',
            'featured_homepage_subtitle' => 'New Featured Subtitle',
            'featured_homepage_cta_label' => 'New CTA',
            'featured_homepage_cta_href' => 'https://example.com/new',
            'property_details' => [
                'bedrooms' => 4,
                'bathrooms' => 2.5,
                'sqft' => 1500,
                'presenceOption' => 'lockbox',
                'accessContactName' => 'New Contact',
                'accessContactPhone' => '202-555-0200',
                'lockboxCode' => '9876',
                'lockboxLocation' => 'Side door',
            ],
            'tour_links' => ['property_mls' => 'NEWMLS'],
        ])->save();
        $shoot->ghostUsers()->sync([$newSharedClient->id]);

        $shoot->services()->sync([
            $hdr->id => [
                'price' => 200,
                'quantity' => 2,
                'photographer_id' => $newPhotographer->id,
                'editor_id' => $newEditor->id,
                'scheduled_at' => '2026-07-02 19:30:00',
                'workflow_status' => 'in_progress',
                'delivery_status' => 'ready',
                'is_deliverable' => false,
            ],
            $floorPlan->id => [
                'price' => 125,
                'quantity' => 1,
                'photographer_id' => $newPhotographer->id,
                'editor_id' => $newEditor->id,
                'scheduled_at' => '2026-07-02 20:30:00',
                'workflow_status' => 'scheduled',
                'delivery_status' => 'not_started',
                'is_deliverable' => true,
            ],
        ]);

        $summary = $mailService->buildShootChangeSummary($before, $shoot->fresh(['client', 'photographer', 'services']));
        $html = view('emails.partials.change-summary', ['changesSummary' => $summary['summary']])->render();

        $this->assertStringContainsString('Status: Scheduled', $summary['summary']);
        $this->assertStringContainsString('Workflow Status: Scheduled', $summary['summary']);
        $this->assertStringContainsString('Schedule: Jul 1, 2026 at 10:00 AM', $summary['summary']);
        $this->assertStringContainsString('Jul 2, 2026 at 2:30 PM', $summary['summary']);
        $this->assertStringContainsString('Timezone: America/New_York', $summary['summary']);
        $this->assertStringContainsString('Location: 100 Old St, Baltimore, MD 21201', $summary['summary']);
        $this->assertStringContainsString('Services: HDR Photos ($175.00) - Photographer: Old Photographer - Editor: Old Editor - Time: Jul 1, 2026 at 10:00 AM - Workflow: Scheduled - Delivery: Not Started - Deliverable: Yes', $summary['summary']);
        $this->assertStringContainsString('HDR Photos x2 ($400.00) - Photographer: New Photographer - Editor: New Editor - Time: Jul 2, 2026 at 2:30 PM - Workflow: In Progress - Delivery: Ready - Deliverable: No', $summary['summary']);
        $this->assertStringContainsString('Floor Plan ($125.00) - Photographer: New Photographer - Editor: New Editor - Time: Jul 2, 2026 at 3:30 PM', $summary['summary']);
        $this->assertStringContainsString('Photographer: Old Photographer', $summary['summary']);
        $this->assertStringContainsString('Base Quote: $175.00', $summary['summary']);
        $this->assertStringContainsString('Discount Type: Percent', $summary['summary']);
        $this->assertStringContainsString('Discount Value: 10.00%', $summary['summary']);
        $this->assertStringContainsString('Discount Amount: $0.00', $summary['summary']);
        $this->assertStringContainsString('Tax: $10.00', $summary['summary']);
        $this->assertStringContainsString('Total: $185.00', $summary['summary']);
        $this->assertStringContainsString('Shoot Type: Standard', $summary['summary']);
        $this->assertStringContainsString('Product Status: Has Product', $summary['summary']);
        $this->assertStringContainsString('Listing Type: For Sale', $summary['summary']);
        $this->assertStringContainsString('Property Status: Available', $summary['summary']);
        $this->assertStringContainsString('MLS ID: MLS-OLD', $summary['summary']);
        $this->assertStringContainsString('MLS Image Width: 2,048', $summary['summary']);
        $this->assertStringContainsString('iGUIDE Property ID: IG-PROP-OLD', $summary['summary']);
        $this->assertStringContainsString('iGUIDE Work Order ID: IG-WO-OLD', $summary['summary']);
        $this->assertStringContainsString('Shoot Notes: Old notes', $summary['summary']);
        $this->assertStringContainsString('Company Notes: Old company notes', $summary['summary']);
        $this->assertStringContainsString('Photographer Notes: Old photographer notes', $summary['summary']);
        $this->assertStringContainsString('Editor Notes: Old editor notes', $summary['summary']);
        $this->assertStringContainsString('Bedrooms: 3', $summary['summary']);
        $this->assertStringContainsString('Bathrooms: 2.0', $summary['summary']);
        $this->assertStringContainsString('Square Footage: 1,200 sqft', $summary['summary']);
        $this->assertStringContainsString('Access Type: Owner Home', $summary['summary']);
        $this->assertStringContainsString('Access Contact Name: Old Contact', $summary['summary']);
        $this->assertStringContainsString('Access Contact Phone: 202-555-0100', $summary['summary']);
        $this->assertStringContainsString('Lockbox Code: 1234', $summary['summary']);
        $this->assertStringContainsString('Lockbox Location: Front door', $summary['summary']);
        $this->assertStringContainsString('Private Listing: No', $summary['summary']);
        $this->assertStringContainsString('Listing Hidden: No', $summary['summary']);
        $this->assertStringContainsString('Featured: No', $summary['summary']);
        $this->assertStringContainsString('Featured Homepage Title: Old Featured Title', $summary['summary']);
        $this->assertStringContainsString('Featured Homepage Location: Old Featured Location', $summary['summary']);
        $this->assertStringContainsString('Featured Homepage Subtitle: Old Featured Subtitle', $summary['summary']);
        $this->assertStringContainsString('Featured Homepage CTA Label: Old CTA', $summary['summary']);
        $this->assertStringContainsString('Featured Homepage CTA Link: https://example.com/old', $summary['summary']);
        $this->assertStringContainsString('Shared Client Access: Old Shared Client', $summary['summary']);
        $this->assertStringContainsString('Tour Links: Updated', $summary['summary']);

        $this->assertStringContainsString('What changed', $html);
        $this->assertStringContainsString('Before', $html);
        $this->assertStringContainsString('After', $html);
        $this->assertStringContainsString('Services', $html);
        $this->assertStringContainsString('HDR Photos x2 ($400.00) - Photographer: New Photographer - Editor: New Editor', $html);
        $this->assertStringContainsString('Tour Links', $html);
    }
}
