<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmAiProposal;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\Crm\CrmEvent;
use App\Services\Crm\Robbie\CrmInsights;
use App\Services\Crm\Robbie\CrmPlanDay;
use App\Services\Crm\Robbie\CrmProposalService;
use App\Services\Crm\Robbie\CrmRobbieChat;
use App\Services\Crm\Robbie\CrmRobbieWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Robbie inside the CRM: strip insights, plan my day, briefs, chat, drafts and proposals. */
class CrmRobbieController extends CrmController
{
    public function __construct(private readonly CrmProposalService $proposals) {}

    public function insights(Request $request): JsonResponse
    {
        return response()->json(['data' => app(CrmInsights::class)->for($this->actor($request))]);
    }

    public function planDay(Request $request): JsonResponse
    {
        return response()->json(['data' => app(CrmPlanDay::class)->for($this->actor($request))]);
    }

    public function brief(Request $request, CrmContact $contact): JsonResponse
    {
        return response()->json(app(CrmRobbieWriter::class)->brief($contact, $request->boolean('ai')));
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:30'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
            'page' => ['nullable', 'string', 'max:120'],
            'entity_type' => ['nullable', 'in:crm_contact'],
            'entity_id' => ['nullable', 'integer'],
        ]);
        try {
            $out = app(CrmRobbieChat::class)->reply($this->actor($request), $data['message'], $data['history'] ?? [], $data);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Robbie is unavailable right now. Try again in a minute.'], 503);
        }

        return response()->json($out);
    }

    public function pending(Request $request): JsonResponse
    {
        $items = CrmAiProposal::query()->where('user_id', $this->actor($request)->id)->where('status', 'pending')
            ->where('expires_at', '>', now())->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $items->map(fn ($p) => $this->proposals->present($p))->all()]);
    }

    public function confirm(Request $request, CrmAiProposal $proposal): JsonResponse
    {
        $data = $request->validate([
            'edits' => ['nullable', 'array'],
            'edits.body' => ['nullable', 'string', 'max:10000'],
            'edits.type' => ['nullable', 'string', 'max:40'],
            'edits.starts_at' => ['nullable', 'date'],
            'edits.duration_min' => ['nullable', 'integer', 'min:5', 'max:720'],
            'edits.note' => ['nullable', 'string', 'max:2000'],
            'edits.stage' => ['nullable', 'string', 'max:24'],
            'edits.lost_reason' => ['nullable', 'string', 'max:24'],
            'edits.lost_note' => ['nullable', 'string', 'max:2000'],
            'edits.subject' => ['nullable', 'string', 'max:200'],
            'edits.body_html' => ['nullable', 'string', 'max:50000'],
            'edits.campaign_id' => ['nullable', 'integer'],
        ]);
        $this->proposals->confirm($proposal, $this->actor($request), array_filter($data['edits'] ?? [], fn ($v) => $v !== null));

        return response()->json(['proposal' => $this->proposals->present($proposal->fresh())]);
    }

    public function dismiss(Request $request, CrmAiProposal $proposal): JsonResponse
    {
        $this->proposals->dismiss($proposal, $this->actor($request));

        return response()->json(['proposal' => $this->proposals->present($proposal->fresh())]);
    }

    /** R3: Robbie drafts inside an approved template; the result is a proposal the user reviews. */
    public function draftEmail(Request $request, CrmContact $contact): JsonResponse
    {
        $data = $request->validate(['template_id' => ['required', 'integer'], 'instruction' => ['nullable', 'string', 'max:600']]);
        $template = CrmEmailTemplate::query()->whereKey($data['template_id'])->whereNotNull('approved_version_id')->where('status', '!=', 'retired')->firstOrFail();
        $user = $this->actor($request);
        $draft = app(CrmRobbieWriter::class)->draftEmail($contact, $template, $data['instruction'] ?? null, $user);
        $proposal = $this->proposals->propose($user, 'email', $contact, ['template_id' => $template->id, 'subject' => $draft['subject'], 'body_html' => $draft['body_html']], 'Email '.$contact->name.': '.$draft['subject']);

        return response()->json(['drafted' => $draft['drafted'], 'proposal' => $this->proposals->present($proposal)], 201);
    }

    public function draftOutcome(Request $request, CrmEvent $event): JsonResponse
    {
        abort_unless(CrmEvent::query()->visible($this->actor($request))->whereKey($event->id)->exists(), 404);
        $data = $request->validate(['hint' => ['nullable', 'string', 'max:800']]);

        return response()->json(['note' => app(CrmRobbieWriter::class)->draftOutcome($event, $data['hint'] ?? null)]);
    }
}
