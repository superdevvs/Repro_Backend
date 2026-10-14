<?php

namespace App\Services\Voice;

use App\Models\VoiceVoicemail;
use App\Support\VoiceLocks;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VoiceVoicemailStorage
{
    private const MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(private VoiceBrowserGateway $gateway) {}

    public function store(VoiceVoicemail $vm): void
    {
        VoiceLocks::lock('voicemail-store:'.$vm->id, 60)->block(5, function () use ($vm): void {
            $vm->refresh();
            if ($vm->state === 'saved' || $vm->state === 'expired' || $vm->expires_at->isPast()) {
                return;
            }
            if (! $vm->recording_id) {
                throw new RuntimeException('No provider recording ID.');
            }
            $recording = $this->gateway->request('GET', '/recordings/'.rawurlencode($vm->recording_id));
            $url = $recording['download_urls']['mp3'] ?? null;
            if (! $this->allowedUrl($url)) {
                throw new RuntimeException('Provider recording origin is not approved.');
            }
            $temporary = tmpfile();
            if (! $temporary) {
                throw new RuntimeException('Private recording storage unavailable.');
            }
            try {
                $this->download($url, $temporary);
                rewind($temporary);
                $path = 'voice/voicemails/'.$vm->id.'/'.$vm->recording_id.'.mp3';
                if (! Storage::disk('local')->put($path, $temporary, ['visibility' => 'private'])) {
                    throw new RuntimeException('Private recording write failed.');
                }
                // Fetch/update under the lifecycle lock so pruning and delayed events cannot resurrect audio.
                VoiceLocks::lock('voicemail:'.$vm->id, 60)->block(5, function () use ($vm, $path): void {
                    $vm->refresh();
                    if ($vm->state === 'expired' || $vm->expires_at->isPast()) {
                        Storage::disk('local')->delete($path);

                        return;
                    }
                    $vm->update(['state' => 'saved', 'recording_path' => $path, 'saved_at' => $vm->saved_at ?? now(), 'failure' => null]);
                    $vm->voiceCall->update(['disposition' => 'voicemail', 'recording_provider' => 'telnyx', 'needs_follow_up' => true]);
                });
            } finally {
                fclose($temporary);
            }
        });
    }

    public function allowedUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 's3.amazonaws.com'
            && ($parts['port'] ?? 443) === 443 && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && str_starts_with($parts['path'] ?? '', '/telephony-recorder-prod/')
            && ! preg_match('~(?:^|/)\.\.(?:/|$)|[\\\\\x00-\x20\x7f]~', rawurldecode($parts['path'] ?? ''));
    }

    protected function download(string $url, $temporary): void
    {
        $stream = Utils::streamFor($temporary);
        $written = 0;
        $sink = FnStream::decorate($stream, ['close' => static fn () => null,
            'write' => function (string $chunk) use ($stream, &$written): int {
                if ($written + strlen($chunk) > self::MAX_BYTES) {
                    throw new RuntimeException('Recording exceeds storage limit.');
                }
                $n = $stream->write($chunk);
                if ($n !== strlen($chunk)) {
                    throw new RuntimeException('Incomplete recording write.');
                }
                $written += $n;

                return $n;
            },
        ]);
        $response = (new Client)->request('GET', $url, [
            'allow_redirects' => false, 'connect_timeout' => 5, 'timeout' => 20,
            'verify' => true, 'cookies' => false, 'decode_content' => false, 'sink' => $sink,
            'headers' => ['Accept' => 'audio/mpeg', 'Accept-Encoding' => 'identity'],
            'on_headers' => function ($response): void {
                if ($response->getStatusCode() !== 200 || (int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new RuntimeException('Recording download rejected.');
                }
            },
        ]);
        if (! $written || $response->getStatusCode() !== 200) {
            throw new RuntimeException('Empty recording download.');
        }
    }
}
