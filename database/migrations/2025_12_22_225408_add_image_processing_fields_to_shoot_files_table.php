<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('watermarked_storage_path');
            $table->string('web_path')->nullable()->after('thumbnail_path');
            $table->string('placeholder_path')->nullable()->after('web_path');
            $table->timestamp('processed_at')->nullable()->after('ai_editing_metadata');
            $table->timestamp('processing_failed_at')->nullable()->after('processed_at');
            $table->text('processing_error')->nullable()->after('processing_failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->dropColumn([
                'thumbnail_path',
                'web_path',
                'placeholder_path',
                'processed_at',
                'processing_failed_at',
                'processing_error'
            ]);
        });
    }
};
