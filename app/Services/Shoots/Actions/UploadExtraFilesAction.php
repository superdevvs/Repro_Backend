<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UploadExtraFilesAction
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootMediaMutationSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): array
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,raw,cr2,cr3,nef,arw,dng|max:51200',
        ]);

        $uploadedFiles = [];
        foreach ($request->file('files') as $file) {
            try {
                $shootFile = $this->dropboxService->uploadToExtra($shoot, $file, $user->id);
                $uploadedFiles[] = $this->support->transformFile($shootFile);
            } catch (\Exception $e) {
                Log::error('Failed to upload extra file', [
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $shoot->updatePhotoCounts();
        $this->support->clearShootFilesCache($shoot);

        return [
            'message' => 'Files uploaded successfully',
            'data' => $uploadedFiles,
            'extra_photo_count' => $shoot->extra_photo_count,
        ];
    }
}
