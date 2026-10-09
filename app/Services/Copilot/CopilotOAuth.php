<?php

namespace App\Services\Copilot;

use App\Models\User;
use App\Services\RolePermissionService;
use App\Services\Users\EmailVerificationPilot;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Opaque, audience-bound OAuth grants; dashboard/Sanctum tokens are never accepted by MCP. */
final class CopilotOAuth
{
    public function issuer(): string
    {
        return config('copilot.issuer');
    }

    public function resource(): string
    {
        return $this->issuer().'/api/copilot/mcp';
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function metadata(): array
    {
        $base = $this->issuer().'/api/copilot/oauth';

        return [
            'issuer' => $this->issuer(), 'authorization_endpoint' => $base.'/authorize',
            'token_endpoint' => $base.'/token', 'registration_endpoint' => $base.'/register',
            'revocation_endpoint' => $base.'/revoke', 'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => config('copilot.scopes'),
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    public function protectedMetadata(): array
    {
        return ['resource' => $this->resource(), 'authorization_servers' => [$this->issuer()],
            'scopes_supported' => config('copilot.scopes'), 'bearer_methods_supported' => ['header'],
            'resource_name' => 'Repro Copilot'];
    }

    public function assertEligible(User $user): void
    {
        abort_unless($user->isAccountEligibleForAuthentication(), 401, 'This account is no longer active.');
        abort_if(app(EmailVerificationPilot::class)->status($user)['required'], 403, 'Verify your current email address to connect Repro.');
        abort_unless(app(RolePermissionService::class)->userCan($user, 'robbie', 'view'), 403, 'AI assistant access is disabled for this account.');
    }

    public function scopes(string $value): array
    {
        $scopes = array_values(array_unique(preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)));
        abort_if(! $scopes || array_diff($scopes, config('copilot.scopes')) || ! in_array('repro.read', $scopes, true), 400, 'invalid_scope');

        return $scopes;
    }

    public function authenticate(Request $request, string $scope): object
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? DB::table('copilot_tokens')->where('access_hash', $this->hash($bearer))->first() : null;
        $grant = $token ? DB::table('copilot_grants')->find($token->grant_id) : null;
        if (! $token || ! $grant || $grant->revoked_at || now()->gte($token->expires_at) || $grant->resource !== $this->resource()) {
            throw new HttpException(401, 'Connect your Repro account.', null, [
                'WWW-Authenticate' => 'Bearer resource_metadata="'.$this->issuer().'/api/copilot/oauth/resource", error="invalid_token"',
            ]);
        }
        $user = User::find($grant->user_id);
        abort_unless($user, 401);
        $this->assertEligible($user);
        if (! in_array($scope, explode(' ', $grant->scopes), true)) {
            throw new HttpException(403, 'Reconnect Repro with the required permission.', null, [
                'WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="repro.read '.$scope.'"',
            ]);
        }
        // Do not allow a client-controlled impersonation header to alter this identity.
        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);
        $request->attributes->set('copilot_grant', $grant);

        return $grant;
    }

    public function issue(object $grant): array
    {
        $access = $this->random();
        $refresh = $this->random();
        DB::table('copilot_tokens')->insert([
            'id' => (string) Str::uuid(), 'grant_id' => $grant->id,
            'access_hash' => $this->hash($access), 'refresh_hash' => $this->hash($refresh),
            'expires_at' => now()->addMinutes(config('copilot.access_minutes')),
            'refresh_expires_at' => now()->addDays(config('copilot.refresh_days')),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => config('copilot.access_minutes') * 60,
            'refresh_token' => $refresh, 'scope' => $grant->scopes];
    }

    public function transaction(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'copilot-oauth');
    }
}
