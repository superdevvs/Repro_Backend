<?php

namespace App\Services\Shoots;

use Illuminate\Support\Arr;

/**
 * Per-shoot (or per-unit) switches that keep saved tour content off public
 * surfaces. A switch never removes the saved link, the file or its delivery
 * receipt: the dashboard keeps showing it and turning the switch back on
 * republishes it.
 *
 * Each kind of content has one switch for the branded page and one for the
 * MLS pages (MLS and generic MLS), the pattern Zillow already used.
 */
class PublicTourVisibility
{
    public const CONTENT = ['video', 'matterport', 'iguide', 'cubicasa', 'zillow'];

    public const KEYS = [
        'show_video_on_branded', 'show_video_on_mls',
        'show_matterport_on_branded', 'show_matterport_on_mls',
        'show_iguide_on_branded', 'show_iguide_on_mls',
        'show_cubicasa_on_branded', 'show_cubicasa_on_mls',
        'show_zillow_on_branded', 'show_zillow_on_mls',
    ];

    /** Saved link slots of each kind of content, by the public page they feed. */
    private const LINK_KEYS = [
        'video' => ['branded' => ['video_branded', 'video_link'], 'mls' => ['video_mls', 'video_generic']],
        'matterport' => ['branded' => ['matterport_branded', 'matterport'], 'mls' => ['matterport_mls']],
        'iguide' => ['branded' => ['iguide_branded', 'iGuide', 'iguide'], 'mls' => ['iguide_mls']],
        'cubicasa' => ['branded' => ['cubicasa_branded', 'cubicasa', 'cubicasa_url'], 'mls' => ['cubicasa_mls']],
        'zillow' => ['branded' => ['zillow_3d'], 'mls' => []],
    ];

    /** Server-owned companions that must leave with the link they describe. */
    private const COMPANION_KEYS = ['iguide_mls' => ['iguide_mls_source']];

    /**
     * The same content can also be pasted into a custom embed, as a URL or as
     * iframe HTML, so these match anywhere in the source.
     */
    private const EMBED_PATTERNS = [
        'video' => '~(?:youtube(?:-nocookie)?\.com|youtu\.be|vimeo\.com|wistia\.(?:com|net)|loom\.com|dailymotion\.com)(?:[/?#:"\'<>\s]|$)|\.(?:mp4|m4v|mov|webm|m3u8)(?:[?#"\'<>\s]|$)~i',
        'matterport' => '~(?:^|[/.@"\'=\s])matterport\.com(?:[/?#:"\'<>\s]|$)~i',
        'iguide' => '~(?:^|[/.@"\'=\s])(?:youriguide|iguidephotos|iguideradix)\.com(?:[/?#:"\'<>\s]|$)~i',
        'cubicasa' => '~(?:^|[/.@"\'=\s])(?:cubi\.casa|cubicasa\.com)(?:[/?#:"\'<>\s]|$)~i',
        'zillow' => '~https://(?:www\.)?zillow\.com/view-(?:imx|3d-home)/[a-z0-9-]+(?:[/?#]|["\'<>\s]|$)~i',
    ];

    private const EMBED_SOURCES = ['branded' => ['url', 'branded', 'branded_embed'], 'mls' => ['mls', 'mls_embed']];

    /** @return array<string, bool> every switch, defaulting to shown */
    public function visibility(array $links): array
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = ($links[$key] ?? true) !== false;
        }

        return $settings;
    }

    public function isVisible(array $links, string $content, bool $branded): bool
    {
        return ($links[self::key($content, $branded)] ?? true) !== false;
    }

    public static function key(string $content, bool $branded): string
    {
        return 'show_'.$content.'_on_'.($branded ? 'branded' : 'mls');
    }

    /** @return list<string> content switched off for this page */
    public function hiddenContent(array $links, bool $branded): array
    {
        return array_values(array_filter(self::CONTENT, fn (string $content) => ! $this->isVisible($links, $content, $branded)));
    }

    /**
     * Links for one public page. Every slot of hidden content is removed, not
     * only this page's own slot, because pages fall back across slots (a
     * branded page shows the MLS iGUIDE when it has no branded one).
     */
    public function publicLinks(array $links, bool $branded): array
    {
        foreach ($this->hiddenContent($links, $branded) as $content) {
            foreach (self::LINK_KEYS[$content] as $keys) {
                $links = $this->withoutLinkKeys($links, $keys);
            }
            $links = $this->withoutEmbedSources($links, $content, array_merge(...array_values(self::EMBED_SOURCES)));
        }

        return Arr::except($links, self::KEYS);
    }

    /**
     * Links for places that list both audiences together (delivery emails,
     * guest shares). Each slot follows the switch of the page it feeds.
     */
    public function shareableLinks(array $links): array
    {
        foreach (self::CONTENT as $content) {
            foreach ([true, false] as $branded) {
                if ($this->isVisible($links, $content, $branded)) {
                    continue;
                }
                $audience = $branded ? 'branded' : 'mls';
                $links = $this->withoutLinkKeys($links, self::LINK_KEYS[$content][$audience]);
                $links = $this->withoutEmbedSources($links, $content, self::EMBED_SOURCES[$audience]);
            }
        }

        return Arr::except($links, self::KEYS);
    }

    /**
     * Whether a link that carries no audience of its own (a delivered external
     * URL, a provider's own tour URL) must stay off a shared list. It follows
     * the slots it was saved in, and the branded page when it is in none.
     */
    public function hidesSharedUrl(array $links, string $content, mixed $url): bool
    {
        if (! isset(self::LINK_KEYS[$content])) {
            return false;
        }
        $audiences = [];
        foreach (self::LINK_KEYS[$content] as $audience => $keys) {
            foreach ($keys as $key) {
                if (is_string($url) && is_string($links[$key] ?? null) && trim($links[$key]) === trim($url)) {
                    $audiences[$audience] = true;
                }
            }
        }
        if ($audiences === []) {
            return ! $this->isVisible($links, $content, true);
        }
        foreach (array_keys($audiences) as $audience) {
            if ($this->isVisible($links, $content, $audience === 'branded')) {
                return false;
            }
        }

        return true;
    }

    /** The kind of content a labelled share link represents, if it has a switch. */
    public function contentFor(string $kind, string $provider): ?string
    {
        if ($kind === 'video') {
            return 'video';
        }

        return in_array($provider, self::CONTENT, true) ? $provider : null;
    }

    private function withoutLinkKeys(array $links, array $keys): array
    {
        foreach ($keys as $key) {
            unset($links[$key]);
            foreach (self::COMPANION_KEYS[$key] ?? [] as $companion) {
                unset($links[$companion]);
            }
        }

        return $links;
    }

    /** Drops the matching sources of each embed and keeps unrelated destinations. */
    private function withoutEmbedSources(array $links, string $content, array $sourceKeys): array
    {
        if (! is_array($links['embeds'] ?? null)) {
            return $links;
        }
        // A saved video can live on any host, so its own links count as video too.
        $saved = $content === 'video'
            ? array_filter(array_map(fn ($key) => is_string($links[$key] ?? null) ? trim($links[$key]) : '', ['video_branded', 'video_link', 'video_mls', 'video_generic']))
            : [];
        $allSources = array_merge(...array_values(self::EMBED_SOURCES));
        $embeds = [];
        foreach ($links['embeds'] as $embed) {
            if (! is_array($embed)) {
                continue;
            }
            foreach (Arr::only($embed, $sourceKeys) as $key => $source) {
                if (! is_string($source)) {
                    continue;
                }
                $decoded = html_entity_decode($source, ENT_QUOTES | ENT_HTML5);
                if (preg_match(self::EMBED_PATTERNS[$content], $decoded) || in_array(trim($decoded), $saved, true)) {
                    unset($embed[$key]);
                }
            }
            if (array_filter(Arr::only($embed, $allSources))) {
                $embeds[] = $embed;
            }
        }
        $links['embeds'] = $embeds;
        $featured = $links['featured_embed_id'] ?? $links['featured_embed'] ?? null;
        if ($featured !== null && ! in_array($featured, array_column($embeds, 'id'), true)) {
            unset($links['featured_embed_id'], $links['featured_embed']);
        }

        return $links;
    }
}
