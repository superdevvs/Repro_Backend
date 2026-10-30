<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MobileNotificationDevice;
use App\Models\PersonalAccessToken;
use App\Services\MobileNotifications\MobileNotificationTransport;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileNotificationController extends Controller
{
    public function capabilities(MobileNotificationTransport $transport)
    {
        return response()->json(['enabled' => (bool) $transport->ready(),
            'environment' => config('mobile_notifications.environment'), 'topic' => config('mobile_notifications.topic')])->header('Cache-Control', 'no-store');
    }
    public function register(Request $request, string $installation, MobileNotificationTransport $transport)
    {
        abort_unless($transport->ready(), 503, 'Mobile notifications are not available yet.');
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->exists
            && (int) $token->tokenable_id === (int) $request->user()->id
            && ! $request->attributes->get('is_impersonating', false), 403);
        $data = $request->validate([
            'sequence' => ['required', 'integer', 'min:1', 'max:9000000000000000'],
            'token' => ['required', 'string', 'min:2', 'max:512', 'regex:/\A(?:[a-fA-F0-9]{2})+\z/'],
            'environment' => ['required', Rule::in([config('mobile_notifications.environment')])],
            'permission' => ['required', Rule::in(['authorized', 'provisional'])],
        ]);
        LockedWrite::run(fn () => DB::transaction(function () use ($request, $installation, $token, $data) {
            abort_unless(PersonalAccessToken::whereKey($token->id)->exists() && $request->user()->fresh()?->isAccountEligibleForAuthentication(), 403);
            $current = MobileNotificationDevice::whereKey($installation)->lockForUpdate()->first();
            abort_if($current && $current->personal_access_token_id > $token->id, 409, 'A newer sign-in owns this installation.');
            abort_if($current && $current->sequence >= $data['sequence'], 409, 'This notification registration has been superseded.');
            $hash = hash('sha256', strtolower($data['token']));
            // One app installation/token has one current owner. A new login
            // invalidates the old binding and any queued old-owner deliveries.
            $otherBindings = MobileNotificationDevice::where('token_hash', $hash)->where('id', '!=', $installation)->lockForUpdate()->get();
            // Sequence numbers are local to one installation UUID. Across UUIDs
            // only a strictly newer login can replace the prior token owner;
            // an equal/older login cannot reorder two installations by replay.
            abort_if($otherBindings->contains(fn ($binding) => $binding->personal_access_token_id >= $token->id),
                409, 'Another current installation owns this notification token. Sign in again before registering this installation.');
            MobileNotificationDevice::whereIn('id', $otherBindings->pluck('id'))->delete();
            MobileNotificationDevice::updateOrCreate(['id' => $installation], [
                'user_id' => $request->user()->id, 'personal_access_token_id' => $token->id,
                'token' => strtolower($data['token']), 'token_hash' => $hash,
                'environment' => $data['environment'], 'permission' => $data['permission'],
                'expires_at' => now()->addDays(30), 'revoked_at' => null, 'sequence' => $data['sequence'],
            ]);
        }), 'mobile-notification.register');
        return response()->json(['registered' => true, 'installation_id' => $installation, 'environment' => $data['environment'], 'sequence' => $data['sequence']])->header('Cache-Control', 'no-store');
    }
    public function revoke(Request $request, string $installation)
    {
        $data = $request->validate(['sequence' => ['required', 'integer', 'min:1', 'max:9000000000000000']]);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->exists
            && (int) $token->tokenable_id === (int) $request->user()->id
            && ! $request->attributes->get('is_impersonating', false), 403);
        LockedWrite::run(fn () => DB::transaction(function () use ($request, $installation, $token, $data) {
            $current = MobileNotificationDevice::whereKey($installation)->lockForUpdate()->first();
            if ($current && ((int) $current->user_id !== (int) $request->user()->id || $current->personal_access_token_id > $token->id)) { return; }
            abort_if($current && $current->sequence > $data['sequence'], 409, 'This notification operation has been superseded.');
            MobileNotificationDevice::updateOrCreate(['id' => $installation], [
                'user_id' => $request->user()->id, 'personal_access_token_id' => $token->id,
                'sequence' => $data['sequence'], 'token' => null, 'token_hash' => null,
                'environment' => config('mobile_notifications.environment'), 'permission' => 'denied',
                'expires_at' => now(), 'revoked_at' => now(),
            ]);
        }), 'mobile-notification.revoke');
        return response()->json(['revoked' => true, 'sequence' => $data['sequence']]);
    }
}
