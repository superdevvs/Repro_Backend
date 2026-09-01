<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->restrictOnDelete();
            $table->foreignId('shoot_service_id')->nullable()->constrained('shoot_service')->nullOnDelete();
            $table->string('scope_key', 64);
            $table->string('recipient_type', 32);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 16);
            $table->string('suggested_mode', 16)->nullable();
            $table->string('calculation_method', 16)->nullable();
            $table->string('standard_calculation_method', 16)->nullable();
            $table->unsignedInteger('quantity_snapshot')->default(1);
            $table->decimal('basis_amount_snapshot', 12, 2)->nullable();
            $table->decimal('rate_snapshot', 12, 4)->nullable();
            $table->decimal('standard_rate_snapshot', 12, 4)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('suggested_amount', 12, 2)->nullable();
            $table->decimal('standard_amount_snapshot', 12, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('reason_code', 40);
            $table->string('policy_version', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['shoot_id', 'recipient_type', 'scope_key'],
                'shoot_compensations_scope_unique'
            );
            $table->index(
                ['recipient_user_id', 'recipient_type', 'earned_at'],
                'shoot_compensations_recipient_earned_index'
            );
            $table->index(['shoot_id', 'voided_at'], 'shoot_compensations_shoot_voided_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_compensations');
    }
};
