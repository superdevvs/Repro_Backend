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
        $setting = DB::table('settings')->where('key', 'permissions.role_map.v1')->first();
        if (! $setting) {
            return; // New installations inherit config defaults.
        }
        $payload = json_decode((string) $setting->value, true);
        if (! is_array($payload)) {
            return;
        }
        $wrapped = isset($payload['roles']) && is_array($payload['roles']);
        $roles = $wrapped ? $payload['roles'] : $payload;
        foreach (['photographer', 'editor'] as $role) {
            // An empty list is an explicit role opt-out. Keep it empty so a
            // later capability migration cannot mistake it for an active role.
            if (! empty($roles[$role]) && is_array($roles[$role])) {
                $roles[$role] = array_values(array_unique([...$roles[$role], 'robbie-view']));
            }
        }
        if ($wrapped) {
            $payload['roles'] = $roles;
        } else {
            $payload = $roles;
        }
        DB::table('settings')->where('id', $setting->id)->update(['value' => json_encode($payload)]);
    }

    public function down(): void
    {
        // Preserve explicit permission edits made after this one-time enablement.
    }
};
