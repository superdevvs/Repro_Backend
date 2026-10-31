<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Services\ListingStudio\ListingStudioTrialAdmission;
use App\Services\ListingStudio\ListingStudioVisitorIp;
use Illuminate\Http\Request;

class ListingStudioTrialAdmissionController extends Controller
{
    public function mint(Request $request, ListingStudioTrialAdmission $admissions)
    {
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/', $key)) {
            throw new PublicApiException('A valid edit request identifier is required.', 'idempotency_key_required', 422);
        }

        return response()->json(['success' => true, 'data' => $admissions->mint($request->attributes->get('studio_session_hash'), $key)])
            ->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, ListingStudioTrialAdmission $admissions, ListingStudioVisitorIp $ips)
    {
        $input = $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'challengeToken' => ['required', 'string', 'max:2048']]);
        $data = $admissions->verify($input['token'], $input['challengeToken'], $ips->resolve($request), (string) $request->header('Origin'));

        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'no-store');
    }
}
