<?php

namespace App\Services\Voice;

use App\Jobs\RecoverVoiceTranscript;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallTranscript;
use App\Models\VoiceTranscriptRecovery;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class VoiceTranscriptRecoveryService
{
    private const MAX_AUDIO_BYTES = 100000000;

    /** Evidence must be a signed provider event for the canonical customer leg. */
    public function source(VoiceCall $call): ?array
    {
        if (! $call->ended_at || ! $call->recording_consent_given || strtolower((string) $call->provider) !== 'telnyx' || ! $call->call_control_id) {
            return null;
        }
        $recording = (string) data_get($call->metadata, 'recording_id', '');
        if (! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $recording)) {
            return null;
        }
        foreach ($call->events()->where('event_type', 'call.recording.saved')->whereNotNull('processed_at')->latest()->limit(20)->get() as $event) {
            $payload = data_get($event->raw_payload, 'data.payload', data_get($event->raw_payload, 'payload', []));
            if (($payload['recording_id'] ?? null) === $recording && ($payload['call_control_id'] ?? null) === $call->call_control_id && empty($payload['conference_id'])) {
                return ['recording_id' => $recording, 'source_event_id' => $event->id, 'source_call_control_id' => $call->call_control_id];
            }
        }

        return null;
    }

    public function request(VoiceCall $call, User $user, string $key): VoiceTranscriptRecovery
    {
        abort_unless(app(VoiceBrowserSessionService::class)->canOperate($user), 403);

        return VoiceLocks::lock('voice-recovery-request:'.$call->id, 15)->block(3, function () use ($call, $user, $key) {
            $call->refresh();
            $source = $this->source($call);
            abort_unless($source, 422, 'Recovery requires consent and a saved recording verified as this customer call.');
            abort_unless(filled(config('services.telnyx.api_key')), 422, 'The transcription provider is not configured.');
            $existing = VoiceTranscriptRecovery::where('voice_call_id', $call->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $current = VoiceTranscriptRecovery::where('voice_call_id', $call->id)->where('recording_id', $source['recording_id'])
                ->whereIn('status', ['queued', 'processing', 'succeeded'])->latest()->first();
            if ($current) {
                return $current;
            }
            $attempt = VoiceTranscriptRecovery::where('voice_call_id', $call->id)->where('recording_id', $source['recording_id'])->count() + 1;
            abort_if($attempt > 3, 422, 'Three recovery attempts have been used for this recording. Contact support with the call reference.');
            $recovery = LockedWrite::run(fn () => VoiceTranscriptRecovery::create(array_merge($source, [
                'voice_call_id' => $call->id, 'requested_by_id' => $user->id, 'idempotency_key' => $key, 'attempt' => $attempt,
            ])));
            RecoverVoiceTranscript::dispatch($recovery->id)->afterCommit();

            return $recovery->fresh();
        });
    }

    public function run(string $id): void
    {
        $recovery = VoiceTranscriptRecovery::find($id);
        if (! $recovery) {
            return;
        }
        // CAS makes duplicate queue deliveries harmless, even after a worker crash.
        $claimed = LockedWrite::run(fn () => VoiceTranscriptRecovery::whereKey($id)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now()]));
        if ($claimed !== 1) {
            return;
        }
        $recovery->refresh();
        $temporary = null;
        $phase = 'metadata';
        try {
            $call = $recovery->voiceCall->fresh();
            if (! $this->authorizedSource($call, $recovery)) {
                $this->fail($recovery, 'failed', 'Consent, recording or staff permission changed before recovery.');

                return;
            }
            $base = rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');
            $recording = Http::withToken(config('services.telnyx.api_key'))->withoutRedirecting()->connectTimeout(5)->timeout(10)->get($base.'/recordings/'.rawurlencode($recovery->recording_id));
            if (! $recording->successful()) {
                $this->fail($recovery, 'failed', 'The carrier could not provide the recording [recording_metadata_http_'.$recording->status().'].');

                return;
            }
            if ($recording->json('data.id') !== $recovery->recording_id
                || $recording->json('data.call_control_id') !== $recovery->source_call_control_id
                || filled($recording->json('data.conference_id'))) {
                throw new \DomainException('recording_metadata_mismatch');
            }
            $format = filled($recording->json('data.download_urls.mp3')) ? 'mp3' : 'wav';
            $url = $recording->json('data.download_urls.'.$format);
            if (! $this->allowedRecordingUrl($url)) {
                throw new \DomainException('recording_origin_unapproved');
            }
            $temporary = tmpfile();
            if ($temporary === false || ! chmod(stream_get_meta_data($temporary)['uri'], 0600)
                || (fstat($temporary)['mode'] & 0777) !== 0600) {
                throw new \DomainException('private_audio_storage_unavailable');
            }
            $phase = 'download';
            $audioStream = $this->downloadRecording($url, $temporary, $format);
            // No client URL, carrier authorization header, or signed URL is sent to STT.
            // Re-resolve all guards after downloading and immediately before upload.
            if (! $this->authorizedSource($call->fresh(), $recovery)) {
                $this->fail($recovery, 'failed', 'Consent, recording or staff permission changed; no audio was submitted.');

                return;
            }
            rewind($temporary);
            $phase = 'transcription';
            $response = Http::withToken(config('services.telnyx.api_key'))->withoutRedirecting()->acceptJson()->connectTimeout(5)->timeout(35)
                ->attach('file', $temporary, 'recording.'.$format, ['Content-Type' => $format === 'mp3' ? 'audio/mpeg' : 'audio/wav'])
                ->post($base.'/ai/audio/transcriptions', ['model' => $recovery->model, 'response_format' => 'json']);
            if (! $response->successful()) {
                $this->fail($recovery, 'failed', 'The transcription provider rejected recovery (HTTP '.$response->status().'; '.$this->providerClassification($response).'). Original segments were preserved.');

                return;
            }
            $text = $response->json('text');
            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 500000) {
                $this->fail($recovery, 'failed', 'The provider returned no usable speech transcript.');

                return;
            }
            $text = trim($text);
            $phase = 'save';
            VoiceLocks::lock('voice-transcript:'.$call->id, 30)->block(3, fn () => LockedWrite::run(fn () => DB::transaction(function () use ($call, $recovery, $text): void {
                $call->refresh();
                if (! $this->authorizedSource($call, $recovery)) {
                    $this->fail($recovery, 'failed', 'Consent, recording or staff permission changed during recovery; no transcript was saved.');

                    return;
                }
                VoiceCallTranscript::updateOrCreate(['voice_call_id' => $call->id, 'provider_message_id' => 'recording-recovery:'.$recovery->recording_id], [
                    'speaker' => 'unattributed', 'transcript_type' => 'recovered', 'text' => $text, 'occurred_at' => $call->ended_at,
                ]);
                $call->update(['metadata' => array_merge($call->metadata ?? [], ['recording_recovery' => [
                    'recovery_id' => $recovery->id, 'recording_id' => $recovery->recording_id, 'source_event_id' => $recovery->source_event_id,
                    'source_call_control_id' => $recovery->source_call_control_id, 'model' => $recovery->model, 'completed_at' => now()->toIso8601String(),
                    'scope' => 'recorded_customer_audio_only', 'speakers_identified' => false,
                ]])]);
                app(VoiceLiveStreamService::class)->projectSavedTranscript($call->fresh());
                $recovery->update(['status' => 'succeeded', 'completed_at' => now(), 'error' => null]);
            }), 'voice.transcript.recovered'));
        } catch (\DomainException $e) {
            $code = in_array($e->getMessage(), ['recording_metadata_mismatch', 'recording_origin_unapproved', 'private_audio_storage_unavailable',
                'audio_download_too_large', 'audio_download_http', 'audio_download_timeout', 'audio_download_failed', 'audio_download_incomplete',
                'audio_format_invalid', 'audio_storage_failed'], true) ? $e->getMessage() : 'recording_validation_failed';
            $this->fail($recovery, 'failed', 'Recording recovery stopped ['.$code.']. No audio was submitted for transcription.');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->fail($recovery, $phase === 'transcription' ? 'uncertain' : 'failed', $phase === 'transcription'
                ? 'The provider did not confirm a result [transcription_transport_uncertain]. Retry explicitly after checking the recording.'
                : 'The carrier did not provide metadata [recording_metadata_transport_failed]. No audio was submitted.');
        } catch (\Throwable $e) {
            $this->fail($recovery, 'failed', 'Recovery could not be completed. Original transcript segments were preserved.');
            \Illuminate\Support\Facades\Log::error('Call transcript recovery failed.', ['recovery_id' => $id]);
        } finally {
            if (is_resource($temporary)) {
                fclose($temporary);
            }
        }
    }

    public function latest(VoiceCall $call): ?VoiceTranscriptRecovery
    {
        return VoiceTranscriptRecovery::where('voice_call_id', $call->id)->orderByDesc('created_at')->orderByDesc('attempt')->first();
    }

    public function reconcile(): void
    {
        // The provider is synchronous and has no result lookup: an interrupted worker
        // must surface uncertainty rather than silently resubmit a potentially billed job.
        LockedWrite::run(fn () => VoiceTranscriptRecovery::where('status', 'processing')->where('started_at', '<', now()->subMinutes(3))
            ->update(['status' => 'uncertain', 'completed_at' => now(), 'error' => 'The recovery worker stopped before confirming a result. Retry explicitly after checking the recording.']));
        LockedWrite::run(fn () => VoiceTranscriptRecovery::where('status', 'queued')->where('created_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'completed_at' => now(), 'error' => 'Recovery did not start within 15 minutes. Ask support to check the queue worker before retrying.']));
    }

    private function fail(VoiceTranscriptRecovery $recovery, string $status, string $message): void
    {
        LockedWrite::run(fn () => $recovery->update(['status' => $status, 'error' => $message, 'completed_at' => now()]));
    }

    private function authorizedSource(VoiceCall $call, VoiceTranscriptRecovery $recovery): bool
    {
        $source = $this->source($call);
        $user = User::find($recovery->requested_by_id);

        return $source && $source['recording_id'] === $recovery->recording_id
            && $source['source_call_control_id'] === $recovery->source_call_control_id
            && $user && app(VoiceBrowserSessionService::class)->canOperate($user);
    }

    private function allowedRecordingUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $path = rawurldecode($parts['path'] ?? '');

        // Observed Telnyx recording origin and bucket. Do not trust arbitrary S3 buckets,
        // wildcard AWS domains, redirects, or caller-provided URLs. New origins need review.
        return ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 's3.amazonaws.com'
            && ($parts['port'] ?? 443) === 443
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && str_starts_with($parts['path'] ?? '', '/telephony-recorder-prod/')
            && ! preg_match('~(?:^|/)\.\.(?:/|$)|[\\\\\x00-\x20\x7f]~', $path);
    }

    protected function recordingClient(): ClientInterface
    {
        return new Client;
    }

    /** Curl's total timeout and bounded sink apply even when Content-Length is absent or false. */
    private function downloadRecording(string $url, mixed $temporary, string $format): \Psr\Http\Message\StreamInterface
    {
        $stream = Utils::streamFor($temporary);
        $written = 0;
        $failure = null;
        // Keep ownership with finally in run(), not the HTTP response/destructor.
        $sink = FnStream::decorate($stream, [
            'close' => static fn () => null,
            'write' => function (string $chunk) use ($stream, &$written, &$failure): int {
                if ($written + strlen($chunk) > self::MAX_AUDIO_BYTES) {
                    $failure = 'audio_download_too_large';
                    throw new \DomainException($failure);
                }
                $bytes = $stream->write($chunk);
                if ($bytes !== strlen($chunk)) {
                    $failure = 'audio_storage_failed';
                    throw new \DomainException($failure);
                }
                $written += $bytes;

                return $bytes;
            },
        ]);
        try {
            $response = $this->recordingClient()->request('GET', $url, [
                'allow_redirects' => false, 'http_errors' => false, 'connect_timeout' => 5, 'timeout' => 10,
                'verify' => true, 'cookies' => false, 'headers' => ['Accept' => 'audio/mpeg, audio/wav, application/octet-stream', 'Accept-Encoding' => 'identity'],
                'decode_content' => false, 'sink' => $sink,
                'on_headers' => function ($response) use (&$failure): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if (ctype_digit($length) && (float) $length > self::MAX_AUDIO_BYTES) {
                        $failure = 'audio_download_too_large';
                        throw new \DomainException($failure);
                    }
                    if ($response->getStatusCode() !== 200) {
                        $failure = 'audio_download_http';
                        throw new \DomainException($failure);
                    }
                },
            ]);
        } catch (\Throwable $e) {
            throw new \DomainException($failure ?? ($e instanceof \GuzzleHttp\Exception\ConnectException ? 'audio_download_timeout' : 'audio_download_failed'));
        }
        $length = $response->getHeaderLine('Content-Length');
        if ($response->getStatusCode() !== 200 || $written === 0 || (ctype_digit($length) && (int) $length !== $written)) {
            throw new \DomainException('audio_download_incomplete');
        }
        rewind($temporary);
        $header = fread($temporary, 12);
        $mime = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        $valid = $format === 'mp3'
            ? (str_starts_with($header, 'ID3') || (strlen($header) >= 2 && ord($header[0]) === 255 && (ord($header[1]) & 224) === 224))
                && in_array($mime, ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true)
            : strlen($header) >= 12 && in_array(substr($header, 0, 4), ['RIFF', 'RF64'], true) && substr($header, 8, 4) === 'WAVE'
                && in_array($mime, ['audio/wav', 'audio/wave', 'audio/x-wav', 'application/octet-stream'], true);
        if (! $valid) {
            throw new \DomainException('audio_format_invalid');
        }
        rewind($temporary);

        return $stream; // Caller retains ownership until its finally block.
    }

    /** Persist only fixed classifications, never provider prose, request IDs, links or speech. */
    private function providerClassification(\Illuminate\Http\Client\Response $response): string
    {
        $code = $response->json('errors.0.code') ?? $response->json('error.code') ?? $response->json('error.type');

        return in_array($code, ['invalid_audio', 'unsupported_audio_format', 'invalid_request_error', 'rate_limit_exceeded', 'insufficient_quota'], true)
            ? $code : 'transcription_rejected';
    }
}
