<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['ai_editing_jobs', 'ai_listing_video_jobs'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignUuid('project_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('projects')
                    ->nullOnDelete();
                $table->string('request_id', 64)
                    ->nullable()
                    ->after('project_id')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['project_id']);
                $table->dropIndex(['request_id']);
                $table->dropColumn(['project_id', 'request_id']);
            });
        }
    }
};
