<?php

namespace App\Services\Voice;

use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/** Server-only Telnyx WebRTC token minting; never persist or return signing material.
 * Protocol reference: team-telnyx/telnyx-ruby, lib/telnyx/lib/access_token.rb.
 */
final class VoiceMobileJwt
{
    private const FAILURE = 'The phone provider returned invalid token signing material.';

    /** The caller has already verified credential/session ownership and bounded the login lease. */
    public function mint(#[\SensitiveParameter] array $credential, Carbon $leaseExpiry): string
    {
        try {
            $id = $this->string($credential, 'id', 255);
            $providerUser = $this->string($credential, 'user_id', 255);
            $identity = $this->string($credential, 'sip_username', 255);
            $credentialExpiry = $this->string($credential, 'expires_at', 64);
            if (! preg_match('/\Agencred[A-Za-z0-9_-]+\z/D', $identity)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?\z/D', $credentialExpiry)
                || (array_key_exists('expired', $credential) && $credential['expired'] !== false)) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            $providerExpiry = Carbon::parse($credentialExpiry, 'UTC');
            $dateErrors = Carbon::getLastErrors();
            if ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            $now = Carbon::now('UTC')->getTimestamp();
            $expires = min($now + 3600, $leaseExpiry->getTimestamp(), $providerExpiry->getTimestamp());
            if ($expires <= $now + 30) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            $material = $credential['token_material'] ?? null;
            if (! is_array($material)) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            $kid = $this->string($material, 'kid', 255);
            $telToken = $this->string($material, 'tel_token', 32768);
            $encodedSecret = $this->string($material, 'signing_secret', 4096);
            if (! preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/D', $encodedSecret)) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            $unpadded = rtrim($encodedSecret, '=');
            $padding = (4 - strlen($unpadded) % 4) % 4;
            if ($padding === 3 || (str_contains($encodedSecret, '=') && strlen($encodedSecret) % 4 !== 0)) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            $secret = base64_decode(strtr($unpadded, '-_', '+/').str_repeat('=', $padding), true);
            if ($secret === false || $secret === '' || $this->encode($secret) !== $unpadded) {
                throw new RuntimeException(self::FAILURE, 503);
            }

            $header = ['alg' => 'HS256', 'typ' => 'JWT', 'cty' => 'telnyx-webrtc;v=1', 'kid' => $kid];
            $claims = ['sub' => $id, 'user_id' => $providerUser, 'identity' => $identity,
                'cred_exp' => $credentialExpiry, 'tel_token' => $telToken,
                'iat' => $now, 'nbf' => $now, 'exp' => $expires, 'jti' => $id.'-'.$now];
            $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
            $input = $this->encode(json_encode($header, $flags)).'.'.$this->encode(json_encode($claims, $flags));

            return $input.'.'.$this->encode(hash_hmac('sha256', $input, $secret, true));
        } catch (Throwable) {
            // No provider value, secret, token or previous exception enters logs/HTTP errors.
            throw new RuntimeException(self::FAILURE, 503);
        }
    }

    private function string(array $values, string $key, int $maximum): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > $maximum
            || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new RuntimeException(self::FAILURE, 503);
        }

        return $value;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
