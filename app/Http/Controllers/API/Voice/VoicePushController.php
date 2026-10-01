<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoicePushSubscription;
use App\Services\Voice\VoiceBrowserSessionService;
use App\Services\Voice\VoicePushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoicePushController extends Controller
{
    public function __construct(private readonly VoicePushService $push, private readonly VoiceBrowserSessionService $sessions) {}

    private function authorizeUser(Request $request): void
    {
        abort_if($request->attributes->get('is_impersonating') || $request->header('X-Impersonate-User-Id'), 403, 'Use your own account to manage call notifications.');
        abort_unless($request->user() && $this->sessions->canOperate($request->user()), 403);
    }

    public function settings(Request $request)
    {
        $this->authorizeUser($request);

        return response()->json($this->push->settings($request->user()));
    }

    public function preferences(Request $request)
    {
        $this->authorizeUser($request);
        $data = $request->validate(['incoming_calls' => ['required', 'boolean']]);
        DB::table('voice_push_preferences')->updateOrInsert(['user_id' => $request->user()->id], [...$data, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json($this->push->settings($request->user()));
    }

    public function subscribe(Request $request)
    {
        $this->authorizeUser($request);
        $data = $request->validate(['endpoint' => ['required', 'url:https', 'max:2048'], 'keys.p256dh' => ['required', 'string', 'max:128'], 'keys.auth' => ['required', 'string', 'max:64'], 'label' => ['required', 'string', 'max:80']]);

        return response()->json($this->push->subscribe($request->user(), $data), 201);
    }

    public function delete(Request $request, VoicePushSubscription $subscription)
    {
        // Owners retain the right to revoke a device after their call permission is removed.
        abort_unless($subscription->user_id === $request->user()?->id, 403);
        abort_if($request->attributes->get('is_impersonating') || $request->header('X-Impersonate-User-Id'), 403);
        $this->push->revoke($subscription);

        return response()->noContent();
    }

    public function test(Request $request, VoicePushSubscription $subscription)
    {
        $this->authorizeUser($request);
        abort_unless($subscription->user_id === $request->user()->id && ! $subscription->revoked_at, 404);
        $delivery = $this->push->test($subscription);

        return response()->json(['status' => 'queued', 'delivery_id' => $delivery->id], 202);
    }

    public function delivery(Request $request, string $delivery)
    {
        $this->authorizeUser($request);
        $row = \App\Models\VoicePushDelivery::whereKey($delivery)->whereHas('subscription', fn ($q) => $q->where('user_id', $request->user()->id))->firstOrFail();
        if (in_array($row->status, ['queued', 'retrying'], true) && $row->expires_at->isPast()) {
            $row->update(['status' => 'expired', 'error_code' => 'notification_window_elapsed']);
        }

        return response()->json($row->only(['id', 'status', 'error_code', 'http_status', 'updated_at']));
    }

    public function revoke(Request $request)
    {
        // Logout has already discarded the bearer token. This narrow capability can only revoke.
        $data = $request->validate(['id' => ['required', 'uuid'], 'token' => ['required', 'string', 'size:64']]);
        $subscription = VoicePushSubscription::find($data['id']);
        if ($subscription && hash_equals($subscription->revoke_hash, hash('sha256', $data['token']))) {
            $this->push->revoke($subscription);
        }

        return response()->noContent();
    }
}
