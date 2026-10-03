<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DesktopEditingAccess
{
    public function released(): bool
    {
        if (!config('desktop_editing.release_ready')) return false;
        foreach (['win32-x64', 'darwin-x64', 'darwin-arm64'] as $platform) {
            $url = config('desktop_editing.installers.'.$platform);
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') return false;
        }
        return true;
    }

    public function staff(User $user): void
    {
        abort_unless($user->isAccountEligibleForAuthentication() && in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true), 403);
    }

    public function device(Request $request): object
    {
        $parts = explode('.', (string) $request->bearerToken());
        abort_unless(count($parts) === 3 && $parts[0] === 'reproedit', 401);
        $device = DB::table('edit_helper_devices')->where('id', $parts[1])->first();
        abort_unless($device && !$device->revoked_at && now()->lt($device->expires_at)
            && hash_equals($device->credential_hash, hash('sha256', $parts[2])), 401, 'Reconnect this device in Settings → Desktop editing.');
        $user = User::findOrFail($device->user_id);
        $this->staff($user);
        $request->setUserResolver(fn () => $user);
        return $device;
    }

    public function file(int $fileId, User $user): ShootFile
    {
        $file = ShootFile::findOrFail($fileId);
        $this->staff($user);
        app(ShootAuthorizationSupport::class)->ensureShootAccess($file->shoot, $user);
        abort_unless(!$file->is_hidden && in_array($file->workflow_stage, ['completed', 'verified'], true)
            && $file->isClearedForProcessing() && app(ShootAuthorizationSupport::class)->isImageMediaFile($file), 422, 'Choose an available edited image.');
        return $file;
    }

    public function session(Request $request, string $id): object
    {
        $device = $this->device($request);
        $session = DB::table('edit_helper_sessions')->where('id', $id)->where('user_id', $device->user_id)->where('device_id', $device->id)->first();
        abort_unless($session && now()->lt($session->expires_at), 410, 'This editing session expired. Your local work is safe; reopen the image from the dashboard or upload it manually.');
        $this->file($session->file_id, $request->user());
        return $session;
    }
}
