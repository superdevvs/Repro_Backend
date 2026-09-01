<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\PropertyDescriptionPolicy;
use App\Services\Shoots\ShootPublicAssetsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShootPublicAssetsController extends Controller
{
    public function __construct(
        protected ShootPublicAssetsService $shootPublicAssetsService,
        protected PropertyDescriptionPolicy $propertyDescriptionPolicy
    ) {}

    public function publicBranded(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (! $shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return $this->publicAssetsResponse($this->buildVisiblePublicAssets($request, $shoot, 'branded'));
    }

    public function publicMls(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (! $shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return $this->publicAssetsResponse($this->buildVisiblePublicAssets($request, $shoot, 'mls'));
    }

    public function publicGenericMls(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (! $shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return $this->publicAssetsResponse($this->buildVisiblePublicAssets($request, $shoot, 'generic-mls'));
    }

    private function publicAssetsResponse(array $assets): JsonResponse
    {
        $response = response()->json($assets);
        if (filled(data_get($assets, 'iguide_viewer.expires_at'))) {
            // The offline viewer URL is a short-lived bearer credential. Do
            // not let a browser, service worker, or intermediary retain a JSON
            // response whose otherwise-valid tour data contains an expired URL.
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    private function buildVisiblePublicAssets(Request $request, Shoot $shoot, string $type): array
    {
        $assets = $this->shootPublicAssetsService->buildTypedPublicAssets($shoot, $type);

        if ($this->canViewVideoAssets($request, $shoot)) {
            return $assets;
        }

        foreach (['video_link', 'video_thumbnail_url', 'video_poster_url'] as $key) {
            $assets[$key] = null;
        }

        $assets['video_access_restricted'] = true;
        $assets['tour_links'] = $this->stripVideoTourLinks($assets['tour_links'] ?? []);
        $assets['embeds'] = [];

        return $assets;
    }

    private function canViewVideoAssets(Request $request, Shoot $shoot): bool
    {
        if ($this->isDelivered($shoot)) {
            return true;
        }

        $user = $request->user() ?? auth('sanctum')->user();
        if (! $user) {
            return false;
        }

        $roles = collect(array_merge([$user->role], is_array($user->secondary_roles) ? $user->secondary_roles : []))
            ->map(fn ($role) => $this->normalizeRole($role))
            ->filter()
            ->values()
            ->all();

        return ! empty(array_intersect($roles, [
            'admin',
            'superadmin',
            'editingmanager',
            'salesrep',
            'rep',
            'representative',
        ]));
    }

    private function isDelivered(Shoot $shoot): bool
    {
        return $shoot->status === Shoot::STATUS_DELIVERED
            || $shoot->workflow_status === Shoot::STATUS_DELIVERED;
    }

    private function normalizeRole(?string $role): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', (string) $role));
    }

    private function stripVideoTourLinks(array $tourLinks): array
    {
        foreach ([
            'video_link',
            'video_branded',
            'video_mls',
            'video_generic',
            'embeds',
            'featured_embed',
            'featured_embed_id',
        ] as $key) {
            unset($tourLinks[$key]);
        }

        return $tourLinks;
    }

    public function publicClientProfile(Request $request, $clientId)
    {
        $client = User::find($clientId);
        if (! $client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        return response()->json(
            $this->shootPublicAssetsService->buildPublicClientProfilePayload($client)
        );
    }

    public function generatePropertyDescription(Request $request, Shoot $shoot)
    {
        $user = auth()->user();
        $canGenerateDescription = $user && (
            in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)
            || ($user->role === 'client' && (string) $shoot->client_id === (string) $user->id)
        );
        if (! $canGenerateDescription) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $llmClient = app(\App\Services\ReproAi\LlmClient::class);
        } catch (\Exception $e) {
            return response()->json(['message' => 'AI service is not configured'], 503);
        }

        $tourLinks = is_array($shoot->tour_links) ? $shoot->tour_links : [];
        $propertyDetails = $this->shootPublicAssetsService->buildPublicTourPropertyDetails($shoot, $tourLinks);
        $imageUrls = $this->shootPublicAssetsService->resolvePropertyDescriptionImageUrls($shoot);
        $descriptionTier = $this->propertyDescriptionPolicy->tierFor($shoot);
        $characterLimit = $this->propertyDescriptionPolicy->maxCharactersFor($shoot);
        $listingType = ($propertyDetails['listing_type'] ?? $shoot->listing_type) === 'for_rent' ? 'rent' : 'sale';
        $bedrooms = $propertyDetails['bedrooms'] ?? $propertyDetails['beds'] ?? null;
        $bathrooms = $propertyDetails['bathrooms'] ?? $propertyDetails['baths'] ?? null;
        $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? $propertyDetails['square_feet'] ?? null;
        $price = $propertyDetails['price'] ?? null;
        $lotSize = $propertyDetails['lot_size'] ?? $propertyDetails['lotSize'] ?? null;
        $detailParts = [];
        foreach ([
            $bedrooms ? $bedrooms.' bedrooms' : null,
            $bathrooms ? $bathrooms.' bathrooms' : null,
            $sqft ? $sqft.' sqft' : null,
            $price ? 'list price '.$price : null,
            $lotSize ? 'lot size '.$lotSize : null,
        ] as $detailPart) {
            if ($detailPart) {
                $detailParts[] = $detailPart;
            }
        }

        $detailStr = ! empty($detailParts) ? ' Property has '.implode(', ', $detailParts).'.' : '';
        $visualContext = empty($imageUrls) ? 'No images are available, so use the property details only.' : 'Attached are images for the property.';
        $textPrompt = "The property is being listed for {$listingType}. {$visualContext}{$detailStr} Write a compelling description based only on the provided property details and visible image context. Do not include or infer the property address or any location details. Write 50 to 100 words and no more than {$characterLimit} characters including spaces. Finish with a complete sentence.";

        $contentParts = [['type' => 'text', 'text' => $textPrompt]];
        foreach ($imageUrls as $url) {
            $contentParts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $url, 'detail' => 'low'],
            ];
        }

        try {
            $response = $llmClient->chatCompletion([
                [
                    'role' => 'system',
                    'content' => "You are a real estate copywriter. Write concise, professional property descriptions. Never include or infer a property address, street name, house number, city, state, ZIP code, neighborhood, or other location details. Keep the description at or below {$characterLimit} characters including spaces. Output ONLY the description text with no quotes, labels, or extra formatting.",
                ],
                [
                    'role' => 'user',
                    'content' => $contentParts,
                ],
            ], [], false, [
                'model' => 'gpt-4o',
                'temperature' => 0.7,
                'max_tokens' => 300,
            ]);

            $description = trim($response['choices'][0]['message']['content'] ?? '');
            if ($description === '') {
                return response()->json(['message' => 'AI returned an empty description'], 500);
            }
            $description = $this->propertyDescriptionPolicy->enforceCharacterLimit($description, $characterLimit);

            return response()->json([
                'description' => $description,
                'images_used' => count($imageUrls),
                'description_tier' => $descriptionTier,
                'character_limit' => $characterLimit,
                'characters_used' => mb_strlen($description),
            ]);
        } catch (\Exception $e) {
            Log::error('AI property description generation failed', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate description: '.$e->getMessage(),
            ], 500);
        }
    }
}
