<?php

namespace App\Http\Middleware;

use App\Models\Shoot;
use App\Models\ShootUnit;
use App\Models\IguideOfflineUploadSession;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootUnitTourScope;
use Closure;
use Illuminate\Http\Request;

class ScopeShootUnitTour
{
    public function handle(Request $request, Closure $next)
    {
        $shoot = $request->route('shoot');
        $shoot = $shoot instanceof Shoot ? $shoot : Shoot::findOrFail($shoot);
        $unit = $request->route('unit');
        $unit = $unit instanceof ShootUnit ? $unit : $shoot->units()->findOrFail($unit);
        abort_unless((string) $unit->shoot_id === (string) $shoot->id, 404);
        app(ShootAuthorizationSupport::class)->ensureShootAccess($shoot, $request->user());
        $upload = $request->route('upload');
        $upload = $upload instanceof IguideOfflineUploadSession ? $upload : ($upload ? IguideOfflineUploadSession::findOrFail($upload) : null);
        if ($upload) abort_unless((string) $upload->shoot_id === (string) $shoot->id && $unit->serviceItems()->whereKey($upload->shoot_service_id)->exists(), 404);
        $scope = app(ShootUnitTourScope::class);
        $line = $scope->resolveLine($unit, $upload?->shoot_service_id ?? $request->route('tourLine') ?? $request->input('shoot_service_id'), 'iguide');
        if (strtolower((string) $request->user()?->role) === 'client') {
            abort_unless($scope->isReleased($line), 404, 'This unit’s tour is not available yet.');
        }
        if ($upload) abort_unless((string) $upload->shoot_id === (string) $shoot->id && (string) $upload->shoot_service_id === (string) $line->id, 404);
        $request->route()->setParameter('shoot', $scope->project($shoot, $unit, $line));
        $request->route()->forgetParameter('unit');
        $request->route()->forgetParameter('tourLine');
        return $next($request);
    }
}
