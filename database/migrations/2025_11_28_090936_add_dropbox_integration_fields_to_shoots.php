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
        Schema::table('shoots', function (Blueprint $table) {
            $table->string('property_slug')->nullable()->index()->after('zip');
            $table->text('dropbox_raw_folder')->nullable()->after('property_slug');
            $table->text('dropbox_extra_folder')->nullable()->after('dropbox_raw_folder');
            $table->text('dropbox_edited_folder')->nullable()->after('dropbox_extra_folder');
            $table->text('dropbox_archive_folder')->nullable()->after('dropbox_edited_folder');
            $table->integer('extra_photo_count')->default(0)->after('edited_photo_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn([
                'property_slug',
                'dropbox_raw_folder',
                'dropbox_extra_folder',
                'dropbox_edited_folder',
                'dropbox_archive_folder',
                'extra_photo_count'
            ]);
        });
    }
};
