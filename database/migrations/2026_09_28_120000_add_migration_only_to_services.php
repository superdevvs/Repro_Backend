<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('services', fn (Blueprint $table) => $table->boolean('is_migration_only')->default(false)->index());
        Schema::create('legacy_shoot_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('source_id');
            $table->foreignId('shoot_id')->constrained('shoots');
            $table->string('batch_id');
            $table->string('action');
            $table->json('source_snapshot');
            $table->timestamp('created_at');
            $table->unique(['source_system', 'source_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('legacy_shoot_imports');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('is_migration_only'));
    }
};
