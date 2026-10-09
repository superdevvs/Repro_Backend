<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $setting = Setting::where('key', 'permissions.role_map.v1')->first();
        if (! $setting) return;
        $value = json_decode($setting->value, true);
        if (! is_array($value)) return;
        $roles = $value['roles'] ?? $value;
        $admin = $roles['admin'] ?? [];
        // Upgrade existing integration administrators once. Subsequent role
        // choices and per-user denials remain authoritative.
        if (! is_array($admin) || ! in_array('integrations-view', $admin, true)
            || in_array('integrations-edit', $admin, true)) return;
        $roles['admin'] = [...$admin, 'integrations-edit'];
        $setting->update(['value' => json_encode(['version' => $value['version'] ?? 1, 'roles' => $roles])]);
    }

    public function down(): void
    {
        // Preserve subsequent administrator choices and user overrides.
    }
};
