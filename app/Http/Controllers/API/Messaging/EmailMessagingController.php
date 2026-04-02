<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function recipients(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->canSendOutbound($user->role)) {
            return response()->json(['message' => 'Recipient directory is only available for outbound messaging roles.'], 403);
        }

        $search = trim((string) $request->query('search', ''));
        $limit = max(5, min((int) $request->query('limit', 20), 50));
        $allowedClientIds = $this->resolveAllowedClientIdsForUser($user);
        $isSalesRep = $this->normalizedRole($user->role) === 'salesrep';

        $results = collect();

        $recentMessages = Message::query()
            ->with('thread.contact')
            ->where('channel', 'EMAIL')
            ->where('direction', 'OUTBOUND')
            ->where('sender_user_id', $user->id)
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($inner) use ($search) {
                    $inner->where('to_address', 'like', '%' . $search . '%')
                        ->orWhere('subject', 'like', '%' . $search . '%');
                })
            )
            ->latest('created_at')
            ->limit($limit)
            ->get();

        foreach ($recentMessages as $message) {
            $contact = $message->thread?->contact;
            $results->push([
                'id' => 'recent-' . $message->id,
                'email' => strtolower($message->to_address),
                'name' => $contact?->name ?: $message->sender_display_name ?: $message->to_address,
                'kind' => 'recent',
                'subtitle' => 'Recent recipient',
                'related_user_id' => $contact?->user_id,
                'related_account_id' => $contact?->account_id,
            ]);
        }

        $contactQuery = Contact::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($search !== '') {
            $contactQuery->where(function ($query) use ($search) {
                $query->where('email', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('comment', 'like', '%' . $search . '%');
            });
        }

        if ($isSalesRep) {
            $contactQuery->where(function ($query) use ($allowedClientIds) {
                if ($allowedClientIds !== []) {
                    $query->whereIn('user_id', $allowedClientIds)
                        ->orWhereIn('account_id', $allowedClientIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
            });
        }

        $contacts = $contactQuery
            ->orderByRaw('CASE WHEN name IS NULL OR name = \'\' THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        foreach ($contacts as $contact) {
            $results->push([
                'id' => 'contact-' . $contact->id,
                'email' => strtolower((string) $contact->email),
                'name' => $contact->name ?: $contact->email,
                'kind' => 'contact',
                'subtitle' => $contact->type ? Str::headline((string) $contact->type) . ' contact' : 'Known contact',
                'related_user_id' => $contact->user_id,
                'related_account_id' => $contact->account_id,
            ]);
        }

        $userQuery = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($search !== '') {
            $userQuery->where(function ($query) use ($search) {
                $query->where('email', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('company_name', 'like', '%' . $search . '%');
            });
        }

        if ($isSalesRep) {
            if ($allowedClientIds === []) {
                $userQuery->whereRaw('1 = 0');
            } else {
                $userQuery->where('role', 'client')->whereIn('id', $allowedClientIds);
            }
        }

        $users = $userQuery
            ->orderByRaw('CASE WHEN role = \'client\' THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        foreach ($users as $recipientUser) {
            $kind = $recipientUser->role === 'client' ? 'client' : 'user';
            $subtitleParts = array_filter([
                $recipientUser->role ? Str::headline((string) $recipientUser->role) : null,
                $recipientUser->company_name ?: null,
            ]);

            $results->push([
                'id' => 'user-' . $recipientUser->id,
                'email' => strtolower((string) $recipientUser->email),
                'name' => $recipientUser->name ?: $recipientUser->email,
                'kind' => $kind,
                'subtitle' => $subtitleParts !== [] ? implode(' • ', $subtitleParts) : ($kind === 'client' ? 'Client account' : 'User account'),
                'related_user_id' => $recipientUser->id,
                'related_account_id' => $recipientUser->role === 'client' ? $recipientUser->id : null,
            ]);
        }

        $groupPriority = [
            'recent' => 0,
            'contact' => 1,
            'client' => 2,
            'user' => 3,
        ];

        $payload = $results
            ->filter(fn ($entry) => !empty($entry['email']) && filter_var($entry['email'], FILTER_VALIDATE_EMAIL))
            ->unique(fn ($entry) => strtolower((string) $entry['email']))
            ->sortBy(fn ($entry) => [
                $groupPriority[$entry['kind']] ?? 99,
                strtolower((string) ($entry['name'] ?? '')),
            ])
            ->take($limit)
            ->values()
            ->all();

        return response()->json($payload);
    }

    public function compose(Request $request): JsonResponse
    {
        $user = $request->user();
        $canSendOutbound = $this->canSendOutbound($user->role);

        $rules = [
            'to' => [$canSendOutbound ? 'required' : 'nullable', 'email'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
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
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];

        $data = $request->validate($rules);
        $data['cc'] = $this->mergeShootCcEmails($data);
        $data['bcc'] = $this->normalizeEmailAddresses($data['bcc'] ?? []);
        $data = array_merge($data, $this->extractUploadedAttachments($request));

        if (empty($data['body_html']) && empty($data['body_text']) && empty($data['template_id'])) {
            throw ValidationException::withMessages([
                'body_text' => 'Either HTML or text body is required.',
            ]);
        }

        $data = $this->applyTemplateIfNeeded($data);

        $senderDisplayName = $user->name ?: $user->email;
        $senderAccountId = $canSendOutbound ? null : $user->id;
        if (!$canSendOutbound) {
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

        if ($canSendOutbound) {
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
        if (!$this->canSendOutbound($user->role)) {
            return response()->json(['message' => 'Only outbound messaging roles can schedule emails.'], 403);
        }

        $data = $request->validate([
            'to' => ['required', 'email'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
            'subject' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'channel_id' => ['nullable', 'exists:message_channels,id'],
            'reply_to' => ['nullable', 'email'],
            'related_shoot_id' => ['nullable', 'integer'],
            'related_account_id' => ['nullable', 'integer'],
            'related_invoice_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'exists:message_templates,id'],
            'variables' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ]);
        $data['cc'] = $this->mergeShootCcEmails($data);
        $data['bcc'] = $this->normalizeEmailAddresses($data['bcc'] ?? []);
        $data = array_merge($data, $this->extractUploadedAttachments($request));
        $data = $this->applyTemplateIfNeeded($data);

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
            'cc' => $message->cc_addresses_json ?? [],
            'bcc' => $message->bcc_addresses_json ?? [],
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function mergeShootCcEmails(array $data): array
    {
        return collect([
            ...$this->normalizeEmailAddresses($data['cc'] ?? []),
            ...$this->resolveRelatedShootCcEmails($data),
        ])->unique()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function resolveRelatedShootCcEmails(array $data): array
    {
        $client = null;

        if (!empty($data['related_shoot_id'])) {
            $client = Shoot::query()
                ->with('client')
                ->find($data['related_shoot_id'])
                ?->client;
        }

        if (!$client && !empty($data['related_account_id'])) {
            $account = User::find($data['related_account_id']);
            if ($account && $account->role === 'client') {
                $client = $account;
            }
        }

        if (!$client && !empty($data['related_invoice_id'])) {
            $invoice = Invoice::query()
                ->with(['client', 'shoot.client'])
                ->find($data['related_invoice_id']);

            $client = $invoice?->shoot?->client ?? $invoice?->client;
        }

        return $this->normalizeEmailAddresses($client?->shoot_cc_emails ?? [], $client?->email);
    }

    /**
     * @param  mixed  $emails
     * @return array<int, string>
     */
    private function normalizeEmailAddresses(mixed $emails, ?string $exclude = null): array
    {
        $excluded = is_string($exclude) ? strtolower(trim($exclude)) : null;

        return collect(is_array($emails) ? $emails : [])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->reject(fn ($email) => $excluded !== null && $email === $excluded)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyTemplateIfNeeded(array $data): array
    {
        if (empty($data['template_id'])) {
            return $data;
        }

        $template = MessageTemplate::find($data['template_id']);
        if (!$template) {
            return $data;
        }

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

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractUploadedAttachments(Request $request): array
    {
        $files = $request->file('attachments', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (!is_array($files) || $files === []) {
            return [];
        }

        $disk = config('filesystems.default', 'local');
        $providerAttachments = [];
        $storedAttachments = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $storagePath = $file->store('messaging-attachments', ['disk' => $disk]);
            $content = Storage::disk($disk)->get($storagePath);

            $providerAttachments[] = [
                'filename' => $file->getClientOriginalName(),
                'content' => $content,
                'content_type' => $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream',
            ];

            $storedAttachments[] = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream',
                'url' => null,
                'disk' => $disk,
                'storage_path' => $storagePath,
            ];
        }

        return array_filter([
            'attachments' => $providerAttachments !== [] ? $providerAttachments : null,
            'attachments_json' => $storedAttachments !== [] ? $storedAttachments : null,
        ], fn ($value) => $value !== null);
    }

    private function canSendOutbound(?string $role): bool
    {
        return in_array($this->normalizedRole($role), ['admin', 'superadmin', 'editingmanager', 'salesrep'], true);
    }

    private function normalizedRole(?string $role): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', (string) $role));
    }

    /**
     * @return array<int, int>
     */
    private function resolveAllowedClientIdsForUser(User $user): array
    {
        if ($this->normalizedRole($user->role) !== 'salesrep') {
            return [];
        }

        $repId = $user->id;
        $clientIdsFromShoots = Shoot::query()
            ->where('rep_id', $repId)
            ->pluck('client_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $metadataClientIds = User::query()
            ->where('role', 'client')
            ->where(function ($query) use ($repId) {
                $query->where('created_by_id', $repId)
                    ->orWhere('metadata->accountRepId', (string) $repId)
                    ->orWhere('metadata->account_rep_id', (string) $repId)
                    ->orWhere('metadata->repId', (string) $repId)
                    ->orWhere('metadata->rep_id', (string) $repId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([
            ...$clientIdsFromShoots,
            ...$metadataClientIds,
        ]));
    }
}

