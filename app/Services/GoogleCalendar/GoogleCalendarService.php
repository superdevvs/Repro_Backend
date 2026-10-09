<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleCalendarConnection;
use App\Exceptions\PublicBusinessRuleException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarService
{
    public const MISSING_PERMISSION_MESSAGE = 'Calendar permission was not granted. Please reconnect, select your intended Google account, and grant the requested Calendar event permission before continuing.';

    private const LEGACY_MISSING_PERMISSION_MESSAGE = 'Calendar permission was not granted. Please reconnect, select your intended Google account, and check "View and edit events on all your calendars" before continuing.';

    public static function isMissingCalendarPermission(?string $message): bool
    {
        return in_array($message, [self::MISSING_PERMISSION_MESSAGE, self::LEGACY_MISSING_PERMISSION_MESSAGE], true);
    }

    public function buildAuthorizationUrl(string $state, ?string $email = null, bool $ownedScopePreview = false): string
    {
        $this->assertConfigured();

        $scope = (string) config('services.google.calendar.scope');
        // Older production environments explicitly configured this scope. The
        // primary-calendar workflow needs access only to calendars the user owns.
        if ($ownedScopePreview && config('services.google.calendar.default_calendar_id', 'primary') === 'primary') {
            $scope = implode(' ', array_unique(array_map(
                static fn (string $item) => $item === 'https://www.googleapis.com/auth/calendar.events'
                    ? 'https://www.googleapis.com/auth/calendar.events.owned'
                    : $item,
                preg_split('/\s+/', trim($scope))
            )));
        }

        return config('services.google.calendar.auth_url') . '?' . http_build_query([
            'client_id' => config('services.google.calendar.client_id'),
            'redirect_uri' => config('services.google.calendar.redirect'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'include_granted_scopes' => 'true',
            'scope' => $scope,
            'state' => $state,
            'login_hint' => $email,
        ]);
    }

    public function assertCalendarPermission(array $tokenData): void
    {
        $scope = $tokenData['scope'] ?? null;
        if (!is_string($scope)) {
            $response = Http::acceptJson()->get('https://oauth2.googleapis.com/tokeninfo', [
                'access_token' => $tokenData['access_token'],
            ]);
            if ($response->failed()) {
                throw new PublicBusinessRuleException('Unable to verify Google Calendar permission. Please reconnect and try again.');
            }
            $scope = $response->json('scope', '');
        }

        $granted = preg_split('/\s+/', trim((string) $scope));
        if (!array_intersect($granted, [
            'https://www.googleapis.com/auth/calendar.events.owned',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar',
        ])) {
            throw new PublicBusinessRuleException(self::MISSING_PERMISSION_MESSAGE);
        }
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $response = Http::asForm()->post(config('services.google.calendar.token_url'), [
            'code' => $code,
            'client_id' => config('services.google.calendar.client_id'),
            'client_secret' => config('services.google.calendar.client_secret'),
            'redirect_uri' => config('services.google.calendar.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        return $this->parseTokenResponse($response);
    }

    public function fetchUserEmail(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get(config('services.google.calendar.userinfo_url'));

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch Google account email.');
        }

        return $response->json('email');
    }

    public function createEvent(GoogleCalendarConnection $connection, array $payload): array
    {
        $response = $this->calendarRequest($connection)
            ->post($this->eventsUrl($connection->calendar_id), $payload);

        return $this->parseCalendarResponse($response);
    }

    public function updateEvent(GoogleCalendarConnection $connection, string $eventId, array $payload): array
    {
        $response = $this->calendarRequest($connection)
            ->patch($this->eventsUrl($connection->calendar_id) . '/' . urlencode($eventId), $payload);

        if (in_array($response->status(), [404, 410], true)) {
            return $this->createEvent($connection, $payload);
        }

        return $this->parseCalendarResponse($response);
    }

    public function deleteEvent(GoogleCalendarConnection $connection, string $calendarId, string $eventId): void
    {
        $response = $this->calendarRequest($connection)
            ->delete($this->eventsUrl($calendarId) . '/' . urlencode($eventId));

        if (in_array($response->status(), [404, 410], true)) {
            return;
        }

        if ($response->failed()) {
            throw new RuntimeException('Unable to delete Google Calendar event (HTTP '.$response->status().').');
        }
    }

    public function revokeToken(?string $token): void
    {
        if (!$token) {
            return;
        }

        Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
            'token' => $token,
        ]);
    }

    public function getValidAccessToken(GoogleCalendarConnection $connection): string
    {
        $accessToken = (string) ($connection->access_token ?? '');

        if ($accessToken !== '' && (!$connection->token_expires_at || $connection->token_expires_at->isFuture())) {
            return $accessToken;
        }

        if (!$connection->refresh_token) {
            throw new RuntimeException('Google Calendar refresh token is missing.');
        }

        $response = Http::asForm()->post(config('services.google.calendar.token_url'), [
            'client_id' => config('services.google.calendar.client_id'),
            'client_secret' => config('services.google.calendar.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $tokenData = $this->parseTokenResponse($response);

        $connection->forceFill([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => $this->resolveExpiry($tokenData),
        ])->save();

        return $tokenData['access_token'];
    }

    protected function calendarRequest(GoogleCalendarConnection $connection)
    {
        return Http::withToken($this->getValidAccessToken($connection))
            ->acceptJson()
            ->contentType('application/json');
    }

    protected function eventsUrl(string $calendarId): string
    {
        return rtrim(config('services.google.calendar.base_url'), '/') . '/calendars/' . urlencode($calendarId) . '/events';
    }

    protected function parseTokenResponse(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('Unable to authenticate with Google Calendar.');
        }

        $data = $response->json();

        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('Google Calendar returned an invalid token payload.');
        }

        return $data;
    }

    protected function parseCalendarResponse(Response $response): array
    {
        if ($response->failed()) {
            if ($response->status() === 403
                && in_array('insufficientPermissions', array_column($response->json('error.errors', []), 'reason'), true)) {
                throw new PublicBusinessRuleException(self::MISSING_PERMISSION_MESSAGE);
            }
            throw new RuntimeException('Google Calendar event request failed.');
        }

        $data = $response->json();

        if (!is_array($data) || empty($data['id'])) {
            throw new RuntimeException('Google Calendar returned an invalid event payload.');
        }

        return $data;
    }

    protected function resolveExpiry(array $tokenData)
    {
        $expiresIn = (int) Arr::get($tokenData, 'expires_in', 3600);

        return now()->addSeconds(max($expiresIn - 60, 0));
    }

    protected function assertConfigured(): void
    {
        if (!config('services.google.calendar.client_id') || !config('services.google.calendar.client_secret')) {
            throw new RuntimeException('Google Calendar OAuth is not configured.');
        }
    }
}
