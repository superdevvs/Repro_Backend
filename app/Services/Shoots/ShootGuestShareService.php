<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootGuestShare;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Support\LockedWrite;
use App\Support\ShootAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ShootGuestShareService
{
    public function canManage(Shoot $shoot, ?User $actor): bool
    {
        if (! $actor || ! $actor->isAccountEligibleForAuthentication() || $shoot->isImportDraft()) return false;
        $access = app(ShootAuthorizationSupport::class);
        if (! $access->canViewShootDetails($shoot, $actor)) return false;
        if ($actor->role === 'client' && (int) $shoot->client_id === (int) $actor->id) return true;
        $permissions = app(\App\Services\RolePermissionService::class);
        if ($access->canManageShootOperations($actor)) return $permissions->userCan($actor, 'shoots', 'manage');
        // Ordinary reps can share assigned shoots without receiving production-management powers.
        return app(ShootManagementAccess::class)->isSalesRep($actor)
            && ! $permissions->userIsDenied($actor, 'shoots', 'view')
            && (int) app(ShootSalesRepResolver::class)->resolve($shoot)?->id === (int) $actor->id;
    }

    public function authorizeManager(Shoot $shoot, User $actor): void
    {
        abort_unless($this->canManage($shoot, $actor), 403);
    }

    public function create(Shoot $shoot, User $actor, string $email): ShootGuestShare
    {
        return $this->createOrReuse($shoot, $actor, $email)['share'];
    }

    /** Reservation is committed before email delivery; batch retries reuse successful recipients. */
    public function createOrReuse(Shoot $shoot, User $actor, string $email, bool $forceNew = false): array
    {
        $this->authorizeManager($shoot, $actor);
        $email = strtolower(trim($email));
        [$share, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $actor, $email, $forceNew) {
            if (! $forceNew) {
                $existing = ShootGuestShare::where('shoot_id', $shoot->id)->where('email', $email)
                    ->whereNull('revoked_at')->where('expires_at', '>', now())->with('creator')->latest('id')->get();
                foreach ($existing as $candidate) {
                    if ($this->canManage($shoot, $candidate->creator)) return [$candidate, false];
                }
            }
            $token = Str::random(64);
            return [ShootGuestShare::create([
                'shoot_id' => $shoot->id, 'created_by' => $actor->id, 'email' => $email,
                'token_hash' => hash('sha256', $token), 'token_encrypted' => $token,
                'expires_at' => now()->addDays(30), 'invitation_status' => 'sending', 'invitation_attempted_at' => now(),
            ]), true];
        }), 'guest-share-create');
        if ($created) $this->deliverInvitation($shoot, $share, true);
        return ['share' => $share->fresh(), 'outcome' => $created ? 'sent' : 'existing'];
    }

    private function deliverInvitation(Shoot $shoot, ShootGuestShare $share, bool $revokeOnFailure): void
    {
        $token = $this->recoverToken($share);
        abort_unless($token, 409, 'This older invitation needs a new link. Use Resend invitation.');
        $url = $this->url($token);
        $propertyAddress = ShootAddress::streetWithAptSuite($shoot->address, $shoot->property_details);
        $expiryCopy = $revokeOnFailure ? 'Access expires in 30 days.'
            : 'Access expires on '.$share->expires_at->copy()->utc()->format('F j, Y').' (UTC).';
        try {
            $this->send($share, 'Your Magic Link to '.$propertyAddress,
                "Your Magic Link to released media and tours for {$propertyAddress}: {$url}. Open the link and request an email verification code. No account is required. {$expiryCopy}",
                view('emails.guest_shoot_invitation', [
                    'propertyAddress' => $propertyAddress,
                    'guestUrl' => $url,
                    'expiryCopy' => $expiryCopy,
                    'magicLinkIllustrationUrl' => rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/brand/magic-link-glass.png',
                    'emailAtelier' => ['kind' => 'none'],
                ])->render());
        } catch (\Throwable $exception) {
            // An explicit revocation remains final even if an in-flight send fails afterward.
            LockedWrite::run(fn () => ShootGuestShare::whereKey($share->id)->where('invitation_status', '!=', 'revoked')
                ->update(['invitation_status' => 'failed']
                    + ($revokeOnFailure ? ['revoked_at' => now()] : [])), 'guest-share-send-failed');
            Log::error('Guest invitation delivery failed.', ['share_id' => $share->id, 'exception' => $exception::class]);
            abort(503, 'The invitation could not be sent. Please try again.');
        }
        // A bookkeeping failure after provider acceptance must not revoke a successfully sent link.
        LockedWrite::run(fn () => ShootGuestShare::whereKey($share->id)->whereNull('revoked_at')
            ->update(['invitation_status' => 'sent', 'invitation_sent_at' => now()]), 'guest-share-sent');
    }

    public function resend(Shoot $shoot, User $actor, ShootGuestShare $share): array
    {
        $this->authorizeManager($shoot, $actor);
        abort_unless((int) $share->shoot_id === (int) $shoot->id, 404);
        abort_if($share->revoked_at && $share->invitation_status !== 'failed', 409,
            'This invitation was revoked. Create a new invitation to share again.');
        $claimed = LockedWrite::run(fn () => ShootGuestShare::whereKey($share->id)
            ->where(fn ($query) => $query->whereNull('invitation_attempted_at')->orWhere('invitation_attempted_at', '<=', now()->subMinute()))
            ->update(['invitation_attempted_at' => now()]), 'guest-share-resend-claim');
        abort_unless($claimed, 429, 'Wait one minute before sending this invitation again.');
        $share->refresh();
        abort_if($share->revoked_at && $share->invitation_status !== 'failed', 409,
            'This invitation was revoked. Create a new invitation to share again.');
        if ($share->revoked_at || $share->expires_at->isPast() || ! $this->recoverToken($share)
            || ! $this->canManage($shoot, $share->creator)) {
            // Explicit renewal keeps earlier links unchanged; revocation is a separate action.
            return $this->createOrReuse($shoot, $actor, $share->email, true) + ['replacement' => true];
        }
        $active = LockedWrite::run(fn () => ShootGuestShare::whereKey($share->id)->whereNull('revoked_at')
            ->update(['invitation_status' => 'sending']), 'guest-share-resending');
        abort_unless($active, 409, 'This invitation was revoked. Create a new invitation to share again.');
        $this->deliverInvitation($shoot, $share, false);
        return ['share' => $share->fresh(), 'outcome' => 'sent', 'replacement' => false];
    }

    public function copyLink(Shoot $shoot, User $actor, ShootGuestShare $share): string
    {
        $this->authorizeManager($shoot, $actor);
        abort_unless((int) $share->shoot_id === (int) $shoot->id, 404);
        $this->authorizeResolvedShare($share);
        $token = $this->recoverToken($share);
        abort_unless($token, 409, 'This older invitation has no copyable link. Resend it to create a new link.');
        return $this->url($token);
    }

    private function recoverToken(ShootGuestShare $share): ?string
    {
        try { $token = $share->token_encrypted; }
        catch (\Illuminate\Contracts\Encryption\DecryptException) { return null; }
        return is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token)
            && hash_equals($share->token_hash, hash('sha256', $token)) ? $token : null;
    }

    private function url(string $token): string
    {
        return rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/guest/shoot/'.$token;
    }

    /** Authorized management DTO; secrets and URLs are never serialized into the list. */
    public function managementData(ShootGuestShare $share): array
    {
        $eligible = $share->shoot && $this->canManage($share->shoot, $share->creator);
        $active = ! $share->revoked_at && $share->expires_at->isFuture() && $eligible;
        $status = $share->revoked_at ? ($share->invitation_status === 'failed' ? 'failed' : 'revoked')
            : ($share->expires_at->isPast() ? 'expired' : (! $eligible ? 'unavailable'
                : (in_array($share->invitation_status, ['sending', 'failed'], true) ? $share->invitation_status
                    : ($share->last_verified_at ? 'verified' : 'active'))));
        return [
            'id' => $share->id, 'email' => $share->email, 'status' => $status,
            'created_at' => $share->created_at?->toIso8601String(), 'expires_at' => $share->expires_at->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(), 'last_verified_at' => $share->last_verified_at?->toIso8601String(),
            'last_sent_at' => $share->invitation_sent_at?->toIso8601String(),
            'resend_available_at' => $share->invitation_attempted_at?->copy()->addMinute()->toIso8601String(),
            'creator' => $share->creator ? ['id' => $share->creator->id, 'name' => $this->senderName($share)] : null,
            'can_copy' => $active && $this->recoverToken($share) !== null,
            'can_resend' => ! $share->revoked_at || $share->invitation_status === 'failed',
        ];
    }

    public function resolve(string $token): ShootGuestShare
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $share = ShootGuestShare::where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->authorizeResolvedShare($share);
        return $share;
    }

    /** Shared with queued guest downloads; callers load the current share from storage. */
    public function authorizeResolvedShare(ShootGuestShare $share): void
    {
        abort_if($share->revoked_at || $share->expires_at->isPast(), 410, 'This invitation has expired or been revoked.');
        abort_unless($share->shoot && ! $share->shoot->isImportDraft(), 404);
        $creator = $share->creator;
        abort_unless($creator, 410, 'This invitation is no longer available.');
        $this->authorizeManager($share->shoot, $creator);
    }

    /** Display only the authorized invitation creator's name, never contact details. */
    public function senderName(ShootGuestShare $share): string
    {
        $name = Str::squish((string) $share->creator?->name);

        // Some imported accounts use an email address as their display name.
        return $name !== '' && ! str_contains($name, '@') ? $name : 'Your sender';
    }

    public function requestCode(ShootGuestShare $share): void
    {
        $key = 'guest-code:'.$share->id;
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429, 'Please try again later.');
        RateLimiter::hit($key, 3600);
        $code = (string) random_int(100000, 999999);
        $claimed = LockedWrite::run(fn () => ShootGuestShare::whereKey($share->id)
            ->where(fn ($query) => $query->whereNull('code_sent_at')->orWhere('code_sent_at', '<=', now()->subMinute()))
            ->update(['code_hash' => $this->codeHash($share, $code), 'code_expires_at' => now()->addMinutes(10),
                'code_sent_at' => now(), 'code_attempts' => 0]), 'guest-share-code');
        abort_unless($claimed, 429, 'Wait one minute before requesting another code.');
        try {
            $this->send($share, 'Your property access code',
                "Your verification code is {$code}. It expires in 10 minutes. If you did not request this code, ignore this email.",
                view('emails.guest_shoot_code', [
                    'verificationCode' => $code,
                    'emailAtelier' => ['kind' => 'none'],
                ])->render());
        } catch (\Throwable $exception) {
            LockedWrite::run(fn () => ShootGuestShare::whereKey($share->id)->where('code_hash', $this->codeHash($share, $code))
                ->update(['code_hash' => null]), 'guest-share-code-failed');
            Log::error('Guest verification email failed.', ['share_id' => $share->id, 'exception' => $exception::class]);
            abort(503, 'The code could not be sent. Please try again shortly.');
        }
    }

    public function verify(ShootGuestShare $share, string $code): string
    {
        $valid = LockedWrite::run(fn () => DB::transaction(function () use ($share, $code) {
            $fresh = ShootGuestShare::whereKey($share->id)->lockForUpdate()->firstOrFail();
            if ($fresh->revoked_at || $fresh->expires_at->isPast() || ! $fresh->code_hash
                || ! $fresh->code_expires_at || $fresh->code_expires_at->isPast() || $fresh->code_attempts >= 5) return false;
            $valid = hash_equals($fresh->code_hash, $this->codeHash($fresh, $code));
            $fresh->update(['code_attempts' => $fresh->code_attempts + 1,
                'code_hash' => $valid ? null : $fresh->code_hash,
                'last_verified_at' => $valid ? now() : $fresh->last_verified_at]);
            return $valid;
        }), 'guest-share-verify');
        abort_unless($valid, 422, 'This code is invalid or expired. Request a new code if needed.');
        $session = Str::random(64);
        Cache::put('guest-session:'.hash('sha256', $session), (int) $share->id, now()->addHours(12));
        return $session;
    }

    public function verified(Request $request, ShootGuestShare $share): bool
    {
        $session = $request->cookie($this->cookieName($share));
        return is_string($session) && strlen($session) === 64
            && Cache::get('guest-session:'.hash('sha256', $session)) === (int) $share->id;
    }

    public function cookieName(ShootGuestShare $share): string
    {
        return 'repro_guest_'.substr($share->token_hash, 0, 12);
    }

    private function codeHash(ShootGuestShare $share, string $code): string
    {
        return hash_hmac('sha256', $share->id.':'.$code, (string) config('app.key'));
    }

    private function send(ShootGuestShare $share, string $subject, string $text, string $html): void
    {
        $message = app(MessagingService::class)->sendEmail([
            'to' => $share->email, 'subject' => $subject, 'body_text' => $text, 'body_html' => $html,
            'send_source' => 'SHOOT_GUEST_ACCESS', 'sender_name' => 'R/E Pro Photos',
            'related_shoot_id' => $share->shoot_id, 'contact_email' => $share->email, 'contact_type' => 'other',
            'metadata' => ['guest_share_id' => $share->id],
        ]);
        if ($message->status !== 'SENT') throw new \RuntimeException('Guest access email was not sent.');
    }
}
