<?php

namespace App\Services\Shoots;

use Illuminate\Support\Facades\Http;

/** Inspect actual published viewer data, never execute provider HTML. */
class IguidePublicViewerVerification
{
    public function verify(string $url, string $propertyId): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array(strtolower($parts['host'] ?? ''), ['youriguide.com', 'www.youriguide.com', 'unbranded.youriguide.com', 'iguideradix.com', 'unbranded.iguideradix.com'], true)) return null;
        $response = Http::timeout(25)->withOptions(['allow_redirects' => false])->get($url);
        if (! $response->successful() || strlen($response->body()) > 2 * 1024 * 1024) return null;
        $proof = $this->inspect($response->body(), $propertyId);
        return $proof ? $proof + ['checked_url' => ProviderDeliveryUrl::canonical($url)] : null;
    }

    public function inspect(string $html, string $propertyId): ?array
    {
        if (preg_match('/\bconst\s+viewerParams\s*=\s*\{/', $html, $start, PREG_OFFSET_CAPTURE) !== 1) return null;
        $offset = $start[0][1] + strlen($start[0][0]);
        if (preg_match('/\bviewObject\s*:\s*/', $html, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) return null;
        $at = $match[0][1] + strlen($match[0][0]);
        if (($html[$at] ?? '') !== '{') return null;
        $depth = 0; $quoted = false; $escaped = false; $end = null;
        for ($i = $at, $length = strlen($html); $i < $length; $i++) {
            $char = $html[$i];
            if ($quoted) { if ($escaped) $escaped = false; elseif ($char === '\\') $escaped = true; elseif ($char === '"') $quoted = false; continue; }
            if ($char === '"') $quoted = true;
            elseif ($char === '{') $depth++;
            elseif ($char === '}' && --$depth === 0) { $end = $i; break; }
        }
        if ($end === null) return null;
        $view = json_decode(substr($html, $at, $end - $at + 1), true);
        if (! is_array($view) || data_get($view, 'iguide.id') !== $propertyId || ! empty($view['isProtected'])) return null;
        $model = $view['dataV2'] ?? $view['data'] ?? null;
        if (is_string($model)) $model = json_decode($model, true);
        if (! is_array($model) || empty($model['buildings']) || empty($view['updated_at'])) return null;
        $panos = collect($model['buildings'])->flatMap(fn ($building) => collect($building['floors'] ?? [])->flatMap(fn ($floor) => $floor['panos'] ?? []));
        if (! $panos->contains(fn ($pano) => ! empty($pano['id']) && empty($pano['noSphere']))) return null;
        try { $updated = \Carbon\CarbonImmutable::parse($view['updated_at']); } catch (\Throwable) { return null; }
        return ['property_id' => $propertyId, 'view_id' => $view['id'] ?? null, 'updated_at' => $updated->toIso8601String(),
            'model_hash' => hash('sha256', json_encode($model, JSON_THROW_ON_ERROR)), 'checked_at' => now()->toIso8601String()];
    }
}
