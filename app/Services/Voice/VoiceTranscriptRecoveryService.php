<?php

namespace App\Services\Voice;

use App\Jobs\RecoverVoiceTranscript;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallTranscript;
use App\Models\VoiceTranscriptRecovery;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class VoiceTranscriptRecoveryService
{
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

        return Cache::lock('voice-recovery-request:'.$call->id, 15)->block(3, function () use ($call, $user, $key) {
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
        try {
            $call = $recovery->voiceCall->fresh();
            $source = $this->source($call);
            if (! $source || $source['recording_id'] !== $recovery->recording_id || ! $recovery->requestedBy
                || ! app(VoiceBrowserSessionService::class)->canOperate($recovery->requestedBy)) {
                $this->fail($recovery, 'failed', 'Consent, recording or staff permission changed before recovery.');

                return;
            }
            $base = rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');
            $recording = Http::withToken(config('services.telnyx.api_key'))->connectTimeout(5)->timeout(10)->get($base.'/recordings/'.rawurlencode($recovery->recording_id));
            if (! $recording->successful()) {
                $this->fail($recovery, 'failed', $recording->status() === 404 ? 'The carrier no longer has this recording.' : 'The carrier could not provide the recording.');

                return;
            }
            $url = $recording->json('data.download_urls.mp3') ?? $recording->json('data.download_urls.wav');
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)) {
                $this->fail($recovery, 'failed', 'The carrier did not provide a valid secure recording link.');

                return;
            }
            // This fresh URL comes from authenticated carrier retrieval, never request input.
            if (! $call->fresh()->recording_consent_given) {
                $this->fail($recovery, 'failed', 'Consent changed before transcription; no audio was submitted.');

                return;
            }
            $response = Http::withToken(config('services.telnyx.api_key'))->acceptJson()->asMultipart()->connectTimeout(5)->timeout(45)
                ->post($base.'/ai/audio/transcriptions', ['file_url' => $url, 'model' => $recovery->model, 'response_format' => 'json']);
            if (! $response->successful()) {
                \Illuminate\Support\Facades\Log::error('Call transcript recovery provider rejection.', [
                    'recovery_id' => $recovery->id,
                    'voice_call_id' => $call->id,
                    'provider_status' => $response->status(),
                    'diagnostics' => $this->providerDiagnostics($response),
                ]);
                $this->fail($recovery, 'failed', 'The transcription provider rejected recovery (HTTP '.$response->status().'). The recording may exceed provider limits or be unavailable.');

                return;
            }
            $text = trim((string) $response->json('text', ''));
            if ($text === '' || mb_strlen($text) > 500000) {
                $this->fail($recovery, 'failed', 'The provider returned no usable speech transcript.');

                return;
            }
            Cache::lock('voice-transcript:'.$call->id, 30)->block(3, fn () => LockedWrite::run(fn () => DB::transaction(function () use ($call, $recovery, $text): void {
                $call->refresh();
                if (! $call->recording_consent_given || data_get($call->metadata, 'recording_id') !== $recovery->recording_id) {
                    $this->fail($recovery, 'failed', 'Consent or recording changed during recovery; no transcript was saved.');

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
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->fail($recovery, 'uncertain', 'The provider timed out without a confirmed transcript. Check the recording and retry explicitly.');
        } catch (\Throwable $e) {
            $this->fail($recovery, 'failed', 'Recovery could not be completed. Original transcript segments were preserved.');
            \Illuminate\Support\Facades\Log::error('Call transcript recovery failed.', ['recovery_id' => $id]);
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

    /** Retain useful provider failure evidence without persisting signed audio links or request bodies. */
    private function providerDiagnostics(\Illuminate\Http\Client\Response $response): array
    {
        $sanitize = static function (mixed $value): ?string {
            if (! is_string($value) && ! is_numeric($value)) {
                return null;
            }
            $value = (string) $value;
            $key = (string) config('services.telnyx.api_key');
            if ($key !== '') {
                $value = str_replace($key, '<redacted-credential>', $value);
            }
            $value = preg_replace('~https?://[^\s"<>]+~i', '<redacted-url>', $value);
            $value = preg_replace('~\bBearer\s+\S+~i', '<redacted-credential>', $value);
            $value = preg_replace('~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i', '<redacted-email>', $value);
            $value = preg_replace('~\+?[0-9][0-9 ()-]{8,}[0-9]~', '<redacted-phone>', $value);
            $value = preg_replace('~\b[A-Za-z0-9_=.-]{40,}\b~', '<redacted-token>', $value);

            return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $value), 0, 700);
        };
        $body = $response->json();
        $details = [];
        if (is_array($body)) {
            foreach (array_slice(is_array($body['errors'] ?? null) ? $body['errors'] : [], 0, 3) as $error) {
                if (is_array($error)) {
                    $details[] = array_filter(array_map($sanitize, array_intersect_key($error, array_flip(['code', 'title', 'detail']))));
                }
            }
            if (is_array($body['error'] ?? null)) {
                $details[] = array_filter(array_map($sanitize, array_intersect_key($body['error'], array_flip(['code', 'type', 'message']))));
            }
            foreach (['error', 'detail', 'message'] as $field) {
                if (is_scalar($body[$field] ?? null)) {
                    $details[] = [$field => $sanitize($body[$field])];
                }
            }
        }

        return ['request_id' => $sanitize($response->header('x-request-id')), 'errors' => array_slice($details, 0, 4)];
    }
}
