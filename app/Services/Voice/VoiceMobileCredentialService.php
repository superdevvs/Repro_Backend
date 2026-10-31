<?php

namespace App\Services\Voice;

use App\Models\VoiceBrowserLeg;
use App\Models\VoiceMobileOffer;
use App\Models\VoiceMobileSession;
use App\Models\VoiceMobileTicket;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Credential identity outlives the short SDK token, but never its authorized registration. */
class VoiceMobileCredentialService
{
    public function __construct(private VoiceBrowserGateway $gateway) {}

    /** @param Closure(): Carbon $assertOwner Fresh authorization and receiving-registration ceiling. */
    public function renew(VoiceMobileSession $session, Carbon $expiry, bool $allowReplacement, Closure $assertOwner): array
    {
        $expiry = $expiry->min($assertOwner());
        abort_unless($expiry->gt(now()->addSeconds(30)), 401, 'Sign in again before connecting mobile calling.');
        if (! $session->credential_id) {
            abort_unless($allowReplacement, 503, 'Open R/E Pro to reconnect calling on this phone.');
            $credential = $this->createOrReconcile($session, $expiry, null, $assertOwner);
        } else {
            $credential = $this->gateway->request('GET', '/telephony_credentials/'.$session->credential_id);
            $this->assertIdentity($session, $credential);
            if ($this->expired($credential)) {
                // A new gencred has not registered this phone's PushKit token yet.
                // Only an authenticated foreground bootstrap can install its SDK identity.
                abort_unless($allowReplacement, 503, 'Open R/E Pro to reconnect calling on this phone.');
                $credential = VoiceLocks::lock('voice-browser-user:'.$session->user_id, 60)->block(5,
                    fn () => $this->createOrReconcile($session, $expiry, $session->credential_id, $assertOwner));
            }
        }
        $assertOwner();
        $this->assertIdentity($session, $credential);
        abort_if($this->expired($credential), 503, 'Mobile calling requires credential reconciliation.');
        // The provider identity lasts only as long as this login's receiving
        // registration. The separately signed SDK token still expires in an hour.
        $expiry = $expiry->min($assertOwner());
        $updated = $this->gateway->request('PATCH', '/telephony_credentials/'.$session->credential_id,
            ['expires_at' => $expiry->toIso8601String()]);
        if (! is_array($updated) || ! isset($updated['id'])) {
            $updated = $this->gateway->request('GET', '/telephony_credentials/'.$session->credential_id);
        }
        $this->assertIdentity($session, $updated);
        abort_if($this->expired($updated), 503, 'Open R/E Pro to reconnect calling on this phone.');
        abort_unless(is_string($updated['expires_at'] ?? null) && is_string($updated['user_id'] ?? null),
            503, 'The phone provider did not confirm the mobile credential lifetime.');
        $confirmed = rescue(fn () => Carbon::parse($updated['expires_at'], 'UTC'), null, false);
        abort_unless($confirmed && $confirmed->gt(now()->addSeconds(30)) && $confirmed->lte($expiry),
            503, 'The phone provider did not confirm the mobile credential lifetime.');
        $freshCeiling = $expiry->min($assertOwner());
        if ($confirmed->gt($freshCeiling)) {
            // A login deadline can be shortened while the provider request is in
            // flight. One bounded correction; never return an overlong identity.
            $updated = $this->gateway->request('PATCH', '/telephony_credentials/'.$session->credential_id,
                ['expires_at' => $freshCeiling->toIso8601String()]);
            $this->assertIdentity($session, $updated);
            abort_unless(is_string($updated['expires_at'] ?? null) && is_string($updated['user_id'] ?? null),
                503, 'The phone provider did not confirm the mobile credential lifetime.');
            $confirmed = rescue(fn () => Carbon::parse($updated['expires_at'] ?? '', 'UTC'), null, false);
            abort_unless($confirmed && $confirmed->gt(now()->addSeconds(30)) && $confirmed->lte($freshCeiling)
                && ! $this->expired($updated), 503, 'The phone provider did not confirm the mobile credential lifetime.');
        }
        // Return verified identity metadata only. SDK tokens are issued by Telnyx;
        // credential signing material is neither fetched nor persisted.
        abort_unless($confirmed->lte($assertOwner()), 503, 'The mobile login lifetime changed. Reconnect calling.');

        return ['id' => $session->credential_id, 'user_id' => $updated['user_id'],
            'sip_username' => $session->sip_username, 'expires_at' => $updated['expires_at'],
            'token_material' => null];
    }

    private function createOrReconcile(VoiceMobileSession $s, Carbon $expiry, ?string $oldId, Closure $assertOwner): array
    {
        $assertOwner();
        if ($oldId !== null) {
            $this->assertIdle($s);
            abort_if($s->credential_rotation_from && $s->credential_rotation_from !== $oldId, 503, 'Mobile calling requires credential reconciliation.');
        }
        $name = $this->name($s, $oldId);
        $rows = $this->gateway->request('GET', '/telephony_credentials',
            ['filter[name]' => $name, 'filter[resource_id]' => 'connection:'.$s->connection_id]);
        $matches = collect($rows)->filter(fn ($v) => is_array($v) && ($v['name'] ?? null) === $name && $this->belongsTo($s, $v))->values();
        abort_if($matches->count() > 1, 503, 'Mobile credential creation needs provider reconciliation.');
        $credential = $matches->first();
        if (! $credential) {
            abort_if($oldId === null ? $s->credential_attempted_at !== null : $s->credential_rotation_from !== null,
                503, 'The provider has not confirmed this mobile credential. Retry later.');
            $this->write($s, $oldId, $assertOwner, function ($current) use ($oldId) {
                if ($oldId !== null) {
                    $this->assertIdle($current);
                }
                abort_if($oldId === null ? $current->credential_attempted_at !== null : $current->credential_rotation_from !== null,
                    503, 'The provider has not confirmed this mobile credential. Retry later.');
                $current->credential_attempted_at = now();
                $current->credential_rotation_from = $oldId;
                $current->save();
            });
            $expiry = $expiry->min($assertOwner());
            $credential = $this->gateway->request('POST', '/telephony_credentials',
                ['connection_id' => $s->connection_id, 'name' => $name, 'expires_at' => $expiry->toIso8601String()]);
        }
        abort_unless(is_array($credential) && filled($credential['id'] ?? null) && $this->belongsTo($s, $credential)
            && preg_match('/^gencred[A-Za-z0-9_-]+$/', $credential['sip_username'] ?? '') && ! $this->expired($credential),
            503, 'The provider did not confirm an active mobile credential.');
        $this->write($s, $oldId, $assertOwner, function ($current) use ($oldId, $credential) {
            if ($oldId !== null) {
                $this->assertIdle($current);
            }
            $current->credential_id = $credential['id'];
            $current->sip_username = $credential['sip_username'];
            $current->credential_rotation_from = null;
            $current->save();
        });

        return $credential;
    }

    private function name(VoiceMobileSession $s, ?string $oldId): string
    {
        return 'repro-mobile-'.$s->id.($oldId === null ? '' : '-r-'.substr(hash('sha256', $oldId), 0, 20));
    }

    private function belongsTo(VoiceMobileSession $s, #[\SensitiveParameter] array $v): bool
    {
        // Telnyx uses connection:<id>. Older responses/test adapters used the raw id.
        return in_array($v['resource_id'] ?? null, ['connection:'.$s->connection_id, $s->connection_id], true);
    }

    private function expired(#[\SensitiveParameter] array $v): bool
    {
        return ($v['expired'] ?? null) === true || ($v['status'] ?? null) === 'expired';
    }

    private function assertIdentity(VoiceMobileSession $s, #[\SensitiveParameter] mixed $v): void
    {
        abort_unless(is_array($v) && ($v['id'] ?? null) === $s->credential_id && $this->belongsTo($s, $v)
            && ($v['sip_username'] ?? null) === $s->sip_username, 503, 'Mobile provider settings require verification.');
    }

    private function assertIdle(VoiceMobileSession $s): void
    {
        abort_if(VoiceBrowserLeg::where('mobile_session_id', $s->id)->whereNull('ended_at')->exists()
            || VoiceMobileTicket::where('session_id', $s->id)->where('state', 'pending')->where('expires_at', '>', now())->exists()
            || VoiceMobileOffer::where('session_id', $s->id)->whereNotIn('state', ['cancelled', 'ended'])->where('expires_at', '>', now())->exists(),
            409, 'Finish the current call before reconnecting calling.');
    }

    private function write(VoiceMobileSession $s, ?string $expectedId, Closure $assertOwner, Closure $change): void
    {
        try {
            LockedWrite::run(fn () => DB::transaction(function () use ($s, $expectedId, $assertOwner, $change) {
                $assertOwner();
                $current = VoiceMobileSession::lockForUpdate()->findOrFail($s->id);
                abort_unless(! $current->revoked_at && $current->credential_id === $expectedId
                    && $current->personal_access_token_id === $s->personal_access_token_id, 409, 'Mobile registration changed. Reconnect calling.');
                $change($current);
            }), 'mobile-credential-lifecycle');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Preserve a logout cleanup marker outside the rolled-back adoption.
            if ($e->getStatusCode() === 401) {
                $assertOwner();
            }
            throw $e;
        }
        $s->refresh();
    }

    /** Logout can race an accepted/unknown rotation; cleanup the exact reserved identity only. */
    public function cleanupPending(VoiceMobileSession $s): void
    {
        $s->refresh();
        if (! $s->credential_cleanup_id && ! $s->credential_rotation_from && ($s->credential_id || ! $s->credential_attempted_at)) {
            return;
        }
        abort_unless($s->revoked_at, 409, 'Revoke mobile calling before credential cleanup.');
        $oldId = $s->credential_rotation_from;
        $target = $s->credential_cleanup_id;
        if (! $target) {
            $name = $this->name($s, $oldId);
            $rows = $this->gateway->request('GET', '/telephony_credentials', ['filter[name]' => $name, 'filter[resource_id]' => 'connection:'.$s->connection_id]);
            $matches = collect($rows)->filter(fn ($v) => is_array($v) && ($v['name'] ?? null) === $name && $this->belongsTo($s, $v))->values();
            abort_unless($matches->count() === 1 && filled($matches[0]['id'] ?? null), 503, 'Mobile credential cleanup is awaiting provider confirmation.');
            $target = $matches[0]['id'];
            LockedWrite::run(fn () => VoiceMobileSession::whereKey($s->id)->whereNotNull('revoked_at')
                ->where('credential_rotation_from', $oldId)->whereNull('credential_cleanup_id')
                ->update(['credential_cleanup_id' => $target]), 'mobile-credential-cleanup-reserve');
            $s->refresh();
            abort_unless($s->credential_cleanup_id === $target, 409, 'Mobile cleanup changed. Retry to reconcile.');
        }
        $this->gateway->request('DELETE', '/telephony_credentials/'.$target);
        $cleared = ['credential_rotation_from' => null, 'credential_cleanup_id' => null];
        if ($oldId === null) {
            $cleared['credential_attempted_at'] = null;
        }
        LockedWrite::run(fn () => VoiceMobileSession::whereKey($s->id)->whereNotNull('revoked_at')->where('credential_rotation_from', $oldId)
            ->where('credential_cleanup_id', $target)
            ->update($cleared), 'mobile-credential-cleanup');
        $s->refresh();
    }
}
