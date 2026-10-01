<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('source_message_id')->nullable()->unique();
            $table->json('attachments_json')->nullable();
        });
        // Upgrade only the known untouched pre-change default. Customized maps,
        // empty roles and all per-user denials remain authoritative.
        $setting = DB::table('settings')->where('key', 'permissions.role_map.v1')->first();
        $payload = $setting ? json_decode((string) $setting->value, true) : null;
        if (! is_array($payload)) {
            return;
        }
        $wrapped = isset($payload['roles']) && is_array($payload['roles']);
        $roles = $wrapped ? $payload['roles'] : $payload;
        $legacy = [
            'account-linking-update',
            'account-linking-view',
            'accounting-view',
            'accounts-view',
            'ai-editing-view',
            'availability-view',
            'clients-view',
            'company-notes-view',
            'coupons-create',
            'coupons-view',
            'cubicasa-scanning-view',
            'dashboard-admin-view',
            'dashboard-availability-view',
            'dashboard-editing-requests-view',
            'dashboard-production-view',
            'dashboard-view',
            'edited-media-view',
            'editing-notes-update',
            'editing-notes-view',
            'integrations-view',
            'invoices-create',
            'invoices-update',
            'invoices-view',
            'media-download',
            'media-upload',
            'media-view',
            'messaging-compose-create',
            'messaging-email-view',
            'messaging-sms-view',
            'notes-view',
            'photographer-notes-update',
            'photographer-notes-view',
            'photographers-view',
            'portal-view',
            'profile-view',
            'raw-media-view',
            'reports-view',
            'requests-view',
            'robbie-view',
            'scheduling-settings-view',
            'settings-update',
            'settings-view',
            'shoots-view',
            'support-view',
            'tour-branding-view',
            'tours-update',
            'tours-view',
        ];
        $selected = $roles['editing_manager'] ?? null;
        if (! is_array($selected)) {
            return;
        }
        $selected = array_values(array_unique($selected));
        sort($selected);
        if ($selected !== $legacy) {
            return;
        }
        $roles['editing_manager'][] = 'support-manage';
        $payload = $wrapped ? [...$payload, 'roles' => $roles] : $roles;
        DB::table('settings')->where('id', $setting->id)->update(['value' => json_encode($payload)]);
    }

    public function down(): void
    {
        // Do not delete imported evidence or revert operator permissions.
    }
};
