<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an opt-in override mechanism so admins can supply a DB-backed
 * MessageTemplate for protected, code/Blade-rendered automated emails
 * (e.g. ACCOUNT_CREATED). Disabled by default so existing behavior is
 * unchanged until an admin explicitly enables an override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('message_templates', 'email_type')) {
                // Protected email alias this template overrides (e.g. ACCOUNT_CREATED).
                $table->string('email_type')->nullable()->after('category')->index();
            }
            if (!Schema::hasColumn('message_templates', 'override_enabled')) {
                $table->boolean('override_enabled')->default(false)->after('is_system');
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            if (Schema::hasColumn('message_templates', 'override_enabled')) {
                $table->dropColumn('override_enabled');
            }
            if (Schema::hasColumn('message_templates', 'email_type')) {
                $table->dropColumn('email_type');
            }
        });
    }
};
