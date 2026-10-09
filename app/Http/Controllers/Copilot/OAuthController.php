<?php

namespace App\Http\Controllers\Copilot;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Copilot\CopilotOAuth;
use App\Services\RolePermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class OAuthController extends Controller
{
    public function __construct(private readonly CopilotOAuth $oauth) {}

    public function metadata()
    {
        return response()->json($this->oauth->metadata());
    }

    public function resource()
    {
        return response()->json($this->oauth->protectedMetadata());
    }

    public function register(Request $request)
    {
        $data = $request->validate(['client_name' => 'nullable|string|max:120', 'redirect_uris' => 'required|array|min:1|max:5',
            'redirect_uris.*' => 'required|string|max:2048', 'token_endpoint_auth_method' => 'sometimes|in:none',
            'grant_types' => 'sometimes|array', 'grant_types.*' => 'in:authorization_code,refresh_token',
            'response_types' => 'sometimes|array', 'response_types.*' => 'in:code']);
        foreach ($data['redirect_uris'] as $uri) {
            $parts = parse_url($uri);
            abort_unless($parts && ($parts['scheme'] ?? '') === 'https'
                && in_array($parts['host'] ?? '', ['chatgpt.com', 'chat.openai.com'], true)
                && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
                && ! isset($parts['port']) && ! isset($parts['query'])
                && preg_match('#^/(connector_platform_oauth_redirect|connector/oauth/[A-Za-z0-9_-]+)$#', $parts['path'] ?? ''), 400, 'invalid_redirect_uri');
        }
        $id = (string) Str::uuid();
        $this->oauth->transaction(fn () => DB::table('copilot_clients')->insert(['id' => $id, 'name' => $data['client_name'] ?? 'ChatGPT',
            'redirect_uris' => json_encode(array_values(array_unique($data['redirect_uris']))), 'created_at' => now(), 'updated_at' => now()]));

        return response()->json(['client_id' => $id, 'client_name' => $data['client_name'] ?? 'ChatGPT',
            'redirect_uris' => $data['redirect_uris'], 'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']], 201);
    }

    public function authorize(Request $request)
    {
        $data = $request->validate(['client_id' => 'required|uuid', 'redirect_uri' => 'required|string|max:2048',
            'response_type' => 'required|in:code', 'state' => 'required|string|min:1|max:1024',
            'scope' => 'required|string|max:120', 'resource' => 'required|string',
            'code_challenge_method' => 'required|in:S256', 'code_challenge' => ['required', 'regex:/^[A-Za-z0-9_-]{43}$/']]);
        $client = DB::table('copilot_clients')->find($data['client_id']);
        abort_unless($client && in_array($data['redirect_uri'], json_decode($client->redirect_uris, true), true), 400, 'invalid_client');
        abort_unless($data['resource'] === $this->oauth->resource(), 400, 'invalid_target');
        $scopes = $this->oauth->scopes($data['scope']);
        $id = (string) Str::uuid();
        $this->oauth->transaction(fn () => DB::table('copilot_auth_requests')->insert(['id' => $id, 'client_id' => $client->id,
            'redirect_uri' => $data['redirect_uri'], 'state' => $data['state'], 'resource' => $data['resource'],
            'scopes' => implode(' ', $scopes), 'challenge' => $data['code_challenge'], 'expires_at' => now()->addMinutes(10),
            'created_at' => now(), 'updated_at' => now()]));

        return redirect($this->oauth->issuer().'/copilot/connect?request_id='.$id)->header('Cache-Control', 'no-store');
    }

    public function consentInfo(Request $request, string $id)
    {
        $this->assertConsentActor($request);
        $pending = $this->pending($id);
        $client = DB::table('copilot_clients')->find($pending->client_id);
        $scopes = $this->consentScopes($request->user(), $pending->scopes);

        return response()->json(['request_id' => $id, 'client_name' => $client->name, 'scopes' => $scopes,
            'omitted_scopes' => array_values(array_diff(explode(' ', $pending->scopes), $scopes)),
            'account' => ['name' => $request->user()->name, 'email' => $request->user()->email], 'expires_at' => $pending->expires_at]);
    }

    public function consent(Request $request, string $id)
    {
        $this->assertConsentActor($request);
        $data = $request->validate(['approve' => 'required|boolean']);
        $result = $this->oauth->transaction(function () use ($request, $id, $data) {
            $pending = $this->pending($id);
            $query = ['state' => $pending->state, 'iss' => $this->oauth->issuer()];
            if (! $data['approve']) {
                DB::table('copilot_auth_requests')->where('id', $id)->update(['used_at' => now()]);
                $query['error'] = 'access_denied';
            } else {
                $code = $this->oauth->random();
                DB::table('copilot_auth_requests')->where('id', $id)->update(['user_id' => $request->user()->id,
                    'scopes' => implode(' ', $this->consentScopes($request->user(), $pending->scopes)),
                    'code_hash' => $this->oauth->hash($code), 'consented_at' => now(), 'expires_at' => now()->addMinutes(2)]);
                $query['code'] = $code;
            }

            return ['redirect_url' => $pending->redirect_uri.'?'.http_build_query($query)];
        });

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function token(Request $request)
    {
        if ($request->filled('grant_type') && ! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            return $this->error('unsupported_grant_type');
        }
        $validator = Validator::make($request->all(), ['grant_type' => 'required|in:authorization_code,refresh_token', 'client_id' => 'required|uuid',
            'resource' => 'required|string', 'code' => 'required_if:grant_type,authorization_code|string|max:200',
            'redirect_uri' => 'required_if:grant_type,authorization_code|string|max:2048',
            'code_verifier' => ['required_if:grant_type,authorization_code', 'regex:/^[A-Za-z0-9._~-]{43,128}$/'],
            'refresh_token' => 'required_if:grant_type,refresh_token|string|max:200', 'scope' => 'sometimes|string|max:120']);
        if ($validator->fails()) {
            return $this->error('invalid_request');
        }
        $data = $validator->validated();
        if ($data['resource'] !== $this->oauth->resource()) {
            return $this->error('invalid_target');
        }
        $result = $this->oauth->transaction(function () use ($data) {
            if ($data['grant_type'] === 'authorization_code') {
                $pending = DB::table('copilot_auth_requests')->where('code_hash', $this->oauth->hash($data['code']))->first();
                $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'), '=');
                if (! $pending || ! $pending->user_id || ! $pending->consented_at || $pending->used_at || now()->gte($pending->expires_at)
                    || $pending->client_id !== $data['client_id'] || $pending->redirect_uri !== $data['redirect_uri']
                    || $pending->resource !== $data['resource'] || ! hash_equals($pending->challenge, $challenge)) {
                    return ['error' => 'invalid_grant'];
                }
                $user = User::find($pending->user_id);
                if (! $user) {
                    return ['error' => 'invalid_grant'];
                }
                $this->oauth->assertEligible($user);
                if (! DB::table('copilot_auth_requests')->where('id', $pending->id)->whereNull('used_at')->update(['used_at' => now()])) {
                    return ['error' => 'invalid_grant'];
                }
                $grant = (object) ['id' => (string) Str::uuid(), 'user_id' => $pending->user_id,
                    'client_id' => $pending->client_id, 'scopes' => $pending->scopes, 'resource' => $pending->resource];
                DB::table('copilot_grants')->insert([...get_object_vars($grant), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $token = DB::table('copilot_tokens')->where('refresh_hash', $this->oauth->hash($data['refresh_token']))->first();
                $grant = $token ? DB::table('copilot_grants')->find($token->grant_id) : null;
                if (! $token || ! $grant || $grant->revoked_at || $grant->client_id !== $data['client_id'] || $grant->resource !== $data['resource']) {
                    return ['error' => 'invalid_grant'];
                }
                if ($token->refresh_used_at) {
                    // Persist revocation rather than throw and roll it back.
                    DB::table('copilot_grants')->where('id', $grant->id)->update(['revoked_at' => now()]);

                    return ['error' => 'invalid_grant'];
                }
                if (now()->gte($token->refresh_expires_at)) {
                    return ['error' => 'invalid_grant'];
                }
                if (isset($data['scope']) && $data['scope'] !== $grant->scopes) {
                    return ['error' => 'invalid_scope'];
                }
                $user = User::find($grant->user_id);
                if (! $user) {
                    return ['error' => 'invalid_grant'];
                }
                $this->oauth->assertEligible($user);
                if (! DB::table('copilot_tokens')->where('id', $token->id)->whereNull('refresh_used_at')->update(['refresh_used_at' => now(), 'expires_at' => now()])) {
                    return ['error' => 'invalid_grant'];
                }
            }

            return $this->oauth->issue($grant);
        });

        return response()->json($result, isset($result['error']) ? 400 : 200)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    public function revoke(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:200', 'client_id' => 'required|uuid']);
        $hash = $this->oauth->hash($data['token']);
        $token = DB::table('copilot_tokens')->where('access_hash', $hash)->orWhere('refresh_hash', $hash)->first();
        if ($token) {
            DB::table('copilot_grants')->where('id', $token->grant_id)->where('client_id', $data['client_id'])->update(['revoked_at' => now()]);
        }

        return response()->json([])->header('Cache-Control', 'no-store');
    }

    public function connections(Request $request)
    {
        return response()->json(['data' => DB::table('copilot_grants')->join('copilot_clients', 'copilot_clients.id', '=', 'copilot_grants.client_id')
            ->where('user_id', $request->user()->id)->whereNull('revoked_at')
            ->get(['copilot_grants.id', 'copilot_clients.name', 'scopes', 'copilot_grants.created_at'])]);
    }

    public function disconnect(Request $request, string $id)
    {
        DB::table('copilot_grants')->where('id', $id)->where('user_id', $request->user()->id)->update(['revoked_at' => now()]);

        return response()->json(['disconnected' => true]);
    }

    private function pending(string $id): object
    {
        $pending = DB::table('copilot_auth_requests')->find($id);
        abort_unless($pending && ! $pending->used_at && ! $pending->consented_at && now()->lt($pending->expires_at), 410, 'This connection request has expired or was already used. Start again in ChatGPT.');

        return $pending;
    }

    private function assertConsentActor(Request $request): void
    {
        abort_if($request->attributes->get('is_impersonating'), 403, 'Exit impersonation before connecting an account.');
        $this->oauth->assertEligible($request->user());
    }

    private function consentScopes(User $user, string $requested): array
    {
        return array_values(array_filter(explode(' ', $requested), fn ($scope) => $scope !== 'repro.finance'
            || (in_array(strtolower($user->role), ['admin', 'superadmin'], true)
                && app(RolePermissionService::class)->userCan($user, 'accounting', 'view'))));
    }

    private function error(string $error)
    {
        return response()->json(['error' => $error], 400)->header('Cache-Control', 'no-store');
    }
}
