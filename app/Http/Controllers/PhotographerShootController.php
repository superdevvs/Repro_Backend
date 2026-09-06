<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootPresenter;

class PhotographerShootController extends Controller
{
    //
    public function index(Request $request, ShootAuthorizationSupport $authorization, ShootPresenter $presenter)
    {
        $authorization->ensureRole(['photographer', 'admin', 'superadmin', 'editing_manager'], $request->user());
        $shoots = $authorization->scopeAccessibleShootMedia(Shoot::query(), $request->user())
            ->with(['client', 'service', 'services', 'photographer', 'files'])
            ->get();

        return response()->json([
            'data' => $shoots->map(fn (Shoot $shoot) => $presenter->transformOperationalShoot($shoot, false)),
        ]);
    }
}
