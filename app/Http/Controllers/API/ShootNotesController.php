<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Shoot;
use App\Services\Payments\StripePaymentMetadataService;
use App\Services\ShootActivityLogger;
use Illuminate\Http\Request;

class ShootNotesController extends Controller
{
    public function __construct(
        protected ShootActivityLogger $activityLogger,
        protected StripePaymentMetadataService $stripePaymentMetadataService
    )
    {
    }

    public function getNotes(Request $request, Shoot $shoot)
    {
        $role = strtolower($request->user()->role ?? '');

        $notes = $shoot->notes()
            ->with('author:id,name,email')
            ->get()
            ->filter(function ($note) use ($role) {
                if ($role === 'editor') {
                    return $note->type === 'editing';
                }

                return true;
            })
            ->filter(fn ($note) => $note->isVisibleToRole($role))
            ->values();

        return response()->json([
            'data' => $notes->map(function ($note) {
                return [
                    'id' => $note->id,
                    'type' => $note->type,
                    'visibility' => $note->visibility,
                    'content' => $note->content,
                    'author' => [
                        'id' => $note->author->id,
                        'name' => $note->author->name,
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
        $role = strtolower($user->role ?? '');

        $validated = $request->validate([
            'type' => 'required|in:shoot,company,photographer,editing',
            'visibility' => 'required|in:internal,photographer_only,client_visible',
            'content' => 'required|string|max:5000',
        ]);

        $allowedTypes = match ($role) {
            'admin', 'superadmin' => ['shoot', 'company', 'photographer', 'editing'],
            'client' => ['shoot'],
            'photographer' => ['photographer', 'shoot'],
            'editor' => [],
            default => [],
        };

        if (!in_array($validated['type'], $allowedTypes, true)) {
            return response()->json([
                'message' => 'You are not authorized to create notes of this type',
            ], 403);
        }

        if ($role === 'client' && $validated['visibility'] !== 'client_visible') {
            return response()->json([
                'message' => 'Clients can only create client-visible notes',
            ], 403);
        }

        $note = $shoot->notes()->create([
            'author_id' => $user->id,
            'type' => $validated['type'],
            'visibility' => $validated['visibility'],
            'content' => $validated['content'],
        ]);

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

    public function updateNotesSimple(Request $request, $shootId)
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

        $data = $request->only($allowed);
        $camel = [
            'shootNotes' => 'shoot_notes',
            'companyNotes' => 'company_notes',
            'photographerNotes' => 'photographer_notes',
            'editingNotes' => 'editor_notes',
            'editorNotes' => 'editor_notes',
        ];
        foreach ($camel as $from => $to) {
            if (in_array($to, $allowed, true) && $request->has($from) && !array_key_exists($to, $data)) {
                $data[$to] = $request->input($from);
            }
        }

        if (!empty($data)) {
            $shoot->fill($data);
            $shoot->save();
        }

        return response()->json([
            'message' => empty($data) ? 'No changes detected' : 'Notes updated',
            'data' => $shoot->only(['id', 'shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes']),
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
            ->orderBy('created_at', 'desc')
            ->get();
        $paymentIds = $activityLogs
            ->map(function ($log) {
                $metadata = is_array($log->metadata) ? $log->metadata : [];

                return $metadata['payment_id'] ?? $metadata['paymentId'] ?? null;
            })
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
            'data' => $activityLogs->map(function ($log) use ($paymentsById) {
                $metadata = is_array($log->metadata) ? $log->metadata : [];
                $paymentId = isset($metadata['payment_id']) ? (string) $metadata['payment_id'] : (isset($metadata['paymentId']) ? (string) $metadata['paymentId'] : null);
                $payment = $paymentId ? $paymentsById->get($paymentId) : null;

                if ($payment instanceof Payment) {
                    $metadata = array_merge($metadata, $this->stripePaymentMetadataService->buildActivityMetadata($payment));
                }

                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'metadata' => $metadata,
                    'created_at' => $log->created_at->toIso8601String(),
                    'timestamp' => $log->created_at->toIso8601String(),
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'role' => $log->user->role,
                    ] : null,
                ];
            }),
        ]);
    }
}
