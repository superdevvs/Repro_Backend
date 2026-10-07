<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKUP = 'floor_plan_duration_correction_snapshots';

    public function up(): void
    {
        if (! Schema::hasTable(self::BACKUP)) Schema::create(self::BACKUP, function (Blueprint $table): void {
            $table->id();
            $table->string('source_table');
            $table->unsignedBigInteger('source_id');
            $table->integer('previous_duration')->nullable();
            $table->unique(['source_table', 'source_id']);
        });
        $service = DB::table('services')->where('id', 17)->first();
        if (! $service || $service->is_migration_only || strtolower(trim($service->name)) !== '2d floor plans') {
            return;
        }
        DB::transaction(function () use ($service): void {
            $this->apply('services', $service->id, 'shoot_duration_minutes', $service->shoot_duration_minutes);
            foreach (DB::table('service_sqft_ranges')->where('service_id', 17)->get() as $range) {
                $this->apply('service_sqft_ranges', $range->id, 'duration', $range->duration);
            }
        });
    }

    private function apply(string $table, int $id, string $field, ?int $before): void
    {
        DB::table(self::BACKUP)->insertOrIgnore([
            'source_table' => $table, 'source_id' => $id, 'previous_duration' => $before,
        ]);
        // Keep the original legacy snapshot when present; null-duration bookings
        // must continue using their historical catalogue rather than today's five minutes.
        DB::table('service_duration_policy_snapshots')->insertOrIgnore([
            'source_table' => $table, 'source_id' => $id,
            'before_json' => json_encode([$field => $before], JSON_THROW_ON_ERROR),
            'applied_json' => json_encode([$field => 5], JSON_THROW_ON_ERROR),
            'applied_at' => now()->toDateTimeString(),
        ]);
        DB::table($table)->where('id', $id)->update([$field => 5]);
    }

    public function down(): void
    {
        foreach (DB::table(self::BACKUP)->get() as $snapshot) {
            $field = $snapshot->source_table === 'services' ? 'shoot_duration_minutes' : 'duration';
            // Preserve later administrator edits.
            DB::table($snapshot->source_table)->where('id', $snapshot->source_id)
                ->where($field, 5)->update([$field => $snapshot->previous_duration]);
        }
        Schema::dropIfExists(self::BACKUP);
    }
};
