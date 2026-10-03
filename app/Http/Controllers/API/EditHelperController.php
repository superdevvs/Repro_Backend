<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ShootFileVersion;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\{DesktopEditingAccess, MediaVersionPublisher};
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

/** Device credentials are accepted only here, never as dashboard credentials. */
class EditHelperController extends Controller
{
    public function claimPairing(Request $request, string $pairing)
    {
        $data = $request->validate(['proof' => 'required|string|min:43|max:128', 'name' => 'required|string|max:100', 'platform' => 'required|in:win32-x64,darwin-x64,darwin-arm64']);
        $result = LockedWrite::run(fn () => DB::transaction(function () use ($pairing, $data) {
            $row = DB::table('edit_helper_pairings')->where('id', $pairing)->first();
            abort_unless($row && now()->lt($row->expires_at), 410, 'Pairing expired.');
            $hash = hash('sha256', $data['proof']);
            abort_if($row->claim_hash && !hash_equals($row->claim_hash, $hash), 409, 'This pairing request was claimed by another installation.');
            if (!$row->claim_hash) {
                DB::table('edit_helper_pairings')->where('id', $pairing)->update(['claim_hash' => $hash, 'name' => $data['name'], 'platform' => $data['platform'],
                    'comparison_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT), 'updated_at' => now()]);
                $row = DB::table('edit_helper_pairings')->where('id', $pairing)->first();
            }
            // The same installation can recover an uncertain response for the short pairing lifetime.
            return ['status' => $row->approved_at ? 'approved' : 'waiting', 'comparison_code' => $row->comparison_code,
                'credential' => $row->approved_at ? Crypt::decryptString($row->credential_encrypted) : null];
        }), 'desktop-editing.claim');
        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function heartbeat(Request $request, DesktopEditingAccess $access)
    {
        $device = $access->device($request);
        $data = $request->validate(['photoshop_detected' => 'required|boolean', 'auto_upload' => 'required|boolean']);
        DB::table('edit_helper_devices')->where('id', $device->id)->update($data + ['last_seen_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['connected' => true]]);
    }

    public function claimSession(Request $request, string $session, DesktopEditingAccess $access)
    {
        $device = $access->device($request);
        return LockedWrite::run(fn () => DB::transaction(function () use ($session, $request, $device, $access) {
            $row = DB::table('edit_helper_sessions')->where('id', $session)->where('user_id', $device->user_id)->first();
            abort_unless($row && now()->lt($row->expires_at), 410, 'This editing session expired.');
            abort_unless(!$row->device_id || $row->device_id === $device->id, 403);
            abort_unless($row->claimed_at || now()->lt($row->launch_expires_at), 410, 'This launch link expired. Open Photoshop again from the image menu.');
            $file = $access->file($row->file_id, $request->user());
            abort_unless((int) $file->content_version === (int) $row->expected_version, 409, 'A newer image is current. Your local work is safe; review versions in the dashboard.');
            DB::table('edit_helper_sessions')->where('id', $session)->update(['device_id' => $device->id, 'claimed_at' => $row->claimed_at ?: now(), 'updated_at' => now()]);
            return response()->json(['data' => ['id' => $row->id, 'file_id' => $file->id, 'shoot_id' => $file->shoot_id, 'filename' => $file->filename,
                'expected_version' => $row->expected_version, 'expires_at' => $row->expires_at]]);
        }), 'desktop-editing.session');
    }

    public function download(Request $request, string $session, DesktopEditingAccess $access, MediaStorage $storage)
    {
        $row = $access->session($request, $session);
        $file = $access->file($row->file_id, $request->user());
        abort_unless((int) $file->content_version === (int) $row->expected_version, 409, 'The image changed. Reopen it from the dashboard.');
        return $storage->downloadResponse($file->path ?: $file->storage_path, $file->filename, ['Cache-Control' => 'private, no-store']);
    }

    public function upload(Request $request, string $session, DesktopEditingAccess $access, MediaVersionPublisher $publisher)
    {
        $row = $access->session($request, $session);
        $data = $request->validate(['file' => 'required|file|max:262144', 'request_id' => 'required|uuid']);
        $file = $access->file($row->file_id, $request->user());
        $upload = $request->file('file');
        $key = 'desktop:'.$row->id.':'.$data['request_id'];
        $prior = ShootFileVersion::where('request_key', $key)->first();
        $expected = (int) ($prior?->metadata['requested_version'] ?? $row->expected_version);
        $version = $publisher->stage($file, $upload->getRealPath(), $upload->getClientOriginalName(), $expected, $request->user(), $key,
            ['origin' => 'photoshop', 'desktop_session_id' => $row->id, 'device_id' => $row->device_id]);
        return response()->json(['data' => $version->present()], 202);
    }

    public function status(Request $request, string $session, ShootFileVersion $version, DesktopEditingAccess $access)
    {
        $row = $access->session($request, $session);
        abort_unless(($version->metadata['desktop_session_id'] ?? '') === $row->id, 404);
        if ($version->status === 'published' && $version->published_file_id) {
            // Compare-and-set prevents an older poll from rolling the session back.
            DB::table('edit_helper_sessions')->where('id', $row->id)->where('expected_version', $version->expected_version)
                ->update(['file_id' => $version->published_file_id, 'expected_version' => $version->version, 'updated_at' => now()]);
        }
        return response()->json(['data' => $version->present()]);
    }
}
