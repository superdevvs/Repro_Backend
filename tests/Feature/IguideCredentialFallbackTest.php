<?php

namespace Tests\Feature;

use App\Services\IguideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IguideCredentialFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_dashboard_credentials_do_not_mask_configured_environment_credentials(): void
    {
        config(['services.iguide.api_username' => 'environment-user', 'services.iguide.api_password' => 'environment-password']);
        DB::table('settings')->insert(['key' => 'integrations.iguide', 'type' => 'json', 'value' => json_encode(['apiUsername' => '', 'apiPassword' => '', 'enabled' => true])]);
        $service = new IguideService;
        foreach (['apiUsername' => 'environment-user', 'apiPassword' => 'environment-password'] as $key => $expected) {
            $property = new \ReflectionProperty($service, $key);
            $this->assertSame($expected, $property->getValue($service));
        }
        Http::assertNothingSent();
    }

    public function test_nonempty_dashboard_credentials_still_take_precedence(): void
    {
        config(['services.iguide.api_username' => 'environment-user']);
        DB::table('settings')->insert(['key' => 'integrations.iguide', 'type' => 'json', 'value' => json_encode(['apiUsername' => 'dashboard-user'])]);
        $property = new \ReflectionProperty(IguideService::class, 'apiUsername');
        $this->assertSame('dashboard-user', $property->getValue(new IguideService));
        Http::assertNothingSent();
    }
}
