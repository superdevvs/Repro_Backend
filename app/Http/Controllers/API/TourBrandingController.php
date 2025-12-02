<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TourBrandingController extends Controller
{
    /**
     * List all tour branding information
     */
    public function index()
    {
        try {
            $brandings = DB::table('tour_branding')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($branding) {
                    return [
                        'id' => (string) $branding->id,
                        'company_name' => $branding->company_name,
                        'logo' => $branding->logo,
                        'primary_color' => $branding->primary_color,
                        'secondary_color' => $branding->secondary_color,
                        'font_family' => $branding->font_family,
                        'custom_domain' => $branding->custom_domain,
                        'created_at' => $branding->created_at,
                        'updated_at' => $branding->updated_at,
                    ];
                });

            return response()->json(['data' => $brandings]);
        } catch (\Exception $e) {
            Log::error('Error fetching tour brandings', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to fetch brandings'], 500);
        }
    }

    /**
     * Create new tour branding
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'logo' => 'nullable|string|url',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'font_family' => 'nullable|string|max:255',
            'custom_domain' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            $id = DB::table('tour_branding')->insertGetId([
                'company_name' => $request->company_name,
                'logo' => $request->logo,
                'primary_color' => $request->primary_color ?? '#1a56db',
                'secondary_color' => $request->secondary_color ?? '#7e3af2',
                'font_family' => $request->font_family ?? 'Inter',
                'custom_domain' => $request->custom_domain,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $branding = DB::table('tour_branding')->where('id', $id)->first();

            return response()->json([
                'message' => 'Branding created successfully',
                'data' => [
                    'id' => (string) $id,
                    'company_name' => $branding->company_name,
                    'logo' => $branding->logo,
                    'primary_color' => $branding->primary_color,
                    'secondary_color' => $branding->secondary_color,
                    'font_family' => $branding->font_family,
                    'custom_domain' => $branding->custom_domain,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating tour branding', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to create branding'], 500);
        }
    }

    /**
     * Update tour branding
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'logo' => 'nullable|string|url',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'font_family' => 'nullable|string|max:255',
            'custom_domain' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            $updated = DB::table('tour_branding')
                ->where('id', $id)
                ->update([
                    'company_name' => $request->company_name,
                    'logo' => $request->logo,
                    'primary_color' => $request->primary_color,
                    'secondary_color' => $request->secondary_color,
                    'font_family' => $request->font_family,
                    'custom_domain' => $request->custom_domain,
                    'updated_at' => now(),
                ]);

            if (!$updated) {
                return response()->json(['error' => 'Branding not found'], 404);
            }

            $branding = DB::table('tour_branding')->where('id', $id)->first();

            return response()->json([
                'message' => 'Branding updated successfully',
                'data' => [
                    'id' => (string) $id,
                    'company_name' => $branding->company_name,
                    'logo' => $branding->logo,
                    'primary_color' => $branding->primary_color,
                    'secondary_color' => $branding->secondary_color,
                    'font_family' => $branding->font_family,
                    'custom_domain' => $branding->custom_domain,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating tour branding', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to update branding'], 500);
        }
    }

    /**
     * Delete tour branding
     */
    public function destroy($id)
    {
        try {
            $deleted = DB::table('tour_branding')->where('id', $id)->delete();

            if (!$deleted) {
                return response()->json(['error' => 'Branding not found'], 404);
            }

            return response()->json(['message' => 'Branding deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error deleting tour branding', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to delete branding'], 500);
        }
    }
}


