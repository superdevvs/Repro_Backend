<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (!Schema::hasColumn('services', 'exclude_from_sales_commission')) {
                $table->boolean('exclude_from_sales_commission')->default(false)->after('photographer_pay');
            }
        });

        DB::table('services')
            ->where(function ($query) {
                $query->where('name', 'like', '%travel%')
                    ->orWhere('name', 'like', '%cancellation%')
                    ->orWhere('name', 'like', '%cancel%')
                    ->orWhere('name', 'like', '%reschedule%');
            })
            ->update(['exclude_from_sales_commission' => true]);

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'approval_snapshot')) {
                $table->json('approval_snapshot')->nullable()->after('modification_notes');
            }
            if (!Schema::hasColumn('invoices', 'unresolved_warnings')) {
                $table->json('unresolved_warnings')->nullable()->after('approval_snapshot');
            }
            if (!Schema::hasColumn('invoices', 'warning_override_reason')) {
                $table->text('warning_override_reason')->nullable()->after('unresolved_warnings');
            }
            if (!Schema::hasColumn('invoices', 'warning_override_by')) {
                $table->foreignId('warning_override_by')->nullable()->after('warning_override_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'warning_override_at')) {
                $table->timestamp('warning_override_at')->nullable()->after('warning_override_by');
            }
        });

        Schema::create('invoice_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_audit_events');

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['warning_override_by'] as $foreignColumn) {
                if (Schema::hasColumn('invoices', $foreignColumn)) {
                    $table->dropForeign([$foreignColumn]);
                }
            }

            $columns = [
                'approval_snapshot',
                'unresolved_warnings',
                'warning_override_reason',
                'warning_override_by',
                'warning_override_at',
            ];

            $existing = array_filter($columns, fn ($column) => Schema::hasColumn('invoices', $column));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'exclude_from_sales_commission')) {
                $table->dropColumn('exclude_from_sales_commission');
            }
        });
    }
};
