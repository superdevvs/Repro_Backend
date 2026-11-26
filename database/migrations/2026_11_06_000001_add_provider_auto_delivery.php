<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Support\MigrationForeignId;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('shoots', 'auto_delivery_enabled')) Schema::table('shoots', fn (Blueprint $table) => $table->boolean('auto_delivery_enabled')->default(true));
        if (! Schema::hasColumn('shoots', 'active_work_cycle_key')) Schema::table('shoots', fn (Blueprint $table) => $table->uuid('active_work_cycle_key')->nullable());
        // Automated delivery is a system event, never an impersonated staff action.
        $actorColumn = collect(Schema::getColumns('workflow_logs'))->firstWhere('name', 'user_id');
        if ($actorColumn && ! $actorColumn['nullable']) Schema::table('workflow_logs', fn (Blueprint $table) => MigrationForeignId::matching($table, 'user_id', 'users')->nullable()->change());
        if (! Schema::hasTable('shoot_provider_delivery_verifications')) Schema::create('shoot_provider_delivery_verifications', function (Blueprint $table) {
            $table->id();
            MigrationForeignId::matching($table, 'shoot_id', 'shoots')->constrained('shoots')->cascadeOnDelete();
            MigrationForeignId::matching($table, 'shoot_service_id', 'shoot_service')->constrained('shoot_service')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('execution_key', 100);
            $table->string('identity_key', 200);
            $table->string('state', 20);
            $table->string('source', 40);
            $table->string('event_fingerprint', 64);
            $table->json('evidence');
            $table->timestamp('verified_at');
            $table->unique(['shoot_service_id', 'provider', 'event_fingerprint'], 'provider_verification_event_unique');
            $table->index(['shoot_id', 'shoot_service_id', 'execution_key'], 'provider_verification_execution_index');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('shoot_provider_delivery_verifications');
        Schema::table('shoots', fn (Blueprint $table) => $table->dropColumn(['auto_delivery_enabled', 'active_work_cycle_key']));
        // System-attributed history is retained; do not invent users on rollback.
    }
};
