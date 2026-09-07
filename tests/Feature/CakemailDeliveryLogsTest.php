<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CakemailDeliveryLogsTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL_ID = '3fbfa67e-c4c4-4e03-8dfd-556037960374';

    public function test_provider_uses_v2_activity_endpoint_and_documented_message_filter(): void
    {
        $provider = $this->providerWithFakeToken();
        $events = [['email_id' => self::EMAIL_ID, 'type' => 'delivered', 'time' => '2026-09-07T17:08:18Z']];
        Http::fake([
            'https://cakemail.example.test/v2/logs/emails*' => Http::response(['data' => $events]),
        ]);

        $result = $provider->getLogs(['email_id' => self::EMAIL_ID, 'log_type' => 'all', 'iso_time' => true]);

        $this->assertSame($events, $result);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://cakemail.example.test/v2/logs/emails?')
            && $request['email_id'] === self::EMAIL_ID
            && $request['log_type'] === 'all'
            && $request['page'] === 1
            && $request['per_page'] === 50
            && !array_key_exists('filter', $request->data()));
    }

    #[DataProvider('invalidProviderResponses')]
    public function test_provider_failure_or_malformed_response_is_not_reported_as_an_empty_log(array $body, int $status): void
    {
        $provider = $this->providerWithFakeToken();
        Http::fake([
            'https://cakemail.example.test/v2/logs/emails*' => Http::response($body, $status),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cakemail email activity is unavailable.');
        $provider->getLogs();
    }

    public function test_a_valid_empty_event_list_is_successful(): void
    {
        $provider = $this->providerWithFakeToken();
        Http::fake(['https://cakemail.example.test/v2/logs/emails*' => Http::response(['data' => []])]);

        $this->assertSame([], $provider->getLogs());
    }

    #[DataProvider('messageFilters')]
    public function test_admin_endpoint_preserves_event_response_and_message_scoping(array $query): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $events = [['email_id' => self::EMAIL_ID, 'type' => 'delivered']];
        $provider = $this->createMock(CakemailProvider::class);
        $provider->expects($this->once())->method('getLogs')->with(['email_id' => self::EMAIL_ID])->willReturn($events);
        $this->app->instance(CakemailProvider::class, $provider);

        $this->getJson('/api/cakemail/logs?' . http_build_query($query))
            ->assertOk()->assertExactJson(['success' => true, 'data' => $events]);
    }

    public function test_admin_endpoint_reports_provider_outage_without_exposing_provider_details(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $provider = $this->createMock(CakemailProvider::class);
        $provider->method('getLogs')->willThrowException(new \RuntimeException('secret provider fixture'));
        $this->app->instance(CakemailProvider::class, $provider);

        $this->getJson('/api/cakemail/logs')->assertStatus(502)->assertJson([
            'success' => false,
            'error' => 'Email delivery logs are temporarily unavailable. Please try again later.',
        ])->assertDontSee('secret provider fixture');
    }

    public function test_unsupported_legacy_filter_is_rejected_instead_of_returning_unfiltered_logs(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $provider = $this->createMock(CakemailProvider::class);
        $provider->expects($this->never())->method('getLogs');
        $this->app->instance(CakemailProvider::class, $provider);

        $this->getJson('/api/cakemail/logs?filter=unsupported')->assertStatus(422)->assertJsonValidationErrors('filter');
    }

    public static function messageFilters(): array
    {
        return [
            'documented email_id parameter' => [['email_id' => self::EMAIL_ID]],
            'legacy email_id filter' => [['filter' => 'email_id==' . self::EMAIL_ID]],
        ];
    }

    public static function invalidProviderResponses(): array
    {
        return [
            'provider outage' => [['detail' => 'private provider response'], 500],
            'missing data' => [['pagination' => []], 200],
            'null data' => [['data' => null], 200],
            'non-array data' => [['data' => 'invalid'], 200],
            'non-list data' => [['data' => ['unexpected' => true]], 200],
            'empty object data' => [['data' => (object) []], 200],
        ];
    }

    private function providerWithFakeToken(): CakemailProvider
    {
        config([
            'cache.default' => 'array',
            'services.cakemail.username' => 'logs@example.test',
            'services.cakemail.password' => 'synthetic-password',
            'services.cakemail.base_url' => 'https://cakemail.example.test',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://cakemail.example.test/token' => Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]),
        ]);
        $provider = new CakemailProvider();
        $provider->clearCache();

        return $provider;
    }
}
