<?php

namespace App\Services\SystemEmails;

use App\Models\SystemEmailDispatch;
use App\Services\Users\EmailHealthService;
use Illuminate\Support\Facades\Log;

class SystemEmailOrchestrator
{
    public function __construct(
        private readonly SystemEmailBuilder $builder,
        private readonly SystemEmailDispatcher $dispatcher,
        private readonly EmailAuditService $auditService,
        private readonly EmailTypeRegistry $registry,
        private readonly EmailHealthService $emailHealthService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $options
     * @return array{sent: bool, duplicate: bool, dispatch: ?SystemEmailDispatch, message_id: ?int}
     */
    public function send(string $emailAlias, array $payload, array $transport, array $options = []): array
    {
        $definition = $this->registry->definition($emailAlias);
        $this->guardRecipientType($definition, (string) ($transport['contact_type'] ?? ($payload['meta']['recipient_type'] ?? 'other')));
        $this->guardEmailHealth($emailAlias, $transport, $options);

        $built = $this->builder->build($emailAlias, $payload);
        $auditState = $this->auditService->begin($definition, $payload, $transport, $options);

        if ($auditState['duplicate']) {
            Log::info('Skipping duplicate protected email send.', [
                'email_alias' => $emailAlias,
                'recipient' => $transport['to'] ?? null,
                'dispatch_id' => $auditState['dispatch']->id,
            ]);

            return [
                'sent' => false,
                'duplicate' => true,
                'dispatch' => $auditState['dispatch'],
                'message_id' => $auditState['dispatch']->message_id,
            ];
        }

        $dispatch = $auditState['dispatch'];
        $metadata = [
            'canonical_email' => [
                'email_alias' => $definition->alias,
                'email_type' => $definition->resolvedType(),
                'email_version' => $definition->version,
                'category' => $definition->category,
                'template_view' => $definition->templateView,
                'template_version' => $definition->templateVersion,
                'idempotency_key' => $dispatch->idempotency_key,
                'correlation_id' => $dispatch->correlation_id,
                'delivery_mode' => $definition->deliveryMode,
                'source_of_truth' => $definition->sourceOfTruth,
                'editable' => $definition->editable,
            ],
        ];

        if (!empty($options['canonical_metadata']) && is_array($options['canonical_metadata'])) {
            $metadata['canonical_email'] = array_merge(
                $metadata['canonical_email'],
                $options['canonical_metadata']
            );
        }

        try {
            $message = $this->dispatcher->dispatch($built, $transport, $metadata);
            $savedDispatch = $this->auditService->sent($dispatch, $message);

            return [
                'sent' => true,
                'duplicate' => false,
                'dispatch' => $savedDispatch,
                'message_id' => $message->id,
            ];
        } catch (\Throwable $exception) {
            $savedDispatch = $this->auditService->failed($dispatch, $exception);
            throw $exception;
        }
    }

    private function guardRecipientType(EmailTypeDefinition $definition, string $recipientType): void
    {
        if ($recipientType === '' || $recipientType === 'other') {
            return;
        }

        if (!in_array($recipientType, $definition->allowedRecipientTypes, true)) {
            throw new \InvalidArgumentException("Recipient type [{$recipientType}] is not allowed for protected email [{$definition->alias}].");
        }
    }

    /**
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $options
     */
    private function guardEmailHealth(string $emailAlias, array $transport, array $options): void
    {
        $relatedAccountId = $transport['related_account_id'] ?? null;
        $enforce = $options['enforce_email_health_gate'] ?? true;

        if (!$relatedAccountId || $enforce === false) {
            return;
        }

        $recipient = \App\Models\User::query()->find($relatedAccountId);
        $blockedReason = $this->emailHealthService->automatedSendBlockedReason($recipient, $emailAlias);

        if ($blockedReason !== null) {
            throw new \RuntimeException($blockedReason);
        }
    }
}
