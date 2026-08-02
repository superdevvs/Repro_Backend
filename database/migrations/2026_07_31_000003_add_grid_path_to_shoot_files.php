<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the path to the intermediate "grid" image rendition.
 *
 * Meeting 26 Jul 2026 [00:23:08] and the A1.docx annotation: desktop grid tiles
 * looked blurred because they were fed the 300px thumbnail and upscaled. A
 * ~1000px derivative fixes that without pushing the 1500px `web` file to phones.
 *
 * Nullable, so existing files simply have no grid rendition until they are
 * reprocessed (`php artisan images:process-existing`), and the URL resolver
 * falls back to the web/thumbnail paths meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shoot_files')) {
            return;
        }

        Schema::table('shoot_files', function (Blueprint $table) {
            if (! Schema::hasColumn('shoot_files', 'grid_path')) {
                $table->string('grid_path')->nullable()->after('thumbnail_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shoot_files')) {
            return;
        }

        Schema::table('shoot_files', function (Blueprint $table) {
            if (Schema::hasColumn('shoot_files', 'grid_path')) {
                $table->dropColumn('grid_path');
            }
        });
    }
};
