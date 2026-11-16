<?php

namespace App\Console\Commands;

use App\Services\Shoots\ProviderAssetRecoveryThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only list of shoots whose iGUIDE floorplans sit on a Protected View with no usable access
 * token. Their floorplans need a manual download by someone with access (or a public view); new
 * provider links lift the mark automatically.
 */
class ListProtectedIguideFloorplans extends Command
{
    protected $signature = 'iguide:protected-floorplans {--json : One JSON object per line}';

    protected $description = 'List shoots whose iGUIDE floorplans are on a protected view without a usable access token';

    public function handle(): int
    {
        $rows = DB::table(ProviderAssetRecoveryThrottle::TABLE.' as attempt')
            ->join('shoots', 'shoots.id', '=', 'attempt.shoot_id')
            ->where('attempt.channel', 'recovery-iguide')
            ->where('attempt.outcome', ProviderAssetRecoveryThrottle::OUTCOME_PROTECTED)
            ->orderBy('attempt.shoot_id')
            ->get(['attempt.shoot_id', 'shoots.address', 'shoots.city', 'shoots.status', 'shoots.scheduled_date',
                'shoots.iguide_property_id', 'shoots.iguide_work_order_id', 'attempt.updated_at as marked_at'])
            ->unique('shoot_id')
            ->values();

        if ($this->option('json')) {
            foreach ($rows as $row) {
                $this->line(json_encode([
                    'shoot_id' => (int) $row->shoot_id, 'address' => $row->address, 'city' => $row->city, 'status' => $row->status,
                    'scheduled_date' => $row->scheduled_date, 'iguide_property_id' => $row->iguide_property_id,
                    'has_work_order' => filled($row->iguide_work_order_id), 'marked_at' => $row->marked_at,
                ], JSON_UNESCAPED_SLASHES));
            }
        } else {
            $this->table(['Shoot', 'Address', 'City', 'Status', 'Scheduled', 'Work order known', 'Marked'], $rows->map(fn ($row) => [
                $row->shoot_id, $row->address, $row->city, $row->status, $row->scheduled_date,
                filled($row->iguide_work_order_id) ? 'yes' : 'no', $row->marked_at,
            ])->all());
        }
        $this->info($rows->count().' shoot(s) need a manual floorplan download or a public iGUIDE view.');

        return self::SUCCESS;
    }
}
