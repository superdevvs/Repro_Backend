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
        Schema::create('account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_account_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('linked_account_id')->constrained('users')->onDelete('cascade');
            $table->json('shared_details')->default('{}');
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            // Prevent duplicate links
            $table->unique(['main_account_id', 'linked_account_id'], 'unique_account_link');
            
            // Indexes for performance
            $table->index(['main_account_id']);
            $table->index(['linked_account_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_links');
    }
};
