<?php

namespace Tests\Unit;

use App\Services\Voice\VoiceMobileJwt;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VoiceMobileJwtTest extends TestCase
{
    private const NOW = 1793458800;

    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credential(): array
    {
        return ['id' => 'synthetic-credential', 'user_id' => 'synthetic-provider-user',
            'sip_username' => 'gencredSynthetic', 'expires_at' => '2026-11-30T15:00:00Z', 'expired' => false,
            'token_material' => ['kid' => 'synthetic-v1', 'tel_token' => 'synthetic-tel-token', 'signing_secret' => self::SECRET]];
    }

    private function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    private function claims(string $token): array
    {
        return json_decode($this->decode(explode('.', $token)[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_exact_reference_shape_matches_independent_python_hmac_vector(): void
    {
        // Independently generated using Python json/base64/hmac and the official Ruby SDK
        // protocol, with synthetic key bytes00..31. This is not a provider credential.
        $expected = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCIsImN0eSI6InRlbG55eC13ZWJydGM7dj0xIiwia2lkIjoic3ludGhldGljLXYxIn0.'
            .'eyJzdWIiOiJzeW50aGV0aWMtY3JlZGVudGlhbCIsInVzZXJfaWQiOiJzeW50aGV0aWMtcHJvdmlkZXItdXNlciIsImlkZW50aXR5IjoiZ2VuY3JlZFN5bnRoZXRpYyIsImNyZWRfZXhwIjoiMjAyNi0xMS0zMFQxNTowMDowMFoiLCJ0ZWxfdG9rZW4iOiJzeW50aGV0aWMtdGVsLXRva2VuIiwiaWF0IjoxNzkzNDU4ODAwLCJuYmYiOjE3OTM0NTg4MDAsImV4cCI6MTc5MzQ2MjQwMCwianRpIjoic3ludGhldGljLWNyZWRlbnRpYWwtMTc5MzQ1ODgwMCJ9.'
            .'SXm_0c9hc7pa_0UqaM4NbbsZVS2JD5qLzr93eW47EJM';
        $actual = (new VoiceMobileJwt)->mint($this->credential(), Carbon::now()->addHour());
        $this->assertSame($expected, $actual);
        [$head, $body, $signature] = explode('.', $actual);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT', 'cty' => 'telnyx-webrtc;v=1', 'kid' => 'synthetic-v1'], json_decode($this->decode($head), true));
        $this->assertSame('synthetic-provider-user', $this->claims($actual)['user_id']);
        $this->assertArrayNotHasKey('token_material', $this->claims($actual));
        $this->assertArrayNotHasKey('signing_secret', $this->claims($actual));
        $this->assertSame(hash_hmac('sha256', $head.'.'.$body, $this->decode(self::SECRET), true), $this->decode($signature));
        $this->assertNotSame(hash_hmac('sha256', $head.'.'.$body, self::SECRET, true), $this->decode($signature), 'Encoded key text is not the HMAC key.');
        $tampered = $head.'.'.rtrim(strtr(base64_encode(str_replace('gencredSynthetic', 'gencredOther', $this->decode($body))), '+/', '-_'), '=');
        $this->assertNotSame(hash_hmac('sha256', $tampered, $this->decode(self::SECRET), true), $this->decode($signature));
    }

    public function test_actual_signed_expiry_is_bounded_by_hour_login_lease_and_provider_expiry(): void
    {
        foreach ([[7200, 86400, 3600], [600, 86400, 600], [3600, 300, 300], [3600, 31, 31]] as [$lease, $provider, $expected]) {
            $credential = $this->credential();
            $credential['expires_at'] = Carbon::now()->addSeconds($provider)->toIso8601String();
            $expiry = Carbon::now()->addSeconds($lease);
            $claims = $this->claims((new VoiceMobileJwt)->mint($credential, $expiry));
            $this->assertSame(self::NOW + $expected, $claims['exp']);
            $this->assertSame(self::NOW, $claims['iat']);
            $this->assertSame(self::NOW, $claims['nbf']);
            $this->assertSame($credential['expires_at'], $claims['cred_exp']);
            $this->assertSame(self::NOW + $lease, $expiry->getTimestamp(), 'Caller expiry must not mutate.');
        }
    }

    public function test_canonical_padded_secret_is_equivalent_to_unpadded_secret(): void
    {
        $credential = $this->credential();
        $expected = (new VoiceMobileJwt)->mint($credential, Carbon::now()->addHour());
        $credential['token_material']['signing_secret'] .= '=';
        $this->assertSame($expected, (new VoiceMobileJwt)->mint($credential, Carbon::now()->addHour()));
    }

    public function test_untrusted_extra_claims_cannot_override_fixed_algorithm_or_lifetime(): void
    {
        $credential = $this->credential();
        $credential['alg'] = 'none';
        $credential['exp'] = PHP_INT_MAX;
        $credential['token_material']['alg'] = 'none';
        $credential['expires_at'] = '2026-10-31T21:00:00+05:30';
        $token = (new VoiceMobileJwt)->mint($credential, Carbon::now()->addHours(2));
        $head = json_decode($this->decode(explode('.', $token)[0]), true);
        $claims = $this->claims($token);
        $this->assertSame('HS256', $head['alg']);
        $this->assertSame(self::NOW + 1800, $claims['exp']);
        $this->assertSame('2026-10-31T21:00:00+05:30', $claims['cred_exp']);
        $this->assertSame(['sub', 'user_id', 'identity', 'cred_exp', 'tel_token', 'iat', 'nbf', 'exp', 'jti'], array_keys($claims));
    }

    public function test_exception_trace_redacts_material_even_when_php_records_function_arguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $credential = $this->credential();
            $credential['token_material']['signing_secret'] = 'DO-NOT-LOG-THIS-PRIVATE-MATERIAL!';
            try {
                (new VoiceMobileJwt)->mint($credential, Carbon::now()->addHour());
                $this->fail('Invalid secret must fail.');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString('DO-NOT-LOG-THIS', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
                $this->assertInstanceOf(\SensitiveParameterValue::class, $error->getTrace()[0]['args'][0]);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_or_expired_material_fails_with_sanitized_error(string $field, mixed $value): void
    {
        $credential = $this->credential();
        if (str_starts_with($field, 'token_material.')) {
            $credential['token_material'][substr($field, 15)] = $value;
        } else {
            $credential[$field] = $value;
        }
        try {
            (new VoiceMobileJwt)->mint($credential, Carbon::now()->addHour());
            $this->fail('Malformed provider material must not mint a token.');
        } catch (RuntimeException $error) {
            $this->assertSame('The phone provider returned invalid token signing material.', $error->getMessage());
            $this->assertSame(503, $error->getCode());
            $this->assertNull($error->getPrevious(), 'A previous exception could disclose provider material.');
        }
    }

    public static function invalidInputs(): array
    {
        return [
            ['id', null], ['id', []], ['user_id', ''], ['user_id', 984],
            ['sip_username', 'sip-not-generated'], ['sip_username', "gencredBAD\n"],
            ['expires_at', 'tomorrow'], ['expires_at', '2026-02-30T15:00:00Z'],
            ['expires_at', '2026-10-31T14:00:00Z'], ['expires_at', '2026-10-31T15:00:30Z'],
            ['expired', true], ['expired', 'false'], ['token_material', null],
            ['token_material.kid', ''], ['token_material.kid', "secret\rvalue"],
            ['token_material.tel_token', []], ['token_material.tel_token', "\xff"],
            ['token_material.signing_secret', ''], ['token_material.signing_secret', 'a'],
            ['token_material.signing_secret', 'AA==garbage'], ['token_material.signing_secret', 'AA==='],
            ['token_material.signing_secret', 'AA+_'], ['token_material.signing_secret', "AA\n"],
            ['token_material.signing_secret', 'AB'], ['token_material.signing_secret', self::SECRET.'=='],
        ];
    }

    public function test_expired_or_too_short_login_lease_cannot_mint_even_for_long_lived_credential(): void
    {
        foreach ([-1, 0, 30] as $seconds) {
            try {
                (new VoiceMobileJwt)->mint($this->credential(), Carbon::now()->addSeconds($seconds));
                $this->fail('Short/expired login lease must not mint.');
            } catch (RuntimeException $error) {
                $this->assertSame(503, $error->getCode());
            }
        }
    }
}
