<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Get a setting value
     */
    public function get(Request $request, string $key)
    {
        try {
            $setting = DB::table('settings')->where('key', $key)->first();
            
            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Setting not found',
                ], 404);
            }

            $value = $this->parseValue($setting->value, $setting->type);

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $setting->key,
                    'value' => $value,
                    'type' => $setting->type,
                    'description' => $setting->description,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching setting', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch setting',
            ], 500);
        }
    }

    /**
     * Store or update a setting
     */
    public function store(Request $request)
    {
        $request->validate([
            'key' => 'required|string|max:255',
            'value' => 'required',
            'type' => 'nullable|string|in:string,json,boolean,integer',
            'description' => 'nullable|string',
        ]);

        try {
            $type = $request->type ?? 'string';
            $value = $this->serializeValue($request->value, $type);

            DB::table('settings')->updateOrInsert(
                ['key' => $request->key],
                [
                    'value' => $value,
                    'type' => $type,
                    'description' => $request->description,
                    'updated_at' => now(),
                    'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Setting saved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error saving setting', [
                'key' => $request->key,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save setting',
            ], 500);
        }
    }

    /**
     * Parse value based on type
     */
    private function parseValue($value, $type)
    {
        switch ($type) {
            case 'json':
                return json_decode($value, true);
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'integer':
                return (int) $value;
            default:
                return $value;
        }
    }

    /**
     * Serialize value based on type
     */
    private function serializeValue($value, $type)
    {
        switch ($type) {
            case 'json':
                return json_encode($value);
            case 'boolean':
                return $value ? '1' : '0';
            case 'integer':
                return (string) $value;
            default:
                return (string) $value;
        }
    }
}


