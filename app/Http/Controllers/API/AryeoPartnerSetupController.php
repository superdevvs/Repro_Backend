<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public registration details only; this is not an OAuth client or authorization server. */
class AryeoPartnerSetupController extends Controller
{
    // Exact production identifiers, independent of request Host/forwarded headers.
    public const ORIGIN = 'https://reprodashboard.com';

    public const SETUP_PATH = '/api/integrations/aryeo/setup';

    public const CALLBACK_PATH = '/api/integrations/aryeo/oauth/callback';

    public function show(Request $request): Response
    {
        $nonce = base64_encode(random_bytes(24));

        return response()->view('integrations.aryeo-partner-setup', [
            'details' => $this->details(),
            'nonce' => $nonce,
            'callbackUnavailable' => $request->query('callback') === 'not_configured',
            'logo' => base64_encode(file_get_contents(public_path('images/email-atelier/v6/logo-dark.png'))),
        ], 200, $this->headers($nonce));
    }

    public function registration(): JsonResponse
    {
        return response()->json($this->details(), 200, $this->headers());
    }

    public function callback(): RedirectResponse
    {
        // No authorization flow has been agreed with the partner. Do not read,
        // persist, exchange, log, or reflect any supplied code/state/error.
        // Scrub the browser URL and make the disabled state explicit. A request
        // reaching this reserved callback can never establish an account link.
        return redirect()->away(self::ORIGIN.self::SETUP_PATH.'?callback=not_configured', 303)
            ->withHeaders($this->headers());
    }

    private function details(): array
    {
        return [
            'application_name' => 'RePro Dashboard',
            'application_url' => self::ORIGIN,
            'integration' => 'Zillow / Aryeo',
            'setup_url' => self::ORIGIN.self::SETUP_PATH,
            'redirect_uri' => self::ORIGIN.self::CALLBACK_PATH,
            'status' => 'awaiting_partner_configuration',
            'callback_reserved' => true,
            'oauth_connection_enabled' => false,
            'token_exchange_enabled' => false,
            'required_partner_details' => [
                'Partner API and OAuth documentation; confirm the authentication method.',
                'Registered client ID and secure delivery of any client secret.',
                'Authorization and token endpoints, supported grant and client authentication methods.',
                'Approved scopes, account or company identifier, and consent requirements.',
                'PKCE, refresh, expiry, revocation, and webhook signing requirements.',
                'Sandbox access and production approval requirements.',
            ],
            'note' => 'Registration handoff only. Account linking and API synchronization are not enabled by this setup.',
        ];
    }

    private function headers(?string $nonce = null): array
    {
        $nonceSource = $nonce === null ? "'none'" : "'nonce-{$nonce}'";

        return [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; img-src data:; style-src {$nonceSource}; script-src {$nonceSource}",
        ];
    }
}
