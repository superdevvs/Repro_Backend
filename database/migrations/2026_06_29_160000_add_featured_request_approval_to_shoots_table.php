<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('featured_requested_at')->nullable()->after('is_featured');
            $table->foreignId('featured_requested_by')->nullable()->after('featured_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('featured_approved_at')->nullable()->after('featured_requested_by');
            $table->foreignId('featured_approved_by')->nullable()->after('featured_approved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('featured_approved_by');
            $table->dropColumn('featured_approved_at');
            $table->dropConstrainedForeignId('featured_requested_by');
            $table->dropColumn('featured_requested_at');
        });
    }
};
