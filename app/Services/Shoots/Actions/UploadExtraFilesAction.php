<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UploadExtraFilesAction
{
    public function __construct(
        protected ShootMediaStorageService $mediaStorageService,
        protected ShootMediaMutationSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): array
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,raw,cr2,cr3,nef,arw,dng|max:51200',
            'required_for_editing' => 'sometimes|boolean',
            'requiredForEditing' => 'sometimes|boolean',
        ]);

        $requiredForEditing = $request->has('required_for_editing')
            ? $request->boolean('required_for_editing')
            : $request->boolean('requiredForEditing', false);

        $uploadedFiles = [];
        foreach ($request->file('files') as $file) {
            try {
                $shootFile = $this->mediaStorageService->uploadToExtra($shoot, $file, $user->id);
                $flagUpdates = [];
                if (Schema::hasColumn('shoot_files', 'is_extra')) {
                    $flagUpdates['is_extra'] = true;
                }
                if (Schema::hasColumn('shoot_files', 'required_for_editing')) {
                    $flagUpdates['required_for_editing'] = $requiredForEditing;
                }
                if ($flagUpdates !== []) {
                    $shootFile->forceFill($flagUpdates)->save();
                    $shootFile->refresh();
                }
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
