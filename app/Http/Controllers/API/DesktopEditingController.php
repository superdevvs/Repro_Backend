<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\DesktopEditingAccess;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

class DesktopEditingController extends Controller
{
    public function settings(Request $request, DesktopEditingAccess $access)
    {
        $access->staff($request->user());
        $ready = $access->released();
        return response()->json(['data' => ['available' => $ready,
            'reason' => $ready ? null : 'Installers are not released yet. Signing and Mac/Windows save-and-upload testing are required.',
            'installers' => $ready ? config('desktop_editing.installers') : [],
            'devices' => DB::table('edit_helper_devices')->where('user_id', $request->user()->id)->whereNull('revoked_at')
                ->get(['id', 'name', 'platform', 'photoshop_detected', 'auto_upload', 'last_seen_at', 'expires_at'])]]);
    }

    public function pair(Request $request, DesktopEditingAccess $access)
    {
        $access->staff($request->user());
        abort_unless($access->released(), 409, 'The signed helper release is not available yet. Use manual download and upload.');
        $id = (string) Str::uuid();
        DB::table('edit_helper_pairings')->insert(['id' => $id, 'user_id' => $request->user()->id, 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['id' => $id, 'launch_url' => 'repro-edit://pair/'.$id, 'expires_in' => 300]], 201);
    }

    public function pairing(Request $request, string $pairing, DesktopEditingAccess $access)
    {
        $access->staff($request->user());
        $row = DB::table('edit_helper_pairings')->where('id', $pairing)->where('user_id', $request->user()->id)->first();
        abort_unless($row && now()->lt($row->expires_at), 410, 'The pairing request expired. Start again.');
        return response()->json(['data' => ['id' => $row->id, 'name' => $row->name, 'platform' => $row->platform,
            'comparison_code' => $row->comparison_code, 'status' => $row->approved_at ? 'approved' : ($row->claim_hash ? 'claimed' : 'waiting')]]);
    }

    public function approve(Request $request, string $pairing, DesktopEditingAccess $access)
    {
        $access->staff($request->user());
        $data = $request->validate(['comparison_code' => 'required|string|size:6']);
        LockedWrite::run(fn () => DB::transaction(function () use ($request, $pairing, $data) {
            $row = DB::table('edit_helper_pairings')->where('id', $pairing)->where('user_id', $request->user()->id)->first();
            abort_unless($row && now()->lt($row->expires_at) && $row->claim_hash && hash_equals($row->comparison_code, $data['comparison_code']), 409, 'Pairing details changed or expired. Start again.');
            if ($row->approved_at) return;
            $id = (string) Str::uuid(); $secret = Str::random(64);
            DB::table('edit_helper_devices')->insert(['id' => $id, 'user_id' => $request->user()->id, 'name' => $row->name, 'platform' => $row->platform,
                'credential_hash' => hash('sha256', $secret), 'expires_at' => now()->addDays(90), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('edit_helper_pairings')->where('id', $pairing)->update(['device_id' => $id, 'approved_at' => now(),
                'credential_encrypted' => Crypt::encryptString('reproedit.'.$id.'.'.$secret), 'updated_at' => now()]);
        }), 'desktop-editing.approve');
        return response()->json(['message' => 'Device approved.']);
    }

    public function revoke(Request $request, string $device, DesktopEditingAccess $access)
    {
        $access->staff($request->user());
        DB::table('edit_helper_devices')->where('id', $device)->where('user_id', $request->user()->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Device disconnected. Local working files remain on that computer.']);
    }

    public function launch(Request $request, Shoot $shoot, ShootFile $file, DesktopEditingAccess $access)
    {
        abort_unless($access->released(), 409, 'The signed helper release is not available yet. Download the image and use Upload saved edit after exporting from Photoshop.');
        abort_unless((int) $file->shoot_id === (int) $shoot->id, 404);
        $access->file($file->id, $request->user());
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        abort_unless((int) $file->content_version === (int) $data['expected_version'], 409, 'The image changed. Refresh before opening Photoshop.');
        abort_unless(DB::table('edit_helper_devices')->where('user_id', $request->user()->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 409, 'Connect the helper in Settings → Desktop editing first.');
        $id = (string) Str::uuid();
        DB::table('edit_helper_sessions')->insert(['id' => $id, 'user_id' => $request->user()->id, 'file_id' => $file->id, 'expected_version' => $file->content_version,
            'launch_expires_at' => now()->addMinutes(5), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['id' => $id, 'launch_url' => 'repro-edit://open/'.$id]], 201);
    }
}
