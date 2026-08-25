<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\LinkPreview\LinkPreviewService;
use App\Services\LinkPreview\OgImageService;
use App\Services\LinkPreview\PreviewPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LinkPreviewController extends Controller
{
    public function __construct(
        private readonly LinkPreviewService $previews,
        private readonly OgImageService $images,
    ) {
    }

    /**
     * Metadata deliberately does not render the card.
     *
     * The edge calls this on every page view of a shareable route, including
     * ordinary human traffic, so it must stay cheap and must never block on
     * image generation. The card URL is content-addressed and derived from the
     * payload, so it is knowable before the bytes exist; the image route renders
     * on first request.
     */
    public function metadata(Request $request, string $type): JsonResponse
    {
        $payload = $this->resolvePayload($request, $type);

        return response()->json([
            'type' => $payload->type,
            'title' => $payload->title,
            'description' => $payload->description,
            'site_name' => $payload->branded || in_array($payload->type, LinkPreviewService::STATIC_TYPES, true)
                ? 'R/E Pro Photos'
                : 'Property Tour',
            'url' => $payload->url,
            'image' => [
                'url' => $this->imageRoute($payload),
                'type' => 'image/jpeg',
                'width' => (int) config('link_preview.card.width', 1200),
                'height' => (int) config('link_preview.card.height', 630),
                'alt' => $this->imageAlt($payload),
            ],
            'fingerprint' => $payload->fingerprint(),
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function image(Request $request, string $type, string $fingerprint): SymfonyResponse
    {
        abort_unless((bool) config('link_preview.enabled', true), 404);
        abort_unless($this->previews->isKnownType($type), 404);

        $shootId = $this->shootId($request, $type);

        // Fingerprinted cards are immutable. If the shoot has changed, continue
        // serving the old object to crawlers that still hold its old URL.
        $image = $this->images->existing($type, $shootId, $fingerprint);
        if ($image === null) {
            $payload = $this->resolvePayload($request, $type);
            abort_unless(hash_equals($payload->fingerprint(), $fingerprint), 404);
            $image = $this->images->ensure($payload);
        }

        $etag = '"' . $fingerprint . '"';
        $headers = [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) $image['size'],
            'Cache-Control' => OgImageService::immutableCacheControl(),
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($image['contents'], 200, $headers);
    }

    /**
     * Origin-rendered metadata document.
     *
     * The Cloudflare Worker rewrites the SPA head and is the primary delivery
     * path, so this route is a fallback: it keeps previews working if the Worker
     * is disabled or its route is removed, and it is the only way to inspect the
     * exact tag set a crawler receives without going through the edge. Like
     * metadata(), it does not render the card.
     */
    public function document(Request $request, string $type): View|Response
    {
        $payload = $this->resolvePayload($request, $type);

        return response()
            ->view('link-preview', [
                'payload' => $payload,
                'imageUrl' => $this->imageRoute($payload),
                'imageAlt' => $this->imageAlt($payload),
                'width' => (int) config('link_preview.card.width', 1200),
                'height' => (int) config('link_preview.card.height', 630),
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function resolvePayload(Request $request, string $type): PreviewPayload
    {
        abort_unless((bool) config('link_preview.enabled', true), 404);
        abort_unless($this->previews->isKnownType($type), 404);

        if (! $this->previews->isTourType($type)) {
            return $this->previews->forStaticPage($type);
        }

        $shootId = $this->shootId($request, $type);
        $shoot = Shoot::query()->find($shootId);
        abort_unless($shoot !== null, 404);

        return $this->previews->forShoot($shoot, $type, $this->provider($request, $type));
    }

    private function shootId(Request $request, string $type): ?int
    {
        if (! $this->previews->isTourType($type)) {
            return null;
        }

        $raw = $request->query('shootId');
        abort_unless(is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw), 404);

        return (int) $raw;
    }

    private function provider(Request $request, string $type): ?string
    {
        if (! in_array($type, ['3d', '3d-branded', '3d-mls'], true)) {
            return null;
        }

        $raw = $request->query('provider');
        if ($raw === null || $raw === '') {
            return null;
        }
        abort_unless(is_string($raw) && $this->previews->normalizeProvider($raw) !== null, 404);

        return $this->previews->normalizeProvider($raw);
    }

    private function imageRoute(PreviewPayload $payload): string
    {
        $parameters = [
            'type' => $payload->type,
            'fingerprint' => $payload->fingerprint(),
        ];
        if ($payload->shootId !== null) {
            $parameters['shootId'] = $payload->shootId;
        }

        return route('api.public.link-previews.image', $parameters);
    }

    private function imageAlt(PreviewPayload $payload): string
    {
        return $payload->addressLine
            ? $payload->addressLine . ' property preview'
            : $payload->title;
    }
}
