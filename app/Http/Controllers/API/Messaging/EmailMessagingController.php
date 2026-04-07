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
use Illuminate\Database\Eloquent\Builder;
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
            ->with(['template', 'channelConfig', 'shoot.client', 'shoot.rep', 'invoice']);

        if (!$this->isAdminUser($user)) {
            $this->applyMessageVisibilityScope($messagesQuery, $user);
        }

        $messages = $messagesQuery->paginate($request->query('per_page', 25));

        return response()->json($messages);
    }

    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();

        $threadsQuery = $this->messaging
            ->listThreads(['channel' => 'EMAIL']);

        if (!$this->isAdminUser($user)) {
            $threadsQuery->whereHas('messages', function (Builder $query) use ($user) {
                $this->applyMessageVisibilityScope($query, $user);
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
            'related_shoot_id' => ['nullable', 'integer', 'exists:shoots,id'],
            'related_shoot_context_type' => ['nullable', 'in:new_shoot,previous_shoot'],
            'related_account_id' => ['nullable', 'integer', 'exists:users,id'],
            'related_invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'variables' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];

        $data = $request->validate($rules);
        $relatedShoot = null;

        if (!$canSendOutbound) {
            $relatedShoot = $this->resolveRequiredShootForContactMessage($user, $data);
            $data['related_shoot_id'] = (int) $relatedShoot->id;
            $data['related_account_id'] = (int) $relatedShoot->client_id;
        }

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
            $payload['related_account_id'] = (int) ($relatedShoot?->client_id ?? $data['related_account_id']);

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
            'related_shoot_id' => ['nullable', 'integer', 'exists:shoots,id'],
            'related_shoot_context_type' => ['nullable', 'in:new_shoot,previous_shoot'],
            'related_account_id' => ['nullable', 'integer', 'exists:users,id'],
            'related_invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
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

        if (!$this->canAccessMessage($user, $message)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($message->load([
            'thread.contact',
            'template',
            'channelConfig',
            'shoot.client',
            'shoot.rep',
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
        return in_array($this->normalizedRole($role), ['admin', 'superadmin'], true);
    }

    private function normalizedRole(?string $role): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', (string) $role));
    }

    private function isAdminUser(User $user): bool
    {
        return $this->userHasAnyNormalizedRole($user, ['admin', 'superadmin']);
    }

    private function isEditingManagerUser(User $user): bool
    {
        return $this->userHasAnyNormalizedRole($user, ['editingmanager']);
    }

    private function isSalesRepUser(User $user): bool
    {
        return $this->userHasAnyNormalizedRole($user, ['salesrep']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveRequiredShootForContactMessage(User $user, array $data): Shoot
    {
        if (empty($data['related_shoot_id'])) {
            throw ValidationException::withMessages([
                'related_shoot_id' => 'Select a shoot before sending this contact message.',
            ]);
        }

        if (empty($data['related_shoot_context_type'])) {
            throw ValidationException::withMessages([
                'related_shoot_context_type' => 'Choose whether this message is regarding a new or previous shoot.',
            ]);
        }

        $shootId = (int) $data['related_shoot_id'];
        $shootQuery = Shoot::query()
            ->with('client')
            ->whereKey($shootId);

        $this->applyContactShootAccessScope($shootQuery, $user);

        $shoot = $shootQuery->first();

        if (!$shoot) {
            throw ValidationException::withMessages([
                'related_shoot_id' => 'The selected shoot is not available for this contact message.',
            ]);
        }

        if (!$shoot->client_id) {
            throw ValidationException::withMessages([
                'related_shoot_id' => 'The selected shoot does not have an associated client account.',
            ]);
        }

        return $shoot;
    }

    private function applyContactShootAccessScope(Builder $query, User $user): void
    {
        if ($this->isAdminUser($user) || $this->isEditingManagerUser($user)) {
            return;
        }

        if ($this->normalizedRole($user->role) === 'client') {
            $query->where(function (Builder $scope) use ($user) {
                $scope->where('client_id', $user->id)
                    ->orWhere(function (Builder $ghostScope) use ($user) {
                        $ghostScope->whereHas('ghostUsers', function (Builder $ghostQuery) use ($user) {
                            $ghostQuery->where('users.id', $user->id);
                        })->where(function (Builder $deliveredScope) {
                            $deliveredScope->whereIn('status', [Shoot::STATUS_DELIVERED])
                                ->orWhereIn('workflow_status', [
                                    Shoot::STATUS_DELIVERED,
                                    'ready_for_client',
                                    'admin_verified',
                                    'ready',
                                    'workflow_completed',
                                    'client_delivered',
                                ]);
                        });
                    });
            });

            return;
        }

        if ($this->normalizedRole($user->role) === 'photographer') {
            $query->where(function (Builder $scope) use ($user) {
                $scope->where('photographer_id', $user->id)
                    ->orWhereHas('services', function (Builder $serviceQuery) use ($user) {
                        $serviceQuery->where('shoot_service.photographer_id', $user->id);
                    });
            });

            return;
        }

        if ($this->normalizedRole($user->role) === 'editor') {
            $query->where(function (Builder $scope) use ($user) {
                $scope->where('editor_id', $user->id)
                    ->orWhereHas('activityLogs', function (Builder $logQuery) use ($user) {
                        $logQuery->where('user_id', $user->id);
                    })
                    ->orWhere(function (Builder $editingPipeline) {
                        $editingPipeline->whereIn('status', [
                            Shoot::STATUS_UPLOADED,
                            Shoot::STATUS_EDITING,
                            Shoot::STATUS_READY,
                            Shoot::STATUS_DELIVERED,
                        ]);
                    });
            });

            return;
        }

        if ($this->isSalesRepUser($user)) {
            $query->where(function (Builder $scope) use ($user) {
                $scope->where('rep_id', $user->id)
                    ->orWhere(function (Builder $fallback) use ($user) {
                        $fallback->whereNull('rep_id')
                            ->whereHas('client', function (Builder $clientQuery) use ($user) {
                                $this->applySalesRepClientFallbackScope($clientQuery, $user->id);
                            });
                    });
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canAccessMessage(User $user, Message $message): bool
    {
        if ($this->isAdminUser($user)) {
            return true;
        }

        if ((int) ($message->sender_user_id ?? 0) === (int) $user->id
            || (int) ($message->created_by ?? 0) === (int) $user->id) {
            return true;
        }

        return $this->canAccessLinkedInternalContactMessage($user, $message);
    }

    private function canAccessLinkedInternalContactMessage(User $user, Message $message): bool
    {
        if (!$this->isLinkedInternalContactMessage($message)) {
            return false;
        }

        if ($this->isEditingManagerUser($user)) {
            return true;
        }

        if (!$this->isSalesRepUser($user)) {
            return false;
        }

        $message->loadMissing('shoot.client');
        $shoot = $message->shoot;

        if ($shoot?->rep_id && (int) $shoot->rep_id === (int) $user->id) {
            return true;
        }

        if (!$shoot?->client) {
            return false;
        }

        return $this->resolveClientRepId($shoot->client) === (int) $user->id;
    }

    private function isLinkedInternalContactMessage(Message $message): bool
    {
        return $message->provider === 'INTERNAL' && !empty($message->related_shoot_id);
    }

    private function applyMessageVisibilityScope(Builder $query, User $user): void
    {
        $query->where(function (Builder $inner) use ($user) {
            $inner->where('sender_user_id', $user->id)
                ->orWhere('created_by', $user->id);

            if ($this->isEditingManagerUser($user)) {
                $inner->orWhere(function (Builder $linked) {
                    $this->applyLinkedInternalContactScope($linked);
                });

                return;
            }

            if ($this->isSalesRepUser($user)) {
                $inner->orWhere(function (Builder $linked) use ($user) {
                    $this->applyLinkedInternalContactScope($linked);
                    $linked->where(function (Builder $scope) use ($user) {
                        $scope->whereHas('shoot', function (Builder $shootQuery) use ($user) {
                            $shootQuery->where('rep_id', $user->id);
                        })->orWhere(function (Builder $fallback) use ($user) {
                            $fallback->whereHas('shoot', function (Builder $shootQuery) {
                                $shootQuery->whereNull('rep_id');
                            })->whereHas('shoot.client', function (Builder $clientQuery) use ($user) {
                                $this->applySalesRepClientFallbackScope($clientQuery, $user->id);
                            });
                        });
                    });
                });
            }
        });
    }

    private function applyLinkedInternalContactScope(Builder $query): void
    {
        $query->where('provider', 'INTERNAL')
            ->whereNotNull('related_shoot_id');
    }

    private function applySalesRepClientFallbackScope(Builder $query, int $repId): void
    {
        $query->where(function (Builder $clientScope) use ($repId) {
            $clientScope->where('created_by_id', $repId)
                ->orWhere('metadata->accountRepId', (string) $repId)
                ->orWhere('metadata->account_rep_id', (string) $repId)
                ->orWhere('metadata->repId', (string) $repId)
                ->orWhere('metadata->rep_id', (string) $repId);
        });
    }

    private function resolveClientRepId(User $client): ?int
    {
        $metadata = is_array($client->metadata) ? $client->metadata : [];
        $repCandidate = $client->created_by_id
            ?? $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? null;

        return is_numeric($repCandidate) ? (int) $repCandidate : null;
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function userHasAnyNormalizedRole(User $user, array $roles): bool
    {
        $normalizedRoles = $this->normalizedRolesForUser($user);

        foreach ($roles as $role) {
            if (in_array($this->normalizedRole($role), $normalizedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedRolesForUser(User $user): array
    {
        $roles = [$user->role];

        if (is_array($user->secondary_roles)) {
            $roles = array_merge($roles, $user->secondary_roles);
        }

        return collect($roles)
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->map(fn ($role) => $this->normalizedRole($role))
            ->unique()
            ->values()
            ->all();
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

