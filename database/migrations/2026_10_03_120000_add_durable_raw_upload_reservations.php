<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never silently discard a legacy actor's retry identity on migration.
        if (Schema::hasTable('shoot_upload_batches') && \Illuminate\Support\Facades\DB::table('shoot_upload_batches')
            ->where('upload_type', 'raw')->groupBy('shoot_id', 'upload_batch_id')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            throw new \RuntimeException('Resolve conflicting legacy RAW batch identities before migration.');
        }
        Schema::create('shoot_raw_upload_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            // Keep the immutable actor/service identities even if their rows are removed.
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('service_scope')->default(0);
            $table->string('batch_id', 191);
            $table->string('upload_lane', 10)->default('photo');
            $table->unsignedSmallInteger('bracket_mode')->default(0);
            $table->unsignedInteger('total_files');
            $table->unsignedBigInteger('start_position');
            $table->boolean('prepared')->default(false);
            $table->timestamps();
            $table->unique(['shoot_id', 'batch_id']);
            $table->index(['shoot_id', 'service_scope', 'upload_lane']);
        });
        if (Schema::hasTable('shoot_upload_batches')) {
            foreach (\Illuminate\Support\Facades\DB::table('shoot_upload_batches')->where('upload_type', 'raw')->orderBy('id')->get() as $old) {
                \Illuminate\Support\Facades\DB::table('shoot_raw_upload_batches')->insertOrIgnore([
                    'shoot_id' => $old->shoot_id, 'actor_id' => $old->actor_id, 'batch_id' => $old->upload_batch_id,
                    'service_scope' => $old->shoot_service_id ?? 0, 'upload_lane' => 'photo', 'bracket_mode' => $old->bracket_mode ?? 0,
                    'total_files' => $old->upload_batch_total, 'start_position' => $old->reserved_offset, 'prepared' => false,
                    'created_at' => $old->created_at, 'updated_at' => $old->updated_at,
                ]);
            }
        }
        Schema::create('shoot_raw_upload_batch_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('shoot_raw_upload_batches')->cascadeOnDelete();
            $table->unsignedInteger('file_index');
            $table->string('idempotency_key', 191);
            $table->unique(['batch_id', 'file_index']);
        });
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->foreignId('raw_upload_batch_id')->nullable()->constrained('shoot_raw_upload_batches')->nullOnDelete();
            $table->unsignedBigInteger('raw_upload_position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('raw_upload_batch_id');
            $table->dropColumn('raw_upload_position');
        });
        Schema::dropIfExists('shoot_raw_upload_batch_slots');
        Schema::dropIfExists('shoot_raw_upload_batches');
    }
};
