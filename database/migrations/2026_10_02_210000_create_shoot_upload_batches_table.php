<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_upload_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('upload_batch_id', 191);
            $table->string('upload_type', 20);
            $table->foreignId('shoot_service_id')->nullable()->constrained('shoot_service')->nullOnDelete();
            $table->unsignedTinyInteger('bracket_mode')->nullable();
            $table->unsignedInteger('upload_batch_total');
            $table->unsignedInteger('reserved_offset');
            $table->unsignedTinyInteger('parallel_uploads');
            $table->timestamps();

            $table->unique(['shoot_id', 'actor_id', 'upload_batch_id'], 'shoot_upload_batch_actor_key_unique');
            $table->index(['shoot_id', 'upload_batch_id'], 'shoot_upload_batch_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_upload_batches');
    }
};
