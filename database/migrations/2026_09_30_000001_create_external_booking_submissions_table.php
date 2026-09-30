<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_booking_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 100);
            $table->string('external_reference', 100);
            $table->char('request_hash', 64);
            $table->foreignId('shoot_id')->nullable()->constrained()->nullOnDelete();
            $table->json('response_payload')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_reference'], 'external_booking_source_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_booking_submissions');
    }
};
