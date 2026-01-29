<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shoot;
use App\Services\DropboxWorkflowService;
use App\Models\ShootFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    protected $dropboxService;

    public function __construct(DropboxWorkflowService $dropboxService)
    {
        $this->dropboxService = $dropboxService;
    }

    /**
     * Upload files from PC to shoot folder
     */
    public function uploadFromPC(Request $request, \App\Models\Shoot $shoot)
    {
        $request->validate([
            'files' => 'required|array',
            // Allow up to ~1 GiB per file (max in KB), plus common photo/video mimes
            'files.*' => 'required|file|max:1048576|mimes:jpeg,jpg,png,gif,mp4,mov,avi,raw,cr2,nef,arw,tiff,bmp,heic,heif,zip',
            'service_category' => 'nullable|string|in:P,iGuide,Video',
            'upload_type' => 'nullable|string|in:raw,edited'
        ]);

        // Route model binding provides $shoot
        $uploadType = $request->input('upload_type', 'raw');
        Log::info('uploadFromPC: received request', [
            'shoot_id' => $shoot->id,
            'user_id' => auth()->id(),
            'upload_type' => $uploadType,
        ]);
        
        // Be defensive about uploaded files shape
        $filesInput = $request->file('files');
        if ($filesInput instanceof \Illuminate\Http\UploadedFile) {
            $files = [$filesInput];
        } elseif (is_array($filesInput)) {
            $files = $filesInput;
        } else {
            // Fallback: attempt to gather from allFiles in case client sent slightly different keys
            $allFiles = $request->allFiles();
            $files = [];
            foreach ($allFiles as $key => $f) {
                if ($f instanceof \Illuminate\Http\UploadedFile) {
                    $files[] = $f;
                } elseif (is_array($f)) {
                    foreach ($f as $ff) {
                        if ($ff instanceof \Illuminate\Http\UploadedFile) {
                            $files[] = $ff;
                        }
                    }
                }
            }
        }
        if (empty($files)) {
            Log::warning('uploadFromPC: no files received', [ 'shoot_id' => $shoot->id ]);
            return response()->json([
                'success' => false,
                'message' => 'No files received by server',
            ], 422);
        }
        
        // Check workflow permissions based on upload type
        if ($uploadType === 'raw' && !$shoot->canUploadPhotos()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot upload raw photos at this workflow stage',
                'current_status' => $shoot->workflow_status
            ], 400);
        }
        if ($uploadType === 'edited' && !in_array($shoot->workflow_status, [
            Shoot::STATUS_EDITING,
        ])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot upload edited files at this workflow stage',
                'current_status' => $shoot->workflow_status
            ], 400);
        }

        $uploadedFiles = [];
        $errors = [];

        try {
            foreach ($files as $file) {
                try {
                    $serviceCategory = $request->input('service_category', 'P');
                    Log::info('uploadFromPC: processing file', [
                        'shoot_id' => $shoot->id,
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'type' => $file->getMimeType(),
                        'upload_type' => $uploadType,
                    ]);
                    
                    if ($uploadType === 'raw') {
                        // Upload to ToDo folder
                        $shootFile = $this->dropboxService->uploadToTodo($shoot, $file, auth()->id(), $serviceCategory);
                    } else {
                        // Upload directly to Completed folder (for edited files)
                        $shootFile = $this->dropboxService->uploadToCompleted($shoot, $file, auth()->id(), $serviceCategory);
                    }
                    
                    $uploadedFiles[] = [
                        'id' => $shootFile->id,
                        'filename' => $shootFile->filename,
                        'workflow_stage' => $shootFile->workflow_stage,
                        'dropbox_path' => $shootFile->dropbox_path,
                        'file_size' => $shootFile->file_size,
                        'uploaded_at' => $shootFile->created_at
                    ];
                } catch (\Exception $e) {
                    Log::error('uploadFromPC: per-file error', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = [
                        'filename' => $file->getClientOriginalName(),
                        'error' => $e->getMessage()
                    ];
                }
            }

            $shoot->refresh();
            $rawCount = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->count();
            $editedCount = $shoot->files()->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])->count();
            $shoot->raw_photo_count = $rawCount;
            $shoot->edited_photo_count = $editedCount;
            $shoot->raw_missing_count = max(0, ($shoot->expected_raw_count ?? 0) - $rawCount);
            $shoot->edited_missing_count = max(0, ($shoot->expected_final_count ?? 0) - $editedCount);
            $shoot->missing_raw = $shoot->raw_missing_count > 0;
            $shoot->missing_final = $shoot->edited_missing_count > 0;
            $shoot->save();

            return response()->json([
                'success' => true,
                'message' => 'Files processed successfully',
                'uploaded_files' => $uploadedFiles,
                'errors' => $errors,
                'success_count' => count($uploadedFiles),
                'error_count' => count($errors),
                'raw_photo_count' => $shoot->raw_photo_count,
                'edited_photo_count' => $shoot->edited_photo_count,
                'raw_missing_count' => $shoot->raw_missing_count,
                'edited_missing_count' => $shoot->edited_missing_count,
            ]);

        } catch (\Exception $e) {
            Log::error('uploadFromPC: failed', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's Dropbox files for selection
     */
    public function listDropboxFiles(Request $request)
    {
        $request->validate([
            'path' => 'nullable|string',
            'cursor' => 'nullable|string'
        ]);

        $path = $request->input('path', '');
        $cursor = $request->input('cursor');

        try {
            $accessToken = config('services.dropbox.access_token');
            
            if ($cursor) {
                // Continue listing with cursor
                $response = Http::withToken($accessToken)
                    ->withOptions(['verify' => config('app.env') === 'production'])
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->withBody(json_encode(['cursor' => $cursor]))
                    ->post('https://api.dropboxapi.com/2/files/list_folder/continue');
            } else {
                // Initial listing
                $response = Http::withToken($accessToken)
                    ->withOptions(['verify' => config('app.env') === 'production'])
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->withBody(json_encode([
                        'path' => $path === '/' ? '' : $path,
                        'recursive' => false,
                        'include_media_info' => true,
                        'include_deleted' => false,
                        'include_has_explicit_shared_members' => false
                    ]))
                    ->post('https://api.dropboxapi.com/2/files/list_folder');
            }

            if ($response->successful()) {
                $data = $response->json();
                
                // Filter and format files
                $files = [];
                foreach ($data['entries'] as $entry) {
                    if ($entry['.tag'] === 'file') {
                        // Check if it's an image or video file
                        $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'avi', 'raw', 'cr2', 'nef', 'arw', 'tiff', 'bmp'];
                        
                        if (in_array($extension, $allowedExtensions)) {
                            $files[] = [
                                'id' => $entry['id'],
                                'name' => $entry['name'],
                                'path_lower' => $entry['path_lower'],
                                'path_display' => $entry['path_display'],
                                'size' => $entry['size'],
                                'client_modified' => $entry['client_modified'] ?? null,
                                'server_modified' => $entry['server_modified'],
                                'is_downloadable' => $entry['is_downloadable'] ?? true,
                                'extension' => $extension,
                                'file_type' => $this->getFileType($extension)
                            ];
                        }
                    } elseif ($entry['.tag'] === 'folder') {
                        $files[] = [
                            'id' => $entry['id'],
                            'name' => $entry['name'],
                            'path_lower' => $entry['path_lower'],
                            'path_display' => $entry['path_display'],
                            'is_folder' => true
                        ];
                    }
                }

                return response()->json([
                    'success' => true,
                    'files' => $files,
                    'has_more' => $data['has_more'],
                    'cursor' => $data['cursor'] ?? null,
                    'current_path' => $path
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to list Dropbox files',
                    'error' => $response->json()
                ], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error listing Dropbox files: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copy files from user's Dropbox to shoot folder
     */
    public function copyFromDropbox(Request $request, \App\Models\Shoot $shoot)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*.path' => 'required|string',
            'files.*.name' => 'required|string',
            'service_category' => 'nullable|string|in:P,iGuide,Video'
        ]);

        // Route model binding provides $shoot
        
        if (!$shoot->canUploadPhotos()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot upload photos at this workflow stage',
                'current_status' => $shoot->workflow_status
            ], 400);
        }

        $copiedFiles = [];
        $errors = [];

        try {
            foreach ($request->input('files') as $fileInfo) {
                try {
                    $serviceCategory = $request->input('service_category');
                    $shootFile = $this->dropboxService->copyFromDropboxToTodo(
                        $shoot, 
                        $fileInfo['path'], 
                        $fileInfo['name'], 
                        auth()->id(), 
                        $serviceCategory
                    );
                    
                    $copiedFiles[] = [
                        'id' => $shootFile->id,
                        'filename' => $shootFile->filename,
                        'workflow_stage' => $shootFile->workflow_stage,
                        'dropbox_path' => $shootFile->dropbox_path,
                        'file_size' => $shootFile->file_size,
                        'uploaded_at' => $shootFile->created_at
                    ];
                } catch (\Exception $e) {
                    $errors[] = [
                        'filename' => $fileInfo['name'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Files copied successfully',
                'copied_files' => $copiedFiles,
                'errors' => $errors,
                'success_count' => count($copiedFiles),
                'error_count' => count($errors)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Copy failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get file type category from extension
     */
    private function getFileType($extension)
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'raw', 'cr2', 'nef', 'arw', 'tiff', 'bmp'];
        $videoExtensions = ['mp4', 'mov', 'avi'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        } elseif (in_array($extension, $videoExtensions)) {
            return 'video';
        }

        return 'unknown';
    }
}
