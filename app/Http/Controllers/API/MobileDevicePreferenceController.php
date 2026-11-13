<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MobileDevicePreference;
use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use App\Models\PersonalAccessToken;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileDevicePreferenceController extends Controller
{
    private function token(Request $request): PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->exists
            && (int) $token->tokenable_id === (int) $request->user()->id
            && $token->tokenable_type === $request->user()->getMorphClass()
            && ! $request->attributes->get('is_impersonating', false)
            && ! $request->hasHeader('X-Impersonate-User-Id'), 403);
        return $token;
    }

    private function receipt(Request $request, ?MobileDevicePreference $preference)
    {
        return response()->json(['version' => 1, 'saved' => $preference !== null,
            'user_id' => (string) $request->user()->id, 'installation_id' => $preference?->installation_id,
            'sequence' => $preference?->sequence ?? 0,
            'preferences' => $preference?->preferences ?? MobileDevicePreference::defaults()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request)
    {
        $token = $this->token($request);
        return $this->receipt($request, MobileDevicePreference::forLogin($request->user()->id, $token->id));
    }

    public function update(Request $request)
    {
        $token = $this->token($request);
        $rules = ['installation_id' => ['required', 'uuid'],
            'sequence' => ['required', 'integer', 'min:1', 'max:9000000000000000'],
            'preferences' => ['required', 'array:'.implode(',', MobileDevicePreference::CHANNELS)]];
        foreach (MobileDevicePreference::CHANNELS as $channel) { $rules['preferences.'.$channel] = ['required', 'boolean']; }
        $data = $request->validate($rules);
        $values = array_map(fn ($value) => (bool) $value, $data['preferences']);
        $preference = LockedWrite::run(fn () => DB::transaction(function () use ($request, $token, $data, $values) {
            abort_unless(PersonalAccessToken::whereKey($token->id)->exists()
                && $request->user()->fresh()?->isAccountEligibleForAuthentication(), 403);
            $current = MobileDevicePreference::whereKey($token->id)->lockForUpdate()->first();
            $installation = strtolower($data['installation_id']);
            abort_if($current && ((int) $current->user_id !== (int) $request->user()->id
                || $current->installation_id !== $installation), 403, 'Sign in again on this device.');
            abort_if($current && ($current->sequence > $data['sequence']
                || ($current->sequence === $data['sequence'] && $current->preferences != $values)), 409,
                'A newer device preference has already been saved.');
            if ($current && $current->sequence === $data['sequence']) { return $current; }
            $after = $current?->enabled_after ?? [];
            foreach ($values as $channel => $enabled) {
                if ($enabled && $current && ! $current->enabled($channel)) {
                    $after[$channel] = now()->toISOString();
                }
            }
            $current = MobileDevicePreference::updateOrCreate(['personal_access_token_id' => $token->id], [
                'user_id' => $request->user()->id, 'installation_id' => $installation,
                'sequence' => $data['sequence'], 'preferences' => $values, 'enabled_after' => $after,
            ]);
            $mutedKinds = array_values(array_filter(['sms', 'shared_group', 'email', 'support', 'voicemail', 'shoot_activity', 'account_activity'],
                fn ($kind) => ! $current->enabled(MobileDevicePreference::channel($kind))));
            // Stop pending deliveries too. Terminal suppression prevents outbox
            // repair from replaying old alerts when a phone is unmuted later.
            MobileNotificationDelivery::where('user_id', $request->user()->id)
                ->whereIn('device_id', MobileNotificationDevice::where('personal_access_token_id', $token->id)->select('id'))
                ->whereIn('kind', $mutedKinds)->where('status', 'queued')->update(['status' => 'suppressed']);
            return $current;
        }), 'mobile-device-preferences.update');
        return $this->receipt($request, $preference);
    }
}
