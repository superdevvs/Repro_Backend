<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT = 'digital_assignment_correction_snapshots';

    public function up(): void
    {
        if (! Schema::hasTable(self::AUDIT)) {
            Schema::create(self::AUDIT, function (Blueprint $table): void {
                $table->id();
                $table->string('source_table');
                $table->unsignedBigInteger('source_id');
                $table->text('before_json');
                $table->text('applied_json');
                $table->text('identity_json');
                $table->timestamp('applied_at');
                $table->unique(['source_table', 'source_id']);
            });
        }

        DB::transaction(function (): void {
            $categoryIds = DB::table('categories')->whereRaw('LOWER(TRIM(name)) = ?', ['digital enhancements'])->pluck('id');
            $digitalIds = DB::table('services')->whereIn('category_id', $categoryIds)->pluck('id')->all();
            foreach ([18, 87] as $id) {
                $name = DB::table('services')->where('id', $id)->value('name');
                if ($name && preg_match('/^virtual staging(?:\s*\(per image\))?$/i', trim($name))) {
                    $digitalIds[] = $id;
                }
            }
            $digitalIds = array_values(array_unique($digitalIds));
            foreach (DB::table('services')->whereIn('id', $digitalIds)->get() as $service) {
                $this->applyOnce('services', (int) $service->id, [
                    'photographer_required' => 0, 'shoot_duration_minutes' => 0, 'requires_editing' => 1,
                ], ['name' => $service->name, 'category_id' => $service->category_id]);
            }

            // These three erroneous operational assignments were verified on 2026-10-13.
            // Historical service rows, payout snapshots, compensation, editors, prices,
            // payments, booking dates and workflow/delivery states are deliberately retained.
            $repairs = [
                2586 => ['shoot_id' => 2407, 'service_id' => 51, 'photographer_id' => 1143],
                2579 => ['shoot_id' => 2460, 'service_id' => 51, 'photographer_id' => 2264],
                2580 => ['shoot_id' => 2460, 'service_id' => 18, 'photographer_id' => 2264],
            ];
            foreach ($repairs as $id => $identity) {
                if (! in_array($identity['service_id'], $digitalIds, true)
                    || ! DB::table('shoot_service')->where('id', $id)->where($identity)->exists()) {
                    continue;
                }
                $this->applyOnce('shoot_service', $id, ['photographer_id' => null, 'duration_minutes' => 0], $identity);
            }
            $shoot = DB::table('shoots')->where('id', 2460)->where('photographer_id', 2264)->first();
            $serviceIds = DB::table('shoot_service')->where('shoot_id', 2460)->pluck('service_id')->all();
            if ($shoot && $serviceIds && array_diff($serviceIds, $digitalIds) === []) {
                $this->applyOnce('shoots', 2460, ['photographer_id' => null], [
                    'address' => $shoot->address, 'photographer_id' => 2264,
                ]);
            }
        });
    }

    private function applyOnce(string $table, int $id, array $values, array $identity): void
    {
        if (DB::table(self::AUDIT)->where('source_table', $table)->where('source_id', $id)->exists()) {
            return;
        }
        $row = DB::table($table)->where('id', $id)->first();
        $before = [];
        foreach ($values as $field => $value) {
            $before[$field] = $row->{$field};
        }
        if ($before == $values) {
            return;
        }
        DB::table(self::AUDIT)->insert([
            'source_table' => $table, 'source_id' => $id,
            'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
            'applied_json' => json_encode($values, JSON_THROW_ON_ERROR),
            'identity_json' => json_encode($identity, JSON_THROW_ON_ERROR),
            'applied_at' => now()->toDateTimeString(),
        ]);
        // Raw writes avoid model hooks, queues, notifications and accounting recalculation.
        DB::table($table)->where('id', $id)->update($values);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::AUDIT)) return;
        DB::transaction(function (): void {
            foreach (DB::table(self::AUDIT)->orderByDesc('id')->get() as $snapshot) {
                if (! in_array($snapshot->source_table, ['services', 'shoot_service', 'shoots'], true)) continue;
                $row = DB::table($snapshot->source_table)->where('id', $snapshot->source_id)->first();
                if (! $row) continue;
                $applied = json_decode($snapshot->applied_json, true, 512, JSON_THROW_ON_ERROR);
                foreach ($applied as $field => $value) {
                    if ($row->{$field} != $value) continue 2;
                }
                DB::table($snapshot->source_table)->where('id', $snapshot->source_id)
                    ->update(json_decode($snapshot->before_json, true, 512, JSON_THROW_ON_ERROR));
                DB::table(self::AUDIT)->where('id', $snapshot->id)->delete();
            }
        });
    }
};
