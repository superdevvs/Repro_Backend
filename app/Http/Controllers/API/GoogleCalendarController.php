<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarController extends Controller
{
    public function __construct(
        protected GoogleCalendarService $calendarService,
        protected GoogleCalendarSyncDispatcher $syncDispatcher,
        protected GoogleCalendarShootSyncService $shootSyncService
    ) {
    }

    public function connect(Request $request): JsonResponse
    {
        try {
            $state = (string) Str::uuid();

            Cache::put($this->oauthStateKey($state), [
                'user_id' => $request->user()->id,
            ], now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => [
                    'authorization_url' => $this->calendarService->buildAuthorizationUrl($state),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $payload = $state !== '' ? Cache::pull($this->oauthStateKey($state)) : null;
        $redirectBase = rtrim(config('app.frontend_url'), '/');

        if ($request->filled('error')) {
            return redirect()->away($redirectBase . '/photographer-account?tab=notifications&google_calendar=error&message=' . urlencode((string) $request->query('error')));
        }

        if (!is_array($payload) || empty($payload['user_id'])) {
            return redirect()->away($redirectBase . '/photographer-account?tab=notifications&google_calendar=error&message=' . urlencode('Invalid or expired Google Calendar connection.'));
        }

        try {
            $tokenData = $this->calendarService->exchangeAuthorizationCode((string) $request->query('code'));
            $user = User::findOrFail((int) $payload['user_id']);
            $existingConnection = GoogleCalendarConnection::query()->where('user_id', $user->id)->first();
            $providerEmail = $this->calendarService->fetchUserEmail((string) $tokenData['access_token']);

            GoogleCalendarConnection::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'provider_email' => $providerEmail,
                    'calendar_id' => config('services.google.calendar.default_calendar_id', 'primary'),
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? $existingConnection?->refresh_token,
                    'token_expires_at' => now()->addSeconds(max(((int) ($tokenData['expires_in'] ?? 3600)) - 60, 0)),
                    'sync_enabled' => true,
                    'last_error' => null,
                ]
            );

            $this->syncDispatcher->dispatchUserResync($user->id);

            return redirect()->away($redirectBase . '/photographer-account?tab=notifications&google_calendar=connected');
        } catch (Throwable $exception) {
            return redirect()->away($redirectBase . '/photographer-account?tab=notifications&google_calendar=error&message=' . urlencode($exception->getMessage()));
        }
    }

    public function status(Request $request): JsonResponse
    {
        $connection = GoogleCalendarConnection::query()
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'available' => (bool) (config('services.google.calendar.client_id') && config('services.google.calendar.client_secret')),
                'connected' => $connection !== null,
                'provider_email' => $connection?->provider_email,
                'calendar_id' => $connection?->calendar_id,
                'sync_enabled' => (bool) ($connection?->sync_enabled ?? false),
                'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
                'last_error' => $connection?->last_error,
            ],
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $connection = GoogleCalendarConnection::query()
            ->where('user_id', $request->user()->id)
            ->first();

        if ($connection) {
            $this->shootSyncService->disconnectUser($request->user()->id);

            try {
                $this->calendarService->revokeToken($connection->refresh_token ?: $connection->access_token);
            } catch (Throwable $exception) {
            }

            $connection->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Google Calendar disconnected.',
        ]);
    }

    public function resync(Request $request): JsonResponse
    {
        $connection = GoogleCalendarConnection::query()
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$connection) {
            return response()->json([
                'message' => 'Google Calendar is not connected.',
            ], 404);
        }

        $connection->forceFill([
            'last_error' => null,
        ])->save();

        $this->syncDispatcher->dispatchUserResync($request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Google Calendar resync started.',
        ]);
    }

    protected function oauthStateKey(string $state): string
    {
        return 'google_calendar_oauth_state:' . $state;
    }
}
