<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\DropboxFolder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DropboxWorkflowService
{
    protected $tokenService;
    protected $dropboxApiUrl = 'https://api.dropboxapi.com/2';
    protected $dropboxContentUrl = 'https://content.dropboxapi.com/2';
    protected $httpOptions;

    public function __construct(DropboxTokenService $tokenService = null)
    {
        $this->tokenService = $tokenService ?: new DropboxTokenService();
        
        // Configure HTTP options for development environment
        $this->httpOptions = [
            'verify' => config('app.env') === 'production' ? true : false,
            'timeout' => 60,
        ];
    }

    /**
     * Get a valid access token
     */
    protected function getAccessToken()
    {
        try {
            return $this->tokenService->getValidAccessToken();
        } catch (\Exception $e) {
            Log::error('Failed to get valid Dropbox access token', ['error' => $e->getMessage()]);
            throw new \Exception('Dropbox authentication failed. Please check your token configuration.');
        }
    }

    /**
     * Create folder structure for a shoot using new Photo Editing organization
     */
    public function createShootFolders(Shoot $shoot)
    {
        // Generate property slug if not already set
        if (!$shoot->property_slug) {
            $shoot->property_slug = $shoot->generatePropertySlug();
            $shoot->save();
        }

        $propertySlug = $shoot->property_slug;
        $basePath = "/Photo Editing";
        
        // Create base Photo Editing folder
        $this->createFolderIfNotExists($basePath);
        
        // Create To-Do and Completed base folders
        $todoBasePath = "{$basePath}/To-Do";
        $completedBasePath = "{$basePath}/Completed";
        $archivedBasePath = "{$basePath}/Archived Shoots";
        
        $this->createFolderIfNotExists($todoBasePath);
        $this->createFolderIfNotExists($completedBasePath);
        $this->createFolderIfNotExists($archivedBasePath);
        
        // Create property folder structure: /To-Do/{propertySlug}/raw and /extra
        $todoPropertyPath = "{$todoBasePath}/{$propertySlug}";
        $rawPath = "{$todoPropertyPath}/raw";
        $extraPath = "{$todoPropertyPath}/extra";
        
        $this->createFolderIfNotExists($todoPropertyPath);
        $this->createFolderIfNotExists($rawPath);
        $this->createFolderIfNotExists($extraPath);
        
        // Create Completed folder: /Completed/{propertySlug}-edited
        $completedPath = "{$completedBasePath}/{$propertySlug}-edited";
        $this->createFolderIfNotExists($completedPath);

        // Update shoot with folder paths
        $shoot->dropbox_raw_folder = $rawPath;
        $shoot->dropbox_extra_folder = $extraPath;
        $shoot->dropbox_edited_folder = $completedPath;
        $shoot->save();

        // Create DropboxFolder records for compatibility
        DropboxFolder::updateOrCreate(
            ['shoot_id' => $shoot->id, 'folder_type' => DropboxFolder::TYPE_TODO],
            ['dropbox_path' => $rawPath, 'dropbox_folder_id' => null]
        );

        DropboxFolder::updateOrCreate(
            ['shoot_id' => $shoot->id, 'folder_type' => DropboxFolder::TYPE_COMPLETED],
            ['dropbox_path' => $completedPath, 'dropbox_folder_id' => null]
        );

        Log::info("Created Dropbox folders for shoot", [
            'shoot_id' => $shoot->id,
            'property_slug' => $propertySlug,
            'raw_folder' => $rawPath,
            'extra_folder' => $extraPath,
            'edited_folder' => $completedPath,
        ]);
    }

    /**
     * Upload file to ToDo folder
     */
    public function uploadToTodo(Shoot $shoot, UploadedFile $file, $userId, $serviceCategory = null)
    {
        // Find (or create) the ToDo folder for this shoot
        $todoFolder = $shoot->dropboxFolders()
            ->where('folder_type', DropboxFolder::TYPE_TODO)
            ->first();
        
        if (!$todoFolder) {
            $this->createShootFolders($shoot);
            $todoFolder = $shoot->dropboxFolders()
                ->where('folder_type', DropboxFolder::TYPE_TODO)
                ->first();
        }

        if (!$todoFolder) {
            Log::warning('ToDo Dropbox folder not found, falling back to local storage', [
                'shoot_id' => $shoot->id,
            ]);
            return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO);
        }

        $filename = 'TODO_' . str_replace('.', '_', uniqid('', true)) . '_' . $file->getClientOriginalName();
        $dropboxPath = $todoFolder->dropbox_path . '/' . $filename;

        try {
            $fileContent = $file->get();
            
            $apiArgs = json_encode([
                'path' => $dropboxPath,
                'mode' => 'add',
                'autorename' => true,
                'mute' => false,
            ]);

            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withBody($fileContent, 'application/octet-stream')
                ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                ->post($this->dropboxContentUrl . '/files/upload');

            if ($response->successful()) {
                $fileData = $response->json();
                
                // Store file record in database
                $shootFile = ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => $filename,
                    'path' => $dropboxPath,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_TODO,
                    'dropbox_path' => $dropboxPath,
                    'dropbox_file_id' => $fileData['id'] ?? null
                ]);

                // Update shoot workflow status if this is the first photo upload
                if (in_array($shoot->workflow_status, [Shoot::WORKFLOW_BOOKED, Shoot::WORKFLOW_RAW_UPLOAD_PENDING])) {
                    $shoot->updateWorkflowStatus(Shoot::WORKFLOW_RAW_UPLOADED, $userId);
                }

                Log::info("File uploaded to Dropbox ToDo folder", [
                    'shoot_id' => $shoot->id,
                    'filename' => $filename,
                    'path' => $dropboxPath
                ]);

                return $shootFile;
            } else {
                Log::error("Failed to upload file to Dropbox, falling back to local", $response->json() ?: []);
                return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO);
            }
        } catch (\Exception $e) {
            Log::error("Exception uploading file to Dropbox, falling back to local", ['error' => $e->getMessage()]);
            return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO);
        }
    }

    /**
     * Store file on local public storage as a fallback when Dropbox fails
     */
    private function storeLocally(Shoot $shoot, UploadedFile $file, $userId, string $stage): ShootFile
    {
        $prefix = $stage === ShootFile::STAGE_COMPLETED ? 'LOCAL_COMPLETED_' : 'LOCAL_TODO_';
        $filename = $prefix . str_replace('.', '_', uniqid('', true)) . '_' . $file->getClientOriginalName();
        $dir = "shoots/{$shoot->id}/" . ($stage === ShootFile::STAGE_COMPLETED ? 'completed' : 'todo');
        $serverPath = $dir . '/' . $filename;

        Storage::disk('public')->putFileAs($dir, $file, $filename);

        $shootFile = ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'path' => $serverPath,
            'file_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $userId,
            'workflow_stage' => $stage,
            'dropbox_path' => null,
            'dropbox_file_id' => null,
        ]);

        if ($stage === ShootFile::STAGE_TODO && in_array($shoot->workflow_status, [Shoot::WORKFLOW_BOOKED, Shoot::WORKFLOW_RAW_UPLOAD_PENDING])) {
            $shoot->updateWorkflowStatus(Shoot::WORKFLOW_RAW_UPLOADED, $userId);
        }
        if ($stage === ShootFile::STAGE_COMPLETED && in_array($shoot->workflow_status, [Shoot::WORKFLOW_BOOKED, Shoot::WORKFLOW_RAW_UPLOADED])) {
            $shoot->updateWorkflowStatus(Shoot::WORKFLOW_EDITING_UPLOADED, $userId);
        }

        Log::info('Stored file locally as Dropbox fallback', [
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'path' => $serverPath,
            'stage' => $stage,
        ]);

        return $shootFile;
    }

    /**
     * Move file from ToDo to Completed folder
     */
    public function moveToCompleted(ShootFile $shootFile, $userId)
    {
        $shoot = $shootFile->shoot;
        
        // Locate the Completed folder for this shoot
        $completedFolder = $shoot->dropboxFolders()
            ->where('folder_type', DropboxFolder::TYPE_COMPLETED)
            ->first();
        
        if (!$completedFolder) {
            // Fallback: mark as completed without Dropbox move, and keep current path
            Log::warning('Completed Dropbox folder not found, marking file as completed locally', [
                'shoot_id' => $shoot->id,
                'file_id' => $shootFile->id,
            ]);

            $shootFile->moveToCompleted($userId);

            // If no remaining TODO files and workflow is PHOTOS_UPLOADED, advance workflow
            $todoFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->count();
            if ($todoFiles === 0 && $shoot->workflow_status === Shoot::WORKFLOW_RAW_UPLOADED) {
                $shoot->updateWorkflowStatus(Shoot::WORKFLOW_EDITING, $userId);
            }
            return true;
        }

        $newPath = $completedFolder->dropbox_path . '/' . $shootFile->stored_filename;

        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/move_v2', [
                    'from_path' => $shootFile->dropbox_path,
                    'to_path' => $newPath,
                    'allow_shared_folder' => false,
                    'autorename' => true
                ]);

            if ($response->successful()) {
                // Update file record
                $shootFile->dropbox_path = $newPath;
                $shootFile->moveToCompleted($userId);

                // Check if all files are moved to completed
                $todoFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->count();
                if ($todoFiles === 0 && $shoot->workflow_status === Shoot::WORKFLOW_RAW_UPLOADED) {
                    $shoot->updateWorkflowStatus(Shoot::WORKFLOW_EDITING, $userId);
                }

                Log::info("File moved to Completed folder", [
                    'shoot_id' => $shoot->id,
                    'filename' => $shootFile->filename,
                    'new_path' => $newPath
                ]);

                return true;
            } else {
                Log::error("Failed to move file in Dropbox", $response->json() ?: []);
                throw new \Exception('Failed to move file in Dropbox');
            }
        } catch (\Exception $e) {
            Log::error("Exception moving file in Dropbox", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Copy verified files to server storage (keep in Dropbox)
     */
    public function moveToFinal(ShootFile $shootFile, $userId)
    {
        $shoot = $shootFile->shoot;

        try {
            if (!empty($shootFile->dropbox_path)) {
                // Download file from Dropbox and store on server (but keep in Dropbox)
                $this->downloadAndStoreOnServer($shootFile, $shootFile->dropbox_path);
            } else {
                // Local fallback: copy existing local file into final directory
                $serverPath = "shoots/{$shoot->id}/final/{$shootFile->stored_filename}";
                $currentPath = $shootFile->path; // e.g., shoots/{id}/completed/...
                if (Storage::disk('public')->exists($currentPath)) {
                    $contents = Storage::disk('public')->get($currentPath);
                    Storage::disk('public')->put($serverPath, $contents);
                    $shootFile->path = $serverPath;
                } else {
                    throw new \Exception('Source file missing in local storage');
                }
            }
            
            // Update file record - keep dropbox_path but mark as verified
            $shootFile->workflow_stage = ShootFile::STAGE_VERIFIED;
            $shootFile->save();

            Log::info("File copied to server storage (kept in Dropbox)", [
                'shoot_id' => $shoot->id,
                'filename' => $shootFile->filename,
                'dropbox_path' => $shootFile->dropbox_path,
                'server_path' => $shootFile->path
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Exception copying file to server storage", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Download file from Dropbox and store on server
     */
    protected function downloadAndStoreOnServer(ShootFile $shootFile, $dropboxPath)
    {
        try {
            $apiArgs = json_encode(['path' => $dropboxPath]);

            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                ->get($this->dropboxContentUrl . '/files/download');

            if ($response->successful()) {
                $serverPath = "shoots/{$shootFile->shoot_id}/final/{$shootFile->stored_filename}";
                
                // Store file on server
                \Storage::disk('public')->put($serverPath, $response->body());
                
                // Update file path to server location
                $shootFile->path = $serverPath;
                $shootFile->save();

                Log::info("File downloaded and stored on server", [
                    'dropbox_path' => $dropboxPath,
                    'server_path' => $serverPath
                ]);
            } else {
                Log::error("Failed to download file from Dropbox", $response->json() ?: []);
                throw new \Exception('Failed to download file from Dropbox');
            }
        } catch (\Exception $e) {
            Log::error("Exception downloading file from Dropbox", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getTemporaryLink(?string $dropboxPath): ?string
    {
        if (!$dropboxPath) {
            return null;
        }

        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/get_temporary_link', [
                    'path' => $dropboxPath,
                ]);

            if ($response->successful()) {
                return $response->json()['link'] ?? null;
            }

            Log::warning('Failed to create Dropbox temporary link', [
                'path' => $dropboxPath,
                'error' => $response->json(),
            ]);
        } catch (\Exception $e) {
            Log::error('Exception creating Dropbox temporary link', [
                'path' => $dropboxPath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * List files in a specific folder
     */
    public function listFolderFiles($folderPath)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/list_folder', [
                    'path' => $folderPath,
                    'recursive' => false,
                    'include_media_info' => true,
                ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error("Failed to list Dropbox folder files", $response->json() ?: []);
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Exception listing Dropbox folder files", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate address-based folder name
     */
    private function generateAddressFolderName(Shoot $shoot)
    {
        // Clean and format address for folder name
        $address = $shoot->address;
        $city = $shoot->city;
        $state = $shoot->state;
        
        // Remove special characters and replace spaces with hyphens
        $cleanAddress = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $address);
        $cleanCity = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $city);
        $cleanState = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $state);
        
        // Replace spaces with hyphens and remove multiple hyphens
        $addressPart = preg_replace('/\s+/', '-', trim($cleanAddress));
        $cityPart = preg_replace('/\s+/', '-', trim($cleanCity));
        $statePart = preg_replace('/\s+/', '-', trim($cleanState));
        
        // Combine parts
        $folderName = "{$addressPart}-{$cityPart}-{$statePart}";
        
        // Clean up multiple hyphens and ensure it's not too long
        $folderName = preg_replace('/-+/', '-', $folderName);
        $folderName = substr($folderName, 0, 100); // Limit length
        
        return trim($folderName, '-');
    }

    /**
     * Get service categories based on the shoot's service
     */
    private function getServiceCategories(Shoot $shoot)
    {
        // If service_category is set, use it
        if ($shoot->service_category) {
            return [$shoot->service_category];
        }
        
        // Otherwise, determine from service name
        $serviceName = strtolower($shoot->service->name ?? '');
        
        if (strpos($serviceName, 'iguide') !== false) {
            return ['iGuide'];
        } elseif (strpos($serviceName, 'video') !== false) {
            return ['Video'];
        } else {
            // Default to Photos, but you might want to create all three
            return ['P']; // or return ['P', 'iGuide', 'Video'] to create all
        }
    }

    /**
     * Get category prefix for folder naming
     */
    private function getCategoryPrefix($category)
    {
        switch ($category) {
            case 'P':
                return 'P';
            case 'iGuide':
                return 'iGuide';
            case 'Video':
                return 'Video';
            default:
                return 'P';
        }
    }

    /**
     * Create folder if it doesn't exist
     */
    private function createFolderIfNotExists($path)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode(['path' => $path, 'autorename' => false]))
                ->post($this->dropboxApiUrl . '/files/create_folder_v2');

            if ($response->successful()) {
                Log::info("Created Dropbox folder: {$path}");
                return true;
            } else {
                $error = $response->json();
                // Check if folder already exists
                if (isset($error['error']['.tag']) && $error['error']['.tag'] === 'path' && 
                    isset($error['error']['path']['.tag']) && $error['error']['path']['.tag'] === 'conflict') {
                    Log::info("Dropbox folder already exists: {$path}");
                    return true;
                } else {
                    Log::error("Failed to create Dropbox folder: {$path}", $error ?: []);
                    return false;
                }
            }
        } catch (\Exception $e) {
            Log::error("Exception creating Dropbox folder: {$path}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get service category from file path
     */
    private function getServiceCategoryFromPath($path, $shoot)
    {
        // Extract category from path like "/RealEstatePhotos/ToDo/2025-01-18/P-123-Main-Street-Anytown-ST/file.jpg"
        if (strpos($path, '/P-') !== false) {
            return 'P';
        } elseif (strpos($path, '/iGuide-') !== false) {
            return 'iGuide';
        } elseif (strpos($path, '/Video-') !== false) {
            return 'Video';
        }
        
        // Fallback to shoot's service category or default
        return $shoot->service_category ?? 'P';
    }

    /**
     * Upload file directly to Completed folder (for edited files)
     */
    public function uploadToCompleted(Shoot $shoot, UploadedFile $file, $userId, $serviceCategory = null)
    {
        // Find (or create) the Completed folder for this shoot
        $completedFolder = $shoot->dropboxFolders()
            ->where('folder_type', DropboxFolder::TYPE_COMPLETED)
            ->first();
        
        if (!$completedFolder) {
            $this->createShootFolders($shoot);
            $completedFolder = $shoot->dropboxFolders()
                ->where('folder_type', DropboxFolder::TYPE_COMPLETED)
                ->first();
        }

        if (!$completedFolder) {
            // Fallback to local storage when Dropbox Completed folder is absent
            Log::warning('Dropbox Completed folder missing; falling back to local storage for edited upload', [
                'shoot_id' => $shoot->id,
                'service_category' => $serviceCategory,
            ]);
            return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_COMPLETED);
        }

        $filename = 'COMPLETED_' . str_replace('.', '_', uniqid('', true)) . '_' . $file->getClientOriginalName();
        $dropboxPath = $completedFolder->dropbox_path . '/' . $filename;

        try {
            $fileContent = $file->get();
            
            $apiArgs = json_encode([
                'path' => $dropboxPath,
                'mode' => 'add',
                'autorename' => true,
                'mute' => false,
            ]);

            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withBody($fileContent, 'application/octet-stream')
                ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                ->post($this->dropboxContentUrl . '/files/upload');

            if ($response->successful()) {
                $fileData = $response->json();
                
                // Store file record in database
                $shootFile = ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => $filename,
                    'path' => $dropboxPath,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_COMPLETED, // Directly to completed
                    'dropbox_path' => $dropboxPath,
                    'dropbox_file_id' => $fileData['id'] ?? null
                ]);

                // Update shoot workflow status if needed
                if (in_array($shoot->workflow_status, [Shoot::WORKFLOW_BOOKED, Shoot::WORKFLOW_RAW_UPLOADED, Shoot::WORKFLOW_EDITING])) {
                    $shoot->updateWorkflowStatus(Shoot::WORKFLOW_EDITING_UPLOADED, $userId);
                }

                Log::info("File uploaded directly to Dropbox Completed folder", [
                    'shoot_id' => $shoot->id,
                    'filename' => $filename,
                    'path' => $dropboxPath
                ]);

                return $shootFile;
            } else {
                Log::error("Failed to upload file to Dropbox Completed folder, falling back to local", $response->json() ?: []);
                return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_COMPLETED);
            }
        } catch (\Exception $e) {
            Log::error("Exception uploading file to Dropbox Completed folder, falling back to local", ['error' => $e->getMessage()]);
            return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_COMPLETED);
        }
    }

    /**
     * Copy file from user's Dropbox to ToDo folder
     */
    public function copyFromDropboxToTodo(Shoot $shoot, $sourcePath, $filename, $userId, $serviceCategory = null)
    {
        // Find (or create) the ToDo folder for this shoot
        $todoFolder = $shoot->dropboxFolders()
            ->where('folder_type', DropboxFolder::TYPE_TODO)
            ->first();
        
        if (!$todoFolder) {
            $this->createShootFolders($shoot);
            $todoFolder = $shoot->dropboxFolders()
                ->where('folder_type', DropboxFolder::TYPE_TODO)
                ->first();
        }

        if (!$todoFolder) {
            throw new \Exception("ToDo folder not found for category: {$serviceCategory}");
        }

        $newFilename = 'COPIED_TODO_' . str_replace('.', '_', uniqid('', true)) . '_' . $filename;
        $destinationPath = $todoFolder->dropbox_path . '/' . $newFilename;

        try {
            // Copy file within Dropbox
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode([
                    'from_path' => $sourcePath,
                    'to_path' => $destinationPath,
                    'allow_shared_folder' => false,
                    'autorename' => true
                ]))
                ->post($this->dropboxApiUrl . '/files/copy_v2');

            if ($response->successful()) {
                $fileData = $response->json();
                
                // Get file metadata to determine size and type
                $metadataResponse = Http::withToken($this->getAccessToken())
                    ->withOptions($this->httpOptions)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->withBody(json_encode(['path' => $destinationPath]))
                    ->post($this->dropboxApiUrl . '/files/get_metadata');

                $fileSize = 0;
                $mimeType = 'application/octet-stream';
                
                if ($metadataResponse->successful()) {
                    $metadata = $metadataResponse->json();
                    $fileSize = $metadata['size'] ?? 0;
                    $mimeType = $this->getMimeTypeFromExtension($filename);
                }
                
                // Store file record in database
                $shootFile = ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $filename,
                    'stored_filename' => $newFilename,
                    'path' => $destinationPath,
                    'file_type' => $mimeType,
                    'file_size' => $fileSize,
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_TODO,
                    'dropbox_path' => $destinationPath,
                    'dropbox_file_id' => $fileData['id'] ?? null
                ]);

                // Update shoot workflow status if this is the first photo upload
                if (in_array($shoot->workflow_status, [Shoot::WORKFLOW_BOOKED, Shoot::WORKFLOW_RAW_UPLOAD_PENDING])) {
                    $shoot->updateWorkflowStatus(Shoot::WORKFLOW_RAW_UPLOADED, $userId);
                }

                Log::info("File copied from Dropbox to ToDo folder", [
                    'shoot_id' => $shoot->id,
                    'source_path' => $sourcePath,
                    'destination_path' => $destinationPath,
                    'filename' => $filename
                ]);

                return $shootFile;
            } else {
                Log::error("Failed to copy file in Dropbox", $response->json() ?: []);
                throw new \Exception('Failed to copy file in Dropbox');
            }
        } catch (\Exception $e) {
            Log::error("Exception copying file in Dropbox", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get MIME type from file extension
     */
    private function getMimeTypeFromExtension($filename)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'raw' => 'image/x-canon-raw',
            'cr2' => 'image/x-canon-cr2',
            'nef' => 'image/x-nikon-nef',
            'arw' => 'image/x-sony-arw',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo'
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Delete file from Dropbox
     */
    private function deleteFromDropbox($path)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode(['path' => $path]))
                ->post($this->dropboxApiUrl . '/files/delete_v2');

            if ($response->successful()) {
                Log::info("Deleted file from Dropbox: {$path}");
                return true;
            } else {
                Log::error("Failed to delete file from Dropbox: {$path}", $response->json() ?: []);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception deleting file from Dropbox: {$path}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Upload file to Extra folder
     */
    public function uploadToExtra(Shoot $shoot, UploadedFile $file, $userId)
    {
        // Ensure extra folder exists
        if (!$shoot->dropbox_extra_folder) {
            $this->createShootFolders($shoot);
            $shoot->refresh();
        }

        if (!$shoot->dropbox_extra_folder) {
            Log::warning('Extra Dropbox folder not found, falling back to local storage', [
                'shoot_id' => $shoot->id,
            ]);
            return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO);
        }

        $filename = 'EXTRA_' . str_replace('.', '_', uniqid('', true)) . '_' . $file->getClientOriginalName();
        $dropboxPath = $shoot->dropbox_extra_folder . '/' . $filename;

        try {
            $fileContent = $file->get();
            
            $apiArgs = json_encode([
                'path' => $dropboxPath,
                'mode' => 'add',
                'autorename' => true,
                'mute' => false,
            ]);

            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withBody($fileContent, 'application/octet-stream')
                ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                ->post($this->dropboxContentUrl . '/files/upload');

            if ($response->successful()) {
                $fileData = $response->json();
                
                $shootFile = ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => $filename,
                    'path' => $dropboxPath,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_TODO,
                    'dropbox_path' => $dropboxPath,
                    'dropbox_file_id' => $fileData['id'] ?? null
                ]);

                // Update extra photo count
                $shoot->extra_photo_count = $shoot->files()
                    ->where('workflow_stage', ShootFile::STAGE_TODO)
                    ->where('path', 'like', '%/extra/%')
                    ->count();
                $shoot->save();

                Log::info("File uploaded to Dropbox Extra folder", [
                    'shoot_id' => $shoot->id,
                    'filename' => $filename,
                    'path' => $dropboxPath
                ]);

                return $shootFile;
            } else {
                Log::error("Failed to upload file to Dropbox Extra folder, falling back to local", $response->json() ?: []);
                return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO);
            }
        } catch (\Exception $e) {
            Log::error("Exception uploading file to Dropbox Extra folder, falling back to local", ['error' => $e->getMessage()]);
            return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO);
        }
    }

    /**
     * Archive shoot by copying completed folder to Archived Shoots
     */
    public function archiveShoot(Shoot $shoot, $userId = null)
    {
        if (!$shoot->dropbox_edited_folder) {
            Log::warning('No edited folder to archive', ['shoot_id' => $shoot->id]);
            return false;
        }

        // Generate client slug
        $client = $shoot->client;
        $clientSlug = $client ? strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '-', $client->name)) : 'unknown-client';
        $clientSlug = preg_replace('/-+/', '-', trim($clientSlug, '-'));

        // Create archive path: /Photo Editing/Archived Shoots/{clientSlug}/{propertySlug}-{shootId}
        $basePath = "/Photo Editing/Archived Shoots";
        $clientPath = "{$basePath}/{$clientSlug}";
        $archivePath = "{$clientPath}/{$shoot->property_slug}-{$shoot->id}";

        try {
            // Create client folder if not exists
            $this->createFolderIfNotExists($basePath);
            $this->createFolderIfNotExists($clientPath);

            // Copy the entire completed folder to archive
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/copy_v2', [
                    'from_path' => $shoot->dropbox_edited_folder,
                    'to_path' => $archivePath,
                    'allow_shared_folder' => false,
                    'autorename' => true
                ]);

            if ($response->successful()) {
                // Update shoot with archive folder path
                $shoot->dropbox_archive_folder = $archivePath;
                $shoot->save();

                Log::info("Shoot archived successfully", [
                    'shoot_id' => $shoot->id,
                    'from_path' => $shoot->dropbox_edited_folder,
                    'to_path' => $archivePath
                ]);

                return true;
            } else {
                Log::error("Failed to archive shoot in Dropbox", $response->json() ?: []);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception archiving shoot", ['error' => $e->getMessage(), 'shoot_id' => $shoot->id]);
            return false;
        }
    }

    /**
     * List shoot files by type (raw, edited, extra, archive)
     */
    public function listShootFiles(Shoot $shoot, string $type)
    {
        $folderPath = $shoot->getDropboxFolderForType($type);
        
        if (!$folderPath) {
            Log::warning("No Dropbox folder found for type: {$type}", ['shoot_id' => $shoot->id]);
            return [];
        }

        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/list_folder', [
                    'path' => $folderPath,
                    'recursive' => false,
                    'include_media_info' => true,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $entries = $data['entries'] ?? [];

                // Transform entries into our format
                return collect($entries)
                    ->filter(function ($entry) {
                        return $entry['.tag'] === 'file';
                    })
                    ->map(function ($entry) use ($shoot) {
                        return [
                            'id' => $entry['id'] ?? null,
                            'name' => $entry['name'] ?? '',
                            'path' => $entry['path_display'] ?? '',
                            'size' => $entry['size'] ?? 0,
                            'modified' => $entry['client_modified'] ?? $entry['server_modified'] ?? null,
                            'mime_type' => $this->getMimeTypeFromExtension($entry['name'] ?? ''),
                            'thumbnail_link' => null, // Will be fetched on demand
                        ];
                    })
                    ->values()
                    ->toArray();
            } else {
                Log::error("Failed to list Dropbox folder files", $response->json() ?: []);
                return [];
            }
        } catch (\Exception $e) {
            Log::error("Exception listing Dropbox folder files", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get Dropbox shared link for ZIP download
     */
    public function getDropboxZipLink(string $folderPath)
    {
        try {
            // Try to create a shared link for the folder
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/sharing/create_shared_link_with_settings', [
                    'path' => $folderPath,
                    'settings' => [
                        'requested_visibility' => 'public',
                        'audience' => 'public',
                        'access' => 'viewer'
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $url = $data['url'] ?? null;
                
                // Convert to direct download link by replacing dl=0 with dl=1
                if ($url) {
                    $url = str_replace('dl=0', 'dl=1', $url);
                    Log::info("Created Dropbox shared link", ['path' => $folderPath, 'url' => $url]);
                    return $url;
                }
            } else {
                $error = $response->json();
                // If link already exists, try to get it
                if (isset($error['error']['.tag']) && $error['error']['.tag'] === 'shared_link_already_exists') {
                    return $this->getExistingSharedLink($folderPath);
                }
                Log::warning("Failed to create Dropbox shared link", $error ?: []);
            }
        } catch (\Exception $e) {
            Log::error("Exception creating Dropbox shared link", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get existing shared link for a folder
     */
    private function getExistingSharedLink(string $folderPath)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/sharing/list_shared_links', [
                    'path' => $folderPath,
                    'direct_only' => true
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $links = $data['links'] ?? [];
                
                if (count($links) > 0) {
                    $url = $links[0]['url'] ?? null;
                    if ($url) {
                        return str_replace('dl=0', 'dl=1', $url);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Exception getting existing shared link", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Generate ZIP file on-the-fly from Dropbox files (fallback)
     */
    public function generateZipOnFly(Shoot $shoot, string $type)
    {
        $files = $this->listShootFiles($shoot, $type);
        
        if (empty($files)) {
            throw new \Exception("No files found for type: {$type}");
        }

        // Create a temporary ZIP file
        $zipPath = storage_path("app/temp/shoot-{$shoot->id}-{$type}-" . time() . ".zip");
        
        // Ensure temp directory exists
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Failed to create ZIP file");
        }

        foreach ($files as $file) {
            try {
                // Download file from Dropbox
                $apiArgs = json_encode(['path' => $file['path']]);
                $response = Http::withToken($this->getAccessToken())
                    ->withOptions($this->httpOptions)
                    ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                    ->get($this->dropboxContentUrl . '/files/download');

                if ($response->successful()) {
                    $zip->addFromString($file['name'], $response->body());
                }
            } catch (\Exception $e) {
                Log::error("Failed to download file for ZIP", [
                    'file' => $file['path'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $zip->close();

        Log::info("Generated ZIP file on-the-fly", [
            'shoot_id' => $shoot->id,
            'type' => $type,
            'file_count' => count($files),
            'zip_path' => $zipPath
        ]);

        return $zipPath;
    }
}
