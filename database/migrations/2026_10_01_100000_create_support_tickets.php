<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 80);
            $table->string('subject', 180);
            $table->string('category', 30);
            $table->string('status', 30)->default('open');
            $table->string('priority', 20)->default('normal');
            $table->string('page_path', 240)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['requester_id', 'request_key']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 80);
            $table->text('body');
            $table->string('kind', 20)->default('reply');
            $table->boolean('internal')->default(false);
            $table->timestamps();
            $table->unique(['support_ticket_id', 'author_id', 'request_key'], 'support_message_idempotency');
            $table->index(['support_ticket_id', 'id']);
        });
        // Existing permission maps receive only the new support capabilities once.
        $setting = DB::table('settings')->where('key', 'permissions.role_map.v1')->first();
        if (! $setting) {
            return;
        }
        $payload = json_decode((string) $setting->value, true);
        if (! is_array($payload)) {
            return;
        }
        $wrapped = isset($payload['roles']) && is_array($payload['roles']);
        $roles = $wrapped ? $payload['roles'] : $payload;
        // Frozen pre-support default. A customized admin role must not silently
        // gain access to every customer's private support conversations.
        $legacyAdminDefault = [
            'account-linking-update', 'account-linking-view', 'accounting-view', 'accounts-view', 'ai-editing-view',
            'availability-view', 'book-shoot-create', 'clients-create', 'clients-update', 'clients-view',
            'company-notes-view', 'coupons-create', 'coupons-view', 'cubicasa-scanning-view', 'dashboard-admin-view',
            'dashboard-availability-view', 'dashboard-editing-requests-view', 'dashboard-production-view',
            'dashboard-quick-actions-update', 'dashboard-view', 'edited-media-view', 'editing-notes-update',
            'editing-notes-view', 'integrations-view', 'invoices-create', 'invoices-update', 'invoices-view',
            'media-download', 'media-upload', 'media-view', 'messaging-automations-update', 'messaging-automations-view',
            'messaging-compose-create', 'messaging-email-view', 'messaging-overview-view', 'messaging-settings-view',
            'messaging-sms-view', 'messaging-templates-update', 'messaging-templates-view', 'notes-view',
            'permissions-manager-update', 'permissions-manager-view', 'photographer-notes-update',
            'photographer-notes-view', 'photographers-view', 'portal-view', 'profile-view', 'raw-media-view',
            'reports-view', 'requests-view', 'robbie-view', 'scheduling-settings-view', 'settings-update',
            'settings-view', 'shoots-view', 'tour-branding-view', 'tours-update', 'tours-view', 'voice-calls-manage',
            'voice-calls-operate', 'voice-calls-supervise', 'voice-calls-view',
        ];
        sort($legacyAdminDefault);
        foreach (['superadmin', 'admin', 'client', 'salesRep', 'photographer', 'editor', 'editing_manager'] as $role) {
            if (! empty($roles[$role]) && is_array($roles[$role])) {
                $selected = array_values(array_unique(array_diff($roles[$role], ['support-view', 'support-manage'])));
                sort($selected);
                $new = ['support-view'];
                if ($role === 'superadmin' || ($role === 'admin' && $selected === $legacyAdminDefault)) {
                    $new[] = 'support-manage';
                }
                $roles[$role] = array_values(array_unique([...$roles[$role], ...$new]));
            }
        }
        $payload = $wrapped ? [...$payload, 'roles' => $roles] : $roles;
        DB::table('settings')->where('id', $setting->id)->update(['value' => json_encode($payload)]);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        // Preserve later operator permission edits.
    }
};
