<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Services\Messaging\EmailComposeAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailComposeAssistantController extends Controller
{
    public function __invoke(Request $request, EmailComposeAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:write,shorter,warmer,clearer,subject,from_shoot'],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:20000'],
            'variables' => ['nullable', 'array'],
        ]);

        if ($validated['mode'] === 'write' && trim((string) ($validated['instruction'] ?? '')) === '') {
            return response()->json(['message' => 'Tell Robbie what the email should say.'], 422);
        }

        try {
            $draft = $assistant->draft(
                $validated['mode'],
                $validated['instruction'] ?? null,
                (string) ($validated['subject'] ?? ''),
                (string) ($validated['body'] ?? ''),
                $validated['variables'] ?? [],
            );
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Robbie could not write that email. Try again.'], 502);
        }

        return response()->json($draft);
    }
}
