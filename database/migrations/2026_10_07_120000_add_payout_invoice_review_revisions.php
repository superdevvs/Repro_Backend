<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('payout_revision')->default(0);
            $table->boolean('payout_edited')->default(false);
            $table->string('payout_submission')->nullable();
            $table->json('payout_submission_snapshot')->nullable();
            $table->json('payout_edit_baseline')->nullable();
        });
        Schema::create('payout_work_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->unsignedBigInteger('recipient_id');
            $table->string('work_key');
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role', 'recipient_id', 'work_key'], 'payout_work_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_work_allocations');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn([
            'payout_revision', 'payout_edited', 'payout_submission',
            'payout_submission_snapshot', 'payout_edit_baseline',
        ]));
    }
};
