<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Http\Resources\Messaging\SmsContactResource;
use App\Http\Resources\Messaging\SmsMessageResource;
use App\Http\Resources\Messaging\SmsThreadResource;
use App\Models\Contact;
use App\Models\MessageThread;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SmsMessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging)
    {
    }

    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = Str::of($request->string('filter')->toString())->lower()->value();
        $search = $request->string('search')->toString();
        $perPage = (int) $request->integer('per_page', 25);

        $threads = $this->messaging
            ->listThreads(['channel' => 'SMS'])
            ->with(['contact', 'assignedTo'])
            ->when($search, function ($query) use ($search) {
                $query->whereHas('contact', function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filter === 'unanswered', fn ($query) => $query->where('last_direction', 'INBOUND'))
            ->when($filter === 'my_recents', function ($query) use ($user) {
                if ($user) {
                    $query->where('assigned_to_user_id', $user->id);
                }
            })
            ->when($filter === 'clients', function ($query) {
                $query->whereHas('contact', fn ($sub) => $sub->where('type', 'client'));
            })
            ->paginate($perPage);

        return SmsThreadResource::collection($threads)->response();
    }

    public function showThread(MessageThread $thread, Request $request): JsonResponse
    {
        $this->ensureSmsThread($thread);
        $this->authorizeThread($thread, $request->user()?->id);

        $thread->load(['contact', 'assignedTo']);
        $messages = $thread->messages()->orderBy('created_at')->get();
        $this->markThreadAsRead($thread, $request->user()?->id);

        return response()->json([
            'thread' => SmsThreadResource::make($thread),
            'messages' => SmsMessageResource::collection($messages),
            'contact' => SmsContactResource::make($thread->contact),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'body_text' => ['required', 'string', 'max:1200'],
            'sms_number_id' => ['nullable', 'exists:sms_numbers,id'],
            'contact_name' => ['nullable', 'string'],
            'contact_type' => ['nullable', 'string'],
        ]);

        $message = $this->messaging->sendSms(array_merge($data, [
            'user_id' => $request->user()?->id,
            'contact_phone' => $data['to'],
        ]));

        $thread = $message->thread->load(['contact', 'assignedTo']);

        return response()->json([
            'message' => SmsMessageResource::make($message),
            'thread' => SmsThreadResource::make($thread),
        ]);
    }

    public function sendToThread(MessageThread $thread, Request $request): JsonResponse
    {
        $this->ensureSmsThread($thread);
        $this->authorizeThread($thread, $request->user()?->id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1200'],
            'sms_number_id' => ['nullable', 'exists:sms_numbers,id'],
        ]);

        $contact = $thread->contact ?? Contact::findOrFail($thread->contact_id);
        $toNumber = $contact->phone ?? Arr::get($contact->phones_json, '0.number');

        if (!$toNumber) {
            return response()->json([
                'message' => 'Contact does not have a phone number on file.',
            ], 422);
        }

        $message = $this->messaging->sendSms([
            'to' => $toNumber,
            'body_text' => $data['body'],
            'sms_number_id' => $data['sms_number_id'] ?? null,
            'user_id' => $request->user()?->id,
            'contact_phone' => $toNumber,
            'contact_name' => $contact->name,
            'contact_type' => $contact->type,
        ]);

        $thread->refresh()->load(['contact', 'assignedTo']);

        return response()->json([
            'message' => SmsMessageResource::make($message),
            'thread' => SmsThreadResource::make($thread),
        ]);
    }

    public function markRead(MessageThread $thread, Request $request): JsonResponse
    {
        $this->ensureSmsThread($thread);
        $this->authorizeThread($thread, $request->user()?->id);

        $this->markThreadAsRead($thread, $request->user()?->id);

        return response()->json(['status' => 'ok']);
    }

    protected function ensureSmsThread(MessageThread $thread): void
    {
        abort_if($thread->channel !== 'SMS', 404);
    }

    protected function authorizeThread(MessageThread $thread, ?int $userId): void
    {
        if (!$userId) {
            abort(403);
        }
    }

    protected function markThreadAsRead(MessageThread $thread, ?int $userId): void
    {
        if (!$userId) {
            return;
        }

        $remaining = collect($thread->unread_for_user_ids_json ?? [])
            ->reject(fn ($id) => (int) $id === (int) $userId)
            ->values()
            ->all();

        $thread->update(['unread_for_user_ids_json' => $remaining]);
    }
}

