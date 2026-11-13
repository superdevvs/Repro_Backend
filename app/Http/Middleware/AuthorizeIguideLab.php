<?php

namespace App\Http\Middleware;

use App\Models\IguideLabProject;
use Closure;
use Illuminate\Http\Request;

/** A lab capability has no Dashboard account, billing, or ordinary Studio authority. */
class AuthorizeIguideLab
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?? '';
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token), 401, 'Unlock this lab session to continue.');
        $project = IguideLabProject::where('token_hash', hash('sha256', $token))->first();
        abort_unless($project?->active(), 401, 'This lab session has expired or was revoked.');
        $request->attributes->set('iguide_lab_project', $project);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
