<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Payments\PublicPaymentAccessTokenService;
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

    private const RECIPIENT_TYPES = ['client', 'photographer'];

    private const CHANNELS = ['email', 'sms'];

    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly TemplateRenderer $templateRenderer,
        private readonly TemplateVariableResolver $variableResolver,
        private readonly AuditLogService $auditLog,
        private readonly PublicPaymentAccessTokenService $paymentTokens,
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
    ): Message {
        $template = $this->resolveTemplate($type);
        $recipientType = $this->normalizeRecipientType($recipientType);
        $channel = $this->normalizeChannel($channel);

        $recipient = $this->resolveRecipient($shoot, $recipientType);
        $address = $this->recipientAddress($recipient, $channel);

        $context = $this->variableResolver->resolve(
            $this->buildContext($shoot, $type, $recipientType, $recipient)
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

        // AC 12.10 — record the ready-notification timestamp, distinct from shoot/invoice dates.
        if ($type === 'shoot_ready') {
            $shoot->forceFill(['shoot_ready_notified_at' => now()])->save();
        }

        // AC 12.9 — audit every manual send (sender, time, shoot, template, recipient, channel, status).
        $this->auditLog->record('notification.manual_send', $sender, $shoot, [
            'type'           => $type,
            'template_id'    => $template->id,
            'template_slug'  => $template->slug,
            'recipient_type' => $recipientType,
            'recipient'      => $address,
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
     * }
     *
     * @throws InvalidArgumentException When $type or $recipientType is unknown.
     * @throws RuntimeException         When the selected recipient cannot be resolved.
     */
    public function preview(Shoot $shoot, string $type, string $recipientType): array
    {
        $template = $this->resolveTemplate($type);
        $recipientType = $this->normalizeRecipientType($recipientType);

        $recipient = $this->resolveRecipient($shoot, $recipientType);

        $context = $this->variableResolver->resolve(
            $this->buildContext($shoot, $type, $recipientType, $recipient)
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
    public function resolveTemplate(string $type): MessageTemplate
    {
        $slug = self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown notification type {$type}");

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
    private function buildContext(Shoot $shoot, string $type, string $recipientType, User $recipient): array
    {
        return [
            'shoot'          => $shoot,
            'shoot_id'       => $shoot->id,
            'account_id'     => $shoot->client_id,
            'notification_type' => $type,
            'recipient'      => $recipient,
            'recipient_type' => $recipientType,
            'recipient_name' => $recipient->name,
            'recipient_email' => $recipient->email,
        ];
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

    private function resolveRecipient(Shoot $shoot, string $recipientType): User
    {
        $recipient = $recipientType === 'photographer'
            ? $shoot->photographer
            : $shoot->client;

        if (!$recipient instanceof User) {
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
