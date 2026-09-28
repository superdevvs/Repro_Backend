<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submitted_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->json('contact');
            $table->string('plan_code', 30)->nullable();
            $table->json('services')->nullable();
            $table->text('details')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('preferred_time', 255)->nullable();
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['submitted_by_id', 'idempotency_key'], 'listing_studio_request_idempotency');
            $table->index(['client_id', 'type', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_requests');
    }
};
