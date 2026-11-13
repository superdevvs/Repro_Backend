<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_media', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 16);
            $t->string('name');
            $t->string('mime_type')->default('application/octet-stream');
            $t->unsignedBigInteger('size');
            $t->unsignedBigInteger('received_bytes')->default(0);
            $t->string('status', 24)->default('uploading');
            $t->string('sha256', 64)->nullable();
            $t->string('message_kind', 16)->nullable();
            $t->string('message_id', 40)->nullable();
            $t->uuid('send_request_key')->nullable();
            $t->string('source_key', 64)->nullable()->unique();
            $t->text('source_url')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('share_expires_at')->nullable();
            $t->timestamps();
            $t->index(['message_kind', 'message_id']);
            $t->index(['user_id', 'status']);
        });
        Schema::create('message_send_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('request_key');
            $t->string('fingerprint', 64);
            $t->string('status', 24)->default('sending');
            $t->json('response')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
        });
        Schema::create('message_reactions', function (Blueprint $t) {
            $t->id();
            $t->string('message_kind', 16);
            $t->string('message_id', 40);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('emoji', 32);
            $t->timestamps();
            $t->unique(['message_kind', 'message_id', 'user_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_send_receipts');
        Schema::dropIfExists('message_media');
    }
};
