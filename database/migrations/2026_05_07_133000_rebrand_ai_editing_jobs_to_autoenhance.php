<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_editing_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_editing_jobs', 'provider')) {
                $table->string('provider')->default('autoenhance')->after('user_id');
            }
            if (!Schema::hasColumn('ai_editing_jobs', 'provider_job_id')) {
                $table->string('provider_job_id')->nullable()->after('provider');
            }
            if (!Schema::hasColumn('ai_editing_jobs', 'provider_order_id')) {
                $table->string('provider_order_id')->nullable()->after('provider_job_id');
            }
            if (!Schema::hasColumn('ai_editing_jobs', 'autoenhance_image_id')) {
                $table->string('autoenhance_image_id')->nullable()->after('provider_order_id');
            }
            if (!Schema::hasColumn('ai_editing_jobs', 'provider_payload')) {
                $table->json('provider_payload')->nullable()->after('autoenhance_image_id');
            }
            if (!Schema::hasColumn('ai_editing_jobs', 'provider_result')) {
                $table->json('provider_result')->nullable()->after('provider_payload');
            }
        });

        if (Schema::hasColumn('ai_editing_jobs', 'fotello_job_id')) {
            DB::table('ai_editing_jobs')
                ->whereNull('provider_job_id')
                ->whereNotNull('fotello_job_id')
                ->update([
                    'provider' => 'fotello',
                    'provider_job_id' => DB::raw('fotello_job_id'),
                ]);
        }

        Schema::table('ai_editing_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('ai_editing_jobs', 'provider_job_id')) {
                $table->index('provider_job_id');
            }
            if (Schema::hasColumn('ai_editing_jobs', 'autoenhance_image_id')) {
                $table->index('autoenhance_image_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_editing_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('ai_editing_jobs', 'autoenhance_image_id')) {
                $table->dropIndex(['autoenhance_image_id']);
            }
            if (Schema::hasColumn('ai_editing_jobs', 'provider_job_id')) {
                $table->dropIndex(['provider_job_id']);
            }
            $dropColumns = array_filter([
                Schema::hasColumn('ai_editing_jobs', 'provider_result') ? 'provider_result' : null,
                Schema::hasColumn('ai_editing_jobs', 'provider_payload') ? 'provider_payload' : null,
                Schema::hasColumn('ai_editing_jobs', 'autoenhance_image_id') ? 'autoenhance_image_id' : null,
                Schema::hasColumn('ai_editing_jobs', 'provider_order_id') ? 'provider_order_id' : null,
                Schema::hasColumn('ai_editing_jobs', 'provider_job_id') ? 'provider_job_id' : null,
                Schema::hasColumn('ai_editing_jobs', 'provider') ? 'provider' : null,
            ]);
            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
