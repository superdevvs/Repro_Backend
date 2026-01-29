<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmailMessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging)
    {
    }

    public function messages(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin'], true);

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

        $messagesQuery = $this->messaging
            ->getMessageLogs($filters)
            ->with(['template', 'channelConfig', 'shoot', 'invoice']);

        if (!$isAdmin) {
            $messagesQuery->where(function ($query) use ($user) {
                $query->where('sender_user_id', $user->id)
                    ->orWhere('created_by', $user->id);
            });
        }

        $messages = $messagesQuery->paginate($request->query('per_page', 25));

        return response()->json($messages);
    }

    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin'], true);

        $threadsQuery = $this->messaging
            ->listThreads(['channel' => 'EMAIL']);

        if (!$isAdmin) {
            $threadsQuery->whereHas('contact', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            });
        }

        $threads = $threadsQuery->paginate(25);

        return response()->json($threads);
    }

    public function compose(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin'], true);

        $rules = [
            'to' => [$isAdmin ? 'required' : 'nullable', 'email'],
            'subject' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'reply_to' => ['nullable', 'email'],
            'template_id' => ['nullable', 'exists:message_templates,id'],
            'channel_id' => ['nullable', 'exists:message_channels,id'],
            'related_shoot_id' => ['nullable', 'integer'],
            'related_account_id' => ['nullable', 'integer'],
            'related_invoice_id' => ['nullable', 'integer'],
            'variables' => ['nullable', 'array'],
        ];

        $data = $request->validate($rules);

        if (empty($data['body_html']) && empty($data['body_text']) && empty($data['template_id'])) {
            throw ValidationException::withMessages([
                'body_text' => 'Either HTML or text body is required.',
            ]);
        }

        if (!empty($data['template_id'])) {
            $template = MessageTemplate::find($data['template_id']);
            if ($template) {
                $renderer = app(TemplateRenderer::class);
                $resolver = app(TemplateVariableResolver::class);
                $context = array_merge($data['variables'] ?? [], array_filter([
                    'shoot_id' => $data['related_shoot_id'] ?? null,
                    'account_id' => $data['related_account_id'] ?? null,
                    'invoice_id' => $data['related_invoice_id'] ?? null,
                ], fn ($value) => $value !== null));
                $variables = $resolver->resolve($context);
                $renderTemplate = clone $template;

                if (!empty($data['subject'])) {
                    $renderTemplate->subject = $data['subject'];
                }
                if (!empty($data['body_html'])) {
                    $renderTemplate->body_html = $data['body_html'];
                }
                if (!empty($data['body_text'])) {
                    $renderTemplate->body_text = $data['body_text'];
                }

                $rendered = $renderer->render($renderTemplate, $variables);
                if (!empty($rendered['missing'])) {
                    Log::warning('Compose email missing template variables', [
                        'template_id' => $template->id,
                        'missing' => $rendered['missing'],
                    ]);
                }
                $data['subject'] = $rendered['subject'] ?? $data['subject'] ?? $template->subject;
                $data['body_html'] = $rendered['body_html'] ?? $data['body_html'] ?? $template->body_html;
                $data['body_text'] = $rendered['body_text'] ?? $data['body_text'] ?? $template->body_text;
            }
        }

        $senderDisplayName = $user->name ?: $user->email;
        $senderAccountId = $isAdmin ? null : $user->id;
        if (!$isAdmin) {
            $senderDisplayName = sprintf('%s (Account #%s)', $senderDisplayName, $user->id);
        }

        $payload = array_merge($data, [
            'user_id' => $user->id,
            'send_source' => 'MANUAL',
            'sender_user_id' => $user->id,
            'sender_account_id' => $senderAccountId,
            'sender_role' => $user->role,
            'sender_display_name' => $senderDisplayName,
        ]);

        if ($isAdmin) {
            $payload['contact_email'] = $data['to'];
            $message = $this->messaging->sendEmail($payload);
        } else {
            $payload['from'] = $user->email;
            $payload['to'] = config('mail.contact_address', 'contact@reprophotos.com');
            $payload['reply_to'] = $data['reply_to'] ?? $user->email;
            $payload['contact_email'] = $user->email;
            $payload['contact_name'] = $user->name ?? $user->email;
            $payload['contact_type'] = $user->role;
            $payload['contact_user_id'] = $user->id;
            $payload['contact_account_id'] = $user->id;

            $message = $this->messaging->storeInternalEmail($payload, 'INBOUND');
        }

        return response()->json($message);
    }

    public function schedule(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Only admins can schedule emails.'], 403);
        }

        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'channel_id' => ['nullable', 'exists:message_channels,id'],
        ]);

        $scheduledAt = \Carbon\Carbon::parse($data['scheduled_at']);

        $senderDisplayName = $user->name ?: $user->email;
        $message = $this->messaging->scheduleEmail(
            array_merge($data, [
                'user_id' => $user->id,
                'send_source' => 'MANUAL',
                'sender_user_id' => $user->id,
                'sender_role' => $user->role,
                'sender_display_name' => $senderDisplayName,
                'contact_email' => $data['to'],
            ]),
            $scheduledAt
        );

        return response()->json($message);
    }

    public function retry(Message $message): JsonResponse
    {
        if (!in_array(request()->user()->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Only admins can retry emails.'], 403);
        }

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
        $user = request()->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin'], true);

        if (!$isAdmin && (int) ($message->sender_user_id ?? 0) !== (int) $user->id
            && (int) ($message->created_by ?? 0) !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
        if (!in_array(request()->user()->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Only admins can cancel emails.'], 403);
        }

        if ($message->status !== 'SCHEDULED') {
            return response()->json(['error' => 'Can only cancel scheduled messages'], 400);
        }

        $message->update(['status' => 'CANCELLED']);

        return response()->json($message->fresh());
    }
}

