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
        $input = $request->validate(['before_id' => 'sometimes|integer|min:1']);
        $rows = ShootGuestShare::where('shoot_id', $shoot->id)->with(['creator', 'shoot'])
            ->when(isset($input['before_id']), fn ($query) => $query->where('id', '<', $input['before_id']))
            ->latest('id')->limit(101)->get();
        $page = $rows->take(100);
        return response()->json(['data' => $page->map(fn ($share) => $this->shares->managementData($share))->values(),
            'meta' => ['next_cursor' => $rows->count() > 100 ? $page->last()->id : null]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Shoot $shoot)
    {
        $this->shares->authorizeManager($shoot, $request->user());
        if (! $request->exists('emails')) {
            $request->merge(['email' => is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : $request->input('email')]);
            $input = $request->validate(['email' => 'required|email:rfc|max:254']);
            $result = $this->shares->createOrReuse($shoot, $request->user(), $input['email']);
            return response()->json(['message' => $result['outcome'] === 'sent' ? 'Invitation sent.' : 'An active invitation already exists.',
                'data' => $result['share']->only(['id', 'email', 'expires_at'])], 201)->header('Cache-Control', 'private, no-store');
        }
        $emails = $request->input('emails');
        if (is_array($emails)) $request->merge(['emails' => array_map(fn ($email) => is_string($email) ? strtolower(trim($email)) : $email, $emails)]);
        $input = $request->validate(['email' => 'prohibited', 'emails' => 'required|array|min:1|max:20',
            'emails.*' => 'required|string|email:rfc|max:254']);
        $results = [];
        foreach (array_values(array_unique($input['emails'])) as $email) {
            try {
                $result = $this->shares->createOrReuse($shoot, $request->user(), $email);
                $results[] = ['email' => $email, 'outcome' => $result['outcome'], 'share' => $this->shares->managementData($result['share'])];
            } catch (\Throwable) {
                $results[] = ['email' => $email, 'outcome' => 'failed', 'message' => 'The invitation could not be sent. Retry this recipient.'];
            }
        }
        return response()->json(['data' => $results, 'summary' => [
            'sent' => count(array_filter($results, fn ($row) => $row['outcome'] === 'sent')),
            'existing' => count(array_filter($results, fn ($row) => $row['outcome'] === 'existing')),
            'failed' => count(array_filter($results, fn ($row) => $row['outcome'] === 'failed')),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function link(Request $request, Shoot $shoot, int $shareId)
    {
        $this->shares->authorizeManager($shoot, $request->user());
        $share = ShootGuestShare::where('shoot_id', $shoot->id)->findOrFail($shareId);
        return response()->json(['data' => ['url' => $this->shares->copyLink($shoot, $request->user(), $share)]])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function resend(Request $request, Shoot $shoot, int $shareId)
    {
        $this->shares->authorizeManager($shoot, $request->user());
        $share = ShootGuestShare::where('shoot_id', $shoot->id)->findOrFail($shareId);
        $result = $this->shares->resend($shoot, $request->user(), $share);
        return response()->json(['message' => $result['replacement'] ? 'A new invitation was sent. Previous links are unchanged.' : 'Invitation resent.',
            'data' => $this->shares->managementData($result['share']), 'replacement' => $result['replacement']])
            ->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, Shoot $shoot, int $shareId)
    {
        $this->shares->authorizeManager($shoot, $request->user());
        $share = ShootGuestShare::where('shoot_id', $shoot->id)->findOrFail($shareId);
        LockedWrite::run(fn () => $share->update(['revoked_at' => now(), 'code_hash' => null,
            'invitation_status' => 'revoked']), 'guest-share-revoke');
        return response()->json(['message' => 'Access revoked.'])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $token)
    {
        $share = $this->shares->resolve($token);
        $verified = $this->shares->verified($request, $share);
        [$name, $domain] = explode('@', $share->email, 2);
        return response()->json(['verified' => $verified, 'recipient' => mb_substr($name, 0, 1).'***@'.$domain,
            'sender_name' => $this->shares->senderName($share),
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
