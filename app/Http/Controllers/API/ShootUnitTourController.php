<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\IngestIguideAssetsJob;
use App\Models\Shoot;
use App\Models\ShootUnit;
use App\Services\CubiCasaService;
use App\Services\IguideService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootUnitTourScope;
use App\Services\Shoots\ShootRealtorOptionsService;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ShootUnitTourController extends Controller
{
    public function __construct(private ShootUnitTourScope $scope, private ShootAuthorizationSupport $access) {}

    private function authorizeUnit(Request $request, Shoot $shoot, ShootUnit $unit, bool $operator = false): void
    {
        abort_unless((string) $unit->shoot_id === (string) $shoot->id, 404);
        $this->access->ensureShootAccess($shoot, $request->user());
        $ownsShoot = $request->user()?->role === 'client' && (string) $shoot->client_id === (string) $request->user()->id;
        $assignedRep = in_array(strtolower((string) $request->user()?->role), ['salesrep', 'sales_rep'], true) && (string) $shoot->rep_id === (string) $request->user()->id;
        $videoEditor = ! $operator && $this->access->canEditVideoTourLinks($shoot, $request->user(), $unit);
        abort_unless($this->access->canManageShootOperations($request->user()) || (! $operator && ($ownsShoot || $assignedRep || $videoEditor)), 403);
    }

    public function update(Request $request, Shoot $shoot, ShootUnit $unit)
    {
        $this->authorizeUnit($request, $shoot, $unit);
        $isVideoEditor = $request->user()->role === 'editor';
        if ($isVideoEditor) {
            abort_if(array_diff(array_keys($request->all()), ['tour_links']), 403);
            abort_if(is_array($request->input('tour_links')) && array_diff(array_keys($request->input('tour_links')), ShootAuthorizationSupport::VIDEO_TOUR_LINK_KEYS), 403);
        }
        $data = $request->validate([
            'shoot_service_id' => 'sometimes|nullable|integer', 'tour_links' => 'sometimes|array', 'tour_links.*' => 'nullable',
            'tour_links.realtor_client_id' => 'sometimes|nullable|integer|exists:users,id',
            'bedrooms' => 'sometimes|nullable|integer|min:0|max:1000',
            'bathrooms' => 'sometimes|nullable|numeric|min:0|max:1000',
            'sqft' => 'sometimes|nullable|integer|min:1|max:100000000',
            'property_status' => 'sometimes|nullable|string|in:available,coming_soon,pending,sold,rented',
            'listing_type' => 'sometimes|nullable|string|in:for_sale,for_rent',
            'include_common_area_media' => 'sometimes|boolean',
            'reset_building_defaults' => 'sometimes|boolean',
            'iguide_property_id' => 'sometimes|nullable|string|max:255',
            'iguide_work_order_id' => 'sometimes|nullable|string|max:255',
        ]);
        $isOperator = $this->access->canManageShootOperations($request->user());
        if (! $isOperator && ! $isVideoEditor) {
            abort_if($request->hasAny(['iguide_property_id', 'iguide_work_order_id', 'reset_building_defaults']), 403);
            $allowed = ['property_mls', 'property_price', 'property_lot_size', 'property_description', 'realtor_client_id'];
            if ($request->user()->role !== 'client') {
                abort_if(array_diff(array_keys($data), ['tour_links']), 403);
                $allowed = ['realtor_client_id'];
            }
            abort_if(array_diff(array_keys($data['tour_links'] ?? []), $allowed), 403);
        }
        if (array_key_exists('realtor_client_id', $data['tour_links'] ?? [])) {
            abort_unless(app(ShootRealtorOptionsService::class)->canAssignRealtor($request->user(), isset($data['tour_links']['realtor_client_id']) ? (int) $data['tour_links']['realtor_client_id'] : null), 403, 'Choose yourself or a linked account as the realtor.');
        }
        $line = $request->hasAny(['iguide_property_id', 'iguide_work_order_id']) ? $this->scope->resolveLine($unit, $request->input('shoot_service_id'), 'iguide') : null;
        LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $unit, $data, $line) {
            $unit->refresh();
            $incomingLinks = $data['tour_links'] ?? [];
            foreach (ShootUnitTourScope::INHERITED_SETTINGS as $key) {
                if (! array_key_exists($key, $unit->tour_links ?? []) && array_key_exists($key, $incomingLinks) && $incomingLinks[$key] === ($shoot->tour_links[$key] ?? null)) unset($incomingLinks[$key]);
            }
            $links = array_replace($unit->tour_links ?? [], $incomingLinks);
            if (! empty($data['reset_building_defaults'])) {
                $links = Arr::except($links, ShootUnitTourScope::INHERITED_SETTINGS);
            }
            $unit->tour_links = $links;
            $provider = array_replace($unit->provider_data ?? [], Arr::only($data, ['iguide_property_id', 'iguide_work_order_id']));
            if ($line) {
                $provider['iguide_service_line_id'] = $line->id;
                $provider['lines'][$line->id] = array_replace($provider['lines'][$line->id] ?? [], Arr::only($data, ['iguide_property_id', 'iguide_work_order_id']));
            }
            $unit->provider_data = $provider;
            foreach (['bedrooms' => 'beds', 'bathrooms' => 'baths', 'sqft' => 'sqft', 'property_status' => 'property_status', 'listing_type' => 'listing_type', 'include_common_area_media' => 'include_common_area_media'] as $input => $column) {
                if (array_key_exists($input, $data)) $unit->{$column} = $data[$input];
            }
            $dimensionsChanged = $unit->isDirty(['beds', 'baths', 'sqft']);
            $unit->save();
            if ($dimensionsChanged) Shoot::whereKey($shoot->id)->increment('units_revision');
        }), 'shoot-unit.tour-update');
        $unit->refresh();
        if ($isVideoEditor) {
            return response()->json(['data' => ['id' => $unit->id, 'tour_links' => Arr::only($unit->tour_links ?? [], ShootAuthorizationSupport::VIDEO_TOUR_LINK_KEYS)]]);
        }
        if (! $isOperator) {
            $lines = $unit->serviceItems()->where('is_deliverable', true)->whereNotIn('delivery_status', ['cancelled'])->get();
            $safe = $this->scope->releasedTourData($unit, $lines->filter(fn ($line) => $this->scope->isReleased($line))->pluck('id')->all(), $lines->count());
            return response()->json(['data' => array_replace($unit->only(['id', 'beds', 'baths', 'sqft', 'property_status', 'listing_type', 'include_common_area_media']), ['tour_links' => $safe['tour_links']])]);
        }
        return response()->json(['data' => $unit]);
    }

    public function provider(Request $request, Shoot $shoot, ShootUnit $unit, string $provider, string $operation)
    {
        $this->authorizeUnit($request, $shoot, $unit, true);
        abort_unless(in_array($provider, ['iguide', 'cubicasa'], true) && in_array($operation, ['identifiers', 'sync', 'order'], true), 404);
        $data = $request->validate(['shoot_service_id' => 'nullable|integer', 'cubicasa_order_id' => 'nullable|string|max:255', 'cubicasa_external_id' => 'nullable|string|max:255']);
        $line = $this->scope->resolveLine($unit, $request->input('shoot_service_id'), $provider);
        if ($operation === 'identifiers') {
            abort_unless($provider === 'cubicasa', 404);
            $view = $this->scope->project($shoot, $unit, $line);
            $view->forceFill(Arr::only($data, ['cubicasa_order_id', 'cubicasa_external_id']));
            $this->scope->persistProvider($view);
            return response()->json(['success' => true, 'unit' => $unit]);
        }
        $view = $this->scope->project($shoot, $unit, $line);
        abort_if($view->isInternalTestShoot(), 422, 'Provider actions are unavailable for internal test shoots.');
        if ($provider === 'iguide') {
            abort_unless($view->iguide_property_id, 422, 'Set this unit’s iGUIDE property ID before syncing.');
            $parsed = app(IguideService::class)->syncShoot($view);
            if (! $parsed) return response()->json(['success' => false, 'message' => 'iGUIDE did not return this unit. Signed app tokens may require the ready webhook.'], 409);
        } else {
            $service = app(CubiCasaService::class);
            $parsed = $operation === 'order' ? $service->createOrder($view, $request->user()) : $service->syncShoot($view);
            if (! $parsed) return response()->json(['success' => false, 'message' => 'CubiCasa could not complete this unit’s request.', 'mode' => $service->getLastFailureReason()], 422);
        }
        LockedWrite::run(fn () => DB::transaction(function () use ($unit, $provider, $line) {
            $unit->refresh();
            $unit->provider_data = array_replace($unit->provider_data ?? [], [$provider.'_service_line_id' => $line->id]);
            $unit->save();
        }), 'shoot-unit.provider-line');
        $floorplans = is_array($parsed['floorplans'] ?? null) ? $parsed['floorplans'] : [];
        if ($floorplans) {
            $job = $provider === 'iguide' ? IngestIguideAssetsJob::class : IngestCubiCasaAssetsJob::class;
            $job::dispatch($shoot->id, $floorplans, $line->id);
        }
        return response()->json(['success' => true, 'queued_assets' => count($floorplans), 'unit' => $unit->fresh()]);
    }
}
