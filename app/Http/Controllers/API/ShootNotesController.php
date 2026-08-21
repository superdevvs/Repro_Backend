<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\WorkflowLog;
use App\Services\Payments\StripePaymentMetadataService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootNotesAccessService;
use App\Services\Shoots\ShootNotesCompatibilityService;
use Illuminate\Http\Request;

class ShootNotesController extends Controller
{
    private const WORKFLOW_ACTIVITY_ACTIONS = [
        'finalize_completed' => 'shoot_finalized_delivered',
        'status_changed_to_delivered' => 'shoot_finalized_delivered',
        'slideshow_created' => 'tour_links_generated',
        'tour_links_generated' => 'tour_links_generated',
        'bright_mls_synced' => 'bright_mls_synced',
    ];

    public function __construct(
        protected ShootActivityLogger $activityLogger,
        protected StripePaymentMetadataService $stripePaymentMetadataService,
        protected ShootNotesAccessService $notesAccess,
        protected ShootNotesCompatibilityService $notesCompatibility
    )
    {
    }

    public function getNotes(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (! $user || ! $this->notesAccess->canRead($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $notes = $shoot->notes()
            ->with('author:id,name,email')
            ->latest('id')
            ->get();
        $notes = $this->notesAccess->visibleNotes($notes, $user)
            ->values();

        return response()->json([
            'data' => $notes->map(function ($note) {
                return [
                    'id' => $note->id,
                    'type' => $note->type,
                    'visibility' => $note->visibility,
                    'content' => $note->content,
                    'author' => $note->author ? [
                        'id' => $note->author->id,
                        'name' => $note->author->name,
                    ] : [
                        'id' => null,
                        'name' => 'Legacy/System',
                    ],
                    'created_at' => $note->created_at->toIso8601String(),
                    'updated_at' => $note->updated_at->toIso8601String(),
                ];
            }),
        ]);
    }

    public function storeNote(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $validated = $request->validate([
            'type' => 'required|in:shoot,company,photographer,editing',
            'visibility' => 'required|in:internal,photographer_only,client_visible',
            'content' => 'required|string|max:5000',
        ]);

        if (! $this->notesAccess->canCreate(
            $shoot,
            $user,
            $validated['type'],
            $validated['visibility']
        )) {
            return response()->json([
                'message' => 'You are not authorized to create notes of this type',
            ], 403);
        }

        $note = $shoot->notes()->create([
            'author_id' => $user->id,
            'type' => $validated['type'],
            'visibility' => $validated['visibility'],
            'content' => $validated['content'],
        ]);

        $scalarField = $this->notesCompatibility->scalarFieldFor($note->type, $note->visibility);
        if ($scalarField) {
            $shoot->{$scalarField} = $note->content;
            $shoot->save();
        }

        $this->activityLogger->log(
            $shoot,
            'note_added',
            [
                'note_id' => $note->id,
                'type' => $note->type,
                'visibility' => $note->visibility,
            ],
            $user
        );

        return response()->json([
            'message' => 'Note created successfully',
            'data' => [
                'id' => $note->id,
                'type' => $note->type,
                'visibility' => $note->visibility,
                'content' => $note->content,
                'author' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'created_at' => $note->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function updateNotesSimple(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (! $user || ! $this->notesAccess->canRead($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'shoot_notes' => 'nullable|string|max:5000',
            'company_notes' => 'nullable|string|max:5000',
            'photographer_notes' => 'nullable|string|max:5000',
            'editor_notes' => 'nullable|string|max:5000',
            'shootNotes' => 'nullable|string|max:5000',
            'companyNotes' => 'nullable|string|max:5000',
            'photographerNotes' => 'nullable|string|max:5000',
            'editingNotes' => 'nullable|string|max:5000',
            'editorNotes' => 'nullable|string|max:5000',
        ]);

        $camel = [
            'shootNotes' => 'shoot_notes',
            'companyNotes' => 'company_notes',
            'photographerNotes' => 'photographer_notes',
            'editingNotes' => 'editor_notes',
            'editorNotes' => 'editor_notes',
        ];
        $data = [];
        foreach (['shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'] as $field) {
            if ($request->exists($field)) {
                $data[$field] = $request->input($field);
            }
        }
        foreach ($camel as $from => $to) {
            if ($request->exists($from) && ! array_key_exists($to, $data)) {
                $data[$to] = $request->input($from);
            }
        }

        $authorized = [];
        foreach ($data as $field => $value) {
            if ($this->notesAccess->canUpdateScalar($shoot, $user, $field)) {
                $authorized[$field] = $value;
            }
        }

        if ($data !== [] && $authorized === []) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $previous = [];
        foreach ($authorized as $field => $value) {
            $previous[$field] = $shoot->{$field};
            $shoot->{$field} = $value;
        }
        if ($authorized !== []) {
            $shoot->save();
            foreach ($authorized as $field => $value) {
                $this->notesCompatibility->syncScalarField($shoot, $field, $value, $user, $previous[$field]);
            }
        }

        return response()->json([
            'message' => $authorized === [] ? 'No changes detected' : 'Notes updated',
            'data' => $this->visibleScalarNotes($shoot, $user),
        ]);
    }

    public function updateNotes(Request $request, $shootId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $role = strtolower(str_replace('-', '_', $request->user()->role ?? ''));

        $request->validate([
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
        ]);

        $allowed = [];
        if (in_array($role, ['admin', 'superadmin'], true)) {
            $allowed = ['shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'];
        } elseif ($role === 'client') {
            $allowed = ['shoot_notes'];
        } elseif ($role === 'photographer') {
            $allowed = ['photographer_notes'];
        }

        if (empty($allowed)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $input = $request->all();
        $synonyms = [
            'shootNotes' => 'shoot_notes',
            'companyNotes' => 'company_notes',
            'photographerNotes' => 'photographer_notes',
            'editingNotes' => 'editor_notes',
            'editorNotes' => 'editor_notes',
        ];
        foreach ($synonyms as $from => $to) {
            if (array_key_exists($from, $input) && !array_key_exists($to, $input)) {
                $input[$to] = $input[$from];
            }
        }

        if (array_key_exists('notes', $input)) {
            $notesPayload = $input['notes'];
            if (is_string($notesPayload)) {
                $input['shoot_notes'] = $notesPayload;
            } elseif (is_array($notesPayload)) {
                foreach ($synonyms as $from => $to) {
                    if (array_key_exists($from, $notesPayload) && !array_key_exists($to, $input)) {
                        $input[$to] = $notesPayload[$from];
                    }
                }
                foreach (['shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'] as $field) {
                    if (array_key_exists($field, $notesPayload) && !array_key_exists($field, $input)) {
                        $input[$field] = $notesPayload[$field];
                    }
                }
            }
        }

        $updates = [];
        foreach (['shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'] as $field) {
            if (array_key_exists($field, $input) && in_array($field, $allowed, true)) {
                $updates[$field] = $input[$field];
            }
        }

        if (empty($updates)) {
            return response()->json([
                'message' => 'No changes detected',
                'data' => $shoot->only(['id', 'shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes']),
            ]);
        }

        foreach ($updates as $field => $value) {
            $shoot->{$field} = $value;
        }
        $shoot->save();

        return response()->json([
            'message' => 'Notes updated',
            'data' => $shoot->only(['id', 'shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes']),
        ]);
    }

    public function getActivityLog(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $role = strtolower($user->role ?? '');

        if ($user->role === 'client' && (string) $shoot->client_id !== (string) $user->id) {
            abort(403, 'Forbidden');
        }

        if (!in_array($role, ['admin', 'superadmin', 'editing_manager', 'salesrep', 'client'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $activityLogs = $shoot->activityLogs()
            ->with('user:id,name,role')
            ->get();

        $workflowLogs = $shoot->workflowLogs()
            ->with('user:id,name,role')
            ->whereIn('action', array_keys(self::WORKFLOW_ACTIVITY_ACTIONS))
            ->get()
            ->reject(function (WorkflowLog $log) use ($activityLogs) {
                $mappedAction = self::WORKFLOW_ACTIVITY_ACTIONS[$log->action] ?? $log->action;

                return $activityLogs->contains('action', $mappedAction);
            })
            ->values();

        $entries = collect($activityLogs)
            ->map(fn ($log) => $this->formatActivityLogEntry($log))
            ->merge($workflowLogs->map(fn (WorkflowLog $log) => $this->formatWorkflowLogEntry($log)))
            ->sortByDesc(fn (array $entry) => $entry['sort_at'])
            ->values();

        $paymentIds = $entries
            ->map(fn (array $entry) => $entry['metadata']['payment_id'] ?? $entry['metadata']['paymentId'] ?? null)
            ->filter()
            ->map(fn ($paymentId) => (string) $paymentId)
            ->unique()
            ->values();

        $paymentsById = Payment::query()
            ->whereIn('id', $paymentIds)
            ->get()
            ->map(function (Payment $payment) {
                return $this->stripePaymentMetadataService->hydratePaymentRecordIfNeeded($payment);
            })
            ->keyBy(fn (Payment $payment) => (string) $payment->id);

        return response()->json([
            'data' => $entries->map(function (array $entry) use ($paymentsById) {
                $metadata = $entry['metadata'];
                $paymentId = isset($metadata['payment_id']) ? (string) $metadata['payment_id'] : (isset($metadata['paymentId']) ? (string) $metadata['paymentId'] : null);
                $payment = $paymentId ? $paymentsById->get($paymentId) : null;

                if ($payment instanceof Payment) {
                    $metadata = array_merge($metadata, $this->stripePaymentMetadataService->buildActivityMetadata($payment));
                }

                unset($entry['sort_at']);
                $entry['metadata'] = $metadata;

                return $entry;
            }),
        ]);
    }

    private function formatActivityLogEntry($log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'metadata' => is_array($log->metadata) ? $log->metadata : [],
            'created_at' => $log->created_at->toIso8601String(),
            'timestamp' => $log->created_at->toIso8601String(),
            'user' => $this->formatActivityUser($log->user),
            'source' => 'activity',
            'sort_at' => $log->created_at,
        ];
    }

    private function visibleScalarNotes(Shoot $shoot, $user): array
    {
        $role = strtolower(str_replace(['-', ' '], '_', (string) ($user->role ?? '')));
        $fields = match ($role) {
            'admin', 'superadmin' => ['id', 'shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'],
            'client' => ['id', 'shoot_notes'],
            'photographer' => ['id', 'shoot_notes', 'photographer_notes'],
            'editor' => ['id', 'editor_notes'],
            default => ['id'],
        };

        return $shoot->only($fields);
    }

    private function formatWorkflowLogEntry(WorkflowLog $log): array
    {
        $action = self::WORKFLOW_ACTIVITY_ACTIONS[$log->action] ?? $log->action;
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $metadata = array_merge($metadata, [
            'workflow_action' => $log->action,
            'workflow_details' => $log->details,
        ]);

        if ($action === 'shoot_finalized_delivered' && $log->user) {
            $metadata['finalized_by_role'] = $metadata['finalized_by_role'] ?? $log->user->role;
            $metadata['finalized_by_name'] = $metadata['finalized_by_name'] ?? $log->user->name;
        }

        return [
            'id' => 'workflow-' . $log->id,
            'action' => $action,
            'description' => $this->activityLogger->describe($action, $metadata),
            'metadata' => $metadata,
            'created_at' => $log->created_at->toIso8601String(),
            'timestamp' => $log->created_at->toIso8601String(),
            'user' => $this->formatActivityUser($log->user),
            'source' => 'workflow',
            'sort_at' => $log->created_at,
        ];
    }

    private function formatActivityUser($user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ] : null;
    }
}
