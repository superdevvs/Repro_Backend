<?php

namespace App\Http\Controllers;

use App\Services\Dropbox\DropboxOAuthFlow;
use App\Services\Dropbox\DropboxWebhookHandler;
use App\Services\DropboxTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DropboxAuthController extends Controller
{
    public function __construct(
        private readonly DropboxTokenService $tokens,
        private readonly DropboxOAuthFlow $flow,
    ) {}

    public function getConfig(Request $request): Response
    {
        $this->flow->administrator($request);

        return response()->json([
            'configured' => $this->oauthConfigured(),
            'connected' => $this->tokens->configured(),
            'connection_version' => $this->tokens->version(),
        ])->header('Cache-Control', 'no-store');
    }

    public function connect(Request $request): Response
    {
        $this->flow->administrator($request);
        abort_unless($this->oauthConfigured(), 503, 'Dropbox connection is not configured.');

        try {
            $version = $this->tokens->version();
            $accountId = $this->tokens->currentAccountId();
            $redirectUri = (string) config('services.dropbox.redirect');
            $flow = $this->flow->begin($request, [
                'connection_version' => $version,
                'account_id' => $accountId,
                'redirect_uri' => $redirectUri,
                'client_id' => (string) config('services.dropbox.client_id'),
            ]);
            $params = [
                'client_id' => config('services.dropbox.client_id'),
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'token_access_type' => 'offline',
                'scope' => 'account_info.read files.metadata.read files.metadata.write files.content.read files.content.write sharing.read sharing.write',
                'state' => $flow['state'],
                'code_challenge_method' => 'S256',
                'code_challenge' => $flow['code_challenge'],
            ];

            return response()->json([
                'authorization_url' => 'https://www.dropbox.com/oauth2/authorize?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            ])->withCookie($flow['cookie'])->header('Cache-Control', 'no-store');
        } catch (\Throwable $exception) {
            Log::warning('Dropbox authorization could not be started.', ['exception' => $exception::class]);

            return response()->json(['message' => 'Dropbox connection could not be started. Check the current connection and try again.'], 503);
        }
    }

    public function callback(Request $request): Response
    {
        try {
            $flow = $this->flow->consume($request);
            abort_unless($this->oauthConfigured()
                && hash_equals($flow['client_id'], (string) config('services.dropbox.client_id'))
                && hash_equals($flow['redirect_uri'], (string) config('services.dropbox.redirect'))
                && hash_equals($flow['connection_version'], $this->tokens->version()), 409, 'Dropbox connection changed. Start again.');
            abort_if($request->has('error'), 400, 'Dropbox authorization was declined.');
            $code = $request->query('code');
            abort_unless(is_string($code) && $code !== '' && strlen($code) <= 4096, 400, 'Dropbox authorization code is invalid.');

            // Match Dropbox's PKCE exchange: client ID plus the server-held verifier.
            $exchange = Http::timeout(15)->asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                    'client_id' => $flow['client_id'],
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $flow['redirect_uri'],
                    'code_verifier' => $flow['code_verifier'],
                ]);
            $data = $exchange->json();
            abort_unless($exchange->successful() && is_array($data)
                && is_string($data['access_token'] ?? null) && $data['access_token'] !== ''
                && is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '', 502, 'Dropbox token exchange failed.');
            $accountResponse = Http::timeout(15)->withToken($data['access_token'])
                ->withBody('null', 'application/json')
                ->post('https://api.dropboxapi.com/2/users/get_current_account');
            $account = $accountResponse->json();
            abort_unless($accountResponse->successful() && is_array($account)
                && is_string($account['account_id'] ?? null) && $account['account_id'] !== '', 502, 'Dropbox account could not be verified.');
            abort_if(! empty($flow['account_id']) && ! hash_equals($flow['account_id'], $account['account_id']), 409,
                'Disconnect the existing studio account before connecting a different account.');
            $this->tokens->bind($data, $account, $flow['connection_version'], $flow['administrator'],
                fn () => $this->flow->revalidate($flow));

            return $this->callbackResult($request, true);
        } catch (\Throwable $exception) {
            // Never log provider bodies, codes, credentials or OAuth state.
            Log::warning('Dropbox authorization was not completed.', ['exception' => $exception::class]);
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;

            return $this->callbackResult($request, false, $status);
        }
    }

    public function disconnect(Request $request): Response
    {
        $administrator = $this->flow->administrator($request);
        $validated = $request->validate(['connection_version' => 'required|string|min:32|max:128']);

        try {
            $result = $this->tokens->disconnect($validated['connection_version'], $administrator);

            return response()->json($result)->withCookie($this->flow->expiredCookie())->header('Cache-Control', 'no-store');
        } catch (\Throwable $exception) {
            Log::warning('Dropbox disconnect could not be completed.', ['exception' => $exception::class]);
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;

            return response()->json(['message' => $status === 409 ? 'Dropbox connection changed. Refresh and try again.' : 'Dropbox disconnect could not be completed. Try again.'], $status)
                ->header('Cache-Control', 'no-store');
        }
    }

    public function webhook(Request $request): Response
    {
        return app(DropboxWebhookHandler::class)->handle($request);
    }

    private function oauthConfigured(): bool
    {
        $clientId = config('services.dropbox.client_id');
        $secret = config('services.dropbox.client_secret');
        $redirect = config('services.dropbox.redirect');
        if (! is_string($clientId) || trim($clientId) === '' || $clientId === 'your_dropbox_app_key'
            || ! is_string($secret) || trim($secret) === '' || ! is_string($redirect)) {
            return false;
        }
        $uri = parse_url($redirect);
        if (! is_array($uri) || empty($uri['host']) || isset($uri['user']) || isset($uri['pass'])
            || isset($uri['query']) || isset($uri['fragment']) || ($uri['path'] ?? '') !== '/api/dropbox/callback') {
            return false;
        }

        return ($uri['scheme'] ?? '') === 'https' || (app()->environment(['local', 'testing'])
            && ($uri['scheme'] ?? '') === 'http' && in_array($uri['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }

    private function callbackResult(Request $request, bool $success, int $status = 400): Response
    {
        if ($request->expectsJson()) {
            $response = response()->json(['connected' => $success,
                'message' => $success ? 'Dropbox connected.' : 'Dropbox authorization could not be completed. Start again from Settings.'], $success ? 200 : $status);
        } else {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            $uri = parse_url($frontend);
            $valid = is_array($uri) && ! empty($uri['host']) && ! isset($uri['user']) && ! isset($uri['pass'])
                && ! isset($uri['query']) && ! isset($uri['fragment'])
                && (($uri['scheme'] ?? '') === 'https' || (app()->environment(['local', 'testing'])
                    && ($uri['scheme'] ?? '') === 'http' && in_array($uri['host'], ['localhost', '127.0.0.1', '[::1]'], true)));
            $response = $valid
                ? redirect()->away($frontend.'/integrations?dropbox='.($success ? 'connected' : 'error'))
                : response()->json(['connected' => $success, 'message' => $success ? 'Dropbox connected. Return to Settings.' : 'Dropbox authorization could not be completed.'], $success ? 200 : $status);
        }

        return $response->withCookie($this->flow->expiredCookie())
            ->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache')->header('Referrer-Policy', 'no-referrer');
    }
}
