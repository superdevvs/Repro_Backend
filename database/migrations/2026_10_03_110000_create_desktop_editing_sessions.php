<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('edit_helper_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('platform', 20);
            $table->string('credential_hash', 64);
            $table->boolean('photoshop_detected')->default(false);
            $table->boolean('auto_upload')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('edit_helper_pairings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('claim_hash', 64)->nullable();
            $table->string('name', 100)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('comparison_code', 6)->nullable();
            $table->uuid('device_id')->nullable();
            $table->text('credential_encrypted')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('edit_helper_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('edit_helper_devices');
            $table->unsignedBigInteger('file_id')->index();
            $table->unsignedInteger('expected_version');
            $table->timestamp('launch_expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('edit_helper_sessions');
        Schema::dropIfExists('edit_helper_pairings');
        Schema::dropIfExists('edit_helper_devices');
    }
};
