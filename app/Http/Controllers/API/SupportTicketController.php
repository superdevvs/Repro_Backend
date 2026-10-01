<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    public const CATEGORIES = ['account', 'booking', 'delivery', 'billing', 'uploads', 'calls', 'other'];

    public const STATUSES = ['open', 'in_progress', 'waiting', 'resolved'];

    public function __construct(private SupportTicketService $tickets) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();
        $canManage = $this->tickets->canManage($user);
        $query = $this->tickets->visible($user)->with(['requester:id,name,role', 'assignee:id,name']);
        if ($status = $data['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($search = trim($data['query'] ?? '')) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw("subject LIKE ? ESCAPE '\\'", ['%'.addcslashes($search, '%_\\').'%']);
                if (preg_match('/^(?:SUP-)?0*([0-9]+)$/i', $search, $match)) {
                    $q->orWhere('id', (int) $match[1]);
                }
            });
        }
        $page = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn ($item) => $this->tickets->payload($item, $user, $canManage)), 'meta' => [
            'can_manage' => $canManage, 'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            ], 'categories' => self::CATEGORIES, 'statuses' => self::STATUSES,
        ]]);
    }

    public function assignees(Request $request)
    {
        abort_unless($this->tickets->canManage($request->user()), 403);

        return response()->json(['data' => User::whereIn('role', ['admin', 'superadmin'])->orderBy('name')->get()
            ->filter(fn ($user) => $this->tickets->canManage($user))->map(fn ($user) => $user->only(['id', 'name']))->values()]);
    }

    public function show(Request $request, int $ticket)
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        $item = $this->tickets->find($user, $ticket);
        $messages = $item->messages()->with('author:id,name')
            ->when(! $this->tickets->canManage($user), fn ($q) => $q->where('internal', false))
            ->latest('id')->paginate(30, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => $this->tickets->payload($item, $user),
            'messages' => $messages->getCollection()->reverse()->values()->map(fn ($message) => [
                'id' => $message->id, 'body' => $message->body, 'kind' => $message->kind,
                'internal' => $message->internal, 'author' => $message->author?->only(['id', 'name']),
                'created_at' => $message->created_at->toIso8601String(),
            ]), 'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage(), 'total' => $messages->total()]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:8000'], 'category' => ['required', Rule::in(self::CATEGORIES)],
            'page_path' => ['nullable', 'string', 'max:240', 'regex:~^/[A-Za-z0-9/_-]*$~'],
        ]);
        $ticket = $this->tickets->create($request->user(), $data);

        return response()->json(['data' => $this->tickets->payload($ticket, $request->user()),
            'message' => 'Request saved. The support team can see it in their dashboard.'], 201);
    }

    public function reply(Request $request, int $ticket)
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'body' => ['required', 'string', 'min:1', 'max:8000'],
            'internal' => ['sometimes', 'boolean'],
        ]);
        $item = $this->tickets->reply($request->user(), $ticket, $data);

        return response()->json(['data' => $this->tickets->payload($item, $request->user())]);
    }

    public function update(Request $request, int $ticket)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'status' => ['sometimes', Rule::in(self::STATUSES)],
            'priority' => ['sometimes', Rule::in(['normal', 'urgent'])], 'assigned_to' => ['sometimes', 'nullable', 'integer'],
        ]);
        abort_unless(count($data) > 1, 422, 'Choose a change to save.');
        $item = $this->tickets->update($request->user(), $ticket, $data);

        return response()->json(['data' => $this->tickets->payload($item, $request->user())]);
    }
}
