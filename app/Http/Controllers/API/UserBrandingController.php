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
                    'branding' => $branding ? [
                        'logo' => $branding->logo,
                        'primary_color' => $branding->primary_color,
                        'secondary_color' => $branding->secondary_color,
                        'font_family' => $branding->font_family,
                        'custom_domain' => $branding->custom_domain,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user branding', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to fetch branding'], 500);
        }
    }

    /**
     * Update user branding settings
     * PUT /api/users/{user}/branding
     */
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'linked_clients' => 'nullable|array',
            'linked_clients.*' => 'exists:users,id',
            'branding' => 'nullable|array',
            'branding.logo' => 'nullable|string|url',
            'branding.primary_color' => 'nullable|string|max:7',
            'branding.secondary_color' => 'nullable|string|max:7',
            'branding.font_family' => 'nullable|string|max:255',
            'branding.custom_domain' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            DB::beginTransaction();

            // Update or create branding
            if ($request->has('branding')) {
                $brandingData = $request->branding;
                DB::table('user_branding')->updateOrInsert(
                    ['user_id' => $user->id],
                    [
                        'logo' => $brandingData['logo'] ?? null,
                        'primary_color' => $brandingData['primary_color'] ?? '#1a56db',
                        'secondary_color' => $brandingData['secondary_color'] ?? '#7e3af2',
                        'font_family' => $brandingData['font_family'] ?? 'Inter',
                        'custom_domain' => $brandingData['custom_domain'] ?? null,
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

            return response()->json([
                'message' => 'Branding updated successfully',
                'data' => [
                    'linked_clients' => $request->linked_clients ?? [],
                    'branding' => $request->branding ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating user branding', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to update branding'], 500);
        }
    }
}


