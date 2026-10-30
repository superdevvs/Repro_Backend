<?php

namespace App\Services\Voice;

use App\Models\VoiceBrowserCommand;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class VoiceBrowserGateway
{
    public function request(string $method, string $path, array $payload = [], bool $plain = false, int $timeout = 15): mixed
    {
        $request = Http::withToken((string) config('services.telnyx.api_key'))->acceptJson()->connectTimeout(min(5, $timeout))->timeout($timeout);
        try {
            $response = $request->send($method, rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/').$path,
                $method === 'GET' ? ['query' => $payload] : ['json' => $payload]);
        } catch (Throwable $e) {
            throw new RuntimeException('The phone provider did not confirm the request. Retry to check its result.');
        }
        if ($method === 'DELETE' && (str_starts_with($path, '/telephony_credentials/') || str_starts_with($path, '/recordings/')) && $response->status() === 404) {
            return [];
        }
        if (! $response->successful()) {
            throw new RuntimeException('The phone provider could not complete the request.', $response->status());
        }

        return $plain ? trim($response->body()) : ($response->json('data') ?? $response->json() ?? []);
    }

    /** Durable provider command IDs make retries safe across ambiguous responses. */
    public function command(string $key, string $path, array $payload = [], bool $sensitive = false): array
    {
        return VoiceLocks::lock('voice-browser-command:'.hash('sha256', $key), 40)->block(5, function () use ($key, $path, $payload, $sensitive): array {
            $stored = $sensitive ? ['sensitive_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))] : $payload;
            $command = LockedWrite::run(fn () => VoiceBrowserCommand::query()->firstOrCreate(
                ['command_key' => $key], ['path' => $path, 'payload' => $stored],
            ));
            if ($command->path !== $path || $command->payload !== $stored) {
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
                $definite = $e->getCode() >= 400 && $e->getCode() < 500 && ! in_array($e->getCode(), [408, 429], true);
                LockedWrite::run(fn () => $command->update(['state' => $definite ? 'failed' : 'uncertain']));
                throw $e;
            }
        });
    }
}
