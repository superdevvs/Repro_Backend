<?php

namespace App\Services\Users;

use Closure;
use App\Exceptions\PublicApiResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Database row locks keep limits shared and atomic across web nodes. */
class AuthSecurityLimiter
{
    public function consume(string $scope, string $identity, int $maximum, int $seconds): array
    {
        $key = $scope.':'.hash('sha256', mb_strtolower(trim($identity)));
        [$retry, $expiry, $report] = DB::transaction(function () use ($key, $maximum, $seconds): array {
            DB::table('auth_security_limits')->insertOrIgnore(['key' => $key, 'attempts' => 0, 'expires_at' => now()->timestamp + $seconds]);
            $row = DB::table('auth_security_limits')->where('key', $key)->lockForUpdate()->first();
            $expired = $row->expires_at <= now()->timestamp;
            $attempts = $expired ? 0 : $row->attempts;
            if ($attempts >= $maximum) {
                $report = !$row->reported;
                if ($report) {
                    DB::table('auth_security_limits')->where('key', $key)->update(['reported' => true]);
                }
                return [max(1, $row->expires_at - now()->timestamp), $row->expires_at, $report];
            }
            DB::table('auth_security_limits')->where('key', $key)->update([
                'attempts' => $attempts + 1,
                'expires_at' => $expired ? now()->timestamp + $seconds : $row->expires_at,
                'reported' => $expired ? false : $row->reported,
            ]);
            return [0, $expired ? now()->timestamp + $seconds : $row->expires_at, false];
        }, 3);
        if ($retry > 0) {
            if ($report) {
                \Illuminate\Support\Facades\Log::notice('Authentication rate limit exceeded.', [
                    'scope' => substr($scope, 0, 64),
                    'request_id' => \App\Services\RequestCorrelation::id(request()),
                ]);
            }
            throw new PublicApiResponseException(response()->json([
                'message' => 'Too many attempts. Please try again later.',
                'code' => 'auth_rate_limited',
                'retry_after' => $retry,
            ], 429, ['Retry-After' => (string) $retry]));
        }
        return ['key' => $key, 'expires_at' => $expiry];
    }

    public function login(Request $request, Closure $callback): mixed
    {
        $this->consume('login-ip', (string) $request->ip(), 10, 60);
        // Reserve an attempt in a short transaction, then verify credentials
        // without holding a rate-limit writer lock. Refund only this attempt in
        // this window; concurrent and prior failures remain counted.
        $reservation = $this->consume('login-account', $this->accountIdentity($request->input('email')), 10, 900);
        $response = $callback();
        if (in_array($response->getStatusCode(), [200, 202], true)) {
            DB::table('auth_security_limits')->where($reservation)->where('attempts', '>', 0)->decrement('attempts');
        }
        return $response;
    }

    public function recovery(Request $request, string $kind, ?string $email = null): void
    {
        $sending = in_array($kind, ['forgot', 'resend'], true);
        $this->consume($kind.'-ip', (string) $request->ip(), 10, $sending ? 3600 : 900);
        $this->consume($kind.'-account', $this->accountIdentity($email ?? $request->input('email')), $sending ? 3 : 10, $sending ? 3600 : 900);
    }

    private function accountIdentity(mixed $value): string
    {
        // Charge malformed payloads without converting arrays/objects to strings.
        // Normal request validation still returns the field-specific 422 response.
        return is_string($value) ? $value : "\0invalid-email-input";
    }
}
