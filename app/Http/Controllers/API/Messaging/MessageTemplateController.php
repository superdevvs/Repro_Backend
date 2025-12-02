<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $channel = $request->query('channel', 'EMAIL');
        $scope = $request->query('scope');
        $category = $request->query('category');
        $is_active = $request->query('is_active');

        $templates = MessageTemplate::query()
            ->when($channel, fn ($query) => $query->where('channel', $channel))
            ->when($scope, fn ($query) => $query->where('scope', $scope))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when($is_active !== null, fn ($query) => $query->where('is_active', (bool) $is_active))
            ->with(['creator', 'updater'])
            ->orderBy('name')
            ->get();

        return response()->json($templates);
    }

    public function show(MessageTemplate $template): JsonResponse
    {
        return response()->json($template->load(['creator', 'updater']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $template = MessageTemplate::create(array_merge($data, [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return response()->json($template, 201);
    }

    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $this->validatePayload($request);
        $template->update(array_merge($data, [
            'updated_by' => $request->user()->id,
        ]));

        return response()->json($template->fresh());
    }

    public function destroy(MessageTemplate $template): JsonResponse
    {
        // Prevent deletion of system templates
        if ($template->is_system) {
            return response()->json(['error' => 'Cannot delete system template'], 403);
        }

        // Check if template is used by any automation
        $automationCount = \App\Models\AutomationRule::where('template_id', $template->id)->count();
        if ($automationCount > 0) {
            return response()->json([
                'error' => "Template is used by {$automationCount} automation(s)",
            ], 400);
        }

        $template->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function duplicate(MessageTemplate $template): JsonResponse
    {
        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name . ' (Copy)';
        $newTemplate->slug = null;
        $newTemplate->is_system = false;
        $newTemplate->scope = 'USER';
        $newTemplate->owner_id = request()->user()->id;
        $newTemplate->created_by = request()->user()->id;
        $newTemplate->updated_by = request()->user()->id;
        $newTemplate->save();

        return response()->json($newTemplate, 201);
    }

    public function testSend(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
            'variables' => ['array'],
        ]);

        $renderer = app(\App\Services\Messaging\TemplateRenderer::class);
        $result = $renderer->render($template, $data['variables'] ?? []);

        $service = app(\App\Services\Messaging\MessagingService::class);
        $service->sendEmail([
            'to' => $data['to'],
            'subject' => $result['subject'],
            'body_html' => $result['html'],
            'body_text' => $result['text'],
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['status' => 'sent']);
    }

    public function preview(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'variables' => ['array'],
        ]);

        $renderer = app(\App\Services\Messaging\TemplateRenderer::class);
        $result = $renderer->render($template, $data['variables'] ?? []);

        return response()->json($result);
    }

    protected function validatePayload(Request $request): array
    {
        $categories = ['BOOKING', 'REMINDER', 'PAYMENT', 'INVOICE', 'ACCOUNT', 'GENERAL'];

        return $request->validate([
            'channel' => ['required', Rule::in(['EMAIL', 'SMS'])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in($categories)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'scope' => ['required', Rule::in(['SYSTEM', 'GLOBAL', 'ACCOUNT', 'USER'])],
            'owner_id' => ['nullable', 'integer'],
            'variables_json' => ['nullable', 'array'],
            'is_system' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}

