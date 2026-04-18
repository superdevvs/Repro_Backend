<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Services\Messaging\EmailOpsSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailOpsSummaryController extends Controller
{
    public function __invoke(Request $request, EmailOpsSummaryService $emailOpsSummaryService): JsonResponse
    {
        $validated = $request->validate([
            'sample' => ['nullable', 'integer', 'min:1', 'max:25'],
            'queued_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        return response()->json($emailOpsSummaryService->build(
            (int) ($validated['sample'] ?? 5),
            (int) ($validated['queued_minutes'] ?? 5),
        ));
    }
}
