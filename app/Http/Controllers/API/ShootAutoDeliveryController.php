<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\{ProviderAutoDeliverySettings, ShootListingService};
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShootAutoDeliveryController extends Controller
{
    public function __invoke(Request $request, Shoot $shoot, ProviderAutoDeliverySettings $settings)
    {
        $input = $request->validate(['enabled' => 'required|boolean']);
        $actor = $request->user();
        abort_unless($settings->canManage($shoot, $actor), 403);
        $shoot = LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $actor, $input, $settings) {
            $fresh = Shoot::lockForUpdate()->findOrFail($shoot->id);
            abort_unless($settings->canManage($fresh, $actor->fresh()), 403);
            abort_unless($settings->supportedItems($fresh)->isNotEmpty(), 422, 'This shoot has no supported provider components.');
            $fresh->forceFill(['auto_delivery_enabled' => $input['enabled']])->save();
            $fresh->workflowLogs()->create(['user_id' => $actor->id, 'action' => 'auto_delivery_setting_changed',
                'details' => $input['enabled'] ? 'Provider auto delivery enabled.' : 'Provider auto delivery disabled.', 'metadata' => ['enabled' => (bool) $input['enabled']]]);
            return $fresh;
        }), 'shoot.auto-delivery-setting');
        ShootListingService::flushCachedListings();
        if ($shoot->auto_delivery_enabled) \App\Jobs\ProcessProviderAutoDelivery::dispatch($shoot->id)->afterCommit();
        return response()->json(['data' => ['id' => $shoot->id, 'auto_delivery' => $settings->payload($shoot, $actor)]]);
    }
}
