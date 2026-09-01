<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Users\TwoFactorAuthenticationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProfileSecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticationService $twoFactor,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $user = $request->user();
        $currentTokenId = $user?->currentAccessToken()?->getKey();
        $loginMetadata = $this->loginMetadataByToken($user);

        $sessions = $user->tokens()
            ->latest('last_used_at')
            ->latest('created_at')
            ->get()
            ->map(function ($token) use ($currentTokenId, $loginMetadata) {
                $metadata = $loginMetadata->get((string) $token->getKey(), []);
                $userAgent = is_string($metadata['user_agent'] ?? null) ? $metadata['user_agent'] : null;

                return [
                    'id' => (string) $token->getKey(),
                    'name' => $token->name ?: 'Dashboard session',
                    'device' => $this->describeDevice($userAgent),
                    'ip_address' => $metadata['ip_address'] ?? null,
                    'user_agent' => $userAgent,
                    'created_at' => optional($token->created_at)?->toIso8601String(),
                    'last_active_at' => optional($token->last_used_at ?? $token->created_at)?->toIso8601String(),
                    'current' => (string) $token->getKey() === (string) $currentTokenId,
                ];
            })
            ->values();

        return response()->json([
            'two_factor' => [
                'enabled' => $this->twoFactor->enabled($user),
                'confirmed_at' => optional($user->two_factor_confirmed_at)?->toIso8601String(),
                'recovery_codes_remaining' => is_array($user->two_factor_recovery_codes)
                    ? count($user->two_factor_recovery_codes)
                    : 0,
            ],
            'password' => [
                'changed_at' => optional($user->password_changed_at)?->toIso8601String(),
            ],
            'sessions' => $sessions,
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        $limit = (int) ($validated['limit'] ?? 50);

        $activities = UserActivityLog::query()
            ->where('user_id', $request->user()->getKey())
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (UserActivityLog $log) {
                $metadata = is_array($log->metadata) ? $log->metadata : [];

                return [
                    'id' => (string) $log->getKey(),
                    'type' => $log->event_type,
                    'title' => $log->title ?: $this->eventTitle($log->event_type),
                    'description' => $log->description,
                    'timestamp' => optional($log->occurred_at ?? $log->created_at)?->toIso8601String(),
                    'ip_address' => $metadata['ip_address'] ?? null,
                    'device' => $this->describeDevice($metadata['user_agent'] ?? null),
                ];
            })
            ->values();

        return response()->json(['activities' => $activities]);
    }

    public function beginTwoFactorSetup(Request $request): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $validated = $request->validate(['current_password' => 'required|string']);
        $user = $request->user();
        $this->ensurePasswordMatches($user, $validated['current_password']);

        if ($this->twoFactor->enabled($user)) {
            throw ValidationException::withMessages([
                'two_factor' => ['Two-factor authentication is already enabled.'],
            ]);
        }

        $setup = $this->twoFactor->beginSetup($user);
        $this->record($user, 'two_factor_setup_started', 'Two-factor setup started', 'Authenticator setup was started.', $request);

        return response()->json($setup);
    }

    public function confirmTwoFactorSetup(Request $request): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $validated = $request->validate([
            'current_password' => 'required|string',
            'code' => 'required|string|max:32',
        ]);
        $user = $request->user();
        $this->ensurePasswordMatches($user, $validated['current_password']);

        try {
            [$recoveryCodes, $revokedSessions] = DB::transaction(function () use ($user, $validated): array {
                $recoveryCodes = $this->twoFactor->confirmSetup($user, $validated['code']);
                $currentTokenId = $user->currentAccessToken()?->getKey();
                $revokedSessions = $user->tokens()
                    ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
                    ->delete();

                return [$recoveryCodes, $revokedSessions];
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        $this->record(
            $user,
            'two_factor_enabled',
            'Two-factor authentication enabled',
            'Authenticator protection was enabled and other dashboard sessions were signed out.',
            $request,
            ['revoked_count' => $revokedSessions],
        );

        return response()->json([
            'message' => 'Two-factor authentication is enabled.',
            'recovery_codes' => $recoveryCodes,
            'revoked_sessions' => $revokedSessions,
        ]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $validated = $request->validate([
            'current_password' => 'required|string',
            'code' => 'required|string|max:32',
        ]);
        $user = $request->user();
        $this->ensurePasswordMatches($user, $validated['current_password']);

        if (! $this->twoFactor->enabled($user)) {
            throw ValidationException::withMessages([
                'two_factor' => ['Two-factor authentication is not enabled.'],
            ]);
        }

        $currentTokenId = $user->currentAccessToken()?->getKey();
        $revokedSessions = DB::transaction(function () use ($user, $validated, $currentTokenId): int {
            if (! $this->twoFactor->verifyUserCode($user, $validated['code'])) {
                throw ValidationException::withMessages([
                    'code' => ['The authentication or recovery code is invalid.'],
                ]);
            }

            $this->twoFactor->disable($user);

            return $user->tokens()
                ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
                ->delete();
        });
        $this->record(
            $user,
            'two_factor_disabled',
            'Two-factor authentication disabled',
            'Authenticator protection was removed and other sessions were signed out.',
            $request,
            ['revoked_count' => $revokedSessions],
        );

        return response()->json([
            'message' => 'Two-factor authentication is disabled.',
            'revoked_sessions' => $revokedSessions,
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $validated = $request->validate([
            'current_password' => 'required|string',
            'code' => 'required|string|max:32',
        ]);
        $user = $request->user();
        $this->ensurePasswordMatches($user, $validated['current_password']);

        if (! $this->twoFactor->enabled($user) || ! $this->twoFactor->verifyUserCode($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The authentication or recovery code is invalid.'],
            ]);
        }

        $recoveryCodes = $this->twoFactor->regenerateRecoveryCodes($user);
        $this->record($user, 'two_factor_recovery_codes_regenerated', 'Recovery codes replaced', 'New two-factor recovery codes were generated and the old codes were invalidated.', $request);

        return response()->json([
            'message' => 'New recovery codes generated.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function revokeSession(Request $request, string $tokenId): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $validated = $request->validate(['current_password' => 'required|string']);
        $user = $request->user();
        $this->ensurePasswordMatches($user, $validated['current_password']);
        $currentTokenId = (string) $user->currentAccessToken()?->getKey();
        if ($currentTokenId === $tokenId) {
            throw ValidationException::withMessages([
                'session' => ['Use Log Out to end the session on this device.'],
            ]);
        }

        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();
        $this->record($user, 'session_revoked', 'Session signed out', 'A dashboard session was remotely signed out.', $request, [
            'revoked_token_id' => $tokenId,
        ]);

        return response()->json(['message' => 'Session signed out.']);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $this->ensureNotImpersonating($request);
        $validated = $request->validate(['current_password' => 'required|string']);
        $user = $request->user();
        $this->ensurePasswordMatches($user, $validated['current_password']);
        $currentTokenId = $user->currentAccessToken()?->getKey();
        $query = $user->tokens();
        if ($currentTokenId) {
            $query->where('id', '!=', $currentTokenId);
        }
        $revoked = $query->delete();

        $this->record($user, 'sessions_revoked', 'Other sessions signed out', sprintf('%d other session%s signed out.', $revoked, $revoked === 1 ? ' was' : 's were'), $request, [
            'revoked_count' => $revoked,
        ]);

        return response()->json([
            'message' => $revoked > 0 ? 'Other sessions signed out.' : 'No other active sessions were found.',
            'revoked_count' => $revoked,
        ]);
    }

    private function ensureNotImpersonating(Request $request): void
    {
        if ($request->attributes->get('is_impersonating')) {
            abort(403, 'Security details are unavailable while impersonating another account.');
        }
    }

    private function ensurePasswordMatches(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }

    private function record(User $user, string $type, string $title, string $description, Request $request, array $metadata = []): void
    {
        try {
            UserActivityLog::record($user, $type, $title, $description, null, array_merge([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], $metadata));
        } catch (\Throwable $exception) {
            Log::warning('Unable to persist profile security activity.', [
                'user_id' => $user->getKey(),
                'event_type' => $type,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function loginMetadataByToken(User $user)
    {
        return UserActivityLog::query()
            ->where('user_id', $user->getKey())
            ->where('event_type', 'login')
            ->latest('occurred_at')
            ->limit(250)
            ->get()
            ->mapWithKeys(function (UserActivityLog $log) {
                $metadata = is_array($log->metadata) ? $log->metadata : [];
                $tokenId = $metadata['token_id'] ?? null;

                return $tokenId === null ? [] : [(string) $tokenId => $metadata];
            });
    }

    private function describeDevice(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $platform = match (true) {
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'unknown system',
        };

        return "{$browser} on {$platform}";
    }

    private function eventTitle(string $eventType): string
    {
        return str($eventType)->replace(['.', '_'], ' ')->title()->toString();
    }
}
