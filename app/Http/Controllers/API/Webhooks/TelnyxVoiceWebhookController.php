<?php

namespace App\Http\Controllers\API\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\TelnyxAi\TelnyxSignatureVerifier;
use App\Services\Voice\TelnyxWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelnyxVoiceWebhookController extends Controller
{
    public function __invoke(Request $request, TelnyxSignatureVerifier $verifier, TelnyxWebhookHandler $handler): JsonResponse
    {
        if (!$verifier->verify($request)) {
            return response()->json(['message' => 'Invalid Telnyx signature.'], 403);
        }

        $payload = $request->json()->all();
        $result = $handler->process($payload, $request->getContent());

        return response()->json($result);
    }
}
