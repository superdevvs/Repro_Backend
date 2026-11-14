<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootGuestShare;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ShootGuestShareService
{
    public function authorizeManager(Shoot $shoot, User $actor): void
    {
        $access = app(ShootAuthorizationSupport::class);
        abort_unless($actor->isAccountEligibleForAuthentication() && ! $shoot->isImportDraft() && $access->canViewShootDetails($shoot, $actor)
            && (($access->canManageShootOperations($actor) && app(\App\Services\RolePermissionService::class)->userCan($actor, 'shoots', 'manage'))
                || ($actor->role === 'client' && (int) $shoot->client_id === (int) $actor->id)), 403);
    }

    public function create(Shoot $shoot, User $actor, string $email): ShootGuestShare
    {
        $this->authorizeManager($shoot, $actor);
        $token = Str::random(64);
        $share = LockedWrite::run(fn () => ShootGuestShare::create([
            'shoot_id' => $shoot->id, 'created_by' => $actor->id, 'email' => strtolower(trim($email)),
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30),
        ]), 'guest-share-create');
        $url = rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/guest/shoot/'.$token;
        try {
            $this->send($share, 'Your Magic Link to '.$shoot->address,
                "Your Magic Link to released media and tours for {$shoot->address}: {$url}. Open the link and request an email verification code. No account is required. Access expires in 30 days.",
                view('emails.guest_shoot_invitation', [
                    'propertyAddress' => $shoot->address,
                    'guestUrl' => $url,
                    'emailAtelier' => ['kind' => 'none'],
                ])->render());
        } catch (\Throwable $exception) {
            LockedWrite::run(fn () => $share->update(['revoked_at' => now()]), 'guest-share-send-failed');
            report($exception);
            abort(503, 'The invitation could not be sent. Please try again.');
        }
        return $share;
    }

    public function resolve(string $token): ShootGuestShare
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $share = ShootGuestShare::where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($share->revoked_at || $share->expires_at->isPast(), 410, 'This invitation has expired or been revoked.');
        abort_unless($share->shoot && ! $share->shoot->isImportDraft(), 404);
        $creator = $share->creator;
        abort_unless($creator, 410, 'This invitation is no longer available.');
        $this->authorizeManager($share->shoot, $creator);
        return $share;
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
            report($exception);
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
