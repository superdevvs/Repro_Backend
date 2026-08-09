<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Services\Shoots\DeliveryMediaOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReorderShootMediaAction
{
    public function __construct(protected DeliveryMediaOrderService $deliveryMediaOrderService)
    {
    }

    public function execute(Request $request, Shoot $shoot): void
    {
        $request->validate([
            'files' => 'required|array',
            'files.*.id' => 'required|exists:shoot_files,id',
            // 1-based to match ShootMediaInteractionService::reorderFiles and
            // scopeInDeliveryOrder(), where a positive value means "deliberately
            // placed" and 0 means "never placed" and sorts to the tail.
            'files.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request, $shoot) {
            foreach ($request->input('files') as $fileData) {
                $shootFile = $shoot->files()->findOrFail($fileData['id']);
                $shootFile->sort_order = $fileData['sort_order'];
                $shootFile->save();
            }

            $this->deliveryMediaOrderService->bumpVersion($shoot);
            $this->deliveryMediaOrderService->refreshSnapshotIfPresent($shoot);
        });
    }
}
