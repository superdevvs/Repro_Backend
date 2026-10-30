<?php

namespace App\Services\Voice;

/** Purpose/session/record-bound proof, never accepted without an authenticated owner or signed provider identity. */
class VoiceMobileProof
{
    public function token(string $purpose, string $session, string $record): string
    {
        return $record.'.'.hash_hmac('sha256', 'mobile-voice-v1|'.$purpose.'|'.$session.'|'.$record, (string) config('app.key'));
    }

    public function verify(string $token, string $purpose, string $session, string $record): bool
    {
        return hash_equals($this->token($purpose, $session, $record), $token);
    }

    public function header(array $payload, string $name): string
    {
        foreach (($payload['custom_headers'] ?? []) as $header) {
            if (is_array($header) && strcasecmp((string) ($header['name'] ?? ''), $name) === 0) {
                return (string) ($header['value'] ?? '');
            }
        }

        return '';
    }

    public static function redact(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                if (isset($v['name']) && in_array(strtolower((string) $v['name']), ['x-repro-mobile-offer', 'x-repro-offer'], true)) {
                    $v['value'] = '[redacted]';
                }
                $value[$k] = self::redact($v);
            } elseif (in_array(strtolower((string) $k), ['push_token', 'jwt', 'offer_token'], true)) {
                $value[$k] = '[redacted]';
            }
        }

        return $value;
    }
}
