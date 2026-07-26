<?php

namespace App\Http\Controllers\API;

use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudioTemplateController extends StudioController
{
    private const WORKFLOW_IDS = [
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
        'batch-ai-jobs',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'view');

        $templates = $this->scopeStudioQuery(Template::query(), $user)
            ->orderByDesc('updated_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Template $template): array => $this->presentTemplate($template))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $templates,
            'meta' => ['total' => $templates->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'create');

        $validated = $request->validate($this->templateRules());
        $template = Template::query()->create([
            'team_id' => $this->scopeTeamId($user),
            'created_by' => (int) $user->getAuthIdentifier(),
            'name' => trim($validated['name']),
            'workflow_id' => $validated['workflowId'],
            'config' => $validated['config'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->presentTemplate($template->fresh()),
        ], 201);
    }
    public function update(Request $request, string $template): JsonResponse
    {
        return DB::transaction(function () use ($request, $template): JsonResponse {
            $record = Template::query()->whereKey($template)->lockForUpdate()->first();
            if ($record === null) {
                abort(404, 'Template not found.');
            }

            $this->authorizeStudioAction($request->user(), 'update', $record);
            $validated = $request->validate([
                ...$this->templateRules(),
                'version' => ['required', 'integer', 'min:1'],
            ]);
            if ((int) $validated['version'] !== (int) $record->version) {
                return $this->staleVersionResponse($record);
            }

            $record->fill([
                'name' => trim($validated['name']),
                'workflow_id' => $validated['workflowId'],
                'config' => $validated['config'],
            ])->save();

            return response()->json([
                'success' => true,
                'data' => $this->presentTemplate($record->fresh()),
            ]);
        });
    }

    public function destroy(Request $request, string $template): JsonResponse
    {
        return DB::transaction(function () use ($request, $template): JsonResponse {
            $record = Template::query()->whereKey($template)->lockForUpdate()->first();
            if ($record === null) {
                abort(404, 'Template not found.');
            }

            $this->authorizeStudioAction($request->user(), 'delete', $record);
            $validated = $request->validate([
                'version' => ['required', 'integer', 'min:1'],
            ]);
            if ((int) $validated['version'] !== (int) $record->version) {
                return $this->staleVersionResponse($record);
            }

            $id = (string) $record->id;
            $version = (int) $record->version;
            $deletedAt = now()->toISOString();
            $record->delete();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $id,
                    'deleted' => true,
                    'version' => $version,
                    'deletedAt' => $deletedAt,
                ],
            ]);
        });
    }
    private function templateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'workflowId' => ['required', 'string', Rule::in(self::WORKFLOW_IDS)],
            'config' => ['present', 'array'],
        ];
    }

    private function presentTemplate(Template $template): array
    {
        $config = $template->config ?? [];

        return [
            'id' => (string) $template->id,
            'name' => (string) $template->name,
            'workflowId' => (string) $template->workflow_id,
            'config' => $config,
            'version' => (int) $template->version,
            'createdBy' => (int) $template->created_by,
            'createdAt' => $template->created_at?->toISOString(),
            'updatedAt' => $template->updated_at?->toISOString(),
            'projectDefaults' => [
                'templateId' => (string) $template->id,
                'workflowId' => (string) $template->workflow_id,
                'workflowConfig' => $config,
            ],
        ];
    }

    private function staleVersionResponse(Template $template): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'stale_version',
                'message' => 'The template has changed since it was loaded.',
                'latestVersion' => (int) $template->version,
            ],
            'data' => $this->presentTemplate($template),
        ], 409);
    }
}
