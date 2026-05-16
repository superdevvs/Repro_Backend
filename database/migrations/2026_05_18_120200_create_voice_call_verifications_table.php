<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_call_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_e164')->index();
            $table->foreignId('voice_call_id')->nullable()->constrained('voice_calls')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method')->index();
            $table->boolean('success')->default(false)->index();
            $table->unsignedInteger('attempts')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_verifications');
    }
};
