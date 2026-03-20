<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shoot_id')->index();
            $table->string('event_type', 50); // page_view, link_click, media_view, share, download
            $table->string('tour_type', 30)->nullable(); // branded, mls, generic_mls
            $table->string('visitor_id', 64)->index(); // hashed fingerprint
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->foreign('shoot_id')->references('id')->on('shoots')->onDelete('cascade');
            $table->index(['shoot_id', 'event_type']);
            $table->index(['shoot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_events');
    }
};
