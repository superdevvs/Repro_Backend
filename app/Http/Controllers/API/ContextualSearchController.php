<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Search\ContextualSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextualSearchController extends Controller
{
    public function __construct(private ContextualSearchService $search) {}

    public function scopes(Request $request): JsonResponse
    {
        return response()->json(['enabled' => (bool) config('contextual-search.enabled'), 'scopes' => $this->search->scopes($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(config('contextual-search.enabled'), 404);
        [$term, $filters] = $this->input($request);
        $request->validate(['scope' => 'nullable|string|max:50', 'page' => 'nullable|integer|min:1|max:10000']);
        return response()->json($this->search->search($request, $request->query('scope', 'everywhere'), $term, $filters, (int) $request->query('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $scope, string $id): JsonResponse
    {
        abort_unless(config('contextual-search.enabled'), 404);
        abort_unless(strlen($id) <= 100 && $scope !== 'everywhere', 404);
        [$term, $filters] = $this->input($request);
        return response()->json(['data' => $this->search->preview($request, $scope, $id, $term, $filters)])->header('Cache-Control', 'private, no-store');
    }

    private function input(Request $request): array
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'filters' => 'nullable|string|max:4000']);
        $filters = json_decode($data['filters'] ?? '{}', true);
        abort_unless(is_array($filters), 422, 'Invalid search filters.');
        foreach ($filters as $key => $value) {
            abort_unless(is_string($key) && strlen($key) <= 50 && ($value === null || is_scalar($value)) && (!is_string($value) || strlen($value) <= 200), 422, 'Invalid search filter.');
        }
        return [trim($data['q'] ?? ''), $filters];
    }
}
