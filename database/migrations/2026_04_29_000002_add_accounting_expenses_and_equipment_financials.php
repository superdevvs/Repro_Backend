<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category')->default('General');
            $table->string('description', 500);
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('expense_date');
            $table->string('vendor')->nullable();
            $table->string('status')->default('unreviewed');
            $table->boolean('reimbursable')->default(false);
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_disk')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_mime_type')->nullable();
            $table->unsignedBigInteger('receipt_size')->nullable();
            $table->timestamps();

            $table->index(['category', 'expense_date']);
            $table->index(['related_type', 'related_id']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            Schema::table('photographer_equipments', function (Blueprint $table) {
                $table->dropForeign(['photographer_id']);
            });
        }

        Schema::table('photographer_equipments', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->unsignedBigInteger('photographer_id')->nullable()->change();
                $table->foreign('photographer_id')->references('id')->on('users')->nullOnDelete();
            }

            $table->date('purchase_date')->nullable()->after('issue_date');
            $table->decimal('purchase_cost', 12, 2)->nullable()->after('purchase_date');
            $table->string('vendor')->nullable()->after('purchase_cost');
            $table->unsignedBigInteger('expense_id')->nullable()->after('vendor');
        });

        if ($driver !== 'sqlite') {
            Schema::table('photographer_equipments', function (Blueprint $table) {
                $table->foreign('expense_id')->references('id')->on('accounting_expenses')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('photographer_equipments', function (Blueprint $table) {
                $table->dropForeign(['expense_id']);
            });
        }

        Schema::table('photographer_equipments', function (Blueprint $table) {
            $table->dropColumn(['purchase_date', 'purchase_cost', 'vendor', 'expense_id']);

            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['photographer_id']);
            }
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('photographer_equipments', function (Blueprint $table) {
                $table->unsignedBigInteger('photographer_id')->nullable(false)->change();
                $table->foreign('photographer_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        Schema::dropIfExists('accounting_expenses');
    }
};
