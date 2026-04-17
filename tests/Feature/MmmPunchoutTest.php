<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MmmPunchoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $superadmin;
    protected User $editingManager;
    protected User $editor;
    protected User $client;
    protected User $photographer;
    protected User $salesRep;
    protected User $unassignedSalesRep;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'mmm-admin@test.com',
            'name' => 'MMM Admin',
            'username' => 'mmm-admin',
        ]);

        $this->superadmin = User::factory()->create([
            'role' => 'superadmin',
            'email' => 'mmm-superadmin@test.com',
            'name' => 'MMM Superadmin',
            'username' => 'mmm-superadmin',
        ]);

        $this->editingManager = User::factory()->create([
            'role' => 'editing_manager',
            'email' => 'mmm-editing-manager@test.com',
        ]);

        $this->editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'mmm-editor@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'email' => 'mmm-client@test.com',
            'name' => 'MMM Client',
            'username' => 'mmm-client',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'mmm-photographer@test.com',
        ]);

        $this->salesRep = User::factory()->create([
            'role' => 'salesRep',
            'email' => 'mmm-sales-rep@test.com',
            'name' => 'MMM Sales Rep',
            'username' => 'mmm-sales-rep',
        ]);

        $this->unassignedSalesRep = User::factory()->create([
            'role' => 'salesRep',
            'email' => 'mmm-sales-rep-unassigned@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'MMM Service',
            'price' => 150.00,
        ]);

        $this->configureMmm();
    }

    #[Test]
    public function admin_can_start_mmm_punchout_and_store_launch_metadata_without_a_template_number(): void
    {
        $redirectUrl = 'https://repro.mymarketingmatters.com/session/print-123';
        Http::fake([
            'https://repro.mymarketingmatters.com/*' => Http::response(
                $this->successfulPunchoutResponse($redirectUrl),
                200,
            ),
        ]);

        Sanctum::actingAs($this->admin);
        $shoot = $this->createShoot();

        $response = $this->postJson("/api/integrations/shoots/{$shoot->id}/mmm/punchout");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('redirect_url', $redirectUrl);

        $shoot->refresh();
        $session = $shoot->mmmPunchoutSessions()->latest()->first();

        $this->assertNotNull($session);
        $this->assertSame('redirect_ready', $session->status);
        $this->assertSame('Repro', $session->cost_center_number);
        $this->assertSame($this->admin->email, $session->employee_email);
        $this->assertSame($this->admin->username, $session->username);
        $this->assertSame('MMM', $session->first_name);
        $this->assertSame('Admin', $session->last_name);
        $this->assertNull($session->template_external_number);
        $this->assertSame($redirectUrl, $session->redirect_url);
        $this->assertSame('category', data_get($session->request_payload, 'payload.start_point'));

        $this->assertSame('punchout_ready', $shoot->mmm_status);
        $this->assertSame($redirectUrl, $shoot->mmm_redirect_url);
        $this->assertNull($shoot->mmm_last_error);
        $this->assertNotNull($shoot->mmm_buyer_cookie);
        $this->assertNotNull($shoot->mmm_last_punchout_at);

        Http::assertSent(function (ClientRequest $request) {
            $xml = (string) $request->body();
            $document = new \DOMDocument();
            $document->loadXML($xml);

            $supplierPartId = $document->getElementsByTagName('SupplierPartID')->item(0);

            return $request->url() === 'https://repro.mymarketingmatters.com/PunchoutSetup.asp'
                && str_contains($xml, 'name="UserFirstName"')
                && str_contains($xml, 'name="UserLastName"')
                && str_contains($xml, 'name="CostCenter">Repro</Extrinsic>')
                && $supplierPartId !== null
                && $supplierPartId->textContent === ''
                && !str_contains($xml, 'name="FirstName"')
                && !str_contains($xml, 'ArtworkURL')
                && !str_contains($xml, '<Properties>');
        });
    }

    #[Test]
    public function canonical_allowed_roles_can_start_mmm_punchout(): void
    {
        Http::fake([
            'https://repro.mymarketingmatters.com/*' => Http::response(
                $this->successfulPunchoutResponse('https://repro.mymarketingmatters.com/session/allowed'),
                200,
            ),
        ]);

        $cases = [
            ['user' => $this->admin, 'shoot' => $this->createShoot()],
            ['user' => $this->superadmin, 'shoot' => $this->createShoot()],
            ['user' => $this->client, 'shoot' => $this->createShoot()],
            ['user' => $this->salesRep, 'shoot' => $this->createShoot(['rep_id' => $this->salesRep->id])],
        ];

        foreach ($cases as $case) {
            Sanctum::actingAs($case['user']);

            $this->postJson("/api/integrations/shoots/{$case['shoot']->id}/mmm/punchout")
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        Http::assertSentCount(4);
    }

    #[Test]
    public function editing_manager_editor_and_photographer_cannot_start_mmm_punchout(): void
    {
        Http::fake();

        $shoot = $this->createShoot();

        foreach ([$this->editingManager, $this->editor, $this->photographer] as $user) {
            Sanctum::actingAs($user);

            $this->postJson("/api/integrations/shoots/{$shoot->id}/mmm/punchout")
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unassigned_sales_rep_cannot_start_mmm_punchout(): void
    {
        Http::fake();

        Sanctum::actingAs($this->unassignedSalesRep);
        $shoot = $this->createShoot(['rep_id' => $this->salesRep->id]);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/mmm/punchout")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function failed_punchout_records_the_error_without_clearing_the_existing_redirect_url(): void
    {
        Http::fake([
            'https://repro.mymarketingmatters.com/*' => Http::response(
                $this->failedPunchoutResponse('400', 'Rejected'),
                200,
            ),
        ]);

        Sanctum::actingAs($this->admin);
        $shoot = $this->createShoot([
            'mmm_status' => 'punchout_ready',
            'mmm_redirect_url' => 'https://repro.mymarketingmatters.com/session/existing',
            'mmm_buyer_cookie' => 'existing-cookie',
        ]);

        $response = $this->postJson("/api/integrations/shoots/{$shoot->id}/mmm/punchout");

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Rejected');

        $shoot->refresh();
        $session = $shoot->mmmPunchoutSessions()->latest()->first();

        $this->assertNotNull($session);
        $this->assertSame('error', $session->status);
        $this->assertSame('Rejected', $session->last_error);
        $this->assertNull($session->redirect_url);

        $this->assertSame('error', $shoot->mmm_status);
        $this->assertSame('https://repro.mymarketingmatters.com/session/existing', $shoot->mmm_redirect_url);
        $this->assertSame('Rejected', $shoot->mmm_last_error);
        $this->assertNotNull($shoot->mmm_buyer_cookie);
        $this->assertNotNull($shoot->mmm_last_punchout_at);
    }

    protected function configureMmm(array $overrides = []): void
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

    protected function createShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'rep_id' => $this->salesRep->id,
            'photographer_id' => $this->photographer->id,
            'editor_id' => $this->editor->id,
            'service_id' => $this->service->id,
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
        ], $overrides));
    }

    protected function successfulPunchoutResponse(string $redirectUrl): string
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

    protected function failedPunchoutResponse(string $statusCode, string $statusText): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE cXML SYSTEM "http://xml.cXML.org/schemas/cXML/1.2.007/cXML.dtd">
<cXML payloadID="response" timestamp="2026-04-17T00:00:00Z" version="1.0" xml:lang="en">
  <Response>
    <Status code="{$statusCode}" text="{$statusText}"/>
    <PunchOutSetupResponse>
      <StartPage/>
    </PunchOutSetupResponse>
  </Response>
</cXML>
XML;
    }
}
