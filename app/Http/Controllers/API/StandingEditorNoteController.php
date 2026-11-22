<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\StandingEditorNote;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootNotesAccessService;
use App\Services\Shoots\StandingEditorNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Standing editor notes for a client or a brokerage (company-name keyword). */
class StandingEditorNoteController extends Controller
{
    public function __construct(private StandingEditorNoteService $notes) {}

    /** All notes (Settings list), or the notes for one client (account form / booking). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->notes->canRead($request->user()), 403);

        if ($request->filled('client_id')) {
            $client = User::query()->where('role', 'client')->findOrFail((int) $request->query('client_id'));
            $own = StandingEditorNote::query()
                ->where('scope', StandingEditorNote::SCOPE_CLIENT)
                ->where('client_id', $client->id)
                ->first();

            return response()->json(['data' => [
                'client' => ['id' => (int) $client->id, 'name' => (string) $client->name, 'company_name' => $client->company_name],
                'client_note' => $own ? $this->row($own) : null,
                'applicable' => $this->notes->notesForClient($client)->map(fn ($note) => $this->notes->present($note))->values(),
            ]]);
        }

        $rows = StandingEditorNote::query()
            ->with(['client:id,name,company_name', 'updatedByUser:id,name'])
            ->orderByDesc('is_active')->orderBy('scope')->orderBy('id')
            ->get()
            ->map(fn (StandingEditorNote $note) => $this->row($note, true));

        return response()->json(['data' => $rows]);
    }

    public function matches(Request $request): JsonResponse
    {
        abort_unless($this->notes->canRead($request->user()), 403);
        $validated = $request->validate(['company' => 'required|string|max:120']);

        return response()->json(['data' => $this->notes->matchingClients($validated['company'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->notes->canManage($user), 403);
        $validated = $request->validate([
            'scope' => ['required', Rule::in([StandingEditorNote::SCOPE_CLIENT, StandingEditorNote::SCOPE_BROKERAGE])],
            'client_id' => [
                'required_if:scope,client', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('role', 'client'),
            ],
            'company_match' => ['required_if:scope,brokerage', 'nullable', 'string', 'min:2', 'max:120'],
            'content' => 'required|string|max:2000',
            'is_active' => 'sometimes|boolean',
        ]);

        $isClient = $validated['scope'] === StandingEditorNote::SCOPE_CLIENT;
        $attributes = [
            'scope' => $validated['scope'],
            'client_id' => $isClient ? (int) $validated['client_id'] : null,
            'company_match' => $isClient ? null : trim((string) $validated['company_match']),
            'content' => trim($validated['content']),
            'is_active' => $validated['is_active'] ?? true,
            'updated_by' => $user->id,
        ];

        // One client note per client: saving again from the account form updates it.
        $note = $isClient
            ? StandingEditorNote::query()->firstOrNew(['client_id' => $attributes['client_id']])
            : new StandingEditorNote();
        if (! $note->exists) {
            $note->created_by = $user->id;
        }
        $note->fill($attributes)->save();

        return response()->json(['data' => $this->row($note->fresh(), true)], $note->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, StandingEditorNote $note): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->notes->canManage($user), 403);
        $validated = $request->validate([
            'company_match' => [Rule::prohibitedIf(! $note->isBrokerage()), 'sometimes', 'string', 'min:2', 'max:120'],
            'content' => 'sometimes|string|max:2000',
            'is_active' => 'sometimes|boolean',
        ]);
        if (array_key_exists('company_match', $validated)) {
            $validated['company_match'] = trim($validated['company_match']);
        }
        if (array_key_exists('content', $validated)) {
            $validated['content'] = trim($validated['content']);
            abort_if($validated['content'] === '', 422, 'The note cannot be empty. Delete it or switch it off instead.');
        }

        $note->fill($validated + ['updated_by' => $user->id])->save();

        return response()->json(['data' => $this->row($note->fresh(), true)]);
    }

    public function destroy(Request $request, StandingEditorNote $note): JsonResponse
    {
        abort_unless($this->notes->canManage($request->user()), 403);
        $note->delete();

        return response()->json(['message' => 'Standing editor note deleted']);
    }

    /** Hide or show the standing notes on one shoot. */
    public function setShootHidden(
        Request $request,
        Shoot $shoot,
        ShootNotesAccessService $access,
        ShootActivityLogger $activityLogger
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && $access->canUpdateScalar($shoot, $user, 'editor_notes'), 403);
        $validated = $request->validate(['hidden' => 'required|boolean']);

        $hidden = (bool) $validated['hidden'];
        if ((bool) $shoot->standing_editor_notes_hidden !== $hidden) {
            $shoot->forceFill(['standing_editor_notes_hidden' => $hidden])->save();
            $activityLogger->log($shoot, $hidden ? 'standing_editor_notes_hidden' : 'standing_editor_notes_shown', [
                'by' => $user->name,
            ], $user);
        }

        return response()->json(['data' => $this->notes->presentForShoot($shoot->fresh(), $user)]);
    }

    /** @return array<string, mixed> */
    private function row(StandingEditorNote $note, bool $withMatches = false): array
    {
        $row = $this->notes->present($note) + [
            'is_active' => (bool) $note->is_active,
            'client_name' => $note->client?->name,
            'updated_by_name' => $note->updatedByUser?->name,
        ];
        if ($withMatches) {
            $row['matched_count'] = $note->isBrokerage()
                ? $this->notes->matchingClients((string) $note->company_match, 0)['count']
                : 1;
        }

        return $row;
    }
}
