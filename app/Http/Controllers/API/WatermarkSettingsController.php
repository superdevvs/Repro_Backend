<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WatermarkSettings;
use App\Models\ShootFile;
use App\Jobs\GenerateWatermarkedImageJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WatermarkSettingsController extends Controller
{
    private const PROGRESS_CACHE_KEY = 'watermark_regeneration_progress';
    private const PROGRESS_TTL = 3600; // 1 hour
    public function index()
    {
        $settings = WatermarkSettings::getDefault();
        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'logo_enabled' => 'sometimes|boolean',
            'logo_position' => 'sometimes|string|in:top-left,top-right,bottom-left,bottom-right,center,custom',
            'logo_opacity' => 'sometimes|integer|min:0|max:100',
            'logo_size' => 'sometimes|numeric|min:5|max:50',
            'logo_offset_x' => 'sometimes|integer|min:0|max:50',
            'logo_offset_y' => 'sometimes|integer|min:0|max:50',
            // custom_logo_url is only set via uploadLogo endpoint, not directly
            'text_enabled' => 'sometimes|boolean',
            'text_content' => 'sometimes|nullable|string|max:255',
            'text_style' => 'sometimes|string|in:diagonal,repeated,corner,banner',
            'text_opacity' => 'sometimes|integer|min:0|max:100',
            'text_color' => 'sometimes|string|max:20',
            'text_size' => 'sometimes|integer|min:5|max:30',
            'text_spacing' => 'sometimes|integer|min:50|max:500',
            'text_angle' => 'sometimes|integer|between:-90,90',
            'overlay_enabled' => 'sometimes|boolean',
            'overlay_color' => 'sometimes|string|max:50',
            'regenerate_watermarks' => 'sometimes|boolean',
        ]);

        $settings = WatermarkSettings::getDefault();
        $shouldRegenerate = $request->boolean('regenerate_watermarks', false);
        unset($validated['regenerate_watermarks']);
        
        $validated['updated_by'] = $user->id;
        $settings->update($validated);

        Log::info('Watermark settings updated', [
            'user_id' => $user->id,
            'changes' => array_keys($validated),
            'regenerate' => $shouldRegenerate,
        ]);

        $regenerationId = null;
        $filesQueued = 0;
        
        if ($shouldRegenerate) {
            $regenerationId = Str::uuid()->toString();
            $filesQueued = $this->queueWatermarkRegeneration($user->id, $regenerationId);
        }

        return response()->json([
            'message' => 'Watermark settings updated successfully',
            'settings' => $settings->fresh(),
            'regeneration_id' => $regenerationId,
            'files_queued' => $filesQueued,
        ]);
    }

    public function uploadLogo(Request $request)
    {
        $user = $request->user();
        
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:5120', // 5MB max
        ]);

        $file = $request->file('logo');
        $filename = 'watermark-logo-' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('watermark-logos', $filename, 'public');

        $settings = WatermarkSettings::getDefault();
        $settings->update([
            'custom_logo_url' => Storage::disk('public')->url($path),
            'updated_by' => $user->id,
        ]);

        Log::info('Watermark logo uploaded', [
            'user_id' => $user->id,
            'path' => $path,
        ]);

        return response()->json([
            'message' => 'Logo uploaded successfully',
            'logo_url' => Storage::disk('public')->url($path),
            'settings' => $settings->fresh(),
        ]);
    }

    public function presets()
    {
        return response()->json([
            'positions' => [
                ['value' => 'top-left', 'label' => 'Top Left'],
                ['value' => 'top-right', 'label' => 'Top Right'],
                ['value' => 'bottom-left', 'label' => 'Bottom Left'],
                ['value' => 'bottom-right', 'label' => 'Bottom Right'],
                ['value' => 'center', 'label' => 'Center'],
            ],
            'text_styles' => [
                ['value' => 'diagonal', 'label' => 'Diagonal Text', 'description' => 'Single diagonal text across the image'],
                ['value' => 'repeated', 'label' => 'Repeated Pattern', 'description' => 'Traditional repeated watermark pattern'],
                ['value' => 'corner', 'label' => 'Corner Text', 'description' => 'Text in corner with logo'],
                ['value' => 'banner', 'label' => 'Banner Style', 'description' => 'Horizontal banner across image'],
            ],
            'default_settings' => [
                'logo_opacity' => 60,
                'logo_size' => 20,
                'logo_offset_x' => 3,
                'logo_offset_y' => 8,
                'text_opacity' => 40,
                'text_size' => 10,
                'text_angle' => -30,
            ],
        ]);
    }

    public function preview(Request $request)
    {
        return response()->json([
            'message' => 'Preview generation requires processing - settings will be applied on next watermark generation',
            'current_settings' => WatermarkSettings::getDefault(),
        ]);
    }

    public function regenerate(Request $request)
    {
        $user = $request->user();
        
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $regenerationId = Str::uuid()->toString();
        $filesQueued = $this->queueWatermarkRegeneration($user->id, $regenerationId);

        return response()->json([
            'message' => 'Watermark regeneration started',
            'regeneration_id' => $regenerationId,
            'files_queued' => $filesQueued,
        ]);
    }

    /**
     * Check progress of watermark regeneration
     */
    public function regenerationProgress(Request $request, string $regenerationId)
    {
        $cacheKey = self::PROGRESS_CACHE_KEY . '_' . $regenerationId;
        $progress = Cache::get($cacheKey);

        if (!$progress) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'Regeneration task not found or expired',
            ], 404);
        }

        return response()->json($progress);
    }

    /**
     * Debug endpoint to check what files would be affected
     */
    public function debugFiles(Request $request)
    {
        $user = $request->user();
        
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get counts for debugging
        $totalFiles = ShootFile::whereNotNull('shoot_id')->count();
        $imageFiles = ShootFile::whereNotNull('shoot_id')
            ->where(function ($query) {
                $query->whereIn('mime_type', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'])
                      ->orWhereIn('file_type', ['image', 'jpg', 'jpeg', 'png', 'webp', 'gif'])
                      ->orWhere('mime_type', 'LIKE', 'image/%')
                      ->orWhere('file_type', 'LIKE', 'image/%');
            })
            ->count();

        // Get sample of file types/mime types
        $sampleTypes = ShootFile::whereNotNull('shoot_id')
            ->select('file_type', 'mime_type')
            ->distinct()
            ->limit(20)
            ->get();

        // Get shoot payment statuses
        $shootStats = \App\Models\Shoot::selectRaw('payment_status, bypass_paywall, COUNT(*) as count')
            ->groupBy('payment_status', 'bypass_paywall')
            ->get();

        // Files that would be regenerated
        $eligibleFiles = ShootFile::whereNotNull('shoot_id')
            ->where(function ($query) {
                $query->whereIn('mime_type', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'])
                      ->orWhereIn('file_type', ['image', 'jpg', 'jpeg', 'png', 'webp', 'gif'])
                      ->orWhere('mime_type', 'LIKE', 'image/%')
                      ->orWhere('file_type', 'LIKE', 'image/%');
            })
            ->whereHas('shoot', function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('payment_status')
                      ->orWhereNotIn('payment_status', ['paid', 'full']);
                })
                ->where(function ($q) {
                    $q->whereNull('bypass_paywall')
                      ->orWhere('bypass_paywall', false);
                });
            })
            ->count();

        return response()->json([
            'total_shoot_files' => $totalFiles,
            'image_files' => $imageFiles,
            'eligible_for_watermark' => $eligibleFiles,
            'file_types_sample' => $sampleTypes,
            'shoot_payment_stats' => $shootStats,
        ]);
    }

    /**
     * Queue watermark regeneration for all applicable files
     */
    private function queueWatermarkRegeneration(int $userId, string $regenerationId): int
    {
        // Find all image files that need watermarking (unpaid shoots only, not bypassing paywall)
        // file_type contains values like 'image/jpeg', 'jpg', etc.
        // mime_type is often empty, so check both
        $files = ShootFile::whereNotNull('shoot_id')
            ->where(function ($query) {
                $query->whereIn('mime_type', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'])
                      ->orWhereIn('file_type', ['image', 'jpg', 'jpeg', 'png', 'webp', 'gif'])
                      ->orWhere('mime_type', 'LIKE', 'image/%')
                      ->orWhere('file_type', 'LIKE', 'image/%');
            })
            ->whereHas('shoot', function ($query) {
                // Only process shoots that need watermarks:
                // - payment_status is not paid/full (or null)
                // - Not bypassing paywall
                $query->where(function ($q) {
                    $q->whereNull('payment_status')
                      ->orWhereNotIn('payment_status', ['paid', 'full']);
                })
                ->where(function ($q) {
                    $q->whereNull('bypass_paywall')
                      ->orWhere('bypass_paywall', false)
                      ->orWhere('bypass_paywall', 0);
                });
            })
            ->get();
        
        Log::info('Watermark regeneration: Found files', [
            'total_files' => $files->count(),
            'regeneration_id' => $regenerationId,
            'query_details' => 'Searching for image files in unpaid shoots without bypass_paywall',
        ]);

        $totalFiles = $files->count();
        
        // Initialize progress tracking
        $cacheKey = self::PROGRESS_CACHE_KEY . '_' . $regenerationId;
        Cache::put($cacheKey, [
            'status' => 'processing',
            'total' => $totalFiles,
            'processed' => 0,
            'failed' => 0,
            'percentage' => 0,
            'started_at' => now()->toIso8601String(),
        ], self::PROGRESS_TTL);

        $count = $totalFiles;
        $isSyncQueue = config('queue.default') === 'sync';
        
        if ($isSyncQueue) {
            // For sync queue, store file IDs for background processing via CLI
            $fileIds = $files->pluck('id')->toArray();
            Cache::put($cacheKey . '_file_ids', $fileIds, self::PROGRESS_TTL);
            
            // Start background process using CLI
            $artisanPath = base_path('artisan');
            $phpPath = PHP_BINARY;
            
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows - use cmd /c with start /B for true background execution
                $command = "cmd /c start /B \"\" \"$phpPath\" \"$artisanPath\" watermarks:process $regenerationId";
                pclose(popen($command, 'r'));
            } else {
                // Unix - use nohup and &
                $command = "nohup \"$phpPath\" \"$artisanPath\" watermarks:process $regenerationId > /dev/null 2>&1 &";
                exec($command);
            }
            
            Log::info('Watermark processing started in background', [
                'regeneration_id' => $regenerationId,
                'command' => $command,
            ]);
        } else {
            // For real queues, dispatch jobs to queue
            foreach ($files as $file) {
                try {
                    GenerateWatermarkedImageJob::dispatch($file, $regenerationId)->onQueue('watermarks');
                } catch (\Exception $e) {
                    Log::warning('Failed to queue watermark regeneration', [
                        'file_id' => $file->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Watermark regeneration queued', [
            'user_id' => $userId,
            'regeneration_id' => $regenerationId,
            'files_queued' => $count,
        ]);

        return $count;
    }

    /**
     * Update regeneration progress (called by jobs)
     */
    public static function updateRegenerationProgress(string $regenerationId, bool $success = true): void
    {
        $cacheKey = self::PROGRESS_CACHE_KEY . '_' . $regenerationId;
        $progress = Cache::get($cacheKey);

        if (!$progress) {
            return;
        }

        $progress['processed']++;
        if (!$success) {
            $progress['failed']++;
        }
        
        $progress['percentage'] = $progress['total'] > 0 
            ? round(($progress['processed'] / $progress['total']) * 100) 
            : 100;
        
        if ($progress['processed'] >= $progress['total']) {
            $progress['status'] = 'completed';
            $progress['completed_at'] = now()->toIso8601String();
        }

        Cache::put($cacheKey, $progress, self::PROGRESS_TTL);
    }
}
