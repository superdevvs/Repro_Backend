<?php

namespace App\Services\Shoots;

use Illuminate\Support\Arr;

/** Zillow-hosted viewers are separate from downloadable, scanned floorplan files. */
class ZillowTourLinks
{
    public const VISIBILITY_KEYS = ['show_zillow_on_branded', 'show_zillow_on_mls'];

    public function visibility(array $links): array
    {
        return [
            'show_zillow_on_branded' => $this->isVisible($links, true),
            'show_zillow_on_mls' => $this->isVisible($links, false),
        ];
    }

    public function isVisible(array $links, bool $branded): bool
    {
        $key = $branded ? 'show_zillow_on_branded' : 'show_zillow_on_mls';

        return ($links[$key] ?? true) !== false;
    }

    public function publicLinks(array $links, bool $branded): array
    {
        if (! $this->isVisible($links, $branded)) {
            unset($links['zillow_3d']);

            // A Zillow walkthrough can also be pasted into a custom embed.
            // Remove its sources while retaining unrelated embed destinations.
            if (is_array($links['embeds'] ?? null)) {
                $sourceKeys = ['url', 'branded', 'branded_embed', 'mls', 'mls_embed'];
                $embeds = [];
                foreach ($links['embeds'] as $embed) {
                    if (! is_array($embed)) {
                        continue;
                    }
                    foreach (Arr::only($embed, $sourceKeys) as $key => $source) {
                        if (is_string($source) && preg_match(
                            '~https://(?:www\.)?zillow\.com/view-(?:imx|3d-home)/[a-z0-9-]+(?:[/?#]|["\'<>\s]|$)~i',
                            html_entity_decode($source, ENT_QUOTES | ENT_HTML5),
                        )) {
                            unset($embed[$key]);
                        }
                    }
                    if (array_filter(Arr::only($embed, $sourceKeys))) {
                        $embeds[] = $embed;
                    }
                }
                $links['embeds'] = $embeds;
            }
        }

        return Arr::except($links, self::VISIBILITY_KEYS);
    }

    public function viewer(array $links, bool $branded): ?array
    {
        if (! $this->isVisible($links, $branded)) {
            return null;
        }

        $url = $this->normalize($links['zillow_3d'] ?? null, ! $branded);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query['initialViewType'] = 'floorplan';
        $floorplan = 'https://'.$parts['host'].$parts['path'].'?'.http_build_query($query);

        return [
            'inline_url' => $url,
            'open_url' => $url,
            // view-imx is Zillow's interactive floor-plan viewer. Older 3D-only
            // URLs are not evidence that a floor plan is available.
            'floorplan_url' => str_contains($url, '/view-imx/')
                ? $floorplan
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
            || ! preg_match('~^/(view-imx|view-3d-home)/([a-zA-Z0-9-]+)/?$~D', $parts['path'] ?? '', $match)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $names = array_map(
            fn ($parameter) => rawurldecode(explode('=', $parameter, 2)[0]),
            explode('&', $parts['query'] ?? '')
        );
        $unbranded = ($query['setAttribution'] ?? null) === 'mls'
            && in_array($query['wl'] ?? null, ['true', '1'], true);
        if ($requireUnbranded && (! $unbranded
            || count(array_keys($names, 'setAttribution', true)) !== 1
            || count(array_keys($names, 'wl', true)) !== 1
            || preg_grep('/^(setAttribution|wl)\[/', $names))) {
            return null;
        }

        // Preserve the published presentation options used by existing embeds.
        return trim($value);
    }
}
