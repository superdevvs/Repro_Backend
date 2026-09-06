<?php

namespace App\Services;

use App\Models\DropboxStudioToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DropboxTokenService
{
    // All studio credential writers must use this lock on the shared cache store.
    public const CONNECTION_LOCK = 'dropbox:studio:connection';

    public function record(): ?DropboxStudioToken
    {
        return DropboxStudioToken::where('provider', 'dropbox')->whereNull('user_id')
            ->where('account_type', 'shared')->latest('id')->first();
    }

    public function configured(): bool
    {
        $record = $this->record();
        return !($record?->metadata['disconnected'] ?? false)
            && (bool) ($this->accessToken($record) ?: $this->refreshToken($record));
    }

    public function version(): string
    {
        return $this->recordVersion($this->record());
    }

    /** Local maintenance only: encrypt existing studio rows without changing their connection generation. */
    public function encryptLegacyCredentials(bool $apply = false): array
    {
        return $this->locked(fn () => DB::transaction(function () use ($apply) {
            $records = DropboxStudioToken::where('provider', 'dropbox')->whereNull('user_id')
                ->where('account_type', 'shared')->lockForUpdate()->get();
            $result = ['records_scanned' => $records->count(), 'records_needing_encryption' => 0, 'records_updated' => 0];
            foreach ($records as $record) {
                $needsEncryption = false;
                foreach (['access_token', 'refresh_token'] as $key) {
                    $raw = $record->getRawOriginal($key);
                    $needsEncryption = $needsEncryption || ($raw !== null && !str_starts_with($raw, 'encrypted:v1:'));
                }
                if (!$needsEncryption) { continue; }
                $result['records_needing_encryption']++;
                if (!$apply) { continue; }
                $version = $this->recordVersion($record);
                $record->fill([
                    'access_token' => $record->access_token, 'refresh_token' => $record->refresh_token,
                    'metadata' => array_merge($record->metadata ?? [], ['connection_version' => $version]),
                ])->save();
                $result['records_updated']++;
            }
            return $result;
        }));
    }

    /** CLI recovery after an operator verifies revocation in Dropbox; never infer success from provider errors. */
    public function acknowledgeProviderRevocation(string $expectedVersion, bool $apply = false, bool $confirmed = false): array
    {
        return $this->locked(function () use ($expectedVersion, $apply, $confirmed) {
            $this->assertVersion($expectedVersion);
            $record = $this->record();
            abort_unless($record && ($record->metadata['disconnected'] ?? false)
                && ($record->metadata['revocation_pending'] ?? false), 409, 'Dropbox has no pending disconnected connection to acknowledge.');
            abort_if($apply && !$confirmed, 422, 'Explicit confirmation of provider revocation is required.');
            if ($apply) {
                $record->fill([
                    'access_token' => null, 'refresh_token' => null, 'expires_at' => null,
                    'metadata' => array_merge($record->metadata ?? [], [
                        'revocation_pending' => false,
                        'connection_version' => bin2hex(random_bytes(32)),
                        'revocation_acknowledged_at' => now()->toIso8601String(),
                        'revocation_acknowledged_via' => 'operator_cli',
                    ]),
                ])->save();
                Log::notice('Dropbox provider revocation acknowledged by CLI operator.', [
                    'connection_id' => $record->id,
                    'connection_version' => $record->metadata['connection_version'],
                ]);
            }
            return [
                'applied' => $apply, 'connected' => false,
                'revocation_pending' => (bool) $record->metadata['revocation_pending'],
                'connection_version' => $this->recordVersion($record),
            ];
        });
    }

    /** Verify the current account before allowing an administrator to reconnect. */
    public function currentAccountId(): ?string
    {
        return $this->locked(function () {
            $record = $this->record();
            if (($record?->metadata['disconnected'] ?? false)
                || !($this->accessToken($record) ?: $this->refreshToken($record))) {
                return null;
            }
            $version = $this->recordVersion($record);
            $token = $this->resolveAccessToken();
            try {
                $response = Http::timeout(15)->withToken($token)->withBody('null', 'application/json')
                    ->post('https://api.dropboxapi.com/2/users/get_current_account');
                $accountId = $response->json('account_id');
                if (!$response->successful() || !is_string($accountId) || $accountId === '') {
                    throw new RuntimeException('Dropbox account identity is unavailable.');
                }
            } catch (\Throwable $e) {
                throw new RuntimeException('Unable to verify the existing Dropbox account. Try again or disconnect it first.');
            }
            $this->assertVersion($version);
            $record = $this->record() ?: $this->newRecord();
            if ($record->provider_account_id && $record->provider_account_id !== $accountId) {
                throw new RuntimeException('Dropbox account identity changed. Disconnect it before connecting another account.');
            }
            $record->fill([
                'access_token' => $token,
                'refresh_token' => $this->refreshToken($this->record()),
                'provider_account_id' => $accountId,
                'provider_account_email' => $response->json('email'),
                'provider_account_name' => $response->json('name.display_name'),
                'metadata' => array_merge($record->metadata ?? [], ['connection_version' => $version]),
            ])->save();
            return $accountId;
        });
    }

    /** $account must come from get_current_account using the newly issued access token. */
    public function bind(array $tokenData, array $account, string $expectedVersion, User $admin, ?callable $authorize = null): DropboxStudioToken
    {
        $this->assertAdministrator($admin);
        return $this->locked(function () use ($tokenData, $account, $expectedVersion, $admin, $authorize) {
            $this->assertVersion($expectedVersion);
            $record = $this->record() ?: $this->newRecord();
            abort_if($record->metadata['revocation_pending'] ?? false, 409, 'Retry the pending Dropbox disconnect before reconnecting.');
            $accountId = $account['account_id'] ?? $account['id'] ?? null;
            abort_unless(is_string($accountId) && $accountId !== '', 422, 'Dropbox did not provide a verified account identity.');
            if (!($record->metadata['disconnected'] ?? false) && $record->provider_account_id) {
                abort_unless(hash_equals($record->provider_account_id, $accountId), 409, 'Disconnect the current Dropbox account before connecting another account.');
            }
            foreach (['access_token', 'refresh_token'] as $key) {
                abort_unless(is_string($tokenData[$key] ?? null) && $tokenData[$key] !== '', 422, 'Dropbox did not provide offline access. Start the connection again.');
            }
            $this->assertAdministrator($admin);
            if ($authorize !== null) { $authorize(); }
            $record->fill([
                'access_token' => $tokenData['access_token'], 'refresh_token' => $tokenData['refresh_token'],
                'expires_at' => $this->expiresAt($tokenData), 'scopes' => $tokenData['scope'] ?? null,
                'provider_account_id' => $accountId, 'provider_account_email' => $account['email'] ?? null,
                'provider_account_name' => is_array($account['name'] ?? null) ? ($account['name']['display_name'] ?? null) : ($account['name'] ?? null),
                'metadata' => array_merge(array_intersect_key($record->metadata ?? [], array_flip([
                    'revocation_acknowledged_at', 'revocation_acknowledged_via',
                ])), [
                    'connected_at' => now()->toIso8601String(), 'connected_by' => $admin->id,
                    'connection_version' => bin2hex(random_bytes(32)), 'disconnected' => false, 'revocation_pending' => false,
                ]),
            ])->save();
            return $record;
        });
    }

    /** Local disconnection persists even if Dropbox is unavailable; retries use retained encrypted credentials only. */
    public function disconnect(string $expectedVersion, User $admin): array
    {
        $this->assertAdministrator($admin);
        return $this->locked(function () use ($expectedVersion, $admin) {
            $this->assertVersion($expectedVersion);
            $this->assertAdministrator($admin);
            $existing = $this->record();
            $record = $existing ?: $this->newRecord();
            $accessToken = $this->accessToken($existing);
            $refreshToken = $this->refreshToken($existing);
            $pending = (bool) ($accessToken ?: $refreshToken);
            $record->fill([
                'access_token' => $accessToken, 'refresh_token' => $refreshToken,
                'metadata' => array_merge($record->metadata ?? [], [
                    'disconnected' => true, 'disconnected_at' => now()->toIso8601String(),
                    'disconnected_by' => $admin->id, 'connection_version' => bin2hex(random_bytes(32)),
                    'revocation_pending' => $pending,
                ]),
            ])->save();
            $disconnectVersion = $this->recordVersion($record);
            if ($pending) {
                try {
                    if ($refreshToken && (!$accessToken || !$record->expires_at?->isFuture())) {
                        $data = $this->requestRefresh($refreshToken);
                        if ($data !== null) {
                            $this->assertVersion($disconnectVersion);
                            $accessToken = $data['access_token'];
                            $record->fill(['access_token' => $accessToken,
                                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                                'expires_at' => $this->expiresAt($data)])->save();
                        }
                    }
                    if ($accessToken) {
                        $response = Http::timeout(15)->withToken($accessToken)->withBody('null', 'application/json')
                            ->post('https://api.dropboxapi.com/2/auth/token/revoke');
                        $pending = !$response->successful();
                    }
                } catch (\Throwable $e) {
                    Log::warning('Dropbox revocation pending', ['exception' => $e::class]);
                }
                $this->assertVersion($disconnectVersion);
                if (!$pending) {
                    $record->fill(['access_token' => null, 'refresh_token' => null, 'expires_at' => null]);
                }
                $record->metadata = array_merge($record->metadata ?? [], ['revocation_pending' => $pending]);
                $record->save();
            }
            return ['connected' => false, 'revocation_pending' => $pending];
        });
    }

    public function getValidAccessToken(): string
    {
        return $this->locked(fn () => $this->resolveAccessToken());
    }

    public function isTokenValid($accessToken): bool
    {
        if (!$accessToken) { return false; }
        return Cache::remember('dropbox:valid:'.hash('sha256', $accessToken), 240, function () use ($accessToken) {
            try {
                return Http::timeout(15)->withToken($accessToken)->withBody('null', 'application/json')
                    ->post('https://api.dropboxapi.com/2/users/get_current_account')->successful();
            } catch (\Throwable $e) {
                Log::warning('Dropbox token validation unavailable', ['exception' => $e::class]);
                return false;
            }
        });
    }

    private function resolveAccessToken(): string
    {
        $record = $this->record();
        if ($record?->metadata['disconnected'] ?? false) {
            throw new RuntimeException('Dropbox is disconnected. An administrator must reconnect it.');
        }
        $version = $this->recordVersion($record);
        $accessToken = $this->accessToken($record);
        if ($accessToken && ($record?->expires_at?->isFuture() || $this->isTokenValid($accessToken))) {
            $this->assertVersion($version);
            return $accessToken;
        }
        $refreshToken = $this->refreshToken($record);
        if ($refreshToken && ($data = $this->requestRefresh($refreshToken))) {
            // A lost/expired lock cannot resurrect an older connection after the network call.
            $this->assertVersion($version);
            $record = $this->record() ?: $this->newRecord();
            $record->fill([
                'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'expires_at' => $this->expiresAt($data),
                'metadata' => array_merge($record->metadata ?? [], ['connection_version' => $version]),
            ])->save();
            return $record->access_token;
        }
        throw new RuntimeException('Dropbox needs to be reconnected by an administrator.');
    }

    private function requestRefresh(string $refreshToken): ?array
    {
        try {
            $response = Http::timeout(15)->withBasicAuth((string) config('services.dropbox.client_id'), (string) config('services.dropbox.client_secret'))
                ->asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                    'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
                ]);
            if ($response->successful() && is_string($response->json('access_token')) && $response->json('access_token') !== '') {
                return $response->json();
            }
            Log::warning('Dropbox token refresh failed', ['status' => $response->status()]);
        } catch (\Throwable $e) {
            Log::warning('Dropbox token refresh unavailable', ['exception' => $e::class]);
        }
        return null;
    }

    private function accessToken(?DropboxStudioToken $record): ?string
    {
        return $record ? $record->access_token : (config('services.dropbox.access_token') ?: null);
    }

    private function refreshToken(?DropboxStudioToken $record): ?string
    {
        return $record ? $record->refresh_token : (config('services.dropbox.refresh_token') ?: null);
    }

    private function newRecord(): DropboxStudioToken
    {
        return new DropboxStudioToken(['provider' => 'dropbox', 'user_id' => null, 'account_type' => 'shared']);
    }

    private function recordVersion(?DropboxStudioToken $record): string
    {
        if (is_string($record?->metadata['connection_version'] ?? null)) {
            return $record->metadata['connection_version'];
        }
        return hash('sha256', json_encode($record
            ? [$record->id, $record->provider_account_id, $record->getRawOriginal('access_token'), $record->getRawOriginal('refresh_token'), $record->metadata['disconnected'] ?? false]
            : ['environment', config('services.dropbox.access_token'), config('services.dropbox.refresh_token')]));
    }

    private function assertVersion(string $expectedVersion): void
    {
        abort_unless(hash_equals($this->version(), $expectedVersion), 409, 'The Dropbox connection changed. Reload and try again.');
    }

    private function assertAdministrator(User $admin): void
    {
        $current = $admin->fresh();
        abort_unless($current && in_array($current->role, ['admin', 'superadmin'], true)
            && $current->isAccountEligibleForAuthentication() && !request()->attributes->get('is_impersonating', false),
            403, 'Only an active administrator can manage the studio Dropbox connection.');
    }

    private function expiresAt(array $data): \Illuminate\Support\Carbon
    {
        return now()->addSeconds(max(0, (int) ($data['expires_in'] ?? 14400) - 60));
    }

    private function locked(callable $callback): mixed
    {
        return Cache::lock(self::CONNECTION_LOCK, 90)->block(10, $callback);
    }
}
