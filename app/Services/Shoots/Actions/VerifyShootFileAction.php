<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\GenerateWatermarkedImageJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VerifyShootFileAction
{
    public function __construct(
        protected ShootMediaStorageService $mediaStorageService,
        protected ShootMediaMutationSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, ShootFile $file, User $user): array
    {
        $request->validate([
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $file->verify($user->id, $request->verification_notes);
            $this->mediaStorageService->moveToFinal($file, $user->id);

            if (in_array($file->media_type, ['image', 'raw', 'edited'], true) && $file->shouldBeWatermarked()) {
                GenerateWatermarkedImageJob::dispatch($file->fresh());
            }

            $unverifiedFiles = $shoot->files()->where('workflow_stage', '!=', ShootFile::STAGE_VERIFIED)->count();
            if ($unverifiedFiles === 0 && $shoot->workflow_status === Shoot::STATUS_EDITING) {
                $shoot->updateWorkflowStatus(Shoot::STATUS_READY, $user->id);
                $shoot->save();
            }

            $shoot = $this->support->refreshMediaCounters($shoot->fresh());
            DB::commit();

            return [
                'message' => 'File verified and moved to final storage successfully',
                'file' => $file->fresh(),
                'shoot_status' => $shoot->workflow_status,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
