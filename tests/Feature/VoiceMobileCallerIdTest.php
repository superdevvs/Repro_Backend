<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\SmsNumber;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceLinePolicy;
use App\Models\VoiceMobileSession;
use App\Models\VoiceMobileTicket;
use App\Services\Voice\VoiceMobileCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoiceMobileCallerIdTest extends TestCase
{
    use RefreshDatabase;

    private const SMS = '+12025550100';

    private const LINE_A = '+12025550101';

    private const LINE_B = '+12025550102';

    private const CUSTOMER = '+12025550199';

    private User $staff;

    private PersonalAccessToken $token;

    private VoiceMobileSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'mobile_voice.enabled' => true,
            'mobile_voice.push_verified' => true, 'mobile_voice.bundle_id' => 'com.example.repro',
            'mobile_voice.push_credentials.debug' => 'isolated-test-push', 'services.telnyx.api_key' => 'fake',
            'services.telnyx.api_base' => 'https://example.test/v2', 'services.telnyx.from_number' => self::SMS,
            'services.telnyx.voice.browser_enabled' => true, 'services.telnyx.voice.connection_id' => 'server',
            'services.telnyx.voice.credential_connection_id' => 'browser',
            'services.telnyx.voice.webhook_url' => 'https://example.test/voice',
            'services.telnyx.voice.outbound_mode' => 'all', 'services.voice.canary_mode' => false, 'sanctum.expiration' => null]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && preg_match('#^/v2/calls/[^/]+/actions/(answer|hangup)$#', $path)) {
                return Http::response(['data' => ['result' => 'ok']]);
            }
            throw new \RuntimeException('Unexpected provider operation in caller-ID test.');
        });
        $this->staff = User::factory()->create(['role' => 'admin']);
        $this->token = $this->signIn($this->staff);
        $this->session = VoiceMobileSession::create(['user_id' => $this->staff->id, 'personal_access_token_id' => $this->token->id,
            'device_id' => (string) Str::uuid(), 'apns_environment' => 'debug', 'bundle_id' => 'com.example.repro',
            'status' => 'ready', 'expires_at' => now()->addHour(), 'registration_expires_at' => now()->addDay(),
            'connection_username' => 'isolated-native', 'connection_id' => 'isolated-native-connection',
            'credential_id' => 'isolated-native-credential', 'sip_username' => 'gencredcallerid']);
        $this->number(self::SMS, 'SMS notifications');
        $this->policy(self::SMS, 'sms_only');
    }

    public function test_options_normalize_duplicates_and_exclude_unconfigured_unassigned_and_sms_sources(): void
    {
        $this->number(self::LINE_A, 'Main voice');
        $this->number('+1 (202) 555-0101', 'Duplicate formatted voice');
        $this->policy(self::LINE_A);
        $this->number(self::LINE_B, 'Other staff');
        $this->policy(self::LINE_B, recipients: []);
        $this->number('+12025550103', 'Missing policy');
        $this->policy('+12025550104'); // Policy alone does not establish a configured provider source.
        $this->number('+12025550105', 'Wrong provider', 'TWILIO');
        $this->policy('+12025550105');

        $data = $this->getJson($this->path('caller-ids'))->assertOk()
            ->assertJsonPath('default_caller_id', self::LINE_A)->assertJsonPath('selection_required', false)->json();
        $this->assertSame([self::LINE_A], array_column($data['caller_ids'], 'number'));
        $this->assertIsString($data['caller_ids'][0]['label']);
        $this->assertNotSame('', $data['caller_ids'][0]['label']);
        $this->assertSame(['label', 'number'], collect(array_keys($data['caller_ids'][0]))->sort()->values()->all());
        Http::assertNothingSent();
    }

    public function test_sms_default_automatically_uses_the_sole_eligible_voice_line(): void
    {
        $this->number(self::LINE_A, 'Main voice');
        $this->policy(self::LINE_A);
        $response = $this->reserve()->assertOk()->assertJsonPath('caller_number', self::LINE_A)->json();
        $this->getJson($this->path('caller-ids'))->assertOk()->assertJsonPath('caller_ids.0.number', self::LINE_A)
            ->assertJsonPath('default_caller_id', self::LINE_A)->assertJsonPath('selection_required', false);
        $this->assertSame(self::LINE_A, VoiceMobileTicket::findOrFail($response['call_id'])->caller_number);
        $this->assertSame(0, VoiceCall::count());
        Http::assertNothingSent();
    }

    public function test_multiple_voice_lines_require_selection_and_ignore_sms_number_default_flags(): void
    {
        $first = $this->number(self::LINE_A, 'Main voice');
        $first->update(['is_default' => true]);
        $this->number(self::LINE_B, 'Second voice');
        $this->policy(self::LINE_A);
        $this->policy(self::LINE_B);
        $data = $this->getJson($this->path('caller-ids'))->assertOk()
            ->assertJsonPath('default_caller_id', null)->assertJsonPath('selection_required', true)->json();
        $this->assertEqualsCanonicalizing([self::LINE_A, self::LINE_B], array_column($data['caller_ids'], 'number'));
        $this->reserve()->assertStatus(422);
        $this->assertSame(0, VoiceMobileTicket::count());
        $this->reserve(['from' => self::LINE_B])->assertOk()->assertJsonPath('caller_number', self::LINE_B);
        Http::assertNothingSent();
    }

    public function test_configured_global_source_remains_default_only_with_an_eligible_policy(): void
    {
        // A configured global source need not also have an SmsNumber row.
        config(['services.telnyx.from_number' => self::LINE_A]);
        $this->policy(self::LINE_A);
        $this->number(self::LINE_B, 'Second voice')->update(['is_default' => true]);
        $this->policy(self::LINE_B);
        $data = $this->getJson($this->path('caller-ids'))->assertOk()
            ->assertJsonPath('default_caller_id', self::LINE_A)->assertJsonPath('selection_required', false)->json();
        $this->assertEqualsCanonicalizing([self::LINE_A, self::LINE_B], array_column($data['caller_ids'], 'number'));
        $this->reserve()->assertOk()->assertJsonPath('caller_number', self::LINE_A);
        Http::assertNothingSent();
    }

    public function test_no_options_and_spoofed_unknown_or_ineligible_sources_fail_closed(): void
    {
        $this->number(self::LINE_A, 'Unassigned voice');
        $this->policy(self::LINE_A, recipients: []);
        $this->number(self::LINE_B, 'No voice policy');
        $this->policy('+12025550103');
        $this->getJson($this->path('caller-ids'))->assertOk()->assertJsonPath('caller_ids', [])
            ->assertJsonPath('default_caller_id', null)->assertJsonPath('selection_required', false);
        foreach ([[], ['from' => self::SMS], ['from' => self::LINE_A], ['from' => self::LINE_B],
            ['from' => '+12025550103'], ['from' => '+12025550998']] as $input) {
            $this->reserve($input)->assertStatus(422)->assertJsonPath('message', 'Choose an available voice business line.');
        }
        $this->reserve(['from' => 'sip:spoof@sip.telnyx.com'])->assertStatus(422);
        $this->assertSame(0, VoiceMobileTicket::count());
        Http::assertNothingSent();
    }

    public function test_selecting_another_line_cannot_dial_the_global_company_number_without_a_database_row(): void
    {
        config(['services.telnyx.from_number' => self::LINE_A]);
        $this->policy(self::LINE_A);
        $this->number(self::LINE_B, 'Second voice');
        $this->policy(self::LINE_B);
        $this->assertFalse(SmsNumber::where('phone_number', self::LINE_A)->exists());

        $this->reserve(['from' => self::LINE_B, 'to' => self::LINE_A])->assertStatus(422);

        $this->assertSame(0, VoiceMobileTicket::count());
        Http::assertNothingSent();
    }

    public function test_explicit_international_destination_does_not_match_a_different_owned_country_code(): void
    {
        $this->number(self::LINE_A, 'Main voice');
        $this->policy(self::LINE_A);
        $this->number('+14587654321', 'Different owned business line');

        $this->reserve(['to' => '+14587654321'])->assertStatus(422)
            ->assertJsonPath('message', 'Dial an external customer number in international format.');
        $this->assertSame(0, VoiceMobileTicket::count());

        $response = $this->reserve(['to' => '+4587654321'])->assertOk()
            ->assertJsonPath('destination', '+4587654321')
            ->assertJsonPath('caller_number', self::LINE_A)->json();
        $ticket = VoiceMobileTicket::findOrFail($response['call_id']);
        $this->assertSame('+4587654321', $ticket->destination);
        $this->assertSame(self::LINE_A, $ticket->caller_number);
        $this->assertSame(1, VoiceMobileTicket::count());
        $this->assertSame(0, VoiceCall::count());
        Http::assertNothingSent();
    }

    public function test_actual_owned_international_destination_remains_blocked(): void
    {
        $this->number(self::LINE_A, 'Main voice');
        $this->policy(self::LINE_A);
        $this->number('+45 87 65 43 21', 'Owned international business line');

        $this->reserve(['to' => '+4587654321'])->assertStatus(422)
            ->assertJsonPath('message', 'Dial an external customer number in international format.');

        $this->assertSame(0, VoiceMobileTicket::count());
        $this->assertSame(0, VoiceCall::count());
        Http::assertNothingSent();
    }

    public function test_caller_id_routes_require_current_session_owner_login_and_operate_permission(): void
    {
        $this->number(self::LINE_A, 'Main voice');
        $this->policy(self::LINE_A);
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $this->getJson($this->path('caller-ids'))->assertNotFound();
        $this->reserve()->assertNotFound();

        $this->signIn($this->staff); // A different login cannot operate the first login's session.
        $this->getJson($this->path('caller-ids'))->assertForbidden();
        $this->reserve()->assertForbidden();
        $this->staff->withAccessToken($this->token);
        $this->actingAs($this->staff, 'sanctum');
        $this->withHeader('X-Impersonate-User-Id', (string) $this->staff->id)->getJson($this->path('caller-ids'))->assertForbidden();
        $this->flushHeaders();
        $this->staff->update(['permission_overrides' => ['allow' => [], 'deny' => ['voice-calls-operate']]]);
        $this->staff->withAccessToken($this->token);
        $this->actingAs($this->staff, 'sanctum');
        $this->getJson($this->path('caller-ids'))->assertForbidden();
        $this->reserve()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_expired_login_and_revoked_mobile_session_cannot_discover_or_reserve_lines(): void
    {
        $this->number(self::LINE_A, 'Main voice');
        $this->policy(self::LINE_A);
        $this->token->update(['expires_at' => now()->subSecond()]);
        $this->getJson($this->path('caller-ids'))->assertUnauthorized();
        $this->reserve()->assertUnauthorized();
        $this->token->update(['expires_at' => now()->addDay()]);
        $this->session->update(['revoked_at' => now()]);
        $this->getJson($this->path('caller-ids'))->assertForbidden();
        $this->reserve()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_ticket_replay_keeps_selected_line_across_default_drift_and_rejects_changed_intent(): void
    {
        $this->number(self::LINE_A, 'Main voice');
        $this->number(self::LINE_B, 'Second voice');
        $this->policy(self::LINE_A);
        $this->policy(self::LINE_B);
        $key = (string) Str::uuid();
        $first = $this->reserve(['from' => self::LINE_A, 'idempotency_key' => $key])->assertOk()->json();
        config(['services.telnyx.from_number' => self::LINE_B]);
        $this->getJson($this->path('caller-ids'))->assertOk()->assertJsonPath('default_caller_id', self::LINE_B);
        $omitted = $this->reserve(['idempotency_key' => $key])->assertOk()->json();
        $this->assertSame($first, $omitted);
        $this->reserve(['from' => self::LINE_A, 'idempotency_key' => $key])->assertOk()->assertJsonPath('call_id', $first['call_id']);
        $this->reserve(['from' => self::LINE_B, 'idempotency_key' => $key])->assertStatus(409);
        $this->reserve(['to' => '+12025550198', 'idempotency_key' => $key])->assertStatus(409);
        $this->assertSame(1, VoiceMobileTicket::count());
        $this->assertSame(self::LINE_A, VoiceMobileTicket::findOrFail($first['call_id'])->caller_number);
        Http::assertNothingSent();

        $result = $this->nativeInitiated($first, 'valid-original-line');
        $this->assertSame('processed_mobile_outbound', $result['status']);
        $this->assertSame(self::LINE_A, VoiceCall::findOrFail($result['voice_call_id'])->from_phone);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/valid-original-line/actions/answer'));
        Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/v2/calls');
    }

    #[DataProvider('sourceRevocations')]
    public function test_webhook_revalidates_selected_line_without_dialing_customer(string $revocation): void
    {
        $number = $this->number(self::LINE_A, 'Main voice');
        $policy = $this->policy(self::LINE_A);
        $ticket = $this->reserve(['from' => self::LINE_A])->assertOk()->json();
        match ($revocation) {
            'assignment' => $policy->update(['recipient_ids' => []]),
            'sms_only' => $policy->update(['mode' => 'sms_only']),
            'provider_removed' => $number->delete(),
            'provider_changed' => $number->update(['provider' => 'TWILIO']),
            'policy_removed' => $policy->delete(),
        };

        $result = $this->nativeInitiated($ticket, 'revoked-source-'.$revocation);
        $this->assertSame('rejected_mobile_outbound', $result['status']);
        $this->assertSame(0, VoiceCall::count());
        $this->assertNull(VoiceMobileTicket::findOrFail($ticket['call_id'])->voice_call_id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/revoked-source-'.$revocation.'/actions/hangup'));
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/actions/hangup'));
    }

    public static function sourceRevocations(): array
    {
        return array_combine(['assignment', 'sms_only', 'provider_removed', 'provider_changed', 'policy_removed'],
            array_map(fn ($value) => [$value], ['assignment', 'sms_only', 'provider_removed', 'provider_changed', 'policy_removed']));
    }

    private function signIn(User $user): PersonalAccessToken
    {
        $token = $user->createToken('isolated-caller-id-test', ['*'], now()->addDays(2))->accessToken;
        $user->withAccessToken($token);
        $this->actingAs($user, 'sanctum');

        return $token;
    }

    private function number(string $phone, string $label, string $provider = 'TELNYX'): SmsNumber
    {
        return SmsNumber::create(['provider' => $provider, 'phone_number' => $phone, 'label' => $label, 'is_default' => false]);
    }

    private function policy(string $phone, string $mode = 'staff_voicemail', ?array $recipients = null): VoiceLinePolicy
    {
        return VoiceLinePolicy::create(['phone_number' => $phone, 'mode' => $mode, 'recipient_role' => null,
            'recipient_roles' => [], 'recipient_ids' => $recipients ?? [$this->staff->id], 'timezone' => 'UTC', 'weekly_hours' => []]);
    }

    private function path(string $suffix): string
    {
        return '/api/voice/mobile/sessions/'.$this->session->id.'/'.$suffix;
    }

    private function reserve(array $overrides = [])
    {
        return $this->postJson($this->path('outbound'), array_merge(['to' => self::CUSTOMER, 'idempotency_key' => (string) Str::uuid()], $overrides));
    }

    private function nativeInitiated(array $ticket, string $control): array
    {
        return app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => [
            'connection_id' => $this->session->connection_id, 'direction' => 'outgoing', 'to' => $ticket['sdk_destination'],
            'call_control_id' => $control, 'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $ticket['offer_token']]],
        ]]]);
    }
}
