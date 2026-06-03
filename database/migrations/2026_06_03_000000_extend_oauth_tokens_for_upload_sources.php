<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('oauth_tokens', 'provider')) {
                try {
                    $table->dropUnique('oauth_tokens_provider_unique');
                } catch (Throwable $e) {
                    // Older/dev databases may not have the original unique index.
                }
            }

            if (!Schema::hasColumn('oauth_tokens', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('provider')->constrained()->nullOnDelete();
            }
            if (!Schema::hasColumn('oauth_tokens', 'account_type')) {
                $table->string('account_type', 20)->default('shared')->after('user_id');
            }
            if (!Schema::hasColumn('oauth_tokens', 'scopes')) {
                $table->text('scopes')->nullable()->after('expires_at');
            }
            if (!Schema::hasColumn('oauth_tokens', 'provider_account_id')) {
                $table->string('provider_account_id')->nullable()->after('scopes');
            }
            if (!Schema::hasColumn('oauth_tokens', 'provider_account_email')) {
                $table->string('provider_account_email')->nullable()->after('provider_account_id');
            }
            if (!Schema::hasColumn('oauth_tokens', 'provider_account_name')) {
                $table->string('provider_account_name')->nullable()->after('provider_account_email');
            }
            if (!Schema::hasColumn('oauth_tokens', 'metadata')) {
                $table->json('metadata')->nullable()->after('provider_account_name');
            }
        });

        DB::table('oauth_tokens')
            ->whereNull('account_type')
            ->update(['account_type' => 'shared']);
    }

    public function down(): void
    {
        Schema::table('oauth_tokens', function (Blueprint $table) {
            foreach ([
                'metadata',
                'provider_account_name',
                'provider_account_email',
                'provider_account_id',
                'scopes',
                'account_type',
                'user_id',
            ] as $column) {
                if (Schema::hasColumn('oauth_tokens', $column)) {
                    $table->dropColumn($column);
                }
            }

            try {
                $table->unique('provider');
            } catch (Throwable $e) {
                //
            }
        });
    }
};
