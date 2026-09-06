<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserBrandingController extends Controller
{
    protected const SELF_EDIT_DISABLED_ROLES = [
        'photographer',
        'editor',
        'editing_manager',
    ];

    /**
     * Get user branding settings
     * GET /api/users/{user}/branding
     */
    public function show(User $user)
    {
        try {
            $branding = DB::table('user_branding')
                ->where('user_id', $user->id)
                ->first();

            $linkedClients = DB::table('user_branding_clients')
                ->where('user_id', $user->id)
                ->pluck('client_id')
                ->map(fn($id) => (string) $id)
                ->toArray();

            return response()->json([
                'data' => [
                    'linked_clients' => $linkedClients,
                    'branding' => $this->formatBrandingPayload($branding),
                ],
            ]);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');
            return response()->json(['error' => 'Failed to fetch branding'], 500);
        }
    }

    /**
     * Update user branding settings
     * PUT /api/users/{user}/branding
     */
    public function update(Request $request, User $user)
    {
        if (!$this->canUpdateBranding($request->user(), $user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'linked_clients' => 'nullable|array',
            'linked_clients.*' => 'exists:users,id',
            'branding' => 'nullable|array',
            'branding.logo' => 'nullable|string',
            'branding.banner' => 'nullable|string',
            'branding.primary_color' => 'nullable|string|max:7',
            'branding.secondary_color' => 'nullable|string|max:7',
            'branding.font_family' => 'nullable|string|max:255',
            'branding.custom_domain' => 'nullable|string|max:255',
            'branding.about' => 'nullable|string',
            'branding.hero_headline' => 'nullable|string|max:255',
            'branding.hero_subtitle' => 'nullable|string|max:500',
            'branding.hero_image' => 'nullable|string|max:255',
            'branding.facebook_url' => 'nullable|string|max:255',
            'branding.linkedin_url' => 'nullable|string|max:255',
            'branding.instagram_url' => 'nullable|string|max:255',
            'branding.show_map' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            DB::beginTransaction();

            // Update or create branding
            if ($request->has('branding')) {
                $brandingData = $request->input('branding', []);
                $existingBranding = DB::table('user_branding')
                    ->where('user_id', $user->id)
                    ->first();

                DB::table('user_branding')->updateOrInsert(
                    ['user_id' => $user->id],
                    [
                        'logo' => $this->resolveBrandingValue($brandingData, 'logo', $existingBranding?->logo ?? null),
                        'banner' => $this->resolveBrandingValue($brandingData, 'banner', $existingBranding?->banner ?? null),
                        'primary_color' => $this->resolveBrandingValue($brandingData, 'primary_color', $existingBranding?->primary_color ?? '#1a56db'),
                        'secondary_color' => $this->resolveBrandingValue($brandingData, 'secondary_color', $existingBranding?->secondary_color ?? '#7e3af2'),
                        'font_family' => $this->resolveBrandingValue($brandingData, 'font_family', $existingBranding?->font_family ?? 'Inter'),
                        'custom_domain' => $this->resolveBrandingValue($brandingData, 'custom_domain', $existingBranding?->custom_domain ?? null),
                        'about' => $this->resolveBrandingValue($brandingData, 'about', $existingBranding?->about ?? null),
                        'hero_headline' => $this->resolveBrandingValue($brandingData, 'hero_headline', $existingBranding?->hero_headline ?? null),
                        'hero_subtitle' => $this->resolveBrandingValue($brandingData, 'hero_subtitle', $existingBranding?->hero_subtitle ?? null),
                        'hero_image' => $this->resolveBrandingValue($brandingData, 'hero_image', $existingBranding?->hero_image ?? null),
                        'facebook_url' => $this->resolveBrandingValue($brandingData, 'facebook_url', $existingBranding?->facebook_url ?? null),
                        'linkedin_url' => $this->resolveBrandingValue($brandingData, 'linkedin_url', $existingBranding?->linkedin_url ?? null),
                        'instagram_url' => $this->resolveBrandingValue($brandingData, 'instagram_url', $existingBranding?->instagram_url ?? null),
                        'show_map' => (bool) $this->resolveBrandingValue($brandingData, 'show_map', $existingBranding?->show_map ?? false),
                        'created_at' => $existingBranding?->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // Update linked clients
            if ($request->has('linked_clients')) {
                // Remove existing links
                DB::table('user_branding_clients')
                    ->where('user_id', $user->id)
                    ->delete();

                // Add new links
                foreach ($request->linked_clients as $clientId) {
                    DB::table('user_branding_clients')->insert([
                        'user_id' => $user->id,
                        'client_id' => $clientId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            $branding = DB::table('user_branding')
                ->where('user_id', $user->id)
                ->first();

            return response()->json([
                'message' => 'Branding updated successfully',
                'data' => [
                    'linked_clients' => $request->linked_clients ?? [],
                    'branding' => $this->formatBrandingPayload($branding),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \App\Services\ApiErrorResponder::log($e, 'error');
            return response()->json(['error' => 'Failed to update branding'], 500);
        }
    }

    protected function canUpdateBranding(?User $actor, User $target): bool
    {
        if (!$actor) {
            return false;
        }

        if ((string) $actor->id === (string) $target->id) {
            return !in_array($actor->role, self::SELF_EDIT_DISABLED_ROLES, true);
        }

        return in_array($actor->role, ['admin', 'superadmin', 'editing_manager'], true);
    }

    protected function resolveBrandingValue(array $brandingData, string $key, mixed $fallback): mixed
    {
        return array_key_exists($key, $brandingData) ? $brandingData[$key] : $fallback;
    }

    protected function formatBrandingPayload(?object $branding): ?array
    {
        if (!$branding) {
            return null;
        }

        return [
            'logo' => $branding->logo,
            'banner' => $branding->banner ?? null,
            'primary_color' => $branding->primary_color,
            'secondary_color' => $branding->secondary_color,
            'font_family' => $branding->font_family,
            'custom_domain' => $branding->custom_domain,
            'about' => $branding->about ?? null,
            'hero_headline' => $branding->hero_headline ?? null,
            'hero_subtitle' => $branding->hero_subtitle ?? null,
            'hero_image' => $branding->hero_image ?? null,
            'facebook_url' => $branding->facebook_url,
            'linkedin_url' => $branding->linkedin_url,
            'instagram_url' => $branding->instagram_url,
            'show_map' => (bool) ($branding->show_map ?? false),
        ];
    }
}


