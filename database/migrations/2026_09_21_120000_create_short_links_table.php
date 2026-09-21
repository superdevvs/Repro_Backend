<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('type', 64);
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('target_key')->default('');
            $table->text('destination_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'target_type', 'target_id', 'target_key'], 'short_links_target_unique');
            $table->index(['type', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
