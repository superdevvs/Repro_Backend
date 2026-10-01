<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        $setting = DB::table('settings')->where('key', 'messaging.telnyx_voice')->first();
        if (! $setting) {
            return;
        }
        $payload = json_decode((string) $setting->value, true);
        if (! is_array($payload) || ! isset($payload['tool_allowlist']) || ! is_array($payload['tool_allowlist'])) {
            return; // Default registry includes the read-only tool.
        }
        // Add the new tool only to known legacy defaults. Empty or customized
        // allowlists are explicit operator choices and must remain restricted.
        $selected = array_values(array_unique($payload['tool_allowlist']));
        $legacyDefault = [
            'verify_caller', 'get_shoot_details', 'list_shoots', 'get_payment_status',
            'get_availability', 'book_shoot', 'reschedule_shoot', 'cancel_shoot',
            'create_payment_link', 'handoff_to_staff', 'transfer_to_staff', 'set_recording_consent',
        ];
        $preConsentDefault = array_values(array_diff($legacyDefault, ['set_recording_consent']));
        sort($selected);
        sort($legacyDefault);
        sort($preConsentDefault);
        if ($selected !== $legacyDefault && $selected !== $preConsentDefault) {
            return;
        }
        $payload['tool_allowlist'] = array_values(array_unique([...$payload['tool_allowlist'], 'search_support_knowledge']));
        DB::table('settings')->where('id', $setting->id)->update(['value' => json_encode($payload)]);
    }

    public function down(): void
    {
        // Do not silently remove a capability that operators may have configured.
    }
};
