<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_share_links', function (Blueprint $table) {
            $table->string('public_token', 128)->nullable()->after('media_stage');
            $table->timestamp('last_accessed_at')->nullable()->after('download_count');
            $table->unique('public_token', 'shoot_share_links_public_token_unique');
        });

        DB::table('shoot_share_links')
            ->orderBy('id')
            ->get()
            ->each(function ($link): void {
                DB::table('shoot_share_links')
                    ->where('id', $link->id)
                    ->update([
                        'public_token' => Str::random(64),
                        'expires_at' => $link->expires_at ?? now()->addDays(7),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('shoot_share_links', function (Blueprint $table) {
            $table->dropUnique('shoot_share_links_public_token_unique');
            $table->dropColumn(['public_token', 'last_accessed_at']);
        });
    }
};
