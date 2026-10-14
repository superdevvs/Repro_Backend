<?php

namespace App\Http\Middleware;

use App\Models\VoiceCall;
use App\Services\Voice\VoiceLinePolicyService;
use Closure;
use Illuminate\Http\Request;

class VoiceLineAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        $value = $request->route('call');
        $call = $value instanceof VoiceCall ? $value : ($value ? VoiceCall::find($value) : null);
        if ($call) {
            abort_unless(app(VoiceLinePolicyService::class)->canAccess($request->user(), $call), 403, 'You are not assigned to this business line.');
        }

        return $next($request);
    }
}
