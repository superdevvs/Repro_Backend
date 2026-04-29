<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootPublicAssetsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShootPublicAssetsController extends Controller
{
    public function __construct(protected ShootPublicAssetsService $shootPublicAssetsService)
    {
    }

    public function publicBranded(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return response()->json($this->buildVisiblePublicAssets($request, $shoot, 'branded'));
    }

    public function publicMls(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return response()->json($this->buildVisiblePublicAssets($request, $shoot, 'mls'));
    }

    public function publicGenericMls(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return response()->json($this->buildVisiblePublicAssets($request, $shoot, 'generic-mls'));
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
        if (!$user) {
            return false;
        }

        $roles = collect(array_merge([$user->role], is_array($user->secondary_roles) ? $user->secondary_roles : []))
            ->map(fn ($role) => $this->normalizeRole($role))
            ->filter()
            ->values()
            ->all();

        return !empty(array_intersect($roles, [
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
        if (!$client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        return response()->json(
            $this->shootPublicAssetsService->buildPublicClientProfilePayload($client)
        );
    }

    public function generatePropertyDescription(Request $request, Shoot $shoot)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $llmClient = app(\App\Services\ReproAi\LlmClient::class);
        } catch (\Exception $e) {
            return response()->json(['message' => 'AI service is not configured'], 503);
        }

        $imageUrls = $this->shootPublicAssetsService->resolvePropertyDescriptionImageUrls($shoot);
        if (empty($imageUrls)) {
            return response()->json([
                'message' => 'No edited images available to generate description',
            ], 422);
        }

        $address = trim(($shoot->address ?? '') . ', ' . ($shoot->city ?? '') . ', ' . ($shoot->state ?? '') . ' ' . ($shoot->zip ?? ''));
        $listingType = $shoot->listing_type === 'for_rent' ? 'rent' : 'sale';
        $propertyDetails = $shoot->property_details ?? [];
        $bedrooms = $propertyDetails['bedrooms'] ?? $propertyDetails['beds'] ?? null;
        $bathrooms = $propertyDetails['bathrooms'] ?? $propertyDetails['baths'] ?? null;
        $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? null;
        $detailParts = [];
        foreach ([
            $bedrooms ? $bedrooms . ' bedrooms' : null,
            $bathrooms ? $bathrooms . ' bathrooms' : null,
            $sqft ? $sqft . ' sqft' : null,
        ] as $detailPart) {
            if ($detailPart) {
                $detailParts[] = $detailPart;
            }
        }

        $detailStr = !empty($detailParts) ? ' Property has ' . implode(', ', $detailParts) . '.' : '';
        $textPrompt = "\"{$address}\" is being placed for \"{$listingType}\". Attached are images for the property.{$detailStr} Write a compelling description for the property based on where it's located and what you see in the images. In max 50-100 words.";

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
                    'content' => 'You are a real estate copywriter. Write concise, professional property descriptions. Output ONLY the description text with no quotes, labels, or extra formatting.',
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

            return response()->json([
                'description' => $description,
                'images_used' => count($imageUrls),
            ]);
        } catch (\Exception $e) {
            Log::error('AI property description generation failed', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate description: ' . $e->getMessage(),
            ], 500);
        }
    }
}
