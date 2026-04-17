<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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

            // Decrypt sensitive fields in JSON integration settings after loading
            if ($setting->type === 'json' && is_array($value) && str_starts_with($setting->key, 'integrations.')) {
                $value = self::decryptSensitiveFields($value);
            }

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
            // Settings may intentionally store empty strings/arrays/objects, so
            // only require that the field is present in the request payload.
            'value' => 'present',
            'type' => 'nullable|string|in:string,json,boolean,integer',
            'description' => 'nullable|string',
        ]);

        try {
            $type = $request->type ?? 'string';

            // Encrypt sensitive fields in JSON integration settings before storing
            $rawValue = $request->value;
            if ($type === 'json' && is_array($rawValue) && str_starts_with($request->key, 'integrations.')) {
                $rawValue = self::encryptSensitiveFields($rawValue);
            }

            $value = $this->serializeValue($rawValue, $type);

            // Check if record exists to decide whether to set created_at
            $exists = DB::table('settings')->where('key', $request->key)->exists();

            $data = [
                'value' => $value,
                'type' => $type,
                'description' => $request->description,
                'updated_at' => now(),
            ];

            if (!$exists) {
                $data['created_at'] = now();
            }

            DB::table('settings')->updateOrInsert(
                ['key' => $request->key],
                $data
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

    // Fields that contain sensitive credentials and should be encrypted at rest
    private const SENSITIVE_JSON_FIELDS = ['apiKey', 'apiUser', 'api_key', 'api_secret', 'access_token', 'secret_key'];

    /**
     * Encrypt sensitive fields within a JSON settings value before storing.
     */
    public static function encryptSensitiveFields(array $data): array
    {
        foreach (self::SENSITIVE_JSON_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = 'enc:' . Crypt::encryptString($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Decrypt sensitive fields within a JSON settings value after loading.
     */
    public static function decryptSensitiveFields(array $data): array
    {
        foreach (self::SENSITIVE_JSON_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && str_starts_with($data[$field], 'enc:')) {
                try {
                    $data[$field] = Crypt::decryptString(substr($data[$field], 4));
                } catch (\Exception $e) {
                    Log::warning('Failed to decrypt setting field', ['field' => $field]);
                    $data[$field] = ''; // Clear corrupted value
                }
            }
        }
        return $data;
    }
}


