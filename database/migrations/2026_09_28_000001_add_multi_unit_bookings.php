<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', fn (Blueprint $table) => $table->unsignedInteger('units_revision')->default(0));
        Schema::create('shoot_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->string('client_key', 100);
            $table->string('label', 120);
            $table->string('kind', 20)->default('unit');
            $table->unsignedInteger('sqft')->nullable();
            $table->unsignedSmallInteger('beds')->nullable();
            $table->decimal('baths', 5, 1)->nullable();
            $table->text('access_notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['shoot_id', 'client_key']);
        });
        Schema::table('shoot_service', function (Blueprint $table) {
            $table->foreignId('shoot_unit_id')->nullable()->constrained('shoot_units')->restrictOnDelete();
            $table->string('client_key', 100)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('contracted_photo_count')->nullable();
            $table->unique(['shoot_id', 'client_key'], 'shoot_service_client_key_unique');
            $table->dropUnique('shoot_service_shoot_id_service_id_unique');
        });
        // NULL is a distinct scope, not an escape from legacy uniqueness. No existing
        // row or media foreign key is recreated or renumbered by this migration.
        DB::statement('CREATE UNIQUE INDEX shoot_service_unit_catalog_unique ON shoot_service (shoot_id, service_id, COALESCE(shoot_unit_id, 0))');
    }

    public function down(): void
    {
        if (DB::table('shoot_units')->exists()) {
            throw new RuntimeException('Multi-unit bookings exist; refusing a rollback that would erase their identity.');
        }
        DB::statement('DROP INDEX shoot_service_unit_catalog_unique');
        Schema::table('shoot_service', function (Blueprint $table) {
            $table->dropUnique('shoot_service_client_key_unique');
            $table->dropConstrainedForeignId('shoot_unit_id');
            $table->dropColumn(['client_key', 'duration_minutes', 'contracted_photo_count']);
            $table->unique(['shoot_id', 'service_id']);
        });
        Schema::dropIfExists('shoot_units');
        Schema::table('shoots', fn (Blueprint $table) => $table->dropColumn('units_revision'));
    }
};
