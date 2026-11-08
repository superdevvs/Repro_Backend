<?php

namespace App\Services\Shoots;

/** Keep semantic view/unit/revision arguments; discard only transport credentials and cache busters. */
class ProviderDeliveryUrl
{
    public static function canonical(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) return '';
        $pairs = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') continue;
            $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (str_starts_with($key, 'x-amz-') || in_array($key, ['authtoken', 'auth_token', 'access_token', 'token', 'signature', 'sig', 'expires', 'expiry',
                'policy', 'key-pair-id', 'googleaccessid', '_', 'cache_bust', 'cachebuster', 'cachebust', 'nocache'], true)) continue;
            $pairs[] = $pair;
        }
        sort($pairs, SORT_STRING);
        return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? ''))
            .(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '')
            .rtrim($parts['path'] ?? '', '/').($pairs ? '?'.implode('&', $pairs) : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
