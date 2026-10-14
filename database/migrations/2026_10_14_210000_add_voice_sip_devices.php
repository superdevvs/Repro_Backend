<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_sip_devices', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('creation_key');
            $t->string('label', 80);
            $t->string('caller_id', 20);
            $t->string('connection_id')->nullable()->unique();
            $t->string('sip_username')->unique();
            $t->text('sip_password');
            $t->string('state')->default('creating');
            $t->boolean('enabled')->default(true);
            $t->boolean('registered')->default(false);
            $t->timestamp('registration_checked_at')->nullable();
            $t->timestamp('provision_attempted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'creation_key']);
        });
        Schema::table('voice_phone_offers', function (Blueprint $t): void {
            $t->uuid('sip_device_id')->nullable();
            $t->foreign('sip_device_id')->references('id')->on('voice_sip_devices');
            $t->string('call_session_id')->nullable()->index();
            $t->string('peer_call_control_id')->nullable()->index();
        });
        Schema::table('voice_browser_legs', function (Blueprint $t): void {
            $t->uuid('sip_device_id')->nullable();
            $t->foreign('sip_device_id')->references('id')->on('voice_sip_devices');
        });
    }

    public function down(): void
    {
        Schema::table('voice_browser_legs', function (Blueprint $t): void {
            $t->dropForeign(['sip_device_id']);
            $t->dropColumn('sip_device_id');
        });
        Schema::table('voice_phone_offers', function (Blueprint $t): void {
            $t->dropForeign(['sip_device_id']);
            $t->dropIndex(['call_session_id']);
            $t->dropIndex(['peer_call_control_id']);
            $t->dropColumn(['sip_device_id', 'call_session_id', 'peer_call_control_id']);
        });
        Schema::dropIfExists('voice_sip_devices');
    }
};
