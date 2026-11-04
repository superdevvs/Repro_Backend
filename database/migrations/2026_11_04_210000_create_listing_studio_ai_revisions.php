<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('listing_studio_ai_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_item_id')->constrained('listing_studio_order_items');
            $table->foreignId('client_id')->constrained('users');
            $table->unsignedTinyInteger('sequence');
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->text('instructions');
            $table->string('base_output_id');
            $table->json('base_descriptor');
            $table->json('output_descriptor')->nullable();
            $table->json('route');
            $table->string('status')->default('queued');
            $table->string('error_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_item_id', 'sequence']);
            $table->unique(['order_item_id', 'request_key']);
            $table->index(['order_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_ai_revisions');
    }
};
