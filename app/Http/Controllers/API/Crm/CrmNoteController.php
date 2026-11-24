<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmNote;
use App\Services\Crm\CrmNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notes on a contact. The contact is resolved through the owner scope, so a rep cannot reach another rep's notes. */
class CrmNoteController extends CrmController
{
    public function __construct(private readonly CrmNoteService $notes) {}

    public function index(Request $request, CrmContact $contact): JsonResponse
    {
        $notes = CrmNote::query()->where('contact_id', $contact->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json(['data' => $notes->map(fn ($n) => $this->presenter()->note($n))->all()]);
    }

    public function store(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $note = $this->notes->add($contact, $data['body'], $this->actor($request));

        return response()->json(['note' => $this->presenter()->note($note)], 201);
    }

    public function update(Request $request, CrmContact $contact, CrmNote $note): JsonResponse
    {
        abort_unless((int) $note->contact_id === (int) $contact->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $this->notes->edit($note, $data['body'], $this->actor($request));

        return response()->json(['note' => $this->presenter()->note($note->fresh())]);
    }

    public function versions(Request $request, CrmContact $contact, CrmNote $note): JsonResponse
    {
        abort_unless((int) $note->contact_id === (int) $contact->id, 404);

        return response()->json([
            'note' => $this->presenter()->note($note),
            'versions' => $note->versions()->get()->map(fn ($v) => [
                'id' => $v->id,
                'body' => $v->body,
                'saved_at' => $v->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
