<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach ([85 => 'Zillow SHOWCASE Premium', 86 => 'ZILLOW SHOWCASE Standard'] as $id => $name) {
            DB::table('services')->where('id', $id)->where('name', $name)->where('upload_intake_type', 'none')
                ->update(['upload_intake_type' => 'photo', 'uses_hdr_brackets' => true]);
        }
        if (! Schema::hasTable('shoot_additional_files')) Schema::create('shoot_additional_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->cascadeOnDelete();
            $table->foreignId('shoot_service_id')->nullable()->constrained('shoot_service')->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('entry_key', 64);
            $table->string('filename');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['shoot_id', 'uploaded_by', 'entry_key'], 'shoot_additional_entry_unique');
        });
    }

    public function down(): void
    {
        // Retain uploaded originals and the enabled capability on rollback.
    }
};
