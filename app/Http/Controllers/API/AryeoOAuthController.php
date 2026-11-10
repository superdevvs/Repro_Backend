<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AryeoApiInstallation;
use App\Models\User;
use App\Services\Aryeo\Api\Client;
use App\Services\Aryeo\Api\OAuth;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

class AryeoOAuthController extends Controller
{
    public function start(Request $request, AryeoApiInstallation $installation, OAuth $oauth)
    {
        abort_if($request->attributes->get('is_impersonating') || $request->hasHeader('X-Impersonate-User-Id'), 403);
        abort_unless($oauth->configured(), 409, 'aryeo_credentials_not_configured');
        $data = $request->validate(['kind' => 'required|in:install,delegate']);
        $state = OAuth::b64(random_bytes(32));
        $browser = OAuth::b64(random_bytes(32));
        $verifier = OAuth::b64(random_bytes(48));
        $hash = hash('sha256', $state);
        DB::table('aryeo_api_oauth_states')->insert(['state_hash' => $hash, 'installation_id' => $installation->id,
            'actor_id' => $request->user()->id, 'auth_token_id' => $request->user()->currentAccessToken()?->id,
            'browser_hash' => hash('sha256', $browser), 'kind' => $data['kind'], 'verifier' => Crypt::encryptString($verifier), 'expires_at' => now()->addMinutes(10)]);
        if ($data['kind'] === 'install') {
            $token = $oauth->applicationToken('aryeo.application-installation-intent:manage');
            app(\App\Services\Aryeo\Api\RateBudget::class)->reserve();
            $result = Http::withToken($token)->acceptJson()->timeout(30)->withoutRedirecting()->post(app(Client::class)->base($installation).'/application-installation-intents', [
                'redirect_uri' => config('aryeo_api.redirect_uri'), 'redirect_state' => $state,
            ]);
            $url = $result->json('data.url');
            abort_unless($result->successful() && is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https'
                && in_array(parse_url($url, PHP_URL_HOST), ['auth.aryeo.com', 'app.aryeo.com'], true), 409, 'installation_start_failed');
        } else {
            $url = config('aryeo_api.auth_url').'/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => config('aryeo_api.client_id'),
                'redirect_uri' => config('aryeo_api.redirect_uri'), 'tenant' => 'aryeo.company:'.$installation->company_id,
                'scope' => config('aryeo_api.delegated_scopes'), 'state' => $state, 'code_challenge' => OAuth::b64(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256']);
        }

        return response()->json(['url' => $url])->header('Cache-Control', 'no-store')->cookie('aryeo_oauth_'.$hash, $browser, 10, '/api/integrations/aryeo', null, true, true, false, 'lax');
    }

    public function callback(Request $request, OAuth $oauth)
    {
        if (! config('aryeo_api.enabled')) {
            return app(AryeoPartnerSetupController::class)->callback();
        }
        $state = (string) $request->query('state', '');
        $hash = hash('sha256', $state);
        $cookie = 'aryeo_oauth_'.$hash;
        $result = 'reconnect_required';
        try {
            $row = LockedWrite::run(fn () => DB::transaction(function () use ($request, $hash, $cookie) {
                $row = DB::table('aryeo_api_oauth_states')->where('state_hash', $hash)->first();
                abort_unless($row && ! $row->consumed_at && now()->lt($row->expires_at)
                    && hash_equals($row->browser_hash, hash('sha256', (string) $request->cookie($cookie))), 403);
                abort_unless(in_array(User::find($row->actor_id)?->role, ['admin', 'superadmin'], true), 403);
                abort_if($row->auth_token_id && ! PersonalAccessToken::find($row->auth_token_id), 403);
                DB::table('aryeo_api_oauth_states')->where('state_hash', $hash)->update(['consumed_at' => now()]);

                return $row;
            }), 'aryeo-api.callback');
            abort_if($request->query('error') || ! is_string($request->query('code')), 409);
            $i = AryeoApiInstallation::findOrFail($row->installation_id);
            $fields = ['grant_type' => 'authorization_code', 'code' => $request->query('code'), 'redirect_uri' => config('aryeo_api.redirect_uri')];
            if ($row->kind === 'delegate') {
                $fields['code_verifier'] = Crypt::decryptString($row->verifier);
            }
            $tokens = $oauth->tokenRequest($fields);
            $me = $oauth->assertIdentity($i, $tokens['access_token']);
            abort_unless(! empty($tokens['refresh_token']), 409, 'offline_authorization_required');
            $tokens['_subject_id'] = $me['subject_id'];
            // Reconnection never changes destination bindings or unpauses writes.
            $oauth->store($i, $tokens, true);
            $result = 'connected';
        } catch (\Throwable) {
            // Never reflect, report or persist authorization codes/provider errors.
        }

        return redirect()->away(config('aryeo_api.public_origin').'/integrations?tab=aryeo&aryeo='.$result, 303)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->withoutCookie($cookie, '/api/integrations/aryeo');
    }
}
