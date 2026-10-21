<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_trial_claims', function (Blueprint $table) {
            $table->id();
            // Keep the email claim even if its client is permanently removed.
            $table->foreignId('client_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('email_hash', 64)->unique();
            $table->foreignId('support_ticket_id')->unique()->constrained('support_tickets')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->json('edit_types');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_trial_claims');
    }
};
