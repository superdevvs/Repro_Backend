<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailMessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging)
    {
    }

    public function messages(Request $request): JsonResponse
    {
        $filters = [
            'channel' => 'EMAIL',
            'status' => $request->query('status'),
        ];

        if ($request->has('channel_id')) {
            $filters['message_channel_id'] = $request->query('channel_id');
        }

        if ($request->has('send_source')) {
            $filters['send_source'] = $request->query('send_source');
        }

        if ($request->has('search')) {
            $filters['search'] = $request->query('search');
        }

        $messages = $this->messaging
            ->getMessageLogs($filters)
            ->with(['template', 'channelConfig', 'shoot', 'invoice'])
            ->paginate($request->query('per_page', 25));

        return response()->json($messages);
    }

    public function threads(Request $request): JsonResponse
    {
        $threads = $this->messaging
            ->listThreads(['channel' => 'EMAIL'])
            ->paginate(25);

        return response()->json($threads);
    }

    public function compose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'template_id' => ['nullable', 'exists:message_templates,id'],
            'channel_id' => ['nullable', 'exists:message_channels,id'],
            'related_shoot_id' => ['nullable', 'integer'],
            'related_account_id' => ['nullable', 'integer'],
        ]);

        if (empty($data['body_html']) && empty($data['body_text'])) {
            throw ValidationException::withMessages([
                'body_text' => 'Either HTML or text body is required.',
            ]);
        }

        $message = $this->messaging->sendEmail(array_merge($data, [
            'user_id' => $request->user()->id,
            'contact_email' => $data['to'],
        ]));

        return response()->json($message);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'channel_id' => ['nullable', 'exists:message_channels,id'],
        ]);

        $scheduledAt = \Carbon\Carbon::parse($data['scheduled_at']);

        $message = $this->messaging->scheduleEmail(
            array_merge($data, ['user_id' => $request->user()->id]),
            $scheduledAt
        );

        return response()->json($message);
    }

    public function retry(Message $message): JsonResponse
    {
        if ($message->channel !== 'EMAIL') {
            abort(400, 'Can only retry email messages.');
        }

        $newMessage = $this->messaging->sendEmail([
            'to' => $message->to_address,
            'subject' => $message->subject,
            'body_html' => $message->body_html,
            'body_text' => $message->body_text,
            'channel_id' => $message->message_channel_id,
            'user_id' => request()->user()->id,
        ]);

        return response()->json($newMessage);
    }

    public function show(Message $message): JsonResponse
    {
        return response()->json($message->load([
            'thread.contact',
            'template',
            'channelConfig',
            'shoot',
            'invoice',
            'creator',
        ]));
    }

    public function cancel(Message $message): JsonResponse
    {
        if ($message->status !== 'SCHEDULED') {
            return response()->json(['error' => 'Can only cancel scheduled messages'], 400);
        }

        $message->update(['status' => 'CANCELLED']);

        return response()->json($message->fresh());
    }
}

