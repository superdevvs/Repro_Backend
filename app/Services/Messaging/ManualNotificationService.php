<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Payments\PublicPaymentAccessTokenService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manual notification dispatch (Req 12.1-12.10).
 *
 * Maps a manual notification type to a {@see MessageTemplate} slug and dispatches it through
 * the existing {@see MessagingService} (the same send path {@see AutomationService} uses),
 * reusing {@see TemplateRenderer} / {@see TemplateVariableResolver} and the SystemEmails
 * branding layer. Routing is by recipient (client|photographer) and channel (email|sms).
 *
 * Side effects:
 *  - payment_due notifications carry a payment link (AC 12.3).
 *  - payment_receipt notifications carry payment confirmation details (AC 12.4).
 *  - shoot_ready notifications stamp `shoot_ready_notified_at` on the Shoot (AC 12.10).
 *  - every send writes one Audit_Log entry (AC 12.9).
 */
class ManualNotificationService
{
    /**
     * Manual notification type → MessageTemplate slug (AC 12.2).
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'shoot_scheduled' => 'shoot-scheduled',
        'shoot_on_hold'   => 'shoot-on-hold',
        'shoot_cancelled' => 'shoot-cancelled',
        'shoot_ready'     => 'shoot-ready',
        'payment_due'     => 'payment-due',
        'payment_receipt' => 'payment-receipt',
    ];

    private const RECIPIENT_TYPES = ['client', 'photographer', 'rep'];

    private const CHANNELS = ['email', 'sms'];

    /**
     * Variables that are inherently optional / contextual and commonly empty for a
     * perfectly valid shoot (e.g. a shoot with no photographer assigned yet, or no
     * notes / change summary). These render cleanly when blank, so they must NOT be
     * surfaced as "missing template variables" warnings in the manual-notification
     * preview — only genuinely-required fields that came back empty should warn.
     *
     * @var list<string>
     */
    private const OPTIONAL_VARIABLES = [
        'assigned_photographers',
        'photographer_name',
        'photographer_first_name',
        'photographer_last_name',
        'photographer_email',
        'photographer_phone',
        'shoot_notes',
        'shoot_change_summary',
        'shoot_changes_html',
        'photographer_change_summary',
        'previous_photographer_name',
        'new_photographer_name',
        'recipient_booking_intro',
        'recipient_update_intro',
        'recipient_manage_copy',
        'recipient_manage_copy_text',
        'cancellation_policy_html',
        'cancellation_policy_text',
        'property_prep_html',
        'property_prep_text',
        'payment_cta_html',
        'payment_cta_text',
        'branded_tour_link',
        'mls_tour_link',
    ];

    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly TemplateRenderer $templateRenderer,
        private readonly TemplateVariableResolver $variableResolver,
        private readonly AuditLogService $auditLog,
        private readonly PublicPaymentAccessTokenService $paymentTokens,
        private readonly AutomationService $automationService,
    ) {
    }

    /**
     * Send a manual notification for a Shoot and record an Audit_Log entry.
     *
     * @param  Shoot   $shoot         The shoot the notification concerns.
     * @param  string  $type          One of {@see self::TYPES} keys.
     * @param  string  $recipientType client|photographer (AC 12.6).
     * @param  string  $channel       email|sms (AC 12.7).
     * @param  User    $sender        The admin dispatching the notification.
     *
     * @throws InvalidArgumentException When $type, $recipientType, or $channel is unknown.
     * @throws RuntimeException         When the selected recipient has no usable address.
     */
    public function send(
        Shoot $shoot,
        string $type,
        string $recipientType,
        string $channel,
        User $sender,
        ?int $recipientUserId = null,
    ): Message {
        $recipientType = $this->normalizeRecipientType($recipientType);
        $this->validateRouting($type, $recipientType);
        $channel = $this->normalizeChannel($channel);
        $template = $this->resolveTemplate($type, $channel);

        $recipients = $this->resolveRecipients($shoot, $recipientType, $recipientUserId);
        if ($recipients->isEmpty()) {
            throw new RuntimeException("Shoot {$shoot->id} has no {$recipientType} to notify.");
        }

        $messages = [];
        foreach ($recipients as $recipient) {
            $messages[] = $this->sendToRecipient(
                $shoot,
                $type,
                $recipientType,
                $channel,
                $sender,
                $template,
                $recipient,
                stampReady: $type === 'shoot_ready' && $messages === [],
            );
        }

        return $messages[array_key_last($messages)];
    }

    /**
     * List notify recipients for a shoot.
     *
     * Photographer recipients include every service-assigned photographer plus the
     * primary shoot photographer when still needed as a fallback — not only
     * shoot.photographer_id.
     *
     * @return list<array{id:int,name:?string,email:?string,phone:?string,role:string,recipient_type:string}>
     */
    public function listRecipients(Shoot $shoot, ?string $recipientType = null): array
    {
        $types = $recipientType
            ? [$this->normalizeRecipientType($recipientType)]
            : self::RECIPIENT_TYPES;

        $out = [];
        foreach ($types as $type) {
            foreach ($this->resolveRecipients($shoot, $type) as $user) {
                $out[] = [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phonenumber ?: $user->phone,
                    'role' => (string) ($user->role ?? $type),
                    'recipient_type' => $type,
                ];
            }
        }

        return $out;
    }

    private function sendToRecipient(
        Shoot $shoot,
        string $type,
        string $recipientType,
        string $channel,
        User $sender,
        MessageTemplate $template,
        User $recipient,
        bool $stampReady = true,
    ): Message {
        $address = $this->recipientAddress($recipient, $channel);

        $context = $this->variableResolver->resolve(
            $this->buildContext($shoot, $type, $recipientType, $recipient, $channel)
        );

        if ($type === 'payment_due') {
            $context['payment_link'] = $this->paymentLink($shoot);          // AC 12.3
            $context['pay_link'] = $context['payment_link'];
        }

        if ($type === 'payment_receipt') {
            $context['payment_details'] = $this->receiptDetails($shoot);    // AC 12.4
        }

        $rendered = $this->templateRenderer->render($template, $context);

        $message = $this->dispatchForChannel($channel, [
            'to'               => $address,
            'subject'          => $rendered['subject'] ?? $template->subject,
            'body_html'        => $rendered['body_html'] ?? $rendered['html'] ?? null,
            'body_text'        => $rendered['body_text'] ?? $rendered['text'] ?? null,
            'send_source'      => 'MANUAL',
            'template_id'      => $template->id,
            'related_shoot_id' => $shoot->id,
            'related_account_id' => $shoot->client_id,
            'contact_email'    => $channel === 'email' ? $address : ($recipient->email ?? null),
            'contact_phone'    => $channel === 'sms' ? $address : ($recipient->phonenumber ?? $recipient->phone ?? null),
            'contact_name'     => $recipient->name ?? ucfirst($recipientType),
            'contact_type'     => $recipientType,
            'contact_user_id'  => $recipient->id ?? null,
            'sender_user_id'   => $sender->id,
            'user_id'          => $sender->id,
        ]);

        // AC 12.10 — stamp once per manual shoot_ready dispatch, not per photographer copy.
        if ($stampReady && $type === 'shoot_ready') {
            $shoot->forceFill(['shoot_ready_notified_at' => now()])->save();
            $this->automationService->schedulePaymentReminders($shoot->refresh());
        }

        // AC 12.9 — audit every manual send (sender, time, shoot, template, recipient, channel, status).
        $this->auditLog->record('notification.manual_send', $sender, $shoot, [
            'type'           => $type,
            'template_id'    => $template->id,
            'template_slug'  => $template->slug,
            'recipient_type' => $recipientType,
            'recipient'      => $address,
            'recipient_user_id' => $recipient->id,
            'channel'        => $channel,
            'status'         => $message->status,
        ]);

        return $message;
    }

    /**
     * Render a manual notification without sending or auditing (AC 12.5, 12.8).
     *
     * Mirrors {@see send()}'s template/context flow exactly so the preview reflects what would
     * actually go out: the same {@see self::TYPES} → slug map, the same {@see buildContext} +
     * {@see TemplateVariableResolver::resolve()} pipeline, and the same payment_link /
     * payment_details enrichment for `payment_due` / `payment_receipt`. No {@see Message} row is
     * created and no Audit_Log entry is written.
     *
     * `missing_variables` lists template variables that did not resolve for this Shoot —
     * either required by the template (`variables_json`) but absent / empty in the resolved
     * context, or `{{variable}}` placeholders that survived rendering. The Dashboard surfaces a
     * non-empty list as a warning before the Admin can send.
     *
     * @return array{
     *     subject: string,
     *     body_html: ?string,
     *     body_text: ?string,
     *     missing_variables: list<string>,
     *     recipients: list<array{id:int,name:?string,email:?string,phone:?string,role:string,recipient_type:string}>,
     * }
     *
     * @throws InvalidArgumentException When $type or $recipientType is unknown.
     * @throws RuntimeException         When the selected recipient cannot be resolved.
     */
    public function preview(Shoot $shoot, string $type, string $recipientType, string $channel = 'email', ?int $recipientUserId = null): array
    {
        $channel = $this->normalizeChannel($channel);
        $template = $this->resolveTemplate($type, $channel);
        $recipientType = $this->normalizeRecipientType($recipientType);

        $this->validateRouting($type, $recipientType);

        $recipients = $this->resolveRecipients($shoot, $recipientType, $recipientUserId);
        if ($recipients->isEmpty()) {
            throw new RuntimeException("Shoot {$shoot->id} has no {$recipientType} to notify.");
        }
        $recipient = $recipients->first();

        $context = $this->variableResolver->resolve(
            $this->buildContext($shoot, $type, $recipientType, $recipient, $channel)
        );

        // Mirror send()'s payment-flow enrichment so the preview shows the same content.
        if ($type === 'payment_due') {
            $context['payment_link'] = $this->paymentLink($shoot);
            $context['pay_link'] = $context['payment_link'];
        }

        if ($type === 'payment_receipt') {
            $context['payment_details'] = $this->receiptDetails($shoot);
        }

        $rendered = $this->templateRenderer->render($template, $context);

        return [
            'subject'           => $rendered['subject'] ?? $template->subject,
            'body_html'         => $rendered['body_html'] ?? $rendered['html'] ?? null,
            'body_text'         => $rendered['body_text'] ?? $rendered['text'] ?? null,
            'missing_variables' => $this->collectMissingVariables($template, $context, $rendered),
            'recipients'        => $this->listRecipients($shoot, $recipientType),
        ];
    }

    /**
     * Collect template variables that did not resolve for the previewed Shoot.
     *
     * Combines two signals so the warning is robust against renderer behavior changes:
     *   1. Required vars (`MessageTemplate::variables_json`) absent or empty in `$context`.
     *   2. Any `{{variable}}` placeholders left in the rendered output (current renderer
     *      substitutes empty strings for missing placeholders, but a future renderer might not).
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $rendered
     * @return list<string>
     */
    private function collectMissingVariables(MessageTemplate $template, array $context, array $rendered): array
    {
        $missing = [];

        foreach ((array) ($template->variables_json ?? []) as $key) {
            if (in_array($key, self::OPTIONAL_VARIABLES, true)) {
                continue;
            }
            $value = $context[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $key;
            }
        }

        foreach (['subject', 'body_html', 'body_text', 'html', 'text'] as $field) {
            $rendered_value = (string) ($rendered[$field] ?? '');
            if ($rendered_value === '') {
                continue;
            }
            if (preg_match_all('/\{\{\s*([a-zA-Z_][\w\.]*)\s*\}\}/', $rendered_value, $matches)) {
                foreach ($matches[1] as $name) {
                    $missing[] = $name;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Resolve the active MessageTemplate for a manual notification type.
     *
     * @throws InvalidArgumentException When the type is not a known manual notification type.
     */
    public function resolveTemplate(string $type, string $channel = 'email'): MessageTemplate
    {
        $slug = self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown notification type {$type}");
        $channel = $this->normalizeChannel($channel);

        if ($channel === 'sms') {
            // Preserve a deliberately configured SMS template under the legacy
            // slug, including its disabled state. Never send email copy as SMS.
            $legacySms = MessageTemplate::where('slug', $slug)->where('channel', 'SMS')->first();
            if ($legacySms) {
                if (! $legacySms->is_active) {
                    throw new RuntimeException('The selected SMS template is disabled.');
                }

                return $legacySms;
            }

            $slug .= '-sms';
            $body = SmsTemplateContent::forSlug($slug)
                ?? throw new RuntimeException('The selected SMS template is unavailable.');
            $template = MessageTemplate::firstOrCreate(['slug' => $slug, 'channel' => 'SMS'], [
                'name' => ucwords(str_replace('-', ' ', substr($slug, 0, -4))).' SMS',
                'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
                'category' => str_starts_with($type, 'payment_') ? 'PAYMENT' : 'BOOKING',
                'subject' => '', 'body_text' => $body,
                'variables_json' => SmsTemplateContent::variables($body),
            ]);
            if (! $template->is_active) {
                throw new RuntimeException('The selected SMS template is disabled.');
            }

            return $template;
        }

        return MessageTemplate::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Build the unresolved template context for a manual notification.
     *
     * @return array<string, mixed>
     */
    private function buildContext(Shoot $shoot, string $type, string $recipientType, User $recipient, string $channel = 'email'): array
    {
        return array_merge($channel === 'sms' ? $this->automationService->buildShootContext($shoot) : [], [
            'shoot'          => $shoot,
            'shoot_id'       => $shoot->id,
            'account_id'     => $shoot->client_id,
            'notification_type' => $type,
            'recipient'      => $recipient,
            'recipient_type' => $recipientType,
            'recipient_name' => $recipient->name,
            'recipient_email' => $recipient->email,
        ]);
    }

    /**
     * Route a rendered payload to the correct MessagingService send path for the channel.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatchForChannel(string $channel, array $payload): Message
    {
        return match ($channel) {
            'sms'   => $this->messagingService->sendSms($payload),
            default => $this->messagingService->sendEmail($payload),
        };
    }

    /**
     * Resolve one or more notify recipients for the shoot.
     *
     * Photographer mode returns every unique service-assigned photographer, falling
     * back to shoot.photographer_id when a booked line still inherits the primary.
     *
     * @return Collection<int, User>
     */
    private function resolveRecipients(Shoot $shoot, string $recipientType, ?int $recipientUserId = null): Collection
    {
        if ($recipientType === 'rep') {
            $rep = app(\App\Services\Shoots\ShootSalesRepResolver::class)->resolve($shoot);

            return collect([$rep])->filter(fn ($user) => $user instanceof User
                && ($recipientUserId === null || (int) $user->id === $recipientUserId))->values();
        }

        if ($recipientType === 'client') {
            $client = $shoot->client;
            if (! $client instanceof User) {
                return collect();
            }

            if ($recipientUserId !== null && (int) $client->id !== (int) $recipientUserId) {
                return collect();
            }

            return collect([$client]);
        }

        $shoot->loadMissing(['photographer', 'services']);

        // Union primary + every service-assigned photographer (not primary-only).
        $photographerIds = collect([$shoot->photographer_id ?? $shoot->photographer?->id])
            ->merge(collect($shoot->services ?? [])->pluck('pivot.photographer_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($recipientUserId !== null) {
            $photographerIds = $photographerIds->filter(fn ($id) => (int) $id === (int) $recipientUserId)->values();
        }

        if ($photographerIds->isEmpty()) {
            return collect();
        }

        $photographers = User::query()
            ->whereIn('id', $photographerIds->all())
            ->get()
            ->keyBy('id');

        if ($shoot->photographer) {
            $photographers->put($shoot->photographer->id, $shoot->photographer);
        }

        return $photographerIds
            ->map(fn ($id) => $photographers->get((int) $id))
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();
    }

    private function resolveRecipient(Shoot $shoot, string $recipientType, ?int $recipientUserId = null): User
    {
        $recipient = $this->resolveRecipients($shoot, $recipientType, $recipientUserId)->first();

        if (! $recipient instanceof User) {
            throw new RuntimeException("Shoot {$shoot->id} has no {$recipientType} to notify.");
        }

        return $recipient;
    }

    private function recipientAddress(User $recipient, string $channel): string
    {
        $address = $channel === 'sms'
            ? ($recipient->phonenumber ?: $recipient->phone)
            : $recipient->email;

        $address = trim((string) $address);

        if ($address === '') {
            throw new RuntimeException(
                "Recipient has no {$channel} address for manual notification."
            );
        }

        return $address;
    }

    private function paymentLink(Shoot $shoot): string
    {
        return $this->paymentTokens->buildPublicUrl($shoot);
    }

    /**
     * Build human-readable payment confirmation details for a receipt notification.
     */
    private function receiptDetails(Shoot $shoot): string
    {
        $summary = $shoot->syncPaymentStatusFromRecords($shoot->payment_type ?: null);
        $totalPaid = (float) ($summary['total_paid'] ?? 0);
        $remaining = (float) ($summary['remaining_balance'] ?? 0);

        $latestPayment = $shoot->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->latest('processed_at')
            ->latest('id')
            ->first();

        $lines = [];
        $lines[] = 'Amount paid: $' . number_format($totalPaid, 2);

        if ($latestPayment) {
            $paidAt = $latestPayment->processed_at ?? $latestPayment->created_at;
            if ($paidAt) {
                $lines[] = 'Payment date: ' . $paidAt->format('M j, Y');
            }
            if (!empty($latestPayment->payment_method)) {
                $lines[] = 'Payment method: ' . $latestPayment->payment_method;
            }
            $reference = $latestPayment->stripe_payment_id
                ?? $latestPayment->square_payment_id
                ?? null;
            if (!empty($reference)) {
                $lines[] = 'Reference: ' . $reference;
            }
        }

        $lines[] = 'Remaining balance: $' . number_format($remaining, 2);

        return implode("\n", $lines);
    }

    private function validateRouting(string $type, string $recipientType): void
    {
        if ($type === 'shoot_on_hold' && $recipientType === 'photographer') {
            throw new InvalidArgumentException('Hold notifications go to the sales rep. Select Sales Rep as the recipient.');
        }
    }

    private function normalizeRecipientType(string $recipientType): string
    {
        $normalized = strtolower(trim($recipientType));

        if (!in_array($normalized, self::RECIPIENT_TYPES, true)) {
            throw new InvalidArgumentException("Unknown recipient type {$recipientType}");
        }

        return $normalized;
    }

    private function normalizeChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));

        if (!in_array($normalized, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown channel {$channel}");
        }

        return $normalized;
    }
}
