<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ReproAi\LlmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Report whether Robbie's language model is reachable and configured.
 *
 * The chat pipeline degrades to the deterministic rule-based orchestrator in
 * three places — a constructor failure, a call failure, and a missing API key —
 * and all three only write to the log. Nothing surfaces to an operator, so
 * "Robbie is not working" could mean the model was never consulted at all
 * (meeting 26 Jul 2026 + A1.docx Robbie screenshot).
 *
 * This gives a definite answer instead of a guess.
 */
class RobbieHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $configured = ! empty(config('services.openai.api_key'));
        $model = (string) config('services.openai.model', 'gpt-4o');

        $result = [
            'configured' => $configured,
            'model' => $model,
            'reachable' => false,
            'engine' => $configured ? 'openai' : 'rule_based',
            'detail' => null,
        ];

        if (! $configured) {
            $result['detail'] = 'OPENAI_API_KEY is not set, so every reply is coming from the rule-based flows.';

            return response()->json($result, 200);
        }

        try {
            $client = app(LlmClient::class);

            // Minimal round trip: enough to prove credentials and quota without
            // spending a meaningful number of tokens.
            $client->chatCompletion(
                [['role' => 'user', 'content' => 'ping']],
                [],
                stream: false,
                options: ['max_tokens' => 1]
            );

            $result['reachable'] = true;
            $result['detail'] = 'Language model responded normally.';
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();

            $result['engine'] = 'rule_based';
            $result['detail'] = $this->classify($message);
            $result['error'] = $message;

            Log::warning('Robbie health check failed', ['error' => $message]);
        }

        return response()->json($result, 200);
    }

    /**
     * Turn a provider error into something an operator can act on.
     */
    private function classify(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'quota') || str_contains($lower, 'insufficient_quota') || str_contains($lower, '429')) {
            return 'The account is out of quota or rate limited, so Robbie is falling back to the rule-based flows.';
        }

        if (str_contains($lower, '401') || str_contains($lower, 'invalid_api_key') || str_contains($lower, 'unauthorized')) {
            return 'The API key was rejected. Check OPENAI_API_KEY.';
        }

        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate')) {
            return 'TLS verification failed when calling the provider. Check the CA bundle on this host.';
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'The provider did not respond in time.';
        }

        return 'The language model call failed; Robbie is answering from the rule-based flows.';
    }
}
