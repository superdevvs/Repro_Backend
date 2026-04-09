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
use Illuminate\Validation\ValidationException;
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
            $targetUser = $this->resolveConnectUser($request);
            $redirectPath = $this->resolveFrontendRedirectPath((string) $request->input('source', 'photographer-account'));

            Cache::put($this->oauthStateKey($state), [
                'user_id' => $targetUser->id,
                'redirect_path' => $redirectPath,
            ], now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'data' => [
                    'authorization_url' => $this->calendarService->buildAuthorizationUrl($state),
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to start Google Calendar connection.',
                'errors' => $exception->errors(),
            ], 422);
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
        $redirectPath = $this->buildFrontendRedirectPath($payload['redirect_path'] ?? null);

        if ($request->filled('error')) {
            return redirect()->away(
                $this->appendRedirectQuery(
                    $redirectBase,
                    $redirectPath,
                    [
                        'google_calendar' => 'error',
                        'message' => (string) $request->query('error'),
                    ]
                )
            );
        }

        if (!is_array($payload) || empty($payload['user_id'])) {
            return redirect()->away(
                $this->appendRedirectQuery(
                    $redirectBase,
                    $redirectPath,
                    [
                        'google_calendar' => 'error',
                        'message' => 'Invalid or expired Google Calendar connection.',
                    ]
                )
            );
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

            return redirect()->away(
                $this->appendRedirectQuery(
                    $redirectBase,
                    $redirectPath,
                    [
                        'google_calendar' => 'connected',
                        'message' => sprintf('Google Calendar connected for %s.', $user->name),
                    ]
                )
            );
        } catch (Throwable $exception) {
            return redirect()->away(
                $this->appendRedirectQuery(
                    $redirectBase,
                    $redirectPath,
                    [
                        'google_calendar' => 'error',
                        'message' => $exception->getMessage(),
                    ]
                )
            );
        }
    }

    public function status(Request $request): JsonResponse
    {
        try {
            $targetUser = $this->resolveStatusUser($request);
            $connection = GoogleCalendarConnection::query()
                ->where('user_id', $targetUser->id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $targetUser->id,
                    'user_name' => $targetUser->name,
                    'available' => (bool) (config('services.google.calendar.client_id') && config('services.google.calendar.client_secret')),
                    'connected' => $connection !== null,
                    'provider_email' => $connection?->provider_email,
                    'calendar_id' => $connection?->calendar_id,
                    'sync_enabled' => (bool) ($connection?->sync_enabled ?? false),
                    'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
                    'last_error' => $connection?->last_error,
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to load Google Calendar status.',
                'errors' => $exception->errors(),
            ], 422);
        }
    }

    public function adminOverview(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'available' => (bool) (config('services.google.calendar.client_id') && config('services.google.calendar.client_secret')),
                'redirect_uri' => config('services.google.calendar.redirect'),
                'connected_photographers_count' => GoogleCalendarConnection::query()->count(),
                'synced_events_count' => GoogleCalendarEventMapping::query()->count(),
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

    protected function resolveConnectUser(Request $request): User
    {
        $actor = $request->user();
        $targetUserId = (int) $request->input('user_id');

        if ($actor->role === 'photographer') {
            return $actor;
        }

        if (in_array($actor->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            if ($targetUserId <= 0) {
                throw ValidationException::withMessages([
                    'user_id' => 'Please choose a photographer to connect Google Calendar.',
                ]);
            }

            $targetUser = User::query()->findOrFail($targetUserId);

            if ($targetUser->role !== 'photographer') {
                throw ValidationException::withMessages([
                    'user_id' => 'Google Calendar sync can only be connected for photographer accounts.',
                ]);
            }

            return $targetUser;
        }

        throw ValidationException::withMessages([
            'user_id' => 'Your account cannot manage Google Calendar connections.',
        ]);
    }

    protected function resolveStatusUser(Request $request): User
    {
        $actor = $request->user();
        $targetUserId = (int) $request->query('user_id', 0);

        if ($actor->role === 'photographer') {
            return $actor;
        }

        if (in_array($actor->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            if ($targetUserId <= 0) {
                throw ValidationException::withMessages([
                    'user_id' => 'Please choose a photographer to view Google Calendar status.',
                ]);
            }

            $targetUser = User::query()->findOrFail($targetUserId);

            if ($targetUser->role !== 'photographer') {
                throw ValidationException::withMessages([
                    'user_id' => 'Google Calendar status is only available for photographer accounts.',
                ]);
            }

            return $targetUser;
        }

        throw ValidationException::withMessages([
            'user_id' => 'Your account cannot view Google Calendar status.',
        ]);
    }

    protected function resolveFrontendRedirectPath(string $source): string
    {
        return match ($source) {
            'availability' => '/availability',
            default => '/photographer-account?tab=notifications',
        };
    }

    protected function buildFrontendRedirectPath(null|string $redirectPath): string
    {
        if (!is_string($redirectPath) || $redirectPath === '') {
            return '/photographer-account?tab=notifications';
        }

        return str_starts_with($redirectPath, '/') ? $redirectPath : '/photographer-account?tab=notifications';
    }

    protected function appendRedirectQuery(string $redirectBase, string $redirectPath, array $params): string
    {
        $separator = str_contains($redirectPath, '?') ? '&' : '?';

        return $redirectBase . $redirectPath . $separator . http_build_query($params);
    }
}
