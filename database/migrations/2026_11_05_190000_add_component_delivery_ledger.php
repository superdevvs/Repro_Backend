<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use App\Support\MigrationForeignId;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'financial_only')) Schema::table('services', fn (Blueprint $table) => $table->boolean('financial_only')->default(false));
        if (! Schema::hasColumn('shoot_service', 'delivery_requirements')) Schema::table('shoot_service', fn (Blueprint $table) => $table->json('delivery_requirements')->nullable());
        if (! Schema::hasColumn('shoots', 'active_work_is_reopened')) Schema::table('shoots', fn (Blueprint $table) => $table->boolean('active_work_is_reopened')->nullable());
        if (! Schema::hasTable('shoot_delivery_receipts')) Schema::create('shoot_delivery_receipts', function (Blueprint $table) {
            $table->id();
            MigrationForeignId::matching($table, 'shoot_id', 'shoots')->constrained('shoots')->cascadeOnDelete();
            MigrationForeignId::matching($table, 'shoot_service_id', 'shoot_service')->constrained('shoot_service')->cascadeOnDelete();
            $table->string('component_key', 80);
            $table->string('execution_key', 100);
            $table->string('method', 40);
            $table->json('evidence');
            MigrationForeignId::matching($table, 'actor_id', 'users')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('plan_revision', 64);
            $table->string('operation_key', 100)->index();
            $table->timestamp('delivered_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unique(['shoot_service_id', 'component_key', 'execution_key', 'operation_key'], 'delivery_component_operation_unique');
            $table->index(['shoot_id', 'shoot_service_id']);
        });
        // The reviewed catalog fee is financial-only for NEW bookings. Existing
        // execution flags/compensation are deliberately preserved for review.
        DB::table('services')->where('id', 90)->update(['financial_only' => true]);
        DB::table('shoots')->whereNotNull('active_work_started_at')->whereNull('active_work_is_reopened')->update([
            'active_work_is_reopened' => DB::raw('CASE WHEN admin_verified_at IS NOT NULL OR completed_at IS NOT NULL THEN 1 ELSE 0 END'),
        ]);
        DB::table('shoots')->whereNotNull('active_work_started_at')->whereNull('active_work_completed_at')
            ->where('active_work_is_reopened', false)->whereNull('admin_verified_at')->whereNull('completed_at')
            ->where('status', 'active')->where('workflow_status', 'active')->update(['status' => 'editing', 'workflow_status' => 'editing']);
        $catalog = \App\Models\Service::with('category')->get()->keyBy('id');
        DB::table('shoot_service')->whereNull('delivery_requirements')->orderBy('id')->chunkById(300, function ($rows) use ($catalog) {
            foreach ($rows as $row) {
                $item = (new \App\Models\ShootService)->newFromBuilder((array) $row);
                $item->setRelation('service', $catalog->get($row->service_id));
                $requirements = app(\App\Services\Shoots\DeliveryComponentRequirements::class)->forItem($item);
                DB::table('shoot_service')->where('id', $row->id)->update(['delivery_requirements' => json_encode($requirements)]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_delivery_receipts');
        Schema::table('shoot_service', fn (Blueprint $table) => $table->dropColumn('delivery_requirements'));
        Schema::table('shoots', fn (Blueprint $table) => $table->dropColumn('active_work_is_reopened'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('financial_only'));
    }
};
