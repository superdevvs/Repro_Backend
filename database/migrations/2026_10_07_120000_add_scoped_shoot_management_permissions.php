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
        foreach (['admin', 'editing_manager', 'salesRep'] as $role) {
            $ids = $roles[$role] ?? [];
            $ids = array_merge($ids, ['shoots-update', 'shoots-manage']);
            $ids[] = 'shoot-pricing-update';
            if ($role === 'salesRep') $ids = array_merge($ids, ['requests-view', 'notes-view', 'company-notes-view', 'editing-notes-view', 'editing-notes-update', 'photographer-notes-view', 'photographer-notes-update']);
            $roles[$role] = array_values(array_unique($ids));
        }
        $setting->update(['value' => json_encode(['version' => 1, 'roles' => $roles])]);
    }

    public function down(): void
    {
        // Do not erase subsequent role choices or individual permission overrides.
    }
};
