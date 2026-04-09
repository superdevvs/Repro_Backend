<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleCalendarConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarService
{
    public function buildAuthorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return config('services.google.calendar.auth_url') . '?' . http_build_query([
            'client_id' => config('services.google.calendar.client_id'),
            'redirect_uri' => config('services.google.calendar.redirect'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'include_granted_scopes' => 'true',
            'scope' => config('services.google.calendar.scope'),
            'state' => $state,
        ]);
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

        return $this->parseCalendarResponse($response);
    }

    public function deleteEvent(GoogleCalendarConnection $connection, string $calendarId, string $eventId): void
    {
        $response = $this->calendarRequest($connection)
            ->delete($this->eventsUrl($calendarId) . '/' . urlencode($eventId));

        if ($response->status() === 404) {
            return;
        }

        if ($response->failed()) {
            throw new RuntimeException('Unable to delete Google Calendar event.');
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
