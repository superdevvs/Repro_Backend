<?php

use App\Models\Setting;
use App\Services\Messaging\EmailNotificationPermissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::where('key', 'permissions.role_map.v1')->first();
        if (! $setting) {
            return;
        }
        $value = json_decode($setting->value, true);
        if (! is_array($value)) {
            return;
        }
        $roles = $value['roles'] ?? $value;
        foreach ($roles as $role => &$ids) {
            if (! is_array($ids)) {
                continue;
            }
            foreach (array_keys(EmailNotificationPermissions::CATEGORIES) as $category) {
                $ids[] = 'email-notifications-'.$category;
            }
            if ($role === 'admin') {
                $ids[] = 'payments-mark-paid';
            }
            $ids = array_values(array_unique($ids));
        }
        unset($ids);
        $setting->update(['value' => json_encode(['version' => 1, 'roles' => $roles])]);
    }

    public function down(): void
    {
        // Preserve subsequent role choices and individual overrides.
    }
};
