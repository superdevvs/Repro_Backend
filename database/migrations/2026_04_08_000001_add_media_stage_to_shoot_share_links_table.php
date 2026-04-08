<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_share_links', function (Blueprint $table) {
            $table->string('media_stage', 32)->default('raw')->after('share_url');
            $table->index(['shoot_id', 'media_stage', 'is_revoked'], 'ssl_shoot_stage_revoked_idx');
        });

        DB::table('shoot_share_links')
            ->whereNull('media_stage')
            ->update(['media_stage' => 'raw']);
    }

    public function down(): void
    {
        Schema::table('shoot_share_links', function (Blueprint $table) {
            $table->dropIndex('ssl_shoot_stage_revoked_idx');
            $table->dropColumn('media_stage');
        });
    }
};
