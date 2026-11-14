<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootGuestShare;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootGuestOverview;
use App\Services\Shoots\ShootGuestShareService;
use App\Support\LockedWrite;
use Illuminate\Http\Request;

class ShootGuestShareController extends Controller
{
    public function __construct(private ShootGuestShareService $shares, private ShootGuestOverview $overview) {}

    public function index(Request $request, Shoot $shoot)
    {
        $this->shares->authorizeManager($shoot, $request->user());
        return response()->json(['data' => ShootGuestShare::where('shoot_id', $shoot->id)->latest()->limit(100)
            ->get(['id', 'email', 'expires_at', 'revoked_at', 'last_verified_at', 'created_at'])]);
    }

    public function store(Request $request, Shoot $shoot)
    {
        $this->shares->authorizeManager($shoot, $request->user());
        $input = $request->validate(['email' => 'required|email:rfc|max:254']);
        $share = $this->shares->create($shoot, $request->user(), $input['email']);
        return response()->json(['message' => 'Invitation sent.', 'data' => $share->only(['id', 'email', 'expires_at'])], 201);
    }

    public function revoke(Request $request, Shoot $shoot, int $shareId)
    {
        $this->shares->authorizeManager($shoot, $request->user());
        $share = ShootGuestShare::where('shoot_id', $shoot->id)->findOrFail($shareId);
        LockedWrite::run(fn () => $share->update(['revoked_at' => now(), 'code_hash' => null]), 'guest-share-revoke');
        return response()->json(['message' => 'Access revoked.']);
    }

    public function show(Request $request, string $token)
    {
        $share = $this->shares->resolve($token);
        $verified = $this->shares->verified($request, $share);
        [$name, $domain] = explode('@', $share->email, 2);
        return response()->json(['verified' => $verified, 'recipient' => mb_substr($name, 0, 1).'***@'.$domain,
            'data' => $verified ? $this->overview->build($share, $token) : null])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function code(string $token)
    {
        $this->shares->requestCode($this->shares->resolve($token));
        return response()->json(['message' => 'Verification code sent.'])->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, string $token)
    {
        $input = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/D']]);
        $share = $this->shares->resolve($token);
        $session = $this->shares->verify($share, $input['code']);
        return response()->json(['message' => 'Email verified.'])->header('Cache-Control', 'private, no-store')
            ->cookie($this->shares->cookieName($share), $session, 720, '/api/public/guest-shares/'.$token,
                null, $request->isSecure(), true, false, 'strict');
    }

    public function file(Request $request, string $token, ShootFile $file)
    {
        $share = $this->shares->resolve($token);
        abort_unless($this->shares->verified($request, $share), 401, 'Verify your email to continue.');
        abort_unless((int) $file->shoot_id === (int) $share->shoot_id
            && $this->overview->allowsFile($share->shoot, $file), 404);
        $media = app(MediaStorage::class);
        $original = app(\App\Services\Shoots\DeliveryPlanService::class)->contentKind($file) === 'video' || $request->boolean('download');
        $path = $original ? ($file->path ?: $file->storage_path)
            : ($file->web_path ?: $file->grid_path ?: $file->thumbnail_path ?: $file->path ?: $file->storage_path);
        abort_unless(is_string($path) && $path !== '', 404);
        if (preg_match('#^https?://#i', $path)) {
            return redirect()->away($path)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }
        if ($request->boolean('download')) {
            return $media->downloadResponse($path, basename($file->filename ?: 'download'), ['Cache-Control' => 'private, no-store']);
        }
        return redirect()->away($media->temporaryUrl($path, 300))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
