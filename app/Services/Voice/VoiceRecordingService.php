<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoiceRecordingService
{
    public function playbackUrl(VoiceCall $call): ?string
    {
        if (! $call->recording_consent_given) {
            return null;
        }

        $recordingId = data_get($call->metadata, 'recording_id');
        if (! $recordingId && strtolower((string) $call->provider) === 'telnyx') {
            $event = $call->events()->where('event_type', 'call.recording.saved')->latest()->first();
            $recordingId = data_get($event?->raw_payload, 'data.payload.recording_id');
        }

        if (strtolower((string) $call->provider) === 'telnyx' && is_string($recordingId) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $recordingId)) {
            // Carrier webhook URLs expire after ten minutes. Obtain a fresh
            // signed URL when playback is opened; never expose the API key.
            return Cache::remember('voice:recording-url:'.$call->id.':'.$recordingId, 240, function () use ($recordingId): string {
                $base = rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');
                $response = Http::withToken(config('services.telnyx.api_key'))->connectTimeout(5)->timeout(15)
                    ->get($base.'/recordings/'.rawurlencode($recordingId));
                if ($response->status() === 404) {
                    throw new HttpException(410, 'This recording is no longer available from the carrier.');
                }
                if (! $response->successful()) {
                    throw new HttpException(502, 'The carrier could not provide a playback link. Please try again.');
                }
                $url = $response->json('data.download_urls.mp3') ?? $response->json('data.download_urls.wav');
                if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new HttpException(502, 'The recording is still being prepared for playback.');
                }

                return $url;
            });
        }

        $url = $call->recording_url;
        if (! $url) {
            return null;
        }
        if (str_starts_with($url, 'https://')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $signedAt = isset($query['X-Amz-Date']) ? strtotime($query['X-Amz-Date']) : false;
            $expiresAt = $signedAt !== false && isset($query['X-Amz-Expires'])
                ? $signedAt + (int) $query['X-Amz-Expires'] : (isset($query['Expires']) ? (int) $query['Expires'] : null);
            if ($expiresAt !== null && $expiresAt <= now()->timestamp) {
                throw new HttpException(410, 'This older playback link has expired and its recording ID is unavailable.');
            }

            return $url;
        }
        if (str_contains($url, '://') || ! Storage::disk('local')->exists($url)) {
            return null;
        }

        return Storage::disk('local')->temporaryUrl($url, now()->addMinutes(15));
    }
}
