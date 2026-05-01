<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\MmmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MmmServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function build_punchout_payload_prefers_formatted_property_address_and_falls_back_to_shoot_fields(): void
    {
        $this->configureMmm();

        $formattedShoot = $this->createShoot([
            'address' => '123 Formatted Ave, Annapolis, MD 21401',
            'property_details' => [
                'address' => [
                    'formatted' => '123 Formatted Ave, Annapolis, MD 21401',
                ],
            ],
        ]);
        $fallbackShoot = $this->createShoot([
            'address' => '250 MMM Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'property_details' => [],
        ]);

        $service = app(MmmService::class);

        $formattedPayload = $service->buildPunchoutPayload($formattedShoot);
        $fallbackPayload = $service->buildPunchoutPayload($fallbackShoot);

        $this->assertSame('123 Formatted Ave, Annapolis, MD 21401', $formattedPayload['address']);
        $this->assertSame('123 Formatted Ave, Annapolis, MD 21401', $formattedPayload['property']['address']);
        $this->assertSame('250 MMM Lane, Baltimore, MD 21201', $fallbackPayload['address']);
        $this->assertSame('250 MMM Lane', $fallbackPayload['property']['address']);
        $this->assertSame('Baltimore', $fallbackPayload['property']['city']);
        $this->assertSame('MD', $fallbackPayload['property']['state']);
        $this->assertSame('21201', $fallbackPayload['property']['zip']);
    }

    #[Test]
    public function build_punchout_payload_includes_mmm_property_metadata_from_the_shoot(): void
    {
        $this->configureMmm();

        $shoot = $this->createShoot([
            'mls_id' => 'WAFGX96178',
            'property_details' => [
                'list_price' => '$1,225,000',
                'description' => 'Welcome to 123 Development Dr.',
            ],
        ]);

        $payload = app(MmmService::class)->buildPunchoutPayload($shoot->fresh());

        $this->assertSame('WAFGX96178', $payload['property']['id']);
        $this->assertSame('1225000', $payload['property']['price']);
        $this->assertSame('Welcome to 123 Development Dr.', $payload['property']['description']);
        $this->assertArrayNotHasKey('artwork_url', $payload);
    }

    #[Test]
    public function build_punchout_payload_only_honors_selected_file_ids_when_they_reference_edited_images(): void
    {
        $this->configureMmm();
        Storage::fake('public');

        $shoot = $this->createShoot();
        $uploader = User::factory()->create();
        $verifiedPath = 'shoots/' . $shoot->id . '/verified-front.jpg';
        $completedPath = 'shoots/' . $shoot->id . '/completed-kitchen.jpg';
        $rawOnlyPath = 'shoots/' . $shoot->id . '/raw-only.jpg';

        Storage::disk('public')->put($verifiedPath, 'front');
        Storage::disk('public')->put($completedPath, 'kitchen');
        Storage::disk('public')->put($rawOnlyPath, 'raw');

        $verifiedImage = ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'verified-front.jpg',
            'stored_filename' => 'verified-front.jpg',
            'path' => $verifiedPath,
            'storage_path' => $verifiedPath,
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'uploaded_by' => $uploader->id,
            'workflow_stage' => ShootFile::STAGE_VERIFIED,
        ]);

        $completedImage = ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'completed-kitchen.jpg',
            'stored_filename' => 'completed-kitchen.jpg',
            'path' => $completedPath,
            'storage_path' => $completedPath,
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'uploaded_by' => $uploader->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $rawOnlyImage = ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'raw-only.jpg',
            'stored_filename' => 'raw-only.jpg',
            'path' => $rawOnlyPath,
            'storage_path' => $rawOnlyPath,
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'uploaded_by' => $uploader->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $service = app(MmmService::class);

        $fallbackPayload = $service->buildPunchoutPayload($shoot->fresh(), [
            'file_ids' => [$rawOnlyImage->id],
        ]);
        $selectedPayload = $service->buildPunchoutPayload($shoot->fresh(), [
            'file_ids' => [$completedImage->id],
        ]);

        $this->assertCount(2, $fallbackPayload['property']['pictures']);
        $this->assertSame(
            ['verified-front.jpg', 'completed-kitchen.jpg'],
            array_column($fallbackPayload['property']['pictures'], 'filename'),
        );
        $this->assertSame(
            ['completed-kitchen.jpg'],
            array_column($selectedPayload['property']['pictures'], 'filename'),
        );
        $this->assertNotContains($rawOnlyImage->stored_filename, array_column($fallbackPayload['property']['pictures'], 'filename'));
        $this->assertContains($verifiedImage->stored_filename, array_column($fallbackPayload['property']['pictures'], 'filename'));
    }

    #[Test]
    public function validate_config_allows_a_missing_template_external_number(): void
    {
        $this->configureMmm();

        $this->assertNull(app(MmmService::class)->validateConfig());
    }

    #[Test]
    public function send_punchout_request_retries_with_form_encoding_if_raw_xml_is_rejected(): void
    {
        $this->configureMmm();

        Http::fakeSequence()
            ->push('Rejected raw XML', 400)
            ->push($this->successfulPunchoutResponse('https://repro.mymarketingmatters.com/session/retried'), 200);

        $result = app(MmmService::class)->sendPunchoutRequest([
            'duns' => 'Wqsw5cPn3Neo9Blz',
            'shared_secret' => 'XxdU9n5pP8bWb4DG',
            'user_agent' => 'REPro Photos Tests',
            'buyer_cookie' => '00000000-0000-0000-0000-000000000001',
            'cost_center_number' => 'Repro',
            'employee_email' => 'print-admin@example.com',
            'username' => 'print-admin@example.com',
            'first_name' => 'Print',
            'last_name' => 'Admin',
            'start_point' => 'category',
            'template_external_number' => null,
            'deployment_mode' => 'test',
            'url_return' => 'https://app.test/api/integrations/mmm/return',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://repro.mymarketingmatters.com/session/retried', $result['redirect_url']);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://repro.mymarketingmatters.com/PunchoutSetup.asp'
                && $request->hasHeader('Content-Type', 'text/xml; charset=UTF-8');
        });

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://repro.mymarketingmatters.com/PunchoutSetup.asp'
                && ($request['xml'] ?? null) !== null;
        });
    }

    #[Test]
    public function validate_config_falls_back_to_env_when_database_settings_are_blank(): void
    {
        $this->configureMmm();

        DB::table('settings')->insert([
            'key' => 'integrations.mmm',
            'value' => json_encode([
                'enabled' => true,
                'duns' => 'Wqsw5cPn3Neo9Blz',
                'sharedSecret' => '',
                'punchoutUrl' => 'https://repro.mymarketingmatters.com/PunchoutSetup.asp',
            ], JSON_THROW_ON_ERROR),
            'type' => 'json',
            'description' => 'MMM settings for test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(app(MmmService::class)->validateConfig());
    }

    #[Test]
    public function send_punchout_request_resolves_relative_redirect_urls_against_the_mmm_host(): void
    {
        $this->configureMmm();

        Http::fake([
            'https://repro.mymarketingmatters.com/*' => Http::response(
                $this->successfulPunchoutResponse('ProductCats.asp?cid=&amp;el=abc123'),
                200,
            ),
        ]);

        $result = app(MmmService::class)->sendPunchoutRequest([
            'duns' => 'Wqsw5cPn3Neo9Blz',
            'shared_secret' => 'XxdU9n5pP8bWb4DG',
            'user_agent' => 'REPro Photos Tests',
            'buyer_cookie' => '00000000-0000-0000-0000-000000000001',
            'cost_center_number' => 'Repro',
            'employee_email' => 'print-admin@example.com',
            'username' => 'print-admin@example.com',
            'first_name' => 'Print',
            'last_name' => 'Admin',
            'start_point' => 'category',
            'template_external_number' => null,
            'deployment_mode' => 'test',
            'url_return' => 'https://app.test/api/integrations/mmm/return',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'https://repro.mymarketingmatters.com/ProductCats.asp?cid=&el=abc123',
            $result['redirect_url'],
        );
    }

    private function configureMmm(array $overrides = []): void
    {
        config(array_merge([
            'services.mmm.enabled' => true,
            'services.mmm.duns' => 'Wqsw5cPn3Neo9Blz',
            'services.mmm.shared_secret' => 'XxdU9n5pP8bWb4DG',
            'services.mmm.user_agent' => 'REPro Photos Tests',
            'services.mmm.punchout_url' => 'https://repro.mymarketingmatters.com/PunchoutSetup.asp',
            'services.mmm.template_external_number' => null,
            'services.mmm.deployment_mode' => 'test',
            'services.mmm.start_point' => 'category',
            'services.mmm.url_return' => 'https://app.test/api/integrations/mmm/return',
            'services.mmm.to_identity' => '',
            'services.mmm.sender_identity' => '',
            'services.mmm.timeout' => 20,
        ], $overrides));
    }

    private function createShoot(array $overrides = []): Shoot
    {
        $client = User::factory()->create(['role' => 'client']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $editor = User::factory()->create(['role' => 'editor']);
        $service = Service::factory()->create([
            'name' => 'MMM Service',
            'price' => 150.00,
        ]);

        return Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'photographer_id' => $photographer->id,
            'editor_id' => $editor->id,
            'service_id' => $service->id,
            'address' => '250 MMM Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 150,
            'tax_amount' => 9,
            'total_quote' => 159,
            'payment_status' => 'paid',
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
            'property_details' => [],
        ], $overrides));
    }

    private function successfulPunchoutResponse(string $redirectUrl): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE cXML SYSTEM "http://xml.cXML.org/schemas/cXML/1.2.007/cXML.dtd">
<cXML payloadID="response" timestamp="2026-04-17T00:00:00Z" version="1.0" xml:lang="en">
  <Response>
    <Status code="200" text="OK"/>
    <PunchOutSetupResponse>
      <StartPage>
        <URL>{$redirectUrl}</URL>
      </StartPage>
    </PunchOutSetupResponse>
  </Response>
</cXML>
XML;
    }
}
