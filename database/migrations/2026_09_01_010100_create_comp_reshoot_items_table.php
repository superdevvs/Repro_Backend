<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comp_reshoot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->restrictOnDelete();
            $table->foreignId('shoot_service_id')->nullable()->constrained('shoot_service')->nullOnDelete();
            $table->foreignId('source_shoot_service_id')->nullable()->constrained('shoot_service')->nullOnDelete();
            $table->unsignedBigInteger('service_id_snapshot');
            $table->string('service_name_snapshot');
            $table->unsignedBigInteger('source_service_id_snapshot')->nullable();
            $table->string('source_service_name_snapshot')->nullable();
            $table->decimal('nominal_unit_price_snapshot', 12, 2)->default(0);
            $table->unsignedInteger('quantity_snapshot')->default(1);
            $table->decimal('nominal_total_snapshot', 12, 2)->default(0);
            $table->string('reason_code', 40);
            $table->text('reason_note')->nullable();
            $table->string('responsibility', 40);
            $table->foreignId('responsible_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('shoot_service_id', 'comp_reshoot_items_child_service_unique');
            $table->index(['shoot_id', 'reason_code'], 'comp_reshoot_items_shoot_reason_index');
            $table->index('source_shoot_service_id', 'comp_reshoot_items_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_reshoot_items');
    }
};
