<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiInstallation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Client
{
    public function base(AryeoApiInstallation $i): string
    {
        $base = rtrim((string) config('aryeo_api.'.$i->environment.'_url'), '/');
        abort_unless(filter_var($base, FILTER_VALIDATE_URL) && str_starts_with($base, 'https://') && ! parse_url($base, PHP_URL_USER), 409, 'provider_endpoint_not_configured');

        return $base;
    }

    public function request(AryeoApiInstallation $i, string $method, string $path, array $body = []): Response
    {
        return $this->sendPrepared($i, $this->prepare($i, $method, $path), $method, $path, $body);
    }

    public function prepare(AryeoApiInstallation $i, string $method, string $path): PendingRequest
    {
        abort_unless(config('aryeo_api.enabled'), 409, 'provider_api_disabled');
        abort_unless(preg_match('~^/[a-z][a-z0-9/-]*(?:\?[a-zA-Z0-9%_=&.\[\]-]*)?$~D', $path), 409, 'invalid_provider_path');
        $this->base($i);
        abort_if($i->environment === 'production' && in_array(config('cache.default'), ['array', 'null'], true), 409, 'shared_credential_cache_required');
        $token = app(OAuth::class)->accessToken($i);
        app(RateBudget::class)->reserve();
        $request = Http::withToken($token)->acceptJson()->timeout(40)->connectTimeout(10)->withoutRedirecting();
        if ($method === 'POST' && in_array($path, ['/file-ingest-intents', '/links'], true)) {
            $request = $request->withHeaders(['Aryeo-Group-Id' => $i->company_id]);
        }

        return $request;
    }

    public function sendPrepared(AryeoApiInstallation $i, PendingRequest $request, string $method, string $path, array $body): Response
    {
        $response = $request->send($method, $this->base($i).$path, $method === 'GET' ? ['query' => $body] : ($body ? ['json' => $body] : []));
        if ($response->status() === 401) {
            AryeoApiInstallation::whereKey($i->id)->where('authorization_generation', $i->authorization_generation)->update(['status' => 'reconnect_required', 'paused' => true]);
        }

        return $response;
    }

    public function get(AryeoApiInstallation $i, string $path, array $query = []): array
    {
        $r = $this->request($i, 'GET', $path, $query);
        abort_unless($r->successful() && is_array($r->json()), 409, 'provider_read_failed');

        return $r->json();
    }

    public function listing(AryeoApiInstallation $i, string $path, array $query = []): array
    {
        $rows = [];
        $cursor = null;
        $seen = [];
        for ($page = 0; $page < 100; $page++) {
            $result = $this->get($i, $path, $query + ['paging[size]' => 100] + ($cursor ? ['paging[after]' => $cursor] : []));
            abort_unless(is_array($result['data'] ?? null) && array_is_list($result['data'])
                && array_key_exists('paging', $result['meta'] ?? []), 409, 'provider_inventory_incomplete');
            array_push($rows, ...$result['data']);
            $paging = $result['meta']['paging'] ?? null;
            if (! $paging || empty($paging['after_url'])) {
                return $rows;
            }
            // Never follow a provider URL with our Authorization header. Only
            // extract its cursor and repeat the same known endpoint.
            $next = [];
            parse_str(parse_url($paging['after_url'], PHP_URL_QUERY) ?? '', $next);
            $cursor = $next['paging']['after'] ?? null;
            abort_unless(is_string($cursor) && $cursor !== '' && ! isset($seen[$cursor]), 409, 'provider_inventory_incomplete');
            $seen[$cursor] = true;
        }
        abort(409, 'provider_inventory_page_limit');
    }
}
