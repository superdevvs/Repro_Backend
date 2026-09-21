<?php

namespace App\Services\Voice;

use App\Models\VoiceBrowserCommand;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class VoiceBrowserGateway
{
    public function request(string $method, string $path, array $payload = [], bool $plain = false): mixed
    {
        $request = Http::withToken((string) config('services.telnyx.api_key'))->acceptJson()->connectTimeout(5)->timeout(15);
        try {
            $response = $request->send($method, rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/').$path,
                $method === 'GET' ? ['query' => $payload] : ['json' => $payload]);
        } catch (Throwable $e) {
            throw new RuntimeException('The phone provider did not confirm the request. Retry to check its result.');
        }
        if ($method === 'DELETE' && str_starts_with($path, '/telephony_credentials/') && $response->status() === 404) {
            return [];
        }
        if (! $response->successful()) {
            throw new RuntimeException('The phone provider could not complete the request.');
        }

        return $plain ? trim($response->body()) : ($response->json('data') ?? $response->json() ?? []);
    }

    /** Durable provider command IDs make retries safe across ambiguous responses. */
    public function command(string $key, string $path, array $payload = []): array
    {
        return Cache::lock('voice-browser-command:'.hash('sha256', $key), 40)->block(5, function () use ($key, $path, $payload): array {
            $command = LockedWrite::run(fn () => VoiceBrowserCommand::query()->firstOrCreate(
                ['command_key' => $key], ['path' => $path, 'payload' => $payload],
            ));
            if ($command->path !== $path || $command->payload !== $payload) {
                throw new RuntimeException('This phone operation was already used for different details.');
            }
            if ($command->state === 'succeeded') {
                return $command->response ?? [];
            }
            LockedWrite::run(fn () => $command->update(['state' => 'sending']));
            try {
                $result = $this->request('POST', $path, array_merge($payload, ['command_id' => $command->id]));
                LockedWrite::run(fn () => $command->update(['state' => 'succeeded', 'response' => $result]));

                return $result;
            } catch (Throwable $e) {
                LockedWrite::run(fn () => $command->update(['state' => 'uncertain']));
                throw $e;
            }
        });
    }
}
