<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_notification_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->index();
            $table->text('token')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->unsignedBigInteger('sequence');
            $table->string('environment', 16);
            $table->string('permission', 16);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mobile_notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 128);
            $table->string('kind', 24);
            $table->string('source_id', 64);
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['device_id', 'event_key']);
            $table->index(['status', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('mobile_notification_deliveries');
        Schema::dropIfExists('mobile_notification_devices');
    }
};
