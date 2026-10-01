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

        return response()->json(['data' => User::whereIn('role', ['admin', 'superadmin', 'editing_manager', 'editing-manager', 'editingManager'])->orderBy('name')->get()
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
                'attachments' => app(\App\Services\SupportAttachmentService::class)->payload($message),
            ]), 'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage(), 'total' => $messages->total()]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:8000'], 'category' => ['required', Rule::in(self::CATEGORIES)],
            'page_path' => ['nullable', 'string', 'max:240', 'regex:~^/[A-Za-z0-9/_-]*$~'],
            ...\App\Services\SupportAttachmentService::rules(),
        ]);
        $ticket = app(\App\Services\SupportAttachmentService::class)->submit($request, $data, fn ($prepared) => $this->tickets->create($request->user(), $prepared));

        return response()->json(['data' => $this->tickets->payload($ticket, $request->user()),
            'message' => 'Request saved. The support team can see it in their dashboard.'], 201);
    }

    public function reply(Request $request, int $ticket)
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'body' => ['required', 'string', 'min:1', 'max:8000'],
            'internal' => ['sometimes', 'boolean'],
            ...\App\Services\SupportAttachmentService::rules(),
        ]);
        $item = app(\App\Services\SupportAttachmentService::class)->submit($request, $data, fn ($prepared) => $this->tickets->reply($request->user(), $ticket, $prepared));

        return response()->json(['data' => $this->tickets->payload($item, $request->user())]);
    }

    public function legacyMessage(Request $request, int $message)
    {
        $source = \App\Models\SupportTicketMessage::where('source_message_id', $message)->firstOrFail();
        $ticket = $this->tickets->find($request->user(), $source->support_ticket_id);
        abort_if($source->internal && ! $this->tickets->canManage($request->user()), 404);

        return response()->json(['support_ticket_id' => $ticket->id, 'redirect_url' => '/messaging/email/inbox?tab=support&ticket='.$ticket->id]);
    }

    public function attachment(Request $request, int $ticket, int $message, int $index)
    {
        $item = $this->tickets->find($request->user(), $ticket);
        $entry = $item->messages()->whereKey($message)
            ->when(! $this->tickets->canManage($request->user()), fn ($q) => $q->where('internal', false))->firstOrFail();
        $file = ($entry->attachments_json ?? [])[$index] ?? null;
        abort_unless(is_array($file), 404);
        $path = $file['storage_path'] ?? '';
        $disk = $file['disk'] ?? 'local';
        // Never resolve arbitrary URLs, absolute paths, traversal or another private namespace.
        abort_unless(is_string($path) && preg_match('~^(?:support|messaging)-attachments/[A-Za-z0-9/_.-]+$~D', $path)
            && ! str_contains($path, '..') && is_string($disk) && config('filesystems.disks.'.$disk), 404);
        $storage = \Illuminate\Support\Facades\Storage::disk($disk);
        abort_unless($storage->exists($path), 404, 'This saved attachment is no longer available.');

        return $storage->download($path, basename(str_replace('\\', '/', (string) ($file['name'] ?? 'Attachment'))), [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
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
