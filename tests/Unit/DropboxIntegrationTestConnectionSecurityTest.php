<?php

namespace Tests\Unit;

use App\Http\Controllers\API\IntegrationController;
use App\Models\User;
use App\Services\BrightMlsService;
use App\Services\CubiCasaService;
use App\Services\DropboxWorkflowService;
use App\Services\IguideService;
use App\Services\MmmService;
use App\Services\ShootActivityLogger;
use App\Services\ZillowPropertyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DropboxIntegrationTestConnectionSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Log::spy();
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_only_studio_administrators_can_invoke_dropbox_connection_test(?string $role): void
    {
        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldNotReceive('testConnection');

        $response = $this->controller($dropbox)->testConnection($this->testRequest($role));

        $this->assertSame(403, $response->getStatusCode());
        Http::assertNothingSent();
    }

    public static function unauthorizedRoles(): array
    {
        return array_map(fn ($role) => [$role], [null, 'client', 'sales', 'photographer', 'editor', 'editing_manager']);
    }

    #[DataProvider('ineligibleAdministrators')]
    public function test_locked_inactive_deleted_and_impersonated_administrators_cannot_test_connection(array $userAttributes, array $requestAttributes, ?string $impersonationHeader): void
    {
        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldNotReceive('testConnection');
        $request = $this->testRequest('admin');
        $request->user()->forceFill($userAttributes);
        $request->attributes->add($requestAttributes);
        if ($impersonationHeader !== null) {
            $request->headers->set('X-Impersonate-User-Id', $impersonationHeader);
        }

        $response = $this->controller($dropbox)->testConnection($request);

        $this->assertSame(403, $response->getStatusCode());
        Http::assertNothingSent();
    }

    public static function ineligibleAdministrators(): array
    {
        return [
            'locked' => [['locked_at' => '2026-01-01 00:00:00'], [], null],
            'inactive' => [['account_status' => 'inactive'], [], null],
            'deleted' => [['deleted_at' => '2026-01-01 00:00:00'], [], null],
            'impersonation attribute' => [[], ['is_impersonating' => true], null],
            'impersonation header' => [[], [], '99'],
            'empty impersonation header' => [[], [], ''],
            'secondary admin role only' => [['role' => 'client', 'secondary_roles' => ['admin']], [], null],
        ];
    }

    #[DataProvider('administratorResults')]
    public function test_provider_fields_and_message_are_excluded_from_success_and_failure_responses(string $role, bool $success): void
    {
        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('testConnection')->once()->andReturn([
            'success' => $success,
            'message' => 'provider-test-secret',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'client_secret' => 'test-client-secret',
            'details' => ['token_preview' => 'test-token-preview'],
            'account' => ['name' => 'test-account-name', 'email' => 'test-private-account@example.test'],
        ]);

        $response = $this->controller($dropbox)->testConnection($this->testRequest($role));

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame(['success', 'message', 'data'], array_keys($data));
        $this->assertSame($success, $data['success']);
        $this->assertSame(['success' => $success, 'message' => $data['message']], $data['data']);
        $this->assertStringNotContainsString('test-', $response->getContent());
        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }

    public static function administratorResults(): array
    {
        return [['admin', true], ['admin', false], ['superadmin', true], ['superadmin', false]];
    }

    public function test_dropbox_exception_is_not_exposed_or_logged_with_credential_text(): void
    {
        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('testConnection')->once()->andThrow(new \RuntimeException('Bearer test-token-in-exception'));

        $response = $this->controller($dropbox)->testConnection($this->testRequest('admin'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Dropbox connection test is temporarily unavailable.', $response->getData(true)['message']);
        $this->assertStringNotContainsString('test-token-in-exception', $response->getContent());
        Log::shouldHaveReceived('log')->once()->with('warning', 'API operation failed.', Mockery::on(
            fn ($context) => ($context['exception'] ?? null) === \RuntimeException::class
                && !str_contains(json_encode($context), 'test-token-in-exception')
        ));
        Http::assertNothingSent();
    }

    public function test_non_dropbox_provider_diagnostics_are_excluded_from_connection_results(): void
    {
        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldNotReceive('testConnection');
        $iguide = Mockery::mock(IguideService::class);
        $result = ['success' => true, 'message' => 'provider-secret-canary', 'details' => ['token' => 'provider-secret-canary']];
        $iguide->shouldReceive('testConnection')->once()->andReturn($result);
        $request = $this->testRequest('editing_manager');
        $request->merge(['service' => 'iguide']);

        $response = $this->controller($dropbox, $iguide)->testConnection($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'message' => 'Connection successful'], $response->getData(true)['data']);
        $this->assertStringNotContainsString('provider-secret-canary', $response->getContent());
        Http::assertNothingSent();
    }

    private function controller(DropboxWorkflowService $dropbox, ?IguideService $iguide = null): IntegrationController
    {
        return new IntegrationController(
            Mockery::mock(ZillowPropertyService::class),
            Mockery::mock(BrightMlsService::class),
            $iguide ?? Mockery::mock(IguideService::class),
            Mockery::mock(CubiCasaService::class),
            $dropbox,
            Mockery::mock(MmmService::class),
            Mockery::mock(ShootActivityLogger::class),
        );
    }

    private function testRequest(?string $role): Request
    {
        $request = Request::create('/api/integrations/test-connection', 'POST', ['service' => 'dropbox']);
        $user = $role === null ? null : new User(['role' => $role]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
