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

        return response()->json($this->shootPublicAssetsService->buildTypedPublicAssets($shoot, 'branded'));
    }

    public function publicMls(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return response()->json($this->shootPublicAssetsService->buildTypedPublicAssets($shoot, 'mls'));
    }

    public function publicGenericMls(Request $request, $shootId = null)
    {
        $shoot = $this->shootPublicAssetsService->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }

        return response()->json($this->shootPublicAssetsService->buildTypedPublicAssets($shoot, 'generic-mls'));
    }

    public function publicClientProfile(Request $request, $clientId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        $client = User::findOrFail($clientId);
        $payload = $this->shootPublicAssetsService->buildClientProfilePayload($user, $client);
        if (!$payload) {
            return response()->json(['message' => 'You do not have permission to view this client profile'], 403);
        }

        return response()->json($payload);
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
