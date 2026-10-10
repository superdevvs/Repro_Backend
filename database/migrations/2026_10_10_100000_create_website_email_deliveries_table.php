<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->uuid('reference');
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamps();
            $table->unique(['type', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_email_deliveries');
    }
};
