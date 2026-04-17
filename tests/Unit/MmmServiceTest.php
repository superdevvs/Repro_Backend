<?php

namespace Tests\Unit;

use App\Services\MmmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MmmServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validate_config_allows_a_missing_template_external_number(): void
    {
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
        ]);

        $this->assertNull(app(MmmService::class)->validateConfig());
    }

    #[Test]
    public function send_punchout_request_retries_with_form_encoding_if_raw_xml_is_rejected(): void
    {
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
        ]);

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
        ]);

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
        ]);

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
