<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CakemailWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://dashboard.example.test/api/webhooks/cakemail';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.cakemail.username' => 'webhooks@example.test',
            'services.cakemail.password' => 'synthetic-password',
            'services.cakemail.base_url' => 'https://cakemail.example.test',
        ]);
        Cache::put('cakemail_token_'.md5('webhooks@example.test'), 'synthetic-token', 3600);
        Http::preventStrayRequests();
    }

    #[DataProvider('emailEventCases')]
    public function test_admin_registration_accepts_legacy_and_canonical_events_and_sends_provider_case(string $input, string $expected): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        Http::fake([
            'https://cakemail.example.test/webhooks' => Http::response(['data' => ['id' => 'webhook-example']], 201),
        ]);

        $this->postJson('/api/cakemail/webhooks/register', ['event' => $input, 'url' => self::CALLBACK])
            ->assertOk()->assertExactJson(['success' => true, 'webhook_id' => 'webhook-example']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://cakemail.example.test/webhooks'
            && $request->hasHeader('Authorization', 'Bearer synthetic-token')
            && $request->data() === ['event' => $expected, 'url' => self::CALLBACK]);
    }

    public static function emailEventCases(): array
    {
        return [
            'legacy clicked' => ['email.clicked', 'Email.Clicked'],
            'canonical clicked' => ['Email.Clicked', 'Email.Clicked'],
            'legacy bounced' => ['email.bounced', 'Email.Bounced'],
            'canonical bounced' => ['Email.Bounced', 'Email.Bounced'],
            // Preserve the existing contract; only Clicked/Bounced are documented creation events.
            'legacy delivered' => ['email.delivered', 'Email.Delivered'],
            'canonical delivered' => ['Email.Delivered', 'Email.Delivered'],
            'legacy opened' => ['email.opened', 'Email.Opened'],
            'canonical opened' => ['Email.Opened', 'Email.Opened'],
            'legacy unsubscribed' => ['email.unsubscribed', 'Email.Unsubscribed'],
            'canonical unsubscribed' => ['Email.Unsubscribed', 'Email.Unsubscribed'],
        ];
    }

    #[DataProvider('invalidEvents')]
    public function test_invalid_event_is_rejected_without_contacting_provider(mixed $event): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        Http::fake();

        $this->postJson('/api/cakemail/webhooks/register', ['event' => $event, 'url' => self::CALLBACK])
            ->assertUnprocessable()->assertJsonValidationErrors('event');

        Http::assertNothingSent();
    }

    public static function invalidEvents(): array
    {
        return [
            'array' => [['Email.Clicked']],
            'missing' => [null],
            'not an accepted application event' => ['Account.Updated'],
            'incorrect event tense' => ['Email.Open'],
        ];
    }

    public function test_client_cannot_register_a_webhook(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        Http::fake();

        $this->postJson('/api/cakemail/webhooks/register', ['event' => 'Email.Clicked', 'url' => self::CALLBACK])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_direct_provider_call_keeps_non_email_event_unchanged(): void
    {
        Http::fake([
            'https://cakemail.example.test/webhooks' => Http::response(['data' => ['id' => 'account-webhook']], 201),
        ]);

        $this->assertSame('account-webhook', (new CakemailProvider)->registerWebhook('Account.Updated', self::CALLBACK));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data() === ['event' => 'Account.Updated', 'url' => self::CALLBACK]);
    }
}
