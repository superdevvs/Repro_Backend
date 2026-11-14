<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\{ShootFile, ShootGuestShare};
use App\Services\Media\{LocalMediaDelivery, MediaStorage};
use App\Services\Shoots\{ShootGuestArchiveFiles, ShootGuestArchiveService, ShootGuestOverview, ShootGuestShareService};
use Illuminate\Http\Request;

class ShootGuestDownloadController extends Controller
{
    public function __construct(private ShootGuestShareService $shares, private ShootGuestArchiveService $archives) {}

    private function authorized(Request $request, string $token): ShootGuestShare
    {
        $share = $this->shares->resolve($token);
        abort_unless($this->shares->verified($request, $share), 401, 'Verify your email to continue.');
        return $share;
    }

    public function store(Request $request, string $token)
    {
        $share = $this->authorized($request, $token);
        $input = $request->validate(['size' => 'required|in:original,small',
            'file_ids' => 'sometimes|required|array|min:1|max:5000', 'file_ids.*' => 'required|integer|min:1|distinct']);
        $record = $this->archives->create($share, $input['file_ids'] ?? null, $input['size']);
        return $this->json($this->archives->payload($record, $token), $record['status'] === 'preparing' ? 202 : 200);
    }

    public function show(Request $request, string $token, string $archive)
    {
        $share = $this->authorized($request, $token);
        $record = $this->archives->record($share, $archive);
        $this->archives->revalidate($share, $record);
        if ($record['status'] === 'ready') $this->archives->path($record);
        return $this->json($this->archives->payload($record, $token), $record['status'] === 'preparing' ? 202 : 200);
    }

    public function download(Request $request, string $token, string $archive)
    {
        $share = $this->authorized($request, $token);
        $record = $this->archives->record($share, $archive);
        [$shoot] = $this->archives->revalidate($share, $record);
        return app(LocalMediaDelivery::class)->forPath($this->archives->path($record), $record['filename'],
            ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'], (int) $shoot->id);
    }

    public function mls(Request $request, string $token, ShootFile $file)
    {
        $share = $this->authorized($request, $token);
        abort_unless(app(ShootGuestOverview::class)->allowsFile($share->shoot, $file), 404);
        $path = app(ShootGuestArchiveFiles::class)->mlsPath($file);
        abort_unless($path && app(MediaStorage::class)->exists($path), 404);
        $filename = pathinfo(basename($file->filename ?: 'photo'), PATHINFO_FILENAME).'.'.pathinfo($path, PATHINFO_EXTENSION);
        return app(MediaStorage::class)->downloadResponse($path, $filename,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private function json(array $payload, int $status)
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
