<?php

namespace App\Services\TelnyxAi;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelnyxSignatureVerifier
{
    public function verify(Request $request): bool
    {
        $publicKey = (string) config('services.telnyx.public_key');
        $signature = (string) ($request->header('Telnyx-Signature-Ed25519')
            ?? $request->header('telnyx-signature-ed25519')
            ?? '');
        $timestamp = (string) ($request->header('Telnyx-Timestamp')
            ?? $request->header('telnyx-timestamp')
            ?? '');

        if ($publicKey === '') {
            return app()->environment(['local', 'testing']);
        }

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            Log::warning('Telnyx signature verification unavailable: libsodium extension missing.');
            return false;
        }

        $signatureBytes = base64_decode($signature, true);
        $publicKeyBytes = base64_decode($publicKey, true);

        if ($signatureBytes === false || $publicKeyBytes === false) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                $signatureBytes,
                $timestamp . '|' . $request->getContent(),
                $publicKeyBytes,
            );
        } catch (\SodiumException $e) {
            Log::warning('Telnyx voice webhook signature verification threw', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
