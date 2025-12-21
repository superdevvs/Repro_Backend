<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->enum('pricing_type', ['fixed', 'variable'])->default('fixed')->after('price');
            $table->boolean('allow_multiple')->default(false)->after('pricing_type');
        });

        // Create sqft ranges table for variable pricing
        Schema::create('service_sqft_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->onDelete('cascade');
            $table->integer('sqft_from');
            $table->integer('sqft_to');
            $table->integer('duration')->nullable(); // in minutes
            $table->decimal('price', 10, 2);
            $table->decimal('photographer_pay', 10, 2)->nullable();
            $table->timestamps();
            
            // Index for efficient lookups
            $table->index(['service_id', 'sqft_from', 'sqft_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_sqft_ranges');
        
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'allow_multiple']);
        });
    }
};
