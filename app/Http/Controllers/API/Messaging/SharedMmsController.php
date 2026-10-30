<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\SmsSharedGroup;
use App\Models\SmsSharedMessage;
use App\Services\Messaging\SharedMmsService;
use Illuminate\Http\Request;

class SharedMmsController extends Controller
{
    public function __construct(private SharedMmsService $groups) {}
    private function user(Request $r)
    {
        abort_if($r->attributes->get('is_impersonating') || $r->hasHeader('X-Impersonate-User-Id'), 403, 'Use your own account for group conversations.');
        $user = $r->user();
        $this->groups->authorize($user);
        return $user;
    }
    private function json(array $value, int $status = 200)
    {
        return response()->json($value, $status)->header('Cache-Control', 'no-store, private');
    }
    public function capabilities(Request $r) { return $this->json($this->groups->capabilities($this->user($r))); }
    public function index(Request $r)
    {
        $user = $this->user($r);
        $page = $this->pagination($r);
        $rows = SmsSharedGroup::whereHas('members', fn ($q) => $q->whereKey($user->id))->orderByDesc('last_message_at')->orderBy('id')->paginate($page);
        return $this->json(['data' => $rows->map(fn ($g) => $this->groups->presentGroup($g, $user))->all(),
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]);
    }
    public function show(Request $r, SmsSharedGroup $sharedGroup)
    {
        $user = $this->user($r); $this->groups->authorize($user, $sharedGroup);
        $rows = $sharedGroup->messages()->orderByDesc('sequence')->paginate($this->pagination($r));
        return $this->json(['data' => $this->groups->presentGroup($sharedGroup, $user),
            'messages' => $rows->map(fn ($m) => $this->groups->presentMessage($m))->all(),
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(),
            'read_cursor_message_id' => $rows->currentPage() === 1 ? $rows->first()?->id : null]);
    }
    public function markRead(Request $r, SmsSharedGroup $sharedGroup)
    {
        $user = $this->user($r);
        $data = $r->validate(['last_message_id' => ['required', 'uuid']]);
        return $this->json(['unread_count' => $this->groups->markRead($user, $sharedGroup, $data['last_message_id'])]);
    }
    public function requestStatus(Request $r, string $requestKey)
    {
        $user = $this->user($r);
        $m = SmsSharedMessage::where('created_by', $user->id)->where('request_key', strtolower($requestKey))->firstOrFail();
        $this->groups->authorize($user, $m->group);
        return $this->json($this->groups->envelope($m, $user));
    }
    public function store(Request $r)
    {
        $user = $this->user($r);
        $data = $r->validate($this->messageRules() + [
            'sms_number_id' => ['required', 'integer', 'min:1'], 'title' => ['nullable', 'string', 'max:80'],
            'participants' => ['required', 'array', 'min:2', 'max:8'], 'participants.*' => ['required', 'string', 'max:20', 'distinct:strict'],
            'member_user_ids' => ['sometimes', 'array', 'max:20'], 'member_user_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $message = $this->groups->create($user, $data);
        return $this->json($this->groups->envelope($message, $user), in_array($message->status, ['uncertain', 'sending'], true) ? 202 : 201);
    }
    public function send(Request $r, SmsSharedGroup $sharedGroup)
    {
        $user = $this->user($r);
        $message = $this->groups->send($user, $sharedGroup, $r->validate($this->messageRules()));
        return $this->json($this->groups->envelope($message, $user), in_array($message->status, ['uncertain', 'sending'], true) ? 202 : 200);
    }
    private function pagination(Request $r): int
    {
        $data = $r->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        return $data['per_page'] ?? 25;
    }
    private function messageRules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'body_text' => ['required', 'string', 'max:1200'], 'media_urls' => ['prohibited'], 'attachments' => ['prohibited']];
    }
}
