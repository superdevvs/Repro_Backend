<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\PublicTourVisibility;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Switches tour content on or off for the public pages; saved links are never edited here. */
class ShootTourVisibilityController extends Controller
{
    public function __invoke(Request $request, Shoot $shoot, ShootAuthorizationSupport $access, PublicTourVisibility $visibility): JsonResponse
    {
        $actor = $request->user();
        abort_unless($access->canManageTourVisibility($shoot, $actor), 403);
        $data = $request->validate(['unit_id' => 'sometimes|integer|min:1']
            + array_fill_keys(PublicTourVisibility::KEYS, 'sometimes|required|boolean'));
        $settings = array_map(static fn ($value): bool => (bool) $value, Arr::only($data, PublicTourVisibility::KEYS));
        if ($settings === [] || array_diff(array_keys($request->all()), array_keys($data))) {
            throw ValidationException::withMessages(['visibility' => 'Submit only public tour visibility settings and an optional unit ID.']);
        }

        $saved = LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $actor, $access, $visibility, $data, $settings) {
            $fresh = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            abort_unless($access->canManageTourVisibility($fresh, $actor->fresh()), 403);

            $target = isset($data['unit_id'])
                ? $fresh->units()->lockForUpdate()->findOrFail($data['unit_id'])
                : $fresh;
            $savedLinks = $target->tour_links ?? [];
            if (is_string($savedLinks)) {
                $savedLinks = json_decode($savedLinks, true);
            }
            abort_unless(is_array($savedLinks), 422, 'The saved tour links are invalid.');
            $links = array_replace($savedLinks, $settings);
            $target->tour_links = $links;
            if ($target->isDirty('tour_links')) {
                $target->save();
                if ($target !== $fresh) {
                    $fresh->touch();
                }
            }

            return $visibility->visibility($links);
        }), 'shoot.tour-visibility');

        return response()->json(['data' => $saved]);
    }
}
