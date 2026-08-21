<?php

namespace App\Services\SystemEmails;

use App\Jobs\SendSystemEmailDispatchJob;
use App\Models\SystemEmailDispatch;
use App\Services\Users\EmailHealthService;
use Illuminate\Support\Facades\DB;
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

    /**
     * Persist a protected email request in the canonical dispatch ledger and
     * hand it to the queue only after the surrounding transaction commits.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $options
     * @return array{sent: bool, queued: bool, duplicate: bool, dispatch: ?SystemEmailDispatch, message_id: ?int}
     */
    public function queue(string $emailAlias, array $payload, array $transport, array $options = []): array
    {
        $definition = $this->registry->definition($emailAlias);
        $this->guardRecipientType($definition, (string) ($transport['contact_type'] ?? ($payload['meta']['recipient_type'] ?? 'other')));
        $this->guardEmailHealth($emailAlias, $transport, $options);

        // Validate the payload and renderer before committing an outbox row.
        // The worker rebuilds it from the immutable snapshot for the send.
        $this->builder->build($emailAlias, $payload);
        $auditState = $this->auditService->begin($definition, $payload, $transport, $options);
        $dispatch = $auditState['dispatch'];

        if ($auditState['duplicate']) {
            return [
                'sent' => strtolower((string) $dispatch->status) === 'sent',
                'queued' => in_array(strtolower((string) $dispatch->status), ['pending', 'processing'], true),
                'duplicate' => true,
                'dispatch' => $dispatch,
                'message_id' => $dispatch->message_id,
            ];
        }

        $dispatch->forceFill(['delivery_mode' => 'async'])->save();
        SendSystemEmailDispatchJob::dispatch($dispatch->id)->afterCommit();

        return [
            'sent' => false,
            'queued' => true,
            'duplicate' => false,
            'dispatch' => $dispatch->fresh(),
            'message_id' => null,
        ];
    }

    public function processQueued(SystemEmailDispatch $dispatch): void
    {
        $claimed = DB::transaction(function () use ($dispatch): ?SystemEmailDispatch {
            $locked = SystemEmailDispatch::query()->lockForUpdate()->find($dispatch->id);
            if (! $locked || in_array(strtolower((string) $locked->status), ['sent', 'delivered'], true)) {
                return null;
            }

            // A processing row represents an uncertain provider outcome. Never
            // blindly resend it; operations must reconcile it first.
            if (strtolower((string) $locked->status) === 'processing') {
                return null;
            }

            if (! in_array(strtolower((string) $locked->status), ['pending', 'failed'], true)) {
                return null;
            }

            $locked->forceFill([
                'status' => 'processing',
                'attempt_count' => max(1, (int) $locked->attempt_count + (strtolower((string) $locked->status) === 'failed' ? 1 : 0)),
                'error_code' => null,
                'error_message' => null,
                'failed_at' => null,
            ])->save();

            return $locked->fresh();
        });

        if (! $claimed) {
            return;
        }

        $payload = (array) ($claimed->payload_snapshot ?? []);
        $transport = (array) ($claimed->transport_snapshot ?? []);

        try {
            $built = $this->builder->build($claimed->email_alias, $payload);
            $definition = $this->registry->definition($claimed->email_alias);
            $metadata = [
                'canonical_email' => [
                    'email_alias' => $definition->alias,
                    'email_type' => $definition->resolvedType(),
                    'email_version' => $definition->version,
                    'category' => $definition->category,
                    'template_view' => $definition->templateView,
                    'template_version' => $definition->templateVersion,
                    'idempotency_key' => $claimed->idempotency_key,
                    'correlation_id' => $claimed->correlation_id,
                    'delivery_mode' => 'async',
                    'source_of_truth' => $definition->sourceOfTruth,
                    'editable' => $definition->editable,
                ],
            ];

        } catch (\Throwable $exception) {
            $this->auditService->failed($claimed, $exception);
            throw $exception;
        }

        try {
            $message = $this->dispatcher->dispatch($built, $transport, $metadata);
        } catch (\Throwable $exception) {
            $this->auditService->uncertain($claimed, $exception);
            Log::critical('System email provider outcome requires reconciliation.', [
                'dispatch_id' => $claimed->id,
                'email_alias' => $claimed->email_alias,
                'exception' => $exception::class,
            ]);

            return;
        }

        try {
            $this->auditService->sent($claimed, $message);
        } catch (\Throwable $exception) {
            $this->auditService->uncertain($claimed, $exception, $message);
            Log::critical('System email was handed to the provider but its local receipt could not be finalized.', [
                'dispatch_id' => $claimed->id,
                'message_id' => $message->id,
                'provider_message_id' => $message->provider_message_id,
                'exception' => $exception::class,
            ]);
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
