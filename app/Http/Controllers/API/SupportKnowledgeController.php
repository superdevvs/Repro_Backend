<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ReproAi\SupportKnowledgeBase;
use Illuminate\Http\Request;

class SupportKnowledgeController extends Controller
{
    public function __construct(private SupportKnowledgeBase $knowledge) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:300'],
            'category' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = trim($validated['query'] ?? '');
        $articles = $this->knowledge->search($query, $request->user(), $validated['category'] ?? null);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 12);

        return response()->json([
            'data' => array_slice($articles, ($page - 1) * $perPage, $perPage),
            'meta' => [
                ...$this->meta($request), 'query' => $query,
                'categories' => $this->knowledge->categories($request->user()),
                'pagination' => [
                    'current_page' => $page, 'per_page' => $perPage, 'total' => count($articles),
                    'last_page' => max(1, (int) ceil(count($articles) / $perPage)),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $articleId)
    {
        $article = $this->knowledge->find($articleId, $request->user());
        abort_if($article === null, 404);

        return response()->json(['data' => $article, 'meta' => $this->meta($request)]);
    }

    private function meta(Request $request): array
    {
        return [
            'role' => $this->knowledge->role($request->user()), 'version' => $this->knowledge->version(),
            'help_only' => $this->knowledge->isHelpOnly($request->user()),
        ];
    }
}
