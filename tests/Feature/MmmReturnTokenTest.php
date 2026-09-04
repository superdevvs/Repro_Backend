<?php

namespace Tests\Feature;

use App\Models\MmmPunchoutSession;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MmmReturnTokenTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $salesRep;
    protected User $photographer;
    protected User $editor;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'mmm-token-admin@test.com',
            'name' => 'MMM Token Admin',
            'username' => 'mmm-token-admin',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'email' => 'mmm-token-client@test.com',
            'name' => 'MMM Token Client',
            'username' => 'mmm-token-client',
        ]);

        $this->salesRep = User::factory()->create([
            'role' => 'salesRep',
            'email' => 'mmm-token-sales@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'mmm-token-photo@test.com',
        ]);

        $this->editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'mmm-token-editor@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'MMM Token Service',
            'price' => 150.00,
        ]);

        config([
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
        ]);
    }

    #[Test]
    public function mmm_punchout_stores_return_token_and_appends_it_to_url_return(): void
    {
        Http::fake([
            'https://repro.mymarketingmatters.com/*' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE cXML SYSTEM "http://xml.cXML.org/schemas/cXML/1.2.007/cXML.dtd">
<cXML payloadID="response" timestamp="2026-04-17T00:00:00Z" version="1.0" xml:lang="en">
  <Response>
    <Status code="200" text="OK"/>
    <PunchOutSetupResponse>
      <StartPage>
        <URL>https://repro.mymarketingmatters.com/session/print-token</URL>
      </StartPage>
    </PunchOutSetupResponse>
  </Response>
</cXML>
XML
                ,
                200,
            ),
        ]);

        Sanctum::actingAs($this->admin);
        $shoot = $this->createShoot();

        $response = $this->postJson("/api/integrations/shoots/{$shoot->id}/mmm/punchout");
        $response->assertOk()->assertJsonPath('success', true);

        $session = $shoot->mmmPunchoutSessions()->latest()->first();
        $this->assertNotNull($session);
        $this->assertNotEmpty($session->return_token);
        $this->assertGreaterThanOrEqual(64, strlen($session->return_token));

        $urlReturn = data_get($session->request_payload, 'payload.url_return');
        $this->assertIsString($urlReturn);
        $this->assertStringContainsString('return_token=' . $session->return_token, $urlReturn);
    }

    #[Test]
    public function mmm_return_without_token_returns_401_and_does_not_mutate(): void
    {
        $session = $this->makeSession([
            'return_token' => Str::random(64),
            'buyer_cookie' => 'cookie-token-1',
        ]);

        $response = $this->postJson('/api/integrations/mmm/return', [
            'xml' => $this->orderXml('cookie-token-1', 'ORD-TOKEN-1'),
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('mmm_punchout_sessions', [
            'id' => $session->id,
            'status' => 'redirect_ready',
        ]);
        $this->assertDatabaseHas('shoots', [
            'id' => $session->shoot_id,
            'mmm_status' => 'punchout_ready',
        ]);
    }

    #[Test]
    public function mmm_return_with_mismatched_token_returns_401_and_does_not_mutate(): void
    {
        $session = $this->makeSession([
            'return_token' => Str::random(64),
            'buyer_cookie' => 'cookie-token-2',
        ]);

        $response = $this->postJson('/api/integrations/mmm/return?return_token=definitely-wrong-token', [
            'xml' => $this->orderXml('cookie-token-2', 'ORD-TOKEN-2'),
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('mmm_punchout_sessions', [
            'id' => $session->id,
            'status' => 'redirect_ready',
        ]);
    }

    #[Test]
    public function mmm_return_without_buyer_cookie_returns_401_and_does_not_mutate(): void
    {
        $token = Str::random(64);
        $session = $this->makeSession([
            'return_token' => $token,
            'buyer_cookie' => 'cookie-token-missing',
        ]);

        $response = $this->postJson('/api/integrations/mmm/return?return_token=' . urlencode($token), [
            'xml' => $this->orderXmlWithoutBuyerCookie('ORD-TOKEN-MISSING'),
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('mmm_punchout_sessions', [
            'id' => $session->id,
            'status' => 'redirect_ready',
        ]);
        $this->assertDatabaseHas('shoots', [
            'id' => $session->shoot_id,
            'mmm_status' => 'punchout_ready',
        ]);
    }

    #[Test]
    public function mmm_return_with_mismatched_buyer_cookie_returns_401_and_does_not_mutate(): void
    {
        $token = Str::random(64);
        $session = $this->makeSession([
            'return_token' => $token,
            'buyer_cookie' => 'cookie-token-expected',
        ]);

        // Unrelated session that must not be selected via unconstrained latest().
        $this->makeSession([
            'return_token' => Str::random(64),
            'buyer_cookie' => 'cookie-other-session',
            'order_number' => 'ORD-OTHER',
        ]);

        $response = $this->postJson('/api/integrations/mmm/return?return_token=' . urlencode($token), [
            'xml' => $this->orderXml('cookie-token-wrong', 'ORD-TOKEN-WRONG'),
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('mmm_punchout_sessions', [
            'id' => $session->id,
            'status' => 'redirect_ready',
        ]);
        $this->assertDatabaseHas('shoots', [
            'id' => $session->shoot_id,
            'mmm_status' => 'punchout_ready',
        ]);
    }

    #[Test]
    public function mmm_return_with_matching_cookie_and_token_updates_session_and_shoot(): void
    {
        $token = Str::random(64);
        $session = $this->makeSession([
            'return_token' => $token,
            'buyer_cookie' => 'cookie-token-3',
            'order_number' => 'ORD-TOKEN-3',
        ]);

        $response = $this->postJson('/api/integrations/mmm/return?return_token=' . urlencode($token), [
            'xml' => $this->orderXml('cookie-token-3', 'ORD-TOKEN-3'),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('shoot_id', $session->shoot_id);

        $this->assertDatabaseHas('mmm_punchout_sessions', [
            'id' => $session->id,
            'status' => 'returned',
            'order_number' => 'ORD-TOKEN-3',
        ]);
        $this->assertDatabaseHas('shoots', [
            'id' => $session->shoot_id,
            'mmm_status' => 'order_returned',
            'mmm_order_number' => 'ORD-TOKEN-3',
        ]);
    }

    protected function makeSession(array $overrides = []): MmmPunchoutSession
    {
        $shoot = $this->createShoot([
            'mmm_status' => 'punchout_ready',
        ]);

        return MmmPunchoutSession::create(array_merge([
            'shoot_id' => $shoot->id,
            'user_id' => $this->admin->id,
            'buyer_cookie' => 'cookie-abc',
            'order_number' => 'ORD-1',
            'status' => 'redirect_ready',
            'return_token' => Str::random(64),
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

    protected function orderXml(string $buyerCookie = 'cookie-abc', string $orderNumber = 'ORD-1'): string
    {
        return <<<XML
<?xml version="1.0"?>
<cXML>
  <Request>
    <PunchOutOrderMessage>
      <BuyerCookie>{$buyerCookie}</BuyerCookie>
      <PunchOutOrderMessageHeader operationAllowed="create">
        <Total><Money currency="USD">10.00</Money></Total>
      </PunchOutOrderMessageHeader>
      <ItemIn quantity="1">
        <ItemID><SupplierPartID>{$orderNumber}</SupplierPartID></ItemID>
      </ItemIn>
    </PunchOutOrderMessage>
  </Request>
</cXML>
XML;
    }

    protected function orderXmlWithoutBuyerCookie(string $orderNumber = 'ORD-1'): string
    {
        return <<<XML
<?xml version="1.0"?>
<cXML>
  <Request>
    <PunchOutOrderMessage>
      <PunchOutOrderMessageHeader operationAllowed="create">
        <Total><Money currency="USD">10.00</Money></Total>
      </PunchOutOrderMessageHeader>
      <ItemIn quantity="1">
        <ItemID><SupplierPartID>{$orderNumber}</SupplierPartID></ItemID>
      </ItemIn>
    </PunchOutOrderMessage>
  </Request>
</cXML>
XML;
    }
}
