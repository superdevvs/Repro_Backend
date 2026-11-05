<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\ClientBrandingAccess;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class BrokerageLogoController extends Controller
{
    public function __construct(private ClientBrandingAccess $access) {}

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $user), 403);

        return $this->response($request, $user);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        abort_unless($this->access->canManage($request->user(), $user), 403);
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ]);

        $disk = Storage::disk('public');
        $path = $request->file('logo')->store('branding/brokerage/'.$user->id, 'public');
        abort_unless(is_string($path) && $path !== '', 500, 'The brokerage logo could not be uploaded. Please try again.');
        $url = $disk->url($path);
        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
        }

        try {
            $this->saveLogo($request, $user, $url);
        } catch (\Throwable $exception) {
            // Only a new, uncommitted upload is disposable. Existing branding files
            // can still be referenced by shared pages or other branding settings.
            $disk->delete($path);
            throw $exception;
        }

        return $this->response($request, $user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($this->access->canManage($request->user(), $user), 403);
        $this->saveLogo($request, $user, null);

        return $this->response($request, $user);
    }

    private function saveLogo(Request $request, User $user, ?string $url): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($request, $user, $url) {
            // Recheck assignment after upload, so reassignment revokes a rep's write.
            abort_unless($this->access->canManage($request->user(), $user->fresh()), 403);
            $existing = DB::table('user_branding')->where('user_id', $user->id)->first();
            DB::table('user_branding')->updateOrInsert(['user_id' => $user->id], [
                'logo' => $url,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }), 'brokerage-logo');
    }

    private function response(Request $request, User $user): JsonResponse
    {
        return response()->json(['data' => [
            'logo' => DB::table('user_branding')->where('user_id', $user->id)->value('logo'),
            'can_manage' => $this->access->canManage($request->user(), $user->fresh()),
        ]]);
    }
}
