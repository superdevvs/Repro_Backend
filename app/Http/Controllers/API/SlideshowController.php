<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\AyrshareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SlideshowController extends Controller
{
    protected $ayrshareService;

    public function __construct(AyrshareService $ayrshareService)
    {
        $this->ayrshareService = $ayrshareService;
    }

    /**
     * List all slideshows for a shoot
     */
    public function index(Shoot $shoot)
    {
        $slideshows = DB::table('shoot_slideshows')
            ->where('shoot_id', $shoot->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($slideshow) {
                return [
                    'id' => $slideshow->id,
                    'title' => $slideshow->title,
                    'orientation' => $slideshow->orientation,
                    'photos' => json_decode($slideshow->photo_urls ?? '[]', true),
                    'transition' => $slideshow->transition ?? 'fade',
                    'speed' => $slideshow->speed ?? 3,
                    'visible' => (bool) $slideshow->visible,
                    'url' => $slideshow->ayrshare_url,
                    'download_url' => $slideshow->download_url,
                    'created_at' => $slideshow->created_at,
                ];
            });

        return response()->json(['data' => $slideshows]);
    }

    /**
     * Create a new slideshow
     */
    public function store(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'orientation' => 'required|in:portrait,landscape',
            'transition' => 'nullable|string|in:fade,slide,zoom',
            'speed' => 'nullable|integer|min:1|max:10',
            'photo_ids' => 'required|array|min:1',
            'photo_ids.*' => 'exists:shoot_files,id',
        ]);

        try {
            // Get photo URLs
            $photoFiles = ShootFile::whereIn('id', $validated['photo_ids'])
                ->where('shoot_id', $shoot->id)
                ->get();

            if ($photoFiles->isEmpty()) {
                return response()->json(['error' => 'No valid photos found'], 400);
            }

            // Get public URLs for photos
            $photoUrls = $photoFiles->map(function ($file) {
                return $file->getPublicUrl() ?? $file->dropbox_path;
            })->filter()->values()->toArray();

            if (empty($photoUrls)) {
                return response()->json(['error' => 'No accessible photo URLs found'], 400);
            }

            // Create slideshow via Ayrshare
            $ayrshareResult = $this->ayrshareService->createSlideshow(
                $photoUrls,
                $validated['title'],
                $validated['orientation'],
                [
                    'transition' => $validated['transition'] ?? 'fade',
                    'speed' => $validated['speed'] ?? 3,
                ]
            );

            if (!$ayrshareResult) {
                return response()->json(['error' => 'Failed to create slideshow via Ayrshare'], 500);
            }

            // Store slideshow in database
            $slideshowId = DB::table('shoot_slideshows')->insertGetId([
                'shoot_id' => $shoot->id,
                'title' => $validated['title'],
                'orientation' => $validated['orientation'],
                'transition' => $validated['transition'] ?? 'fade',
                'speed' => $validated['speed'] ?? 3,
                'photo_urls' => json_encode($photoUrls),
                'photo_ids' => json_encode($validated['photo_ids']),
                'ayrshare_id' => $ayrshareResult['id'] ?? null,
                'ayrshare_url' => $ayrshareResult['url'] ?? $ayrshareResult['slideshowUrl'] ?? null,
                'download_url' => $ayrshareResult['downloadUrl'] ?? null,
                'visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log activity
            $shoot->workflowLogs()->create([
                'user_id' => auth()->id(),
                'action' => 'slideshow_created',
                'details' => "Slideshow '{$validated['title']}' created with {$photoFiles->count()} photos",
                'metadata' => [
                    'slideshow_id' => $slideshowId,
                    'ayrshare_id' => $ayrshareResult['id'] ?? null,
                ],
            ]);

            $slideshow = DB::table('shoot_slideshows')->where('id', $slideshowId)->first();

            return response()->json([
                'message' => 'Slideshow created successfully',
                'data' => [
                    'id' => (string) $slideshowId,
                    'title' => $slideshow->title,
                    'orientation' => $slideshow->orientation,
                    'photos' => json_decode($slideshow->photo_urls ?? '[]', true),
                    'transition' => $slideshow->transition ?? 'fade',
                    'speed' => $slideshow->speed ?? 3,
                    'visible' => (bool) $slideshow->visible,
                    'url' => $slideshow->ayrshare_url,
                    'download_url' => $slideshow->download_url,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating slideshow', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to create slideshow'], 500);
        }
    }

    /**
     * Update slideshow visibility
     */
    public function update(Request $request, Shoot $shoot, $slideshowId)
    {
        $validated = $request->validate([
            'visible' => 'nullable|boolean',
            'title' => 'nullable|string|max:255',
        ]);

        $updated = DB::table('shoot_slideshows')
            ->where('id', $slideshowId)
            ->where('shoot_id', $shoot->id)
            ->update(array_filter([
                'visible' => $validated['visible'] ?? null,
                'title' => $validated['title'] ?? null,
                'updated_at' => now(),
            ]));

        if (!$updated) {
            return response()->json(['error' => 'Slideshow not found'], 404);
        }

        return response()->json(['message' => 'Slideshow updated successfully']);
    }

    /**
     * Delete a slideshow
     */
    public function destroy(Shoot $shoot, $slideshowId)
    {
        $deleted = DB::table('shoot_slideshows')
            ->where('id', $slideshowId)
            ->where('shoot_id', $shoot->id)
            ->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Slideshow not found'], 404);
        }

        // Log activity
        $shoot->workflowLogs()->create([
            'user_id' => auth()->id(),
            'action' => 'slideshow_deleted',
            'details' => "Slideshow deleted",
            'metadata' => ['slideshow_id' => $slideshowId],
        ]);

        return response()->json(['message' => 'Slideshow deleted successfully']);
    }

    /**
     * Download slideshow
     */
    public function download(Shoot $shoot, $slideshowId)
    {
        $slideshow = DB::table('shoot_slideshows')
            ->where('id', $slideshowId)
            ->where('shoot_id', $shoot->id)
            ->first();

        if (!$slideshow) {
            return response()->json(['error' => 'Slideshow not found'], 404);
        }

        // If we have a download URL, return it
        if ($slideshow->download_url) {
            return response()->json(['download_url' => $slideshow->download_url]);
        }

        // Try to get download URL from Ayrshare
        if ($slideshow->ayrshare_id) {
            $downloadUrl = $this->ayrshareService->getSlideshowDownloadUrl($slideshow->ayrshare_id);
            if ($downloadUrl) {
                // Update database with download URL
                DB::table('shoot_slideshows')
                    ->where('id', $slideshowId)
                    ->update(['download_url' => $downloadUrl, 'updated_at' => now()]);

                return response()->json(['download_url' => $downloadUrl]);
            }
        }

        return response()->json(['error' => 'Download URL not available'], 404);
    }
}


