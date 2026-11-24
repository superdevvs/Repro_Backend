<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmEmailTemplate;
use App\Models\Crm\CrmEmailTemplateVersion;
use App\Services\Crm\Email\CrmEmailPresenter;
use App\Services\Crm\Email\CrmEmailRenderer;
use App\Services\Crm\Email\CrmSignatureService;
use App\Services\Crm\Email\CrmTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Branded templates. Everyone sees approved templates; admins write, submit and approve them. */
class CrmTemplateController extends CrmController
{
    public function __construct(private readonly CrmTemplateService $templates, private readonly CrmEmailPresenter $present) {}

    private function canEdit(Request $request): bool
    {
        return $this->access()->can($request->user(), 'crm-templates', 'approve') || $this->access()->can($request->user(), 'crm-drips', 'manage');
    }

    public static function contentRules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'subject' => [$r, 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body_html' => [$r, 'string', 'max:50000'],
            'image_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/storage/)#'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|mailto:|\{\{)#'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['nullable', Rule::in(CrmEmailTemplate::KINDS)], 'approved_only' => ['nullable', 'boolean']]);
        $canEdit = $this->canEdit($request);
        $items = CrmEmailTemplate::query()
            ->when(! empty($data['kind']), fn ($q) => $q->where('kind', $data['kind']))
            ->when(! $canEdit || ($data['approved_only'] ?? false), fn ($q) => $q->whereNotNull('approved_version_id')->where('status', '!=', 'retired'))
            ->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($t) => $this->present->template($t))->all(), 'can_edit' => $canEdit]);
    }

    public function show(Request $request, CrmEmailTemplate $template): JsonResponse
    {
        abort_unless($this->canEdit($request) || $template->approved_version_id, 404);

        return response()->json(['template' => $this->present->template($template, $this->canEdit($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->canEdit($request), 403, 'Only admins create templates.');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'kind' => ['required', Rule::in(CrmEmailTemplate::KINDS)]] + self::contentRules());
        $template = $this->templates->create($data['name'], $data['kind'], $data, $this->actor($request));

        return response()->json(['template' => $this->present->template($template, true)], 201);
    }

    public function update(Request $request, CrmEmailTemplate $template): JsonResponse
    {
        abort_unless($this->canEdit($request), 403, 'Only admins edit templates.');
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:160']] + self::contentRules());
        if (isset($data['name'])) {
            $template->forceFill(['name' => $data['name']])->save();
        }
        $this->templates->saveVersion($template, $data, $this->actor($request));

        return response()->json(['template' => $this->present->template($template->fresh(), true)]);
    }

    public function submit(Request $request, CrmEmailTemplate $template): JsonResponse
    {
        abort_unless($this->canEdit($request), 403);
        $version = $this->templates->submit($template, $this->actor($request));

        return response()->json(['version' => $this->present->version($version), 'template' => $this->present->template($template->fresh(), true)]);
    }

    public function approve(Request $request, CrmEmailTemplateVersion $version): JsonResponse
    {
        $this->templates->approve($version, $this->actor($request));

        return response()->json(['template' => $this->present->template($version->template->fresh(), true)]);
    }

    public function reject(Request $request, CrmEmailTemplateVersion $version): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:1000']]);
        $this->templates->reject($version, $this->actor($request), $data['comment']);

        return response()->json(['template' => $this->present->template($version->template->fresh(), true)]);
    }

    public function retire(Request $request, CrmEmailTemplate $template): JsonResponse
    {
        $this->templates->retire($template, $this->actor($request));

        return response()->json(['template' => $this->present->template($template->fresh())]);
    }

    /** Render any content in the branded layout, with the viewer's signature, without sending. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(self::contentRules());
        $user = $this->actor($request);
        $renderer = app(CrmEmailRenderer::class);
        $signature = app(CrmSignatureService::class)->currentApproved($user->id) ?? app(CrmSignatureService::class)->latest($user->id);
        $rendered = $renderer->render($data, $renderer->varsFor(null, $user, ['first_name' => 'Dana', 'company' => 'Whitfield & Rowe Realty', 'market' => 'Bethesda']), $renderer->signatureHtml($signature), '#unsubscribe');

        return response()->json($rendered);
    }
}
