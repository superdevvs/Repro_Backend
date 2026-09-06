<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auth_security_limits', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('expires_at')->index();
            $table->boolean('reported')->default(false);
        });
        Schema::create('auth_security_rollouts', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestamp('started_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verification_required_at')->nullable();
            $table->string('email_verified_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verification_required_at', 'email_verified_email']);
        });
        Schema::dropIfExists('auth_security_limits');
        Schema::dropIfExists('auth_security_rollouts');
    }
};
