<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ReproAi\FlowEngine\FlowEngine;
use App\Services\ReproAi\FlowEngine\FlowHandlerInterface;
use App\Services\ReproAi\FlowEngine\FlowState;
use App\Services\ReproAi\FlowEngine\FlowTransition;
use App\Services\ReproAi\Tools\AiEditingTools;
use App\Services\AutoenhanceService;

class EditPhotosFlow implements FlowHandlerInterface
{
    protected AiEditingTools $editingTools;
    protected AutoenhanceService $autoenhanceService;
    protected FlowEngine $flowEngine;

    public function __construct(
        ?AiEditingTools $editingTools = null,
        ?AutoenhanceService $autoenhanceService = null,
        ?FlowEngine $flowEngine = null,
    ) {
        $this->editingTools = $editingTools ?? app(AiEditingTools::class);
        $this->autoenhanceService = $autoenhanceService ?? app(AutoenhanceService::class);
        $this->flowEngine = $flowEngine ?? app(FlowEngine::class);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(AiChatSession $session, string $message, array $context = []): array
    {
        return $this->flowEngine->handle($session, $message, $context, $this);
    }

    public function defaultStep(): string
    {
        return 'start';
    }

    public function handleStep(string $step, FlowState $state): FlowTransition
    {
        $userId = (int) ($state->context['user_id'] ?? $state->session->user_id);
        $userRole = (string) ($state->context['user_role'] ?? '');
        $messageLower = strtolower(trim($state->message));

        // ----------------------------------------------------------------
        // Anytime intents — status / retry / cancel based on last batch.
        // ----------------------------------------------------------------
        if ($this->matchesStatusIntent($messageLower) && !empty($state->data['last_job_ids'] ?? [])) {
            return $this->statusReply($state);
        }
        if ($this->matchesRetryIntent($messageLower) && !empty($state->data['last_job_ids'] ?? [])) {
            return $this->retryFailed($state);
        }
        $cancelTargets = $this->parseCancelIds($messageLower, $state->data['last_job_ids'] ?? []);
        if ($cancelTargets !== null) {
            return $this->cancelTargets($state, $cancelTargets);
        }

        // Global override: phrases like "show me my shoots", "list shoots",
        // "start over" always break out and return to the property picker.
        if ($this->isListOrResetIntent($state->message)) {
            $suggestions = $this->recentShootSuggestions($userId, $userRole);
            $msg = empty($suggestions)
                ? "I don't see any shoots on your account yet. Book or upload a shoot first, then come back."
                : "Here are your most recent shoots. Pick one or type an address:";

            return FlowTransition::next('ask_property', [
                'assistant_messages' => [[
                    'content' => $msg,
                    'metadata' => ['step' => 'ask_property'],
                ]],
                'suggestions' => empty($suggestions) ? ['Edit another property'] : $suggestions,
            ], []);
        }

        return match ($step) {
            'start'          => $this->start($state),
            'ask_property'   => $this->askProperty($state),
            'confirm_photos' => $this->confirmPhotos($state),
            'ask_mode'       => $this->askMode($state),
            'ask_params'     => $this->askParams($state),
            'confirm'        => $this->confirm($state),
            'done'           => $this->done($state),
            default          => $this->start($state),
        };
    }

    /**
     * Entry router. If the chat message arrived with `staged_ids` in context, we
     * jump straight to mode selection (the user already attached images). Otherwise
     * we fall through to the shoot/address picker.
     */
    protected function start(FlowState $state): FlowTransition
    {
        $context = $state->context;
        $stagedIds = array_values(array_filter((array) ($context['staged_ids'] ?? [])));

        if (!empty($stagedIds)) {
            $data = $state->data;
            $data['staged_ids'] = $stagedIds;
            $data['source_type'] = 'staged';
            $count = count($stagedIds);

            return FlowTransition::next('ask_mode', [
                'assistant_messages' => [[
                    'content' => "Got **{$count} image" . ($count === 1 ? '' : 's') . "** ready to edit. Which Autoenhance pipeline would you like?\n\n" .
                        "• **Enhance** — full property photo enhancement\n" .
                        "• **Sky replace** — swap grey skies\n" .
                        "• **Vertical correction** — straighten verticals only\n" .
                        "• **Window pull** — recover window highlights",
                    'metadata' => ['step' => 'ask_mode'],
                ]],
                'suggestions' => ['Enhance', 'Sky replace', 'Vertical correction', 'Window pull'],
            ], $data);
        }

        // No attachments — fall through to the existing address picker.
        return $this->askProperty($state);
    }

    /**
     * Detect phrases that should always reset the flow and list recent shoots.
     */
    protected function isListOrResetIntent(string $message): bool
    {
        $m = strtolower(trim($message));
        if ($m === '') {
            return false;
        }
        $patterns = [
            'show me my shoots', 'show my shoots', 'show all shoots', 'show shoots',
            'list my shoots', 'list shoots', 'list all shoots',
            'what shoots', 'which shoots', 'show me shoots',
            'start over', 'main menu', 'go back', 'nevermind', 'never mind',
            'edit another property', 'edit another shoot',
        ];
        foreach ($patterns as $p) {
            if ($m === $p || str_contains($m, $p)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Steps
    // ---------------------------------------------------------------------

    protected function askProperty(FlowState $state): FlowTransition
    {
        $message = trim($state->message);
        $data = $state->data;
        $context = $state->context;
        $userId = (int) ($context['user_id'] ?? $state->session->user_id);
        $userRole = (string) ($context['user_role'] ?? '');

        // Intent triggers that mean "start editing", not an actual address.
        $intentTriggers = [
            'edit photos', 'edit my photos', 'enhance photos', 'enhance my photos',
            'ai edit', 'submit for editing', 'autoenhance', 'retouch photos',
            'start editing', 'submit photos', 'send to autoenhance',
        ];
        $messageLower = strtolower($message);
        $isIntentTrigger = $message === '' || in_array($messageLower, $intentTriggers, true);

        if ($isIntentTrigger) {
            $suggestions = $this->recentShootSuggestions($userId, $userRole);
            if (empty($suggestions)) {
                $suggestions = ['No recent shoots found'];
            }
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "Sure — which property do you want me to send to AI editing? Tell me the address or pick one of your recent shoots.",
                    'metadata' => ['step' => 'ask_property'],
                ]],
                'suggestions' => $suggestions,
            ], $data);
        }

        // Strip common leading phrases so "Edit photos for 24 Ocean Ave" → "24 Ocean Ave".
        $cleanAddress = preg_replace(
            '/^(please\s+)?(?:edit|enhance|retouch|autoenhance|ai\s*edit(?:ing)?|submit)\s+(?:my\s+)?(?:photos?|images?|shoots?)?\s*(?:for|at|of|on)?\s*/i',
            '',
            $message
        );
        $cleanAddress = is_string($cleanAddress) ? trim($cleanAddress) : $message;
        if ($cleanAddress === '') {
            $cleanAddress = $message;
        }

        $shoot = $this->editingTools->findShootByAddress($cleanAddress, $userId, $userRole);
        if (!$shoot) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "I couldn't find a shoot matching **{$message}**. Try a different address or pick one of these:",
                    'metadata' => ['step' => 'ask_property', 'error' => 'shoot_not_found'],
                ]],
                'suggestions' => $this->recentShootSuggestions($userId, $userRole),
            ], $data);
        }

        $count = $this->editingTools->countRawPhotos($shoot->id);
        if ($count === 0) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "I found **" . $this->formatShootLabel($shoot) . "**, but there are no raw photos uploaded yet. Upload photos to that shoot and try again.",
                    'metadata' => ['step' => 'ask_property', 'error' => 'no_photos'],
                ]],
                'suggestions' => $this->recentShootSuggestions($userId, $userRole),
            ], $data);
        }

        $data['shoot_id'] = $shoot->id;
        $data['shoot_label'] = $this->formatShootLabel($shoot);
        $data['photo_count'] = $count;

        return FlowTransition::next('confirm_photos', [
            'assistant_messages' => [[
                'content' => "Found **{$data['shoot_label']}** with **{$count} raw photo" . ($count === 1 ? '' : 's') . "**. Send all of them to Autoenhance?",
                'metadata' => ['step' => 'confirm_photos'],
            ]],
            'suggestions' => ['Yes, send them all', 'No, pick a different shoot'],
        ], $data);
    }

    protected function confirmPhotos(FlowState $state): FlowTransition
    {
        $messageLower = strtolower(trim($state->message));
        $data = $state->data;

        if ($this->isNegative($messageLower) || str_contains($messageLower, 'different') || str_contains($messageLower, 'another')) {
            return FlowTransition::next('ask_property', [
                'assistant_messages' => [[
                    'content' => "No worries — which property should I edit instead?",
                    'metadata' => ['step' => 'ask_property'],
                ]],
                'suggestions' => $this->recentShootSuggestions(
                    (int) ($state->context['user_id'] ?? $state->session->user_id),
                    (string) ($state->context['user_role'] ?? '')
                ),
            ], array_diff_key($data, array_flip(['shoot_id', 'shoot_label', 'photo_count'])));
        }

        if (!$this->isAffirmative($messageLower) && !empty(trim($state->message))) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "Just to confirm — should I send all **{$data['photo_count']} photos** at **{$data['shoot_label']}** to Autoenhance?",
                    'metadata' => ['step' => 'confirm_photos'],
                ]],
                'suggestions' => ['Yes, send them all', 'No, pick a different shoot'],
            ], $data);
        }

        // Affirmative → move to mode picker
        return FlowTransition::next('ask_mode', [
            'assistant_messages' => [[
                'content' => "Great. Which editing mode would you like?\n\n" .
                    "• **Enhance** — core property photo enhancement\n" .
                    "• **Sky replace** — replace grey skies\n" .
                    "• **Vertical correction** — straighten wonky verticals\n" .
                    "• **Window pull** — Autoenhance window-pull processing",
                'metadata' => ['step' => 'ask_mode'],
            ]],
            'suggestions' => ['Enhance', 'Sky replace', 'Vertical correction', 'Window pull'],
        ], $data);
    }

    protected function askMode(FlowState $state): FlowTransition
    {
        $message = strtolower(trim($state->message));
        $data = $state->data;

        $modeId = $this->matchMode($message);
        if (!$modeId) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "I didn't catch that — pick one: Enhance, Sky replace, Vertical correction, or Window pull.",
                    'metadata' => ['step' => 'ask_mode'],
                ]],
                'suggestions' => ['Enhance', 'Sky replace', 'Vertical correction', 'Window pull'],
            ], $data);
        }

        if ($modeId === 'hdr_merge') {
            // HDR Bracket merge needs Autoenhance Orders grouping (not yet wired locally).
            // Fall back to standard enhance with a note so the user isn't blocked.
            $modeId = 'enhance';
            $message = "HDR bracket merge needs grouped exposures via Autoenhance Orders, which isn't wired up yet. I'll run **Enhance** instead — let me know if you want a different pipeline.";
        } else {
            $message = '';
        }

        $data['editing_type'] = $modeId;
        $data['editing_label'] = $this->modeLabel($modeId);
        // Initialise params with safe defaults from Autoenhance docs.
        $data['editing_params'] = $this->defaultParamsFor($modeId);
        $data['param_step'] = $this->firstParamStepFor($modeId);

        // If the chosen mode has no follow-up params, jump straight to confirm.
        if (!$data['param_step']) {
            $assistant = $message !== ''
                ? [['content' => $message, 'metadata' => ['step' => 'ask_mode', 'type' => 'system']]]
                : [];
            $assistant[] = [
                'content' => $this->confirmRecap($data),
                'metadata' => ['step' => 'confirm'],
            ];
            return FlowTransition::next('confirm', [
                'assistant_messages' => $assistant,
                'suggestions' => ['Yes, submit', 'No, change mode', 'Cancel'],
            ], $data);
        }

        // Otherwise ask the first follow-up question for this pipeline.
        $prompt = $this->paramPrompt($modeId, $data['param_step']);
        $assistant = $message !== ''
            ? [['content' => $message, 'metadata' => ['step' => 'ask_mode', 'type' => 'system']]]
            : [];
        $assistant[] = ['content' => $prompt['content'], 'metadata' => ['step' => 'ask_params']];

        return FlowTransition::next('ask_params', [
            'assistant_messages' => $assistant,
            'suggestions' => $prompt['suggestions'],
        ], $data);
    }

    /**
     * Collects each follow-up parameter for the chosen pipeline (one per turn) and
     * advances through the param plan until none are left, then jumps to `confirm`.
     */
    protected function askParams(FlowState $state): FlowTransition
    {
        $data = $state->data;
        $modeId = (string) ($data['editing_type'] ?? 'enhance');
        $paramStep = (string) ($data['param_step'] ?? '');

        if ($paramStep === '') {
            // No outstanding param — go to confirm.
            return FlowTransition::next('confirm', [
                'assistant_messages' => [[
                    'content' => $this->confirmRecap($data),
                    'metadata' => ['step' => 'confirm'],
                ]],
                'suggestions' => ['Yes, submit', 'No, change mode', 'Cancel'],
            ], $data);
        }

        $messageLower = strtolower(trim($state->message));
        $current = is_array($data['editing_params'] ?? null) ? $data['editing_params'] : [];

        $parsed = $this->parseParamAnswer($modeId, $paramStep, $messageLower);
        if ($parsed === null) {
            // Could not interpret — re-ask the same question.
            $prompt = $this->paramPrompt($modeId, $paramStep);
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "Sorry, I didn't catch that. " . $prompt['content'],
                    'metadata' => ['step' => 'ask_params'],
                ]],
                'suggestions' => $prompt['suggestions'],
            ], $data);
        }

        $current = array_merge($current, $parsed);
        $data['editing_params'] = $current;
        $data['param_step'] = $this->nextParamStepFor($modeId, $paramStep);

        if (!$data['param_step']) {
            return FlowTransition::next('confirm', [
                'assistant_messages' => [[
                    'content' => $this->confirmRecap($data),
                    'metadata' => ['step' => 'confirm'],
                ]],
                'suggestions' => ['Yes, submit', 'No, change mode', 'Cancel'],
            ], $data);
        }

        $next = $this->paramPrompt($modeId, $data['param_step']);
        return FlowTransition::stay([
            'assistant_messages' => [[
                'content' => $next['content'],
                'metadata' => ['step' => 'ask_params'],
            ]],
            'suggestions' => $next['suggestions'],
        ], $data);
    }

    protected function confirm(FlowState $state): FlowTransition
    {
        $messageLower = strtolower(trim($state->message));
        $data = $state->data;

        if (str_contains($messageLower, 'change mode') || str_contains($messageLower, 'different mode')) {
            return FlowTransition::next('ask_mode', [
                'assistant_messages' => [[
                    'content' => "Sure — which mode would you like?",
                    'metadata' => ['step' => 'ask_mode'],
                ]],
                'suggestions' => ['Enhance', 'Sky replace', 'Vertical correction', 'Window pull'],
            ], $data);
        }

        if ($this->isNegative($messageLower) || str_contains($messageLower, 'cancel')) {
            return FlowTransition::clear([
                'assistant_messages' => [[
                    'content' => "Okay, cancelled. Let me know when you're ready to edit something else.",
                    'metadata' => ['step' => 'cancelled', 'type' => 'system'],
                ]],
                'suggestions' => ['Edit another property', 'Check editing status'],
            ], []);
        }

        if (!$this->isAffirmative($messageLower)) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "Should I go ahead and submit **{$data['photo_count']} photos** for **{$data['editing_label']}** at **{$data['shoot_label']}**?",
                    'metadata' => ['step' => 'confirm'],
                ]],
                'suggestions' => ['Yes, submit', 'No, change mode', 'Cancel'],
            ], $data);
        }

        // Submit! — branch by source type
        $context = $state->context;
        $userId = (int) ($context['user_id'] ?? $state->session->user_id);
        $editingType = (string) ($data['editing_type'] ?? 'enhance');
        $params = is_array($data['editing_params'] ?? null) ? $data['editing_params'] : [];
        $sourceType = (string) ($data['source_type'] ?? 'shoot');
        $jobIds = [];
        $jobsCount = 0;
        $skipped = [];

        if ($sourceType === 'staged') {
            $stagedIds = (array) ($data['staged_ids'] ?? []);
            $result = $this->editingTools->submitStagedQuickEdit($stagedIds, $userId, $editingType, $params);
            $jobs = $result['jobs'] ?? [];
            $skipped = $result['skipped'] ?? [];
            $jobIds = array_map(fn ($j) => (int) ($j->id ?? 0), $jobs);
            $jobIds = array_values(array_filter($jobIds));
            $jobsCount = count($jobIds);

            if ($jobsCount === 0) {
                $reason = $skipped[0]['reason'] ?? 'unknown error';
                return FlowTransition::clear([
                    'assistant_messages' => [[
                        'content' => "I couldn't submit those uploads: **{$reason}**. Try the wizard for a richer error path.",
                        'metadata' => ['step' => 'error', 'type' => 'error', 'tool_status' => 'error'],
                    ]],
                    'suggestions' => ['Edit another property', 'Check editing status'],
                ], []);
            }

            $modeLabel = (string) ($data['editing_label'] ?? $editingType);
            $skipNote = !empty($skipped) ? "\n\nSkipped " . count($skipped) . " file" . (count($skipped) === 1 ? '' : 's') . "." : '';
            return FlowTransition::next('done', [
                'assistant_messages' => [[
                    'content' => "Submitted **{$jobsCount} image" . ($jobsCount === 1 ? '' : 's') . "** for **{$modeLabel}**. Track them in your Recent activity.{$skipNote}\n\nYou can ask me: *what's the status?*, *retry the failed ones*, or *cancel job #N*.",
                    'metadata' => [
                        'step' => 'done',
                        'tool_status' => 'success',
                        'actions' => [['type' => 'view_editing_jobs', 'label' => 'View jobs']],
                    ],
                ]],
                'suggestions' => ['What\'s the status?', 'Retry the failed ones', 'Edit another property'],
                'actions' => [['type' => 'view_editing_jobs']],
            ], [
                'last_job_ids' => $jobIds,
                'last_mode' => $editingType,
                'last_mode_label' => $modeLabel,
            ]);
        }

        // Shoot-based path (existing behaviour, now with collected params)
        $result = $this->editingTools->submitAiEditing([
            'shoot_id' => $data['shoot_id'],
            'editing_type' => $editingType,
            'params' => $params,
        ], [
            'user_id' => $userId,
            'user_role' => $context['user_role'] ?? null,
        ]);

        if (empty($result['success'])) {
            $err = $result['error'] ?? 'Unknown error';
            return FlowTransition::clear([
                'assistant_messages' => [[
                    'content' => "I hit a problem submitting those photos: **{$err}**. Try again from the AI Editing wizard, or pick a different shoot.",
                    'metadata' => ['step' => 'error', 'type' => 'error', 'tool_status' => 'error'],
                ]],
                'suggestions' => ['Edit another property', 'Check editing status'],
            ], []);
        }

        $jobIds = array_map(fn ($j) => (int) ($j['id'] ?? $j->id ?? 0), $result['jobs'] ?? []);
        $jobIds = array_values(array_filter($jobIds));
        $jobsCount = count($jobIds) ?: ($data['photo_count'] ?? 0);

        return FlowTransition::next('done', [
            'assistant_messages' => [[
                'content' => "Submitted **{$jobsCount} photo" . ($jobsCount === 1 ? '' : 's') . "** for **{$data['editing_label']}** at **{$data['shoot_label']}**. I'll keep them in your Recent activity — refresh it to see progress.\n\nYou can ask me: *what's the status?*, *retry the failed ones*, or *cancel job #N*.",
                'metadata' => [
                    'step' => 'done',
                    'tool_status' => 'success',
                    'actions' => [[
                        'type' => 'view_editing_jobs',
                        'shoot_id' => $data['shoot_id'],
                        'label' => 'View jobs',
                    ]],
                ],
            ]],
            'suggestions' => ['What\'s the status?', 'Retry the failed ones', 'Edit another property'],
            'actions' => [[
                'type' => 'view_editing_jobs',
                'shoot_id' => $data['shoot_id'],
            ]],
        ], [
            'last_job_ids' => $jobIds,
            'last_mode' => $editingType,
            'last_mode_label' => (string) ($data['editing_label'] ?? $editingType),
            'last_shoot_id' => $data['shoot_id'] ?? null,
        ]);
    }

    protected function done(FlowState $state): FlowTransition
    {
        return FlowTransition::clear([
            'assistant_messages' => [[
                'content' => "What would you like to do next?",
                'metadata' => ['step' => 'done', 'type' => 'system'],
            ]],
            'suggestions' => ['Edit another property', 'Check editing status', 'Show editing modes'],
        ], []);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function isAffirmative(string $m): bool
    {
        $affirmatives = ['yes', 'y', 'yeah', 'yep', 'sure', 'ok', 'okay', 'confirm', 'submit', 'send', 'go', 'do it', 'proceed', 'go ahead'];
        foreach ($affirmatives as $a) {
            if ($m === $a || str_starts_with($m, $a . ' ') || str_contains($m, ' ' . $a . ' ') || str_ends_with($m, ' ' . $a)) {
                return true;
            }
        }
        return false;
    }

    protected function isNegative(string $m): bool
    {
        $negatives = ['no', 'n', 'nope', 'nah', 'don\'t', 'do not', 'stop', 'cancel'];
        foreach ($negatives as $n) {
            if ($m === $n || str_starts_with($m, $n . ' ') || str_ends_with($m, ' ' . $n)) {
                return true;
            }
        }
        return false;
    }

    protected function matchMode(string $m): ?string
    {
        if ($m === '') {
            return null;
        }
        if (str_contains($m, 'sky')) {
            return 'sky_replace';
        }
        if (str_contains($m, 'vertical')) {
            return 'vertical_correction';
        }
        if (str_contains($m, 'window')) {
            return 'window_pull';
        }
        if (str_contains($m, 'hdr') || str_contains($m, 'bracket')) {
            return 'hdr_merge';
        }
        if (str_contains($m, 'enhance') || str_contains($m, 'standard') || str_contains($m, 'normal') || str_contains($m, 'default')) {
            return 'enhance';
        }
        return null;
    }

    protected function modeLabel(string $modeId): string
    {
        return match ($modeId) {
            'enhance' => 'Enhance',
            'sky_replace' => 'Sky replacement',
            'vertical_correction' => 'Vertical correction',
            'window_pull' => 'Window pull',
            'hdr_merge' => 'HDR bracket merge',
            default => ucfirst(str_replace('_', ' ', $modeId)),
        };
    }

    protected function formatShootLabel(Shoot $shoot): string
    {
        $parts = array_filter([
            trim((string) $shoot->address),
            trim((string) $shoot->city),
            trim((string) $shoot->state),
        ]);
        return implode(', ', $parts) ?: ('Shoot #' . $shoot->id);
    }

    /**
     * Recent shoots that have raw photos uploaded, scoped to the requesting user.
     *
     * @return array<int, string>
     */
    protected function recentShootSuggestions(int $userId, string $userRole = ''): array
    {
        $isPrivileged = in_array($userRole, ['admin', 'superadmin', 'editor', 'editing_manager'], true);

        $query = Shoot::query();
        if (!$isPrivileged) {
            $query->where(function ($q) use ($userId) {
                $q->where('client_id', $userId)
                  ->orWhere('rep_id', $userId)
                  ->orWhere('editor_id', $userId)
                  ->orWhere('photographer_id', $userId);
            });
        }

        $shoots = $query->orderByDesc('created_at')->limit(20)->get();

        $unique = $shoots->unique(function (Shoot $s) {
            return strtolower(trim((string) $s->address)) . '|' . strtolower(trim((string) $s->city)) . '|' . strtolower(trim((string) $s->state));
        });

        $labels = [];
        foreach ($unique->take(5) as $shoot) {
            $label = $this->formatShootLabel($shoot);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return $labels;
    }

    // ---------------------------------------------------------------------
    // Pipeline params (per Autoenhance docs)
    // ---------------------------------------------------------------------

    /**
     * Sensible defaults per pipeline. These mirror the docs' recommended values
     * and are merged with whatever the user picks in the follow-up prompts.
     */
    protected function defaultParamsFor(string $modeId): array
    {
        return match ($modeId) {
            'enhance' => [
                'enhance' => true,
                'enhance_type' => 'neutral',
                'lens_correction' => true,
                'vertical_correction' => true,
            ],
            'sky_replace' => [
                'enhance' => true,
                'sky_replace' => true,
                'cloud_type' => 'CLEAR',
                'lens_correction' => true,
                'vertical_correction' => true,
            ],
            'vertical_correction' => [
                'enhance' => true,
                'vertical_correction' => true,
                'lens_correction' => true,
            ],
            'window_pull' => [
                'enhance' => true,
                'window_pull' => true,
                'window_pull_type' => 'ONLY_WINDOWS',
            ],
            'hdr_merge' => [
                'enhance' => true,
                'hdr' => true,
            ],
            default => ['enhance' => true],
        };
    }

    /**
     * Each pipeline has an ordered plan of parameter questions. Returning '' / null
     * skips the follow-up entirely.
     */
    protected function firstParamStepFor(string $modeId): ?string
    {
        return match ($modeId) {
            'enhance' => 'lens_correction',
            'sky_replace' => 'cloud_type',
            'window_pull' => 'window_pull_type',
            // vertical_correction & hdr_merge currently have no follow-ups
            default => null,
        };
    }

    protected function nextParamStepFor(string $modeId, string $current): ?string
    {
        return match ([$modeId, $current]) {
            ['enhance', 'lens_correction'] => 'vertical_correction',
            ['enhance', 'vertical_correction'] => null,
            ['sky_replace', 'cloud_type'] => null,
            ['window_pull', 'window_pull_type'] => null,
            default => null,
        };
    }

    /**
     * Build the question + suggestion chips for a given param step.
     *
     * @return array{content:string, suggestions:array<int,string>}
     */
    protected function paramPrompt(string $modeId, string $paramStep): array
    {
        return match ($paramStep) {
            'lens_correction' => [
                'content' => "Should I apply **lens correction** (fix barrel/pincushion distortion)?",
                'suggestions' => ['Yes', 'No'],
            ],
            'vertical_correction' => [
                'content' => "And **vertical correction** (straighten leaning verticals)?",
                'suggestions' => ['Yes', 'No'],
            ],
            'cloud_type' => [
                'content' => "Which sky should I drop in?\n\n• **Clear** — bright clean blue\n• **Low cloud** — natural mixed clouds\n• **Low cloud low sat** — softer / muted\n• **High cloud** — wispy high clouds",
                'suggestions' => ['Clear', 'Low cloud', 'Low cloud low sat', 'High cloud'],
            ],
            'window_pull_type' => [
                'content' => "Window pull scope:\n\n• **Only windows** — recover window highlights only\n• **With skies** — also enhance skies seen through windows",
                'suggestions' => ['Only windows', 'With skies'],
            ],
            default => ['content' => 'OK.', 'suggestions' => []],
        };
    }

    /**
     * Parse user's answer for a given param step into the partial param map.
     * Returns null when the answer is unrecognised (caller will re-ask).
     *
     * @return array<string, mixed>|null
     */
    protected function parseParamAnswer(string $modeId, string $paramStep, string $messageLower): ?array
    {
        $messageLower = trim($messageLower);
        if ($messageLower === '') {
            return null;
        }

        switch ($paramStep) {
            case 'lens_correction':
            case 'vertical_correction':
                if ($this->isAffirmative($messageLower)) {
                    return [$paramStep => true];
                }
                if ($this->isNegative($messageLower) || str_contains($messageLower, 'skip')) {
                    return [$paramStep => false];
                }
                return null;

            case 'cloud_type':
                if (str_contains($messageLower, 'high')) return ['cloud_type' => 'HIGH_CLOUD'];
                if (str_contains($messageLower, 'low cloud low sat') || str_contains($messageLower, 'muted') || str_contains($messageLower, 'soft')) {
                    return ['cloud_type' => 'LOW_CLOUD_LOW_SAT'];
                }
                if (str_contains($messageLower, 'low cloud') || str_contains($messageLower, 'low')) return ['cloud_type' => 'LOW_CLOUD'];
                if (str_contains($messageLower, 'clear') || str_contains($messageLower, 'blue') || str_contains($messageLower, 'sunny')) return ['cloud_type' => 'CLEAR'];
                return null;

            case 'window_pull_type':
                if (str_contains($messageLower, 'with sky') || str_contains($messageLower, 'with skies') || str_contains($messageLower, 'and sky')) {
                    return ['window_pull_type' => 'WITH_SKIES'];
                }
                if (str_contains($messageLower, 'window') || str_contains($messageLower, 'only')) {
                    return ['window_pull_type' => 'ONLY_WINDOWS'];
                }
                return null;
        }

        return null;
    }

    /**
     * Build the recap shown right before the user says "yes, submit".
     */
    protected function confirmRecap(array $data): string
    {
        $sourceType = $data['source_type'] ?? 'shoot';
        $modeLabel = (string) ($data['editing_label'] ?? 'Enhance');
        $params = is_array($data['editing_params'] ?? null) ? $data['editing_params'] : [];

        $paramLines = [];
        foreach (['enhance_type', 'cloud_type', 'window_pull_type'] as $key) {
            if (!empty($params[$key])) {
                $paramLines[] = '• ' . str_replace('_', ' ', $key) . ': **' . str_replace('_', ' ', strtolower((string) $params[$key])) . '**';
            }
        }
        foreach (['lens_correction', 'vertical_correction'] as $key) {
            if (array_key_exists($key, $params)) {
                $paramLines[] = '• ' . str_replace('_', ' ', $key) . ': **' . ($params[$key] ? 'on' : 'off') . '**';
            }
        }
        $paramBlock = !empty($paramLines) ? "\n" . implode("\n", $paramLines) : '';

        if ($sourceType === 'staged') {
            $count = count((array) ($data['staged_ids'] ?? []));
            return "Ready to submit:\n\n📤 **{$count} uploaded image" . ($count === 1 ? '' : 's') . "**\n✨ **{$modeLabel}**" . $paramBlock . "\n\nConfirm to send to Autoenhance?";
        }

        return "Ready to submit:\n\n📍 **" . ($data['shoot_label'] ?? '') . "**\n🖼️ " . ($data['photo_count'] ?? 0) . " photo" . (($data['photo_count'] ?? 0) === 1 ? '' : 's') . "\n✨ **{$modeLabel}**" . $paramBlock . "\n\nConfirm to send to Autoenhance?";
    }

    // ---------------------------------------------------------------------
    // Anytime intents — status / retry / cancel
    // ---------------------------------------------------------------------

    protected function matchesStatusIntent(string $m): bool
    {
        if ($m === '') return false;
        $patterns = [
            "what's the status", 'whats the status', 'check status', 'check the status',
            'how are those', 'how are they', 'how are my', 'how is it going',
            'how are the edits', 'how are those coming', 'progress', 'still processing',
        ];
        foreach ($patterns as $p) {
            if (str_contains($m, $p)) return true;
        }
        return false;
    }

    protected function matchesRetryIntent(string $m): bool
    {
        if ($m === '') return false;
        return (bool) preg_match('/\b(retry|re[- ]?run|try again)\b/i', $m)
            && (str_contains($m, 'failed') || str_contains($m, 'errored') || str_contains($m, 'them all') || str_contains($m, 'all of them') || str_contains($m, 'those'));
    }

    /**
     * Parse "cancel job 12 and 13", "cancel all" etc.
     * Returns null if no cancel intent was found.
     *
     * @param  array<int,int>  $lastJobIds
     * @return array<int,int>|null
     */
    protected function parseCancelIds(string $m, array $lastJobIds): ?array
    {
        if ($m === '' || !str_contains($m, 'cancel')) return null;
        if (str_contains($m, 'cancel all') || str_contains($m, 'cancel them all') || str_contains($m, 'cancel everything')) {
            return $lastJobIds;
        }
        if (preg_match_all('/#?(\d+)/', $m, $matches) && !empty($matches[1])) {
            return array_values(array_map('intval', $matches[1]));
        }
        return null;
    }

    protected function statusReply(FlowState $state): FlowTransition
    {
        $userId = (int) ($state->context['user_id'] ?? $state->session->user_id);
        $lastIds = (array) ($state->data['last_job_ids'] ?? []);
        $jobs = $this->editingTools->getJobsByIds($lastIds, $userId);

        if (empty($jobs)) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "I don't have any recent jobs from this conversation to check. Try the Activity tab or ask 'show recent jobs'.",
                    'metadata' => ['step' => 'status'],
                ]],
                'suggestions' => ['Edit another property', 'Show editing modes'],
            ], $state->data);
        }

        $counts = ['processing' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'cancelled' => 0];
        foreach ($jobs as $j) {
            $counts[$j['status']] = ($counts[$j['status']] ?? 0) + 1;
        }
        $lines = [];
        $lines[] = "Status of the last batch (" . count($jobs) . " job" . (count($jobs) === 1 ? '' : 's') . "):";
        if ($counts['processing']) $lines[] = "• ⏳ {$counts['processing']} processing";
        if ($counts['completed']) $lines[] = "• ✅ {$counts['completed']} completed";
        if ($counts['failed']) $lines[] = "• ❌ {$counts['failed']} failed";
        if ($counts['pending']) $lines[] = "• ⏸ {$counts['pending']} pending";
        if ($counts['cancelled']) $lines[] = "• ⛔ {$counts['cancelled']} cancelled";

        return FlowTransition::stay([
            'assistant_messages' => [[
                'content' => implode("\n", $lines),
                'metadata' => ['step' => 'status'],
            ]],
            'suggestions' => ['Retry the failed ones', 'Cancel all', 'Edit another property'],
        ], $state->data);
    }

    protected function retryFailed(FlowState $state): FlowTransition
    {
        $userId = (int) ($state->context['user_id'] ?? $state->session->user_id);
        $lastIds = (array) ($state->data['last_job_ids'] ?? []);
        $jobs = $this->editingTools->getJobsByIds($lastIds, $userId);
        $failedIds = array_values(array_map(fn ($j) => (int) $j['id'], array_filter($jobs, fn ($j) => $j['status'] === 'failed')));

        if (empty($failedIds)) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "Good news — none of those jobs are failed.",
                    'metadata' => ['step' => 'retry'],
                ]],
                'suggestions' => ['What\'s the status?', 'Edit another property'],
            ], $state->data);
        }

        $result = $this->editingTools->retryJobs($failedIds, $userId);
        $count = $result['retried'] ?? 0;
        return FlowTransition::stay([
            'assistant_messages' => [[
                'content' => "Re-queued **{$count} failed job" . ($count === 1 ? '' : 's') . "** for processing.",
                'metadata' => ['step' => 'retry', 'tool_status' => 'success'],
            ]],
            'suggestions' => ['What\'s the status?', 'Edit another property'],
        ], $state->data);
    }

    protected function cancelTargets(FlowState $state, array $jobIds): FlowTransition
    {
        $userId = (int) ($state->context['user_id'] ?? $state->session->user_id);
        if (empty($jobIds)) {
            return FlowTransition::stay([
                'assistant_messages' => [[
                    'content' => "Tell me which job(s) to cancel — e.g. 'cancel #12' or 'cancel all'.",
                    'metadata' => ['step' => 'cancel'],
                ]],
                'suggestions' => ['Cancel all', 'What\'s the status?'],
            ], $state->data);
        }

        $result = $this->editingTools->cancelJobs($jobIds, $userId);
        $count = $result['cancelled'] ?? 0;
        return FlowTransition::stay([
            'assistant_messages' => [[
                'content' => "Cancelled **{$count} job" . ($count === 1 ? '' : 's') . "**.",
                'metadata' => ['step' => 'cancel', 'tool_status' => 'success'],
            ]],
            'suggestions' => ['What\'s the status?', 'Edit another property'],
        ], $state->data);
    }
}
