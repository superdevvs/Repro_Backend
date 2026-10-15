<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_sip_devices', function (Blueprint $t) {
            $t->string('sms_number')->nullable();
            $t->string('sms_install_hash', 64)->nullable();
            $t->text('sms_push')->nullable();
            $t->timestamp('sms_bound_at')->nullable();
            $t->timestamp('sms_last_fetch_at')->nullable();
            $t->timestamp('sms_last_send_at')->nullable();
        });
        Schema::create('groundwire_sms_sends', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('device_id')->index();
            $t->string('request_key', 64)->nullable();
            $t->string('fingerprint', 64)->index();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->string('status')->default('attempting');
            $t->timestamps();
            $t->unique(['device_id', 'request_key']);
        });
        Schema::create('groundwire_sms_pushes', function (Blueprint $t) {
            $t->id();
            $t->uuid('device_id');
            $t->unsignedBigInteger('message_id');
            $t->string('binding_hash', 64);
            $t->string('status')->default('queued')->index();
            $t->timestamps();
            $t->unique(['device_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groundwire_sms_pushes');
        Schema::dropIfExists('groundwire_sms_sends');
        Schema::table('voice_sip_devices', fn (Blueprint $t) => $t->dropColumn([
            'sms_number', 'sms_install_hash', 'sms_push', 'sms_bound_at', 'sms_last_fetch_at', 'sms_last_send_at',
        ]));
    }
};
