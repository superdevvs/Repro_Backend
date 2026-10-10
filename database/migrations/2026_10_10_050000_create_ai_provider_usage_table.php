<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_usage', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->dateTime('occurred_at')->index();
            $table->string('provider', 20)->default('openai');
            $table->string('feature', 60);
            $table->string('model', 100);
            $table->string('endpoint', 60);
            $table->string('source', 30)->default('metered');
            $table->unsignedInteger('calls')->default(1);
            $table->string('status', 20)->default('pending');
            $table->string('request_id', 150)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('cached_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 18, 8)->nullable();
            $table->string('pricing_version', 30)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->index(['source', 'occurred_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('ai_provider_usage'); }
};
