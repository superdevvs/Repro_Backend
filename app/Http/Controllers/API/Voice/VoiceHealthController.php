<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Services\Voice\VoiceHealthService;
use Illuminate\Http\JsonResponse;

class VoiceHealthController extends Controller
{
    public function __invoke(VoiceHealthService $health): JsonResponse
    {
        return response()->json($health->summary());
    }
}
