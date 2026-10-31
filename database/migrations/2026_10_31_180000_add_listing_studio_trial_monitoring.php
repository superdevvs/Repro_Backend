<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_studio_guest_trials', function (Blueprint $table) {
            $table->timestamp('worker_started_at')->nullable();
            $table->timestamp('first_download_requested_at')->nullable();
            $table->unsignedInteger('download_request_count')->default(0);
        });
        // Fixed vocabulary and daily buckets only. Never retain visitor identifiers or payloads.
        Schema::create('listing_studio_trial_counters', function (Blueprint $table) {
            $table->date('day');
            $table->string('event', 64);
            $table->string('service', 24);
            $table->unsignedBigInteger('count')->default(0);
            $table->primary(['day', 'event', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_trial_counters');
        Schema::table('listing_studio_guest_trials', function (Blueprint $table) {
            $table->dropColumn(['worker_started_at', 'first_download_requested_at', 'download_request_count']);
        });
    }
};
