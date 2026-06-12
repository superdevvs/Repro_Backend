<?php

namespace App\Services;

use App\Http\Controllers\API\SettingsController;
use Illuminate\Support\Facades\DB;

class ReproApiSettingsService
{
    public function settings(): array
    {
        $row = DB::table('settings')->where('key', 'integrations.repro_api')->first();
        if (!$row || $row->type !== 'json') {
            return [];
        }

        $value = json_decode((string) $row->value, true);
        if (!is_array($value)) {
            return [];
        }

        return SettingsController::decryptSensitiveFields($value);
    }

    public function featuredShootApiKey(): ?string
    {
        $settings = $this->settings();
        $value = $settings['featuredShootApiKey'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function externalBookingApiKey(): ?string
    {
        $settings = $this->settings();
        $value = $settings['externalBookingApiKey'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
