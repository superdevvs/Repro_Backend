<?php

namespace App\Services\Studio\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-only adapter for Virtual Staging AI API v2.
 * Result links are signed and short-lived, so callers download them immediately.
 * The studio worker polls instead of receiving webhooks.
 */
class VirtualStagingAiClient
{
    private array $configuration;

    public function __construct(#[\SensitiveParameter] array $configuration = [])
    {
        $this->configuration = array_replace((array) config('studio_providers.virtualstagingai', []), $configuration);
    }

    public function __debugInfo(): array
    {
        return ['configured' => filled($this->configuration['api_key'] ?? null)];
    }

    public function account(): array
    {
        $data = $this->send('GET', 'user');
        $limit = $data['photoLimit']['staging'] ?? null;
        $used = $data['photosUsedThisPeriod']['staging'] ?? null;
        if (! is_numeric($used) || (! is_numeric($limit) && $limit !== 'UNLIMITED')) {
            throw new VirtualStagingAiException('Virtual Staging AI returned an unexpected account response.');
        }

        return [
            'stagingUsed' => (int) $used,
            'stagingLimit' => $limit === 'UNLIMITED' ? 'UNLIMITED' : (int) $limit,
            'checkedAt' => now()->toIso8601String(),
        ];
    }

    public function createRender(#[\SensitiveParameter] string $imageUrl, array $config, int $variationCount): array
    {
        return $this->send('POST', 'renders', ['json' => [
            'config' => $config,
            'image_url' => $imageUrl,
            'variation_count' => $variationCount,
            'wait_for_completion' => false,
        ]]);
    }

    public function createVariations(string $renderId, array $config, int $variationCount): array
    {
        return $this->send('POST', 'renders/'.rawurlencode($renderId).'/variations', ['json' => [
            'config' => $config,
            'variation_count' => $variationCount,
            'wait_for_completion' => false,
        ]]);
    }

    public function render(string $renderId): array
    {
        $items = [];
        $cursor = null;
        $render = [];
        do {
            $query = ['include_variations' => 'true', 'variations_limit' => 100, 'variations_order' => 'asc'];
            if ($cursor) {
                $query['variations_cursor'] = $cursor;
            }
            $render = $this->send('GET', 'renders/'.rawurlencode($renderId), ['query' => $query]);
            foreach ($render['variations']['items'] ?? [] as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $items[$item['id']] = $item;
                }
            }
            $cursor = $render['variations']['next_cursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');
        $render['variations']['items'] = array_values($items);

        return $render;
    }

    public function analyze(#[\SensitiveParameter] string $imageUrl): array
    {
        return $this->send('POST', 'analyze', ['json' => ['image_url' => $imageUrl]], 70);
    }

    public function download(string $url): string
    {
        $this->assertPublicHttps($url);
        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(120)
                ->connectTimeout(15)
                ->withHeaders(['Accept' => 'image/*,*/*'])
                ->get($url);
        } catch (Throwable) {
            throw new \RuntimeException('Virtual staging could not be reached. Retry to resume this photo.');
        }
        if (! $response->successful()) {
            throw new \RuntimeException('Virtual staging could not be reached. Retry to resume this photo.');
        }
        $body = $response->body();
        if ($body === '' || strlen($body) > 30_000_000 || ! @getimagesizefromstring($body)) {
            throw new VirtualStagingAiException('Virtual staging returned an unreadable image.');
        }

        return $body;
    }

    private function send(string $method, string $path, array $options = [], ?int $timeout = null): array
    {
        $key = trim((string) ($this->configuration['api_key'] ?? ''));
        if ($key === '') {
            throw new VirtualStagingAiException('An administrator needs to finish configuring this service.');
        }
        $url = rtrim((string) ($this->configuration['base_url'] ?? 'https://api.virtualstagingai.app/v2'), '/').'/'.ltrim($path, '/');
        try {
            $pending = Http::withHeaders([
                'Authorization' => 'Api-Key '.$key,
                'Accept' => 'application/json',
            ])->timeout($timeout ?? (int) ($this->configuration['timeout'] ?? 60))->connectTimeout(15);
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $options['query'] ?? []),
                'POST' => $pending->asJson()->post($url, $options['json'] ?? []),
                default => throw new VirtualStagingAiException('Virtual staging could not finish this photo. Please retry.'),
            };
        } catch (VirtualStagingAiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new \RuntimeException('Virtual staging could not be reached. Retry to resume this photo.');
        }

        return $this->decode($response);
    }

    private function decode(Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();
            if (! is_array($json)) {
                throw new \RuntimeException('Virtual staging returned an unreadable response. Retry to resume this photo.');
            }

            return $json;
        }
        $status = $response->status();
        if ($status === 401) {
            throw new VirtualStagingAiException('The virtual staging API key was rejected. Save a valid key in AI Editing settings.');
        }
        if ($status === 403) {
            throw new VirtualStagingAiException('Virtual staging is unavailable for this account. The plan may not include API access, or the photo limit is used up.');
        }
        if (in_array($status, [400, 404, 422], true)) {
            throw new VirtualStagingAiException('Virtual staging rejected this photo or these options. Check the room, style, and removal settings.');
        }

        throw new \RuntimeException('Virtual staging could not finish this photo. Retry to resume this photo.');
    }

    private function assertPublicHttps(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $invalid = ($parts['scheme'] ?? '') !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($invalid) {
            throw new VirtualStagingAiException('Virtual staging returned an invalid image address.');
        }
    }
}
