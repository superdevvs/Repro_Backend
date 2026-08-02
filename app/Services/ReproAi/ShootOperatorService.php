<?php

namespace App\Services\ReproAi;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\IngestIguideAssetsJob;
use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\User;
use App\Services\CubiCasaService;
use App\Services\IguideService;
use App\Services\Shoots\Actions\FinalizeRawUploadAction;
use App\Services\Shoots\Actions\UpdateShootAction;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ShootOperatorService
{
    public function __construct(
        protected FinalizeRawUploadAction $finalizeRawUploadAction,
        protected UpdateShootAction $updateShootAction,
        protected IguideService $iguideService,
        protected CubiCasaService $cubicasaService,
    ) {
    }

    public function handle(AiChatSession $session, string $message, array $context, User $user): ?array
    {
        $pendingUpload = Arr::get($context, 'pendingUpload');
        if (is_array($pendingUpload)) {
            return $this->handlePendingUpload($message, $context, $user, $pendingUpload);
        }

        $uploadResult = Arr::get($context, 'uploadResult');
        if (is_array($uploadResult)) {
            return $this->handleUploadResult($uploadResult);
        }

        if (!$this->looksLikeShootOperatorRequest($message, $context)) {
            return null;
        }

        $shoot = $this->resolveShoot($message, $context, $user)['shoot'] ?? null;
        if (!$shoot) {
            return $this->noShootResponse($message, $context, $user);
        }

        if (!$this->canAccessShoot($shoot, $user)) {
            return [
                'assistant_messages' => [[
                    'content' => "I found shoot #{$shoot->id}, but your account does not have access to manage it.",
                    'metadata' => ['type' => 'shoot_operator', 'error' => 'forbidden'],
                ]],
            ];
        }

        $lower = strtolower($message);

        if (str_contains($lower, 'sync') && str_contains($lower, 'iguide')) {
            return $this->confirmationResponse($shoot, 'sync_iguide', 'Sync iGuide', 'I can sync iGuide metadata and queue any eligible floorplan deliverables for this shoot.');
        }

        if (str_contains($lower, 'sync') && str_contains($lower, 'cubicasa')) {
            return $this->confirmationResponse($shoot, 'sync_cubicasa', 'Sync CubiCasa', 'I can sync CubiCasa metadata and queue returned floorplan deliverables for this shoot.');
        }

        if ($cubicasaPreview = $this->buildCubicasaIdentifierPreview($message, $shoot, $user)) {
            return $cubicasaPreview;
        }

        if (str_contains($lower, 'iguide') || str_contains($lower, 'cubicasa')) {
            return $this->integrationStatusResponse($shoot);
        }

        if (str_contains($lower, 'ready for review')) {
            return $this->confirmationResponse($shoot, 'ready_for_review', 'Ready for review', 'I can move this shoot into the ready-for-review workflow step.');
        }

        if ($editorAssignment = $this->buildEditorAssignmentPreview($message, $shoot, $user)) {
            return $editorAssignment;
        }

        if ($tab = $this->detectOpenTab($lower)) {
            return [
                'assistant_messages' => [[
                    'content' => "Opening shoot #{$shoot->id} on the {$tab} tab.",
                    'metadata' => [
                        'type' => 'shoot_operator',
                        'actions' => [[
                            'type' => 'open_shoot_tab',
                            'label' => 'Open shoot',
                            'shootId' => $shoot->id,
                            'tab' => $tab,
                        ]],
                    ],
                ]],
            ];
        }

        $preview = $this->buildUpdatePreview($message, $shoot, $user);
        if ($preview) {
            return $preview;
        }

        if (str_contains($lower, 'issue') || str_contains($lower, 'status') || str_contains($lower, 'overview') || str_contains($lower, 'update')) {
            return $this->shootOverviewResponse($shoot);
        }

        return $this->shootOverviewResponse($shoot);
    }

    public function executeAction(array $payload, User $user): array
    {
        $action = (string) ($payload['type'] ?? '');
        $shootId = $payload['shootId'] ?? $payload['shoot_id'] ?? null;
        $shoot = $shootId ? Shoot::findOrFail($shootId) : null;

        if ($shoot && !$this->canAccessShoot($shoot, $user)) {
            return [
                'status' => 403,
                'payload' => ['message' => 'Forbidden'],
            ];
        }

        return match ($action) {
            'finalize_raw_upload' => $this->finalizeRaw($shoot, $user),
            'sync_iguide' => $this->syncIguide($shoot),
            'sync_cubicasa' => $this->syncCubicasa($shoot),
            'save_cubicasa_identifiers' => $this->saveCubicasaIdentifiers($shoot, $payload, $user),
            'apply_shoot_update' => $this->applyShootUpdate($shoot, $payload, $user),
            default => [
                'status' => 422,
                'payload' => ['message' => 'Unsupported Robbie action.'],
            ],
        };
    }

    protected function handlePendingUpload(string $message, array $context, User $user, array $pendingUpload): array
    {
        $resolution = $this->resolveShoot($message, $context, $user);
        $shoot = $resolution['shoot'] ?? null;

        if (!$shoot) {
            $suggestions = collect($resolution['candidates'] ?? [])
                ->take(4)
                ->map(fn (Shoot $candidate) => "#{$candidate->id} - {$candidate->address}, {$candidate->city}")
                ->values()
                ->all();

            return [
                'assistant_messages' => [[
                    'content' => "I have {$pendingUpload['fileCount']} file(s) ready, but I need a shoot number or address before uploading.",
                    'metadata' => ['type' => 'shoot_upload_target_needed'],
                ]],
                'suggestions' => $suggestions ?: ['Show recent shoots', 'Enter shoot number', 'Enter address'],
            ];
        }

        $fileCount = (int) ($pendingUpload['fileCount'] ?? 0);
        $classification = $pendingUpload['classification'] ?? 'auto';
        $address = trim("{$shoot->address}, {$shoot->city}");

        return [
            'assistant_messages' => [[
                'content' => "I matched {$fileCount} file(s) to shoot #{$shoot->id} - {$address}. Confirm and I will upload them to the shoot media queue. Floorplans and videos will be classified automatically.",
                'metadata' => [
                    'type' => 'shoot_upload_confirmation',
                    'actions' => [[
                        'type' => 'start_robbie_upload',
                        'label' => "Upload to #{$shoot->id}",
                        'shootId' => $shoot->id,
                        'uploadType' => 'raw',
                        'classification' => $classification,
                    ], [
                        'type' => 'open_shoot_tab',
                        'label' => 'Review shoot first',
                        'shootId' => $shoot->id,
                        'tab' => 'media',
                    ]],
                ],
            ]],
            'suggestions' => ['Upload them', 'Pick another shoot', 'Open shoot media'],
        ];
    }

    protected function handleUploadResult(array $uploadResult): array
    {
        $shootId = $uploadResult['shootId'] ?? null;
        $successCount = (int) ($uploadResult['successCount'] ?? 0);
        $errorCount = (int) ($uploadResult['errorCount'] ?? 0);
        $floorplanCount = (int) ($uploadResult['floorplanCount'] ?? 0);
        $videoCount = (int) ($uploadResult['videoCount'] ?? 0);

        $parts = ["Uploaded {$successCount} file(s)"];
        if ($floorplanCount > 0) {
            $parts[] = "{$floorplanCount} floorplan(s)";
        }
        if ($videoCount > 0) {
            $parts[] = "{$videoCount} video(s)";
        }
        if ($errorCount > 0) {
            $parts[] = "{$errorCount} failed";
        }

        $actions = [];
        if ($shootId && $errorCount > 0) {
            $actions[] = [
                'type' => 'start_robbie_upload',
                'label' => 'Retry failed files',
                'shootId' => $shootId,
                'uploadType' => 'raw',
                'classification' => 'auto',
            ];
        }
        if ($shootId && $successCount > 0) {
            $actions[] = [
                'type' => 'finalize_raw_upload',
                'label' => 'Finalize RAW',
                'shootId' => $shootId,
            ];
            $actions[] = [
                'type' => 'open_shoot_tab',
                'label' => 'Open media',
                'shootId' => $shootId,
                'tab' => 'media',
            ];
        }

        return [
            'assistant_messages' => [[
                'content' => implode(', ', $parts) . '. Should I finalize the RAW upload queue for this shoot?',
                'metadata' => [
                    'type' => 'shoot_upload_result',
                    'actions' => $actions,
                ],
            ]],
            'suggestions' => ['Finalize RAW', 'Open media tab', 'Upload more files'],
        ];
    }

    protected function shootOverviewResponse(Shoot $shoot): array
    {
        $shoot->loadMissing(['client:id,name,email', 'photographer:id,name,email', 'services:id,name']);
        $rawCount = (int) ($shoot->raw_photo_count ?? $shoot->files()->where('workflow_stage', 'todo')->count());
        $editedCount = (int) ($shoot->edited_photo_count ?? $shoot->files()->whereIn('workflow_stage', ['completed', 'verified'])->count());
        $issueBits = [];

        if ($shoot->missing_raw) {
            $issueBits[] = 'missing RAW';
        }
        if ($shoot->missing_final) {
            $issueBits[] = 'missing final media';
        }
        if ($shoot->is_flagged) {
            $issueBits[] = 'flagged';
        }

        $content = "Shoot #{$shoot->id} overview\n";
        $content .= "Property: {$shoot->address}, {$shoot->city}, {$shoot->state} {$shoot->zip}\n";
        $content .= "Status: {$shoot->status} / {$shoot->workflow_status}\n";
        $content .= "Schedule: " . ($shoot->scheduled_at?->format('M d, Y g:i A') ?? trim(($shoot->scheduled_date?->format('M d, Y') ?? 'TBD') . ' ' . ($shoot->time ?? ''))) . "\n";
        $content .= "Client: " . ($shoot->client?->name ?? 'Unassigned') . "\n";
        $content .= "Photographer: " . ($shoot->photographer?->name ?? 'Unassigned') . "\n";
        $content .= "Media: {$rawCount} RAW, {$editedCount} edited\n";
        $content .= "Issues: " . (empty($issueBits) ? 'none detected' : implode(', ', $issueBits));

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'type' => 'shoot_operator_overview',
                    'actions' => [[
                        'type' => 'open_shoot_tab',
                        'label' => 'Open overview',
                        'shootId' => $shoot->id,
                        'tab' => 'overview',
                    ], [
                        'type' => 'open_shoot_tab',
                        'label' => 'Open media',
                        'shootId' => $shoot->id,
                        'tab' => 'media',
                    ], [
                        'type' => 'open_shoot_tab',
                        'label' => 'Open tours',
                        'shootId' => $shoot->id,
                        'tab' => 'tours',
                    ]],
                ],
            ]],
            'suggestions' => ['Check iGuide status', 'Check CubiCasa status', 'Open media tab', 'Sync iGuide'],
        ];
    }

    protected function integrationStatusResponse(Shoot $shoot): array
    {
        $iguideLast = optional($shoot->iguide_last_synced_at)->toIso8601String() ?: 'never';
        $cubicasaLast = optional($shoot->cubicasa_last_synced_at)->toIso8601String() ?: 'never';
        $cubicasaStatus = $shoot->cubicasa_status ?: 'not linked';

        $content = "Integration status for shoot #{$shoot->id}\n";
        $content .= "iGuide: property " . ($shoot->iguide_property_id ?: 'not set') . ', work order ' . ($shoot->iguide_work_order_id ?: 'not set') . ", last synced {$iguideLast}\n";
        $content .= "CubiCasa: order " . ($shoot->cubicasa_order_id ?: 'not set') . ', external id ' . ($shoot->cubicasa_external_id ?: 'not set') . ", status {$cubicasaStatus}, last synced {$cubicasaLast}";

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'type' => 'shoot_operator_integrations',
                    'actions' => [[
                        'type' => 'sync_iguide',
                        'label' => 'Sync iGuide',
                        'shootId' => $shoot->id,
                    ], [
                        'type' => 'sync_cubicasa',
                        'label' => 'Sync CubiCasa',
                        'shootId' => $shoot->id,
                    ], [
                        'type' => 'open_shoot_tab',
                        'label' => 'Open tours',
                        'shootId' => $shoot->id,
                        'tab' => 'tours',
                    ]],
                ],
            ]],
            'suggestions' => ['Sync iGuide', 'Sync CubiCasa', 'Open tours tab'],
        ];
    }

    protected function buildUpdatePreview(string $message, Shoot $shoot, User $user): ?array
    {
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return null;
        }

        $payload = [];
        $lower = strtolower($message);

        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $message, $dateMatch)) {
            $payload['scheduled_date'] = $dateMatch[1];
        } elseif (preg_match('/\b(tomorrow|today)\b/i', $message, $dateWord)) {
            $payload['scheduled_date'] = strtolower($dateWord[1]) === 'tomorrow'
                ? now()->addDay()->toDateString()
                : now()->toDateString();
        }

        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $message, $timeMatch)) {
            $payload['time'] = sprintf('%02d:%s', (int) $timeMatch[1], $timeMatch[2]);
        } elseif (str_contains($lower, 'morning')) {
            $payload['time'] = '10:00';
        } elseif (str_contains($lower, 'afternoon')) {
            $payload['time'] = '14:00';
        } elseif (str_contains($lower, 'evening')) {
            $payload['time'] = '17:00';
        }

        if (preg_match('/(?:note|notes?)[:\s]+(.+)$/i', $message, $noteMatch)) {
            $payload['shoot_notes'] = trim($noteMatch[1]);
        }

        if (preg_match('/\bphotographer(?:\s+id)?\s*#?\s*(\d+)\b/i', $message, $photographerMatch)) {
            $payload['photographer_id'] = (int) $photographerMatch[1];
        }

        if ($status = $this->detectStatusUpdate($lower)) {
            $payload['status'] = $status;
            $payload['workflow_status'] = $status;
        }

        if (preg_match('/\b(?:services?|service ids?)\s*[:#]?\s*((?:\d+\s*,?\s*){1,12})\b/i', $message, $serviceMatch)) {
            $serviceIds = collect(preg_split('/[,\s]+/', trim($serviceMatch[1])))
                ->filter(fn ($value) => $value !== '')
                ->map(fn ($value) => (int) $value)
                ->filter()
                ->unique()
                ->values();

            if ($serviceIds->isNotEmpty()) {
                $payload['services'] = $serviceIds
                    ->map(fn (int $id) => ['id' => $id, 'quantity' => 1])
                    ->all();
            }
        }

        if (empty($payload)) {
            return null;
        }

        $summary = collect($payload)
            ->map(fn ($value, $key) => "{$key}: {$value}")
            ->implode("\n");

        return [
            'assistant_messages' => [[
                'content' => "I can update shoot #{$shoot->id} with:\n{$summary}\n\nConfirm to apply these changes.",
                'metadata' => [
                    'type' => 'shoot_update_preview',
                    'actions' => [[
                        'type' => 'apply_shoot_update',
                        'label' => 'Apply update',
                        'shootId' => $shoot->id,
                        'payload' => $payload,
                    ], [
                        'type' => 'open_shoot_tab',
                        'label' => 'Open overview',
                        'shootId' => $shoot->id,
                        'tab' => 'overview',
                    ]],
                ],
            ]],
        ];
    }

    protected function buildCubicasaIdentifierPreview(string $message, Shoot $shoot, User $user): ?array
    {
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return null;
        }

        $lower = strtolower($message);
        if (!str_contains($lower, 'cubicasa')) {
            return null;
        }

        $payload = [];
        if (preg_match('/\border(?:\s+id)?\s*[:#-]?\s*([A-Za-z0-9_-]+)/i', $message, $orderMatch)) {
            $payload['cubicasa_order_id'] = $orderMatch[1];
        }
        if (preg_match('/\bexternal(?:\s+id)?\s*[:#-]?\s*([A-Za-z0-9_-]+)/i', $message, $externalMatch)) {
            $payload['cubicasa_external_id'] = $externalMatch[1];
        }

        if (empty($payload)) {
            return null;
        }

        $summary = collect($payload)
            ->map(fn ($value, $key) => "{$key}: {$value}")
            ->implode("\n");

        return [
            'assistant_messages' => [[
                'content' => "I can save these CubiCasa identifiers for shoot #{$shoot->id}:\n{$summary}\n\nConfirm to save them. After that, you can sync CubiCasa from here.",
                'metadata' => [
                    'type' => 'cubicasa_identifier_preview',
                    'actions' => [[
                        'type' => 'save_cubicasa_identifiers',
                        'label' => 'Save CubiCasa IDs',
                        'shootId' => $shoot->id,
                        ...$payload,
                    ], [
                        'type' => 'open_shoot_tab',
                        'label' => 'Open tours',
                        'shootId' => $shoot->id,
                        'tab' => 'tours',
                    ]],
                ],
            ]],
        ];
    }

    protected function buildEditorAssignmentPreview(string $message, Shoot $shoot, User $user): ?array
    {
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return null;
        }

        if (!preg_match('/\bassign\s+editor(?:\s+id)?\s*#?\s*(\d+)\b/i', $message, $editorMatch)) {
            return null;
        }

        $editorId = (int) $editorMatch[1];

        return [
            'assistant_messages' => [[
                'content' => "I can assign editor #{$editorId} to shoot #{$shoot->id}. Confirm to apply this workflow assignment.",
                'metadata' => [
                    'type' => 'editor_assignment_preview',
                    'actions' => [[
                        'type' => 'assign_editor',
                        'label' => 'Assign editor',
                        'shootId' => $shoot->id,
                        'editorId' => $editorId,
                    ], [
                        'type' => 'open_shoot_tab',
                        'label' => 'Open settings',
                        'shootId' => $shoot->id,
                        'tab' => 'settings',
                    ]],
                ],
            ]],
        ];
    }

    protected function detectStatusUpdate(string $lower): ?string
    {
        if (!preg_match('/\b(mark|move|set|change|update)\b.*\b(status|workflow|scheduled|completed|uploaded|editing|delivered|hold|cancelled|canceled)\b/', $lower)) {
            return null;
        }

        return match (true) {
            str_contains($lower, 'on hold') || str_contains($lower, 'hold') => 'on_hold',
            str_contains($lower, 'cancelled') || str_contains($lower, 'canceled') => 'cancelled',
            str_contains($lower, 'delivered') => 'delivered',
            str_contains($lower, 'editing') => 'editing',
            str_contains($lower, 'uploaded') => 'uploaded',
            str_contains($lower, 'completed') => 'completed',
            str_contains($lower, 'scheduled') => 'scheduled',
            default => null,
        };
    }

    protected function resolveShoot(string $message, array $context, User $user): array
    {
        $contextShootId = Arr::get($context, 'targetShootId') ?? Arr::get($context, 'entityId');
        if ($contextShootId) {
            $shoot = Shoot::find($contextShootId);
            if ($shoot && $this->canAccessShoot($shoot, $user)) {
                return ['shoot' => $shoot, 'candidates' => collect([$shoot])];
            }
        }

        if (preg_match('/#?\b(\d{1,8})\b/', $message, $matches)) {
            $shoot = Shoot::find((int) $matches[1]);
            if ($shoot && $this->canAccessShoot($shoot, $user)) {
                return ['shoot' => $shoot, 'candidates' => collect([$shoot])];
            }
        }

        $addressNeedle = trim((string) (Arr::get($context, 'address') ?? Arr::get($context, 'targetShootAddress') ?? $message));
        $query = $this->visibleShootsQuery($user);

        $candidates = $query
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        $normalizedNeedle = $this->normalizeAddress($addressNeedle);
        if ($normalizedNeedle !== '') {
            $matches = $candidates->filter(function (Shoot $shoot) use ($normalizedNeedle) {
                $candidate = $this->normalizeAddress("{$shoot->address} {$shoot->city} {$shoot->state} {$shoot->zip}");
                return str_contains($candidate, $normalizedNeedle) || str_contains($normalizedNeedle, $candidate);
            })->values();

            if ($matches->count() === 1) {
                return ['shoot' => $matches->first(), 'candidates' => $matches];
            }
            if ($matches->count() > 1) {
                return ['shoot' => null, 'candidates' => $matches];
            }
        }

        $hasShootContext = Arr::get($context, 'entityType') === 'shoot'
            || Arr::get($context, 'page') === 'shoot_details'
            || (bool) $contextShootId;

        return [
            'shoot' => $hasShootContext ? $candidates->first() : null,
            'candidates' => $candidates->take(5),
        ];
    }

    protected function noShootResponse(string $message, array $context, User $user): array
    {
        $candidates = $this->resolveShoot($message, $context, $user)['candidates'] ?? collect();
        $suggestions = collect($candidates)
            ->take(4)
            ->map(fn (Shoot $shoot) => "#{$shoot->id} - {$shoot->address}, {$shoot->city}")
            ->values()
            ->all();

        // When we cannot resolve the shoot but DO have near-matches, offer them:
        // the user picks one and stays in the deterministic flow.
        if (!empty($suggestions)) {
            return [
                'assistant_messages' => [[
                    'content' => "I could not confidently match that to a shoot. Did you mean one of these?",
                    'metadata' => ['type' => 'shoot_operator', 'error' => 'shoot_not_found'],
                ]],
                'suggestions' => $suggestions,
            ];
        }

        // No candidates at all: hand the message to the assistant rather than
        // ending the exchange here. This service runs BEFORE either orchestrator
        // and short-circuits the request, so returning a dead end meant the user
        // got "send a shoot number" and the assistant was never consulted
        // (A1.docx, Robbie screenshot).
        return [
            'handoff' => true,
            'handoff_reason' => 'shoot_operator_unresolved',
            'handoff_context' => [
                'attempted' => 'shoot_operator',
                'message' => $message,
            ],
        ];
    }

    protected function confirmationResponse(Shoot $shoot, string $action, string $label, string $description): array
    {
        return [
            'assistant_messages' => [[
                'content' => "{$description}\n\nConfirm to run this for shoot #{$shoot->id}.",
                'metadata' => [
                    'type' => 'shoot_operator_confirmation',
                    'actions' => [[
                        'type' => $action,
                        'label' => $label,
                        'shootId' => $shoot->id,
                    ]],
                ],
            ]],
        ];
    }

    protected function looksLikeShootOperatorRequest(string $message, array $context): bool
    {
        if (Arr::get($context, 'entityType') === 'shoot' || Arr::get($context, 'page') === 'shoot_details') {
            return true;
        }

        $lower = strtolower($message);

        // A request to create a shoot is not an operation on an existing one.
        // The keyword list below matches the bare word "shoot", so "I want to
        // book a shoot" used to be captured here and could only ever answer with
        // existing shoots — the booking flow never ran and Robbie looked broken
        // (A1.docx Robbie screenshot). Creation intent falls through instead.
        if ($this->looksLikeNewBookingRequest($lower)) {
            return false;
        }

        foreach (['shoot', 'raw', 'floorplan', 'floor plan', 'iguide', 'cubicasa', 'overview', 'sync', 'issue', 'schedule', 'reschedule'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the message asks to create a booking rather than act on one.
     *
     * An explicit shoot reference wins: "reschedule shoot #12" names a record to
     * operate on, so it stays with the operator even though it reads like
     * scheduling. Likewise "reschedule" alone is an operation, not a booking.
     */
    protected function looksLikeNewBookingRequest(string $lower): bool
    {
        if (preg_match('/#\s*\d+/', $lower)) {
            return false;
        }

        // "reschedule" contains "schedule"; strip it so the scheduling verbs
        // below cannot fire on an existing-shoot reschedule request.
        $withoutReschedule = str_replace(['reschedule', 're-schedule'], '', $lower);

        $bookingVerbs = [
            'book a shoot',
            'book shoot',
            'book another',
            'new shoot',
            'new booking',
            'create a shoot',
            'create shoot',
            'schedule a shoot',
            'schedule a new',
            'set up a shoot',
            'want to book',
            'like to book',
            'need to book',
        ];

        foreach ($bookingVerbs as $verb) {
            if (str_contains($withoutReschedule, $verb)) {
                return true;
            }
        }

        return false;
    }

    protected function detectOpenTab(string $lower): ?string
    {
        return match (true) {
            str_contains($lower, 'media') || str_contains($lower, 'raw') || str_contains($lower, 'floorplan') || str_contains($lower, 'floor plan') => 'media',
            str_contains($lower, 'tour') || str_contains($lower, 'iguide') || str_contains($lower, 'cubicasa') => 'tours',
            str_contains($lower, 'setting') => 'settings',
            str_contains($lower, 'note') => 'notes',
            str_contains($lower, 'issue') => 'issues',
            str_contains($lower, 'activity') => 'activity',
            str_contains($lower, 'overview') => 'overview',
            default => null,
        };
    }

    protected function finalizeRaw(?Shoot $shoot, User $user): array
    {
        if (!$shoot) {
            return ['status' => 404, 'payload' => ['message' => 'Shoot not found']];
        }

        return $this->finalizeRawUploadAction->execute($shoot, $user);
    }

    protected function syncIguide(?Shoot $shoot): array
    {
        if (!$shoot) {
            return ['status' => 404, 'payload' => ['message' => 'Shoot not found']];
        }

        $data = $this->iguideService->syncShoot($shoot);
        if (!$data) {
            $reason = $this->iguideService->getLastFailureReason();
            if ($reason === IguideService::FAILURE_WEBHOOK_ONLY) {
                return [
                    'status' => 409,
                    'payload' => [
                        'success' => false,
                        'mode' => 'webhook-only',
                        'message' => 'iGuide will sync automatically when the ready webhook fires.',
                    ],
                ];
            }
            return ['status' => 404, 'payload' => ['success' => false, 'message' => 'iGuide property not found']];
        }

        $floorplans = is_array($data['floorplans'] ?? null) ? $data['floorplans'] : [];
        $shouldIngest = !empty($floorplans) && $shoot->hasIguideEligibleService();
        if ($shouldIngest) {
            IngestIguideAssetsJob::dispatch($shoot->id, $floorplans);
        }

        $shoot->refresh();

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => $shouldIngest
                    ? 'iGuide synced and floorplan deliverables were queued.'
                    : 'iGuide metadata refreshed.',
                'queued_assets' => $shouldIngest ? count($floorplans) : 0,
                'shoot' => [
                    'id' => $shoot->id,
                    'iguide_tour_url' => $shoot->iguide_tour_url,
                    'iguide_property_id' => $shoot->iguide_property_id,
                    'iguide_work_order_id' => $shoot->iguide_work_order_id,
                    'iguide_last_synced_at' => optional($shoot->iguide_last_synced_at)->toIso8601String(),
                ],
            ],
        ];
    }

    protected function syncCubicasa(?Shoot $shoot): array
    {
        if (!$shoot) {
            return ['status' => 404, 'payload' => ['message' => 'Shoot not found']];
        }
        if (empty($shoot->cubicasa_order_id) && empty($shoot->cubicasa_external_id)) {
            return [
                'status' => 409,
                'payload' => [
                    'success' => false,
                    'mode' => 'not-linked',
                    'message' => 'No CubiCasa order linked to this shoot.',
                ],
            ];
        }

        $data = $this->cubicasaService->syncShoot($shoot);
        if (!$data) {
            return [
                'status' => 404,
                'payload' => [
                    'success' => false,
                    'message' => 'CubiCasa order not found or could not be synced.',
                ],
            ];
        }

        $floorplans = is_array($data['floorplans'] ?? null) ? $data['floorplans'] : [];
        $shouldIngest = !empty($floorplans) && $shoot->hasCubiCasaEligibleService();
        if ($shouldIngest) {
            IngestCubiCasaAssetsJob::dispatch($shoot->id, $floorplans);
        }

        $shoot->refresh();

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => $shouldIngest
                    ? 'CubiCasa synced and floorplan deliverables were queued.'
                    : 'CubiCasa metadata refreshed.',
                'queued_assets' => $shouldIngest ? count($floorplans) : 0,
                'shoot' => [
                    'id' => $shoot->id,
                    'cubicasa_order_id' => $shoot->cubicasa_order_id,
                    'cubicasa_external_id' => $shoot->cubicasa_external_id,
                    'cubicasa_status' => $shoot->cubicasa_status,
                    'cubicasa_last_synced_at' => optional($shoot->cubicasa_last_synced_at)->toIso8601String(),
                ],
            ],
        ];
    }

    protected function saveCubicasaIdentifiers(?Shoot $shoot, array $payload, User $user): array
    {
        if (!$shoot) {
            return ['status' => 404, 'payload' => ['message' => 'Shoot not found']];
        }
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return ['status' => 403, 'payload' => ['message' => 'Forbidden']];
        }

        $shoot->cubicasa_order_id = $payload['cubicasa_order_id'] ?? $shoot->cubicasa_order_id;
        $shoot->cubicasa_external_id = $payload['cubicasa_external_id'] ?? $shoot->cubicasa_external_id;
        $shoot->save();

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'CubiCasa identifiers saved.',
            ],
        ];
    }

    protected function applyShootUpdate(?Shoot $shoot, array $payload, User $user): array
    {
        if (!$shoot) {
            return ['status' => 404, 'payload' => ['message' => 'Shoot not found']];
        }

        $updatePayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        if (empty($updatePayload)) {
            return ['status' => 422, 'payload' => ['message' => 'No update payload provided.']];
        }

        $request = Request::create('/api/shoots/' . $shoot->id, 'PATCH', $updatePayload);
        $request->setUserResolver(fn () => $user);

        $updated = $this->updateShootAction->execute($request, $shoot, $user);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'Shoot updated.',
                'shoot_id' => $updated->id,
            ],
        ];
    }

    protected function visibleShootsQuery(User $user)
    {
        $query = Shoot::query();

        return match ($user->role) {
            'client' => $query->where('client_id', $user->id),
            'photographer' => $query->where(function ($q) use ($user) {
                $q->where('photographer_id', $user->id)
                    ->orWhereHas('serviceItems', fn ($serviceQuery) => $serviceQuery->where('photographer_id', $user->id));
            }),
            'editor' => $query->where('editor_id', $user->id),
            'salesRep' => $query->where('rep_id', $user->id),
            default => $query,
        };
    }

    protected function canAccessShoot(Shoot $shoot, User $user): bool
    {
        return match ($user->role) {
            'admin', 'superadmin', 'editing_manager' => true,
            'client' => (string) $shoot->client_id === (string) $user->id,
            'photographer' => (string) $shoot->photographer_id === (string) $user->id
                || $shoot->serviceItems()->where('photographer_id', $user->id)->exists(),
            'editor' => (string) $shoot->editor_id === (string) $user->id,
            'salesRep' => (string) $shoot->rep_id === (string) $user->id,
            default => false,
        };
    }

    protected function normalizeAddress(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }
}
