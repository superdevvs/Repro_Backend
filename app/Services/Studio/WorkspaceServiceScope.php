<?php

namespace App\Services\Studio;

use App\Models\StudioWorkspace;
use Illuminate\Support\Facades\DB;

/** Older workspaces stored catalogue IDs; new workspaces retain execution-row IDs. */
class WorkspaceServiceScope
{
    public static function lineIds(StudioWorkspace $workspace): array
    {
        if ($workspace->shoot_service_item_ids !== null) {
            return array_values(array_unique(array_map('intval', $workspace->shoot_service_item_ids)));
        }

        return DB::table('shoot_service')->where('shoot_id', $workspace->shoot_id)->whereNull('shoot_unit_id')
            ->whereIn('service_id', $workspace->shoot_service_ids ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function contains(StudioWorkspace $workspace, int $lineId): bool
    {
        return in_array($lineId, self::lineIds($workspace), true);
    }
}
