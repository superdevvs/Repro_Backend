<?php

namespace App\Services\LinkPreview;

/**
 * Everything needed to render one preview card and emit its meta tags.
 *
 * Resolved once by LinkPreviewService so the image renderer, the JSON metadata
 * endpoint and the prerendered HTML document all describe the same thing. If a
 * field is null here it is because the shoot genuinely lacks it - the cards are
 * built to degrade rather than to assume.
 */
class PreviewPayload
{
    /**
     * @param  string  $type      Shareable link type: branded|mls|g-mls|video-*|3d-*|dashboard|portal
     * @param  string  $design    Card design key: d2|d4|d5|d6|d8
     * @param  bool    $branded   False for MLS/unbranded links, which must carry no agent or brokerage identity
     * @param  array<int, string>  $gallery       Extra image sources for the mosaic, hero excluded
     * @param  array<int, array{label: string, value: string}>  $stats  Beds/baths/sqft/lot, already filtered to what exists
     */
    public function __construct(
        public readonly string $type,
        public readonly string $design,
        public readonly bool $branded,
        public readonly string $title,
        public readonly string $description,
        public readonly string $url,
        public readonly ?string $hero = null,
        public readonly array $gallery = [],
        public readonly ?string $floorplan = null,
        public readonly ?string $addressLine = null,
        public readonly ?string $cityLine = null,
        public readonly array $stats = [],
        public readonly ?string $price = null,
        public readonly ?string $mlsId = null,
        public readonly int $photoCount = 0,
        public readonly ?string $chipLabel = null,
        public readonly ?string $chipColor = null,
        public readonly ?string $agentName = null,
        public readonly ?string $agentCompany = null,
        public readonly ?string $headline = null,
        public readonly ?string $subhead = null,
        public readonly ?string $videoUrl = null,
        public readonly ?int $shootId = null,
        public readonly string $fingerprintSeed = '',
    ) {
    }

    /**
     * Stable hash of every input that affects the rendered pixels.
     *
     * This lands in the card filename. Crawlers cache an og:image URL hard -
     * Facebook effectively forever - so the URL has to change when the content
     * does. Keying on the inputs means a new hero photo or a price edit
     * produces a new URL automatically, and an unchanged shoot keeps serving
     * the cached file.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', implode('|', [
            self::RENDERER_VERSION,
            $this->type,
            $this->design,
            $this->branded ? 'b' : 'u',
            $this->title,
            $this->description,
            (string) $this->hero,
            implode(',', $this->gallery),
            (string) $this->floorplan,
            (string) $this->addressLine,
            (string) $this->cityLine,
            json_encode($this->stats),
            (string) $this->price,
            (string) $this->mlsId,
            (string) $this->photoCount,
            (string) $this->chipLabel,
            (string) $this->agentName,
            (string) $this->agentCompany,
            (string) $this->headline,
            (string) $this->subhead,
            $this->fingerprintSeed,
        ])), 0, 16);
    }

    /**
     * Bump when the drawing code changes in a way that should invalidate every
     * previously generated card.
     */
    public const RENDERER_VERSION = 'v2';

    public function isVideo(): bool
    {
        return $this->design === 'd5';
    }

    public function hasProperty(): bool
    {
        return $this->addressLine !== null && $this->addressLine !== '';
    }
}
