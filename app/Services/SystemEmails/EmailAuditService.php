<?php

namespace App\Services\SystemEmails;

use App\Models\Message;
use App\Models\SystemEmailDispatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class EmailAuditService
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $options
     * @return array{dispatch: SystemEmailDispatch, duplicate: bool}
     */
    public function begin(EmailTypeDefinition $definition, array $payload, array $transport, array $options = []): array
    {
        $idempotencyKey = $this->resolveIdempotencyKey($definition, $payload, $transport, $options);
        $force = (bool) ($options['force'] ?? false);

        if (!$force) {
            $existing = SystemEmailDispatch::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                if (($options['retry_failed'] ?? false) === true && strtolower((string) $existing->status) === 'failed') {
                    $existing->update([
                        'status' => 'pending',
                        'attempt_count' => max(1, (int) $existing->attempt_count + 1),
                        'payload_snapshot' => $payload,
                        'transport_snapshot' => $transport,
                        'error_code' => null,
                        'error_message' => null,
                        'failed_at' => null,
                    ]);

                    return ['dispatch' => $existing->fresh(), 'duplicate' => false];
                }

                return ['dispatch' => $existing, 'duplicate' => true];
            }
        }

        $effectiveKey = $force
            ? $idempotencyKey . ':force:' . Str::uuid()->toString()
            : $idempotencyKey;

        try {
            $dispatch = SystemEmailDispatch::create([
                'email_type' => $definition->resolvedType(),
                'email_alias' => $definition->alias,
                'email_version' => $definition->version,
                'category' => $definition->category,
                'idempotency_key' => $effectiveKey,
                'correlation_id' => (string) Str::uuid(),
                'recipient_email' => (string) ($transport['to'] ?? ''),
                'recipient_type' => $transport['contact_type'] ?? Arr::get($payload, 'meta.recipient_type'),
                'related_account_id' => $transport['related_account_id'] ?? null,
                'related_shoot_id' => $transport['related_shoot_id'] ?? null,
                'related_invoice_id' => $transport['related_invoice_id'] ?? null,
                'send_source' => $transport['send_source'] ?? $definition->alias,
                'delivery_mode' => $definition->deliveryMode,
                'template_view' => $definition->templateView,
                'template_version' => $definition->templateVersion,
                'status' => 'pending',
                'attempt_count' => 1,
                'payload_snapshot' => $payload,
                'transport_snapshot' => $transport,
                'metadata' => [
                    'requested_idempotency_key' => $idempotencyKey,
                    'forced' => $force,
                    'canonical_metadata' => is_array($options['canonical_metadata'] ?? null)
                        ? $options['canonical_metadata']
                        : [],
                ],
            ]);
        } catch (QueryException $exception) {
            if ($force || ! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $dispatch = SystemEmailDispatch::query()->where('idempotency_key', $effectiveKey)->first();
            if (! $dispatch) {
                throw $exception;
            }

            return ['dispatch' => $dispatch, 'duplicate' => true];
        }

        return ['dispatch' => $dispatch, 'duplicate' => false];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    public function sent(SystemEmailDispatch $dispatch, Message $message): SystemEmailDispatch
    {
        $dispatch->update([
            'message_id' => $message->id,
            'provider' => $message->provider,
            'provider_message_id' => $message->provider_message_id,
            'status' => strtolower((string) $message->status),
            'sent_at' => $message->sent_at ?? now(),
            'metadata' => array_merge($dispatch->metadata ?? [], [
                'message_status' => $message->status,
            ]),
        ]);

        return $dispatch->fresh();
    }

    public function failed(SystemEmailDispatch $dispatch, \Throwable $exception): SystemEmailDispatch
    {
        $dispatch->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_code' => class_basename($exception),
            'error_message' => $exception->getMessage(),
        ]);

        return $dispatch->fresh();
    }

    /**
     * Preserve a claimed row when the provider outcome may be uncertain. A
     * processing row is intentionally not eligible for automated resend; ops
     * must reconcile the provider before changing it back to pending.
     */
    public function uncertain(
        SystemEmailDispatch $dispatch,
        \Throwable $exception,
        ?Message $message = null
    ): SystemEmailDispatch {
        $dispatch->update([
            'message_id' => $message?->id ?? $dispatch->message_id,
            'provider' => $message?->provider ?? $dispatch->provider,
            'provider_message_id' => $message?->provider_message_id ?? $dispatch->provider_message_id,
            'status' => 'processing',
            'error_code' => class_basename($exception),
            'error_message' => $exception->getMessage(),
            'metadata' => array_merge($dispatch->metadata ?? [], [
                'reconciliation_required' => true,
                'uncertain_at' => now()->toIso8601String(),
            ]),
        ]);

        return $dispatch->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $options
     */
    public function resolveIdempotencyKey(EmailTypeDefinition $definition, array $payload, array $transport, array $options = []): string
    {
        if (!empty($options['idempotency_key'])) {
            return (string) $options['idempotency_key'];
        }

        $recipient = strtolower(trim((string) ($transport['to'] ?? '')));
        $accountId = $transport['related_account_id'] ?? Arr::get($payload, 'account.id') ?? 0;
        $shootId = $transport['related_shoot_id'] ?? Arr::get($payload, 'shoot.id') ?? 0;
        $invoiceId = $transport['related_invoice_id'] ?? Arr::get($payload, 'invoice.id') ?? 0;
        $eventVersion = Arr::get($payload, 'meta.event_version')
            ?? Arr::get($payload, 'meta.token_hash')
            ?? Arr::get($payload, 'meta.scheduled_at')
            ?? Arr::get($payload, 'meta.period')
            ?? 'default';

        return sprintf(
            '%s:%s:acct_%s:shoot_%s:invoice_%s:%s',
            $definition->resolvedType(),
            $recipient,
            $accountId ?: 'none',
            $shootId ?: 'none',
            $invoiceId ?: 'none',
            sha1((string) $eventVersion)
        );
    }
}
