<?php

namespace App\Http\Controllers\API\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Voice\VapiWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VapiWebhookController extends Controller
{
    public function __invoke(Request $request, VapiWebhookHandler $handler): JsonResponse
    {
        $secret = (string) config('services.vapi.server_secret', '');
        if ($secret !== '' && !$this->validSecret($request, $secret)) {
            return response()->json(['message' => 'Invalid Vapi webhook secret.'], 403);
        }

        $payload = $request->json()->all();
        $result = $handler->process($payload, $request->getContent());

        return response()->json($result);
    }

    private function validSecret(Request $request, string $secret): bool
    {
        $headerSecret = (string) ($request->header('X-Vapi-Secret') ?: $request->header('X-Webhook-Secret'));
        $authorization = (string) $request->header('Authorization');

        return hash_equals($secret, $headerSecret)
            || hash_equals('Bearer ' . $secret, $authorization);
    }
}
