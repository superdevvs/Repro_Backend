<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            // Add album_id if not exists
            if (!Schema::hasColumn('shoot_files', 'album_id')) {
                $table->foreignId('album_id')->nullable()->after('shoot_id')
                    ->constrained('shoot_media_albums')->nullOnDelete();
            }

            // Add type enum if not exists
            if (!Schema::hasColumn('shoot_files', 'media_type')) {
                $table->enum('media_type', ['raw', 'edited', 'video', 'iguide', 'extra'])->default('raw')->after('album_id');
            }

            // Rename path to storage_path if needed, or add storage_path
            if (Schema::hasColumn('shoot_files', 'path') && !Schema::hasColumn('shoot_files', 'storage_path')) {
                $table->string('storage_path')->nullable()->after('path');
            }

            // Add watermarked_storage_path
            if (!Schema::hasColumn('shoot_files', 'watermarked_storage_path')) {
                $table->string('watermarked_storage_path')->nullable()->after('storage_path');
            }

            // Add mime_type if not exists (file_type might exist)
            if (!Schema::hasColumn('shoot_files', 'mime_type') && Schema::hasColumn('shoot_files', 'file_type')) {
                $table->string('mime_type')->nullable()->after('file_type');
            } elseif (!Schema::hasColumn('shoot_files', 'mime_type')) {
                $table->string('mime_type')->nullable()->after('stored_filename');
            }

            // Ensure uploaded_at exists
            if (!Schema::hasColumn('shoot_files', 'uploaded_at')) {
                $table->timestamp('uploaded_at')->nullable()->after('uploaded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (Schema::hasColumn('shoot_files', 'album_id')) {
                $table->dropForeign(['album_id']);
                $table->dropColumn('album_id');
            }
            if (Schema::hasColumn('shoot_files', 'media_type')) {
                $table->dropColumn('media_type');
            }
            if (Schema::hasColumn('shoot_files', 'storage_path')) {
                $table->dropColumn('storage_path');
            }
            if (Schema::hasColumn('shoot_files', 'watermarked_storage_path')) {
                $table->dropColumn('watermarked_storage_path');
            }
            if (Schema::hasColumn('shoot_files', 'mime_type')) {
                $table->dropColumn('mime_type');
            }
            if (Schema::hasColumn('shoot_files', 'uploaded_at')) {
                $table->dropColumn('uploaded_at');
            }
        });
    }
};

