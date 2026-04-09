<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shoot_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('calendar_id')->default('primary');
            $table->string('google_event_id');
            $table->string('sync_fingerprint', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['shoot_id', 'user_id']);
            $table->index('shoot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_event_mappings');
    }
};
