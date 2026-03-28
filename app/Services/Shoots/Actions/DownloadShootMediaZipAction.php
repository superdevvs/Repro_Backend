<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Services\DropboxWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DownloadShootMediaZipAction
{
    public function __construct(protected DropboxWorkflowService $dropboxService)
    {
    }

    public function execute(Request $request, Shoot $shoot)
    {
        $type = $request->query('type', 'raw');
        $folderPath = $shoot->getDropboxFolderForType($type);

        if (!$folderPath) {
            return response()->json(['error' => 'No folder found for type: ' . $type], 404);
        }

        $zipLink = $this->dropboxService->getDropboxZipLink($folderPath);
        if ($zipLink) {
            return response()->json([
                'type' => 'redirect',
                'url' => $zipLink,
            ]);
        }

        try {
            $zipPath = $this->dropboxService->generateZipOnFly($shoot, $type);

            return response()->download($zipPath, "shoot-{$shoot->id}-{$type}.zip")->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Failed to generate ZIP', [
                'error' => $e->getMessage(),
                'shoot_id' => $shoot->id,
            ]);

            return response()->json(['error' => 'Failed to generate ZIP file'], 500);
        }
    }
}
