<?php

namespace App\Services\Shoots;

/** Zillow-hosted viewers are separate from downloadable, scanned floorplan files. */
class ZillowTourLinks
{
    public function viewer(array $links, bool $branded): ?array
    {
        $url = $this->normalize($links['zillow_3d'] ?? null, ! $branded);
        if ($url === null) {
            return null;
        }

        return [
            'inline_url' => $url,
            'open_url' => $url,
            // view-imx is Zillow's interactive floor-plan viewer. Older 3D-only
            // URLs are not evidence that a floor plan is available.
            'floorplan_url' => str_contains($url, '/view-imx/')
                ? str_replace('initialViewType=pano', 'initialViewType=floorplan', $url)
                : null,
        ];
    }

    public function normalize(mixed $value, bool $requireUnbranded = false): ?string
    {
        if (! is_string($value) || ! filter_var(trim($value), FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url(trim($value));
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), ['zillow.com', 'www.zillow.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! preg_match('~^/(view-imx|view-3d-home)/([a-zA-Z0-9-]{6,80})/?$~D', $parts['path'] ?? '', $match)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $unbranded = ($query['setAttribution'] ?? null) === 'mls'
            && in_array($query['wl'] ?? null, ['true', '1'], true);
        if ($requireUnbranded && ! $unbranded) {
            return null;
        }

        // Only retain the provider's presentation flags, never arbitrary
        // redirects, user identifiers, or tracking parameters.
        $params = $unbranded ? ['setAttribution' => 'mls', 'wl' => 'true'] : [];
        $params['initialViewType'] = 'pano';

        return 'https://www.zillow.com/'.$match[1].'/'.$match[2].'?'.http_build_query($params);
    }
}
