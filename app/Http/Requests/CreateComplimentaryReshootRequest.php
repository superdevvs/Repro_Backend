<?php

namespace App\Http\Requests;

use App\Models\CompReshootItem;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Services\Shoots\ComplimentaryReshootReasonPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateComplimentaryReshootRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(strtolower((string) $this->user()?->role), ['admin', 'superadmin'], true);
    }

    protected function prepareForValidation(): void
    {
        $policy = app(ComplimentaryReshootReasonPolicy::class);
        $reasonCode = (string) $this->input('reason_code', '');
        $rawItems = $this->input('items');
        if (! is_array($rawItems)) {
            $rawItems = $this->input('service_items');
        }
        if (! is_array($rawItems)) {
            $rawItems = $this->input('services', []);
        }

        $servicePhotographers = collect($this->input('service_photographers', []))
            ->filter(fn ($row) => is_array($row))
            ->mapWithKeys(fn (array $row) => [
                (string) ($row['service_id'] ?? '') => $row['photographer_id'] ?? null,
            ]);

        $normalizedItems = collect($rawItems)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) use ($reasonCode, $policy, $servicePhotographers) {
                $itemReason = (string) ($row['reason_code'] ?? $reasonCode);
                $serviceId = $row['service_id'] ?? $row['id'] ?? null;
                $topMode = $this->input('photographer_compensation_mode');
                $mode = data_get($row, 'photographer_compensation.mode')
                    ?? ($row['photographer_compensation_mode'] ?? null)
                    ?? ($topMode !== 'mixed' ? $topMode : null)
                    ?? $policy->suggestedMode($itemReason, ShootCompensation::RECIPIENT_PHOTOGRAPHER);
                $amount = data_get($row, 'photographer_compensation.amount')
                    ?? ($row['photographer_pay'] ?? null);
                if ($mode !== ShootCompensation::MODE_CUSTOM) {
                    $amount = null;
                }

                return [
                    'source_shoot_service_id' => $row['source_shoot_service_id'] ?? null,
                    'service_id' => $serviceId,
                    'quantity' => $row['quantity'] ?? 1,
                    'photographer_id' => $row['photographer_id']
                        ?? $servicePhotographers->get((string) $serviceId)
                        ?? $this->input('photographer_id'),
                    'editor_id' => $row['editor_id'] ?? null,
                    'scheduled_at' => $row['scheduled_at'] ?? $this->input('scheduled_at'),
                    'reason_code' => $itemReason,
                    'reason_note' => $row['reason_note'] ?? $this->input('reason_note'),
                    'responsibility' => $row['responsibility']
                        ?? $policy->suggestedResponsibility($itemReason),
                    'responsible_staff_id' => $row['responsible_staff_id'] ?? null,
                    'photographer_compensation' => [
                        'mode' => $mode,
                        'amount' => $amount,
                    ],
                ];
            })
            ->values()
            ->all();

        $repCompensation = $this->input('sales_rep_compensation');
        if (! is_array($repCompensation)) {
            $submittedMode = $this->input('sales_rep_compensation_mode');
            $repCompensation = [
                'mode' => $submittedMode ?? (
                    $policy->requiresExplicitSalesRepChoice($reasonCode)
                        ? null
                        : $policy->suggestedMode($reasonCode, ShootCompensation::RECIPIENT_SALES_REP)
                ),
                'amount' => $this->input('sales_rep_compensation_amount'),
            ];
        }

        $perItemRepChoices = collect($rawItems)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => [
                'mode' => data_get($row, 'sales_rep_compensation.mode')
                    ?? ($row['sales_rep_compensation_mode'] ?? null),
                'amount' => data_get($row, 'sales_rep_compensation.amount')
                    ?? ($row['sales_rep_compensation_amount'] ?? null),
            ])
            ->filter(fn (array $row) => $row['mode'] !== null)
            ->unique(fn (array $row) => json_encode($row))
            ->values();

        if (($repCompensation['mode'] ?? null) === null && $perItemRepChoices->count() === 1) {
            $repCompensation = $perItemRepChoices->first();
        }
        if (($repCompensation['mode'] ?? null) !== ShootCompensation::MODE_CUSTOM) {
            $repCompensation['amount'] = null;
        }

        $this->merge([
            '_idempotency_key' => $this->header('Idempotency-Key')
                ?: $this->input('idempotency_key')
                ?: (string) Str::uuid(),
            '_rep_compensation_conflict' => $perItemRepChoices->count() > 1 ? true : null,
            'items' => $normalizedItems,
            'sales_rep_compensation' => $repCompensation,
        ]);
    }

    public function rules(): array
    {
        return [
            '_idempotency_key' => ['required', 'uuid'],
            '_rep_compensation_conflict' => ['prohibited'],
            'shoot_type' => ['nullable', Rule::in([Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT])],
            'scheduled_at' => ['nullable', 'date'],
            'scheduled_date' => ['nullable', 'date'],
            'time' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'timezone:all'],
            'photographer_id' => ['nullable', 'integer', 'exists:users,id'],
            'rep_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason_code' => ['required', Rule::in(ComplimentaryReshootReasonPolicy::REASONS)],
            'reason_note' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => $this->input('reason_code') === ComplimentaryReshootReasonPolicy::OTHER)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.source_shoot_service_id' => ['required', 'integer', 'distinct', 'exists:shoot_service,id'],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.photographer_id' => ['nullable', 'integer', 'exists:users,id'],
            'items.*.editor_id' => ['nullable', 'integer', 'exists:users,id'],
            'items.*.scheduled_at' => ['nullable', 'date'],
            'items.*.reason_code' => ['required', Rule::in(ComplimentaryReshootReasonPolicy::REASONS)],
            'items.*.reason_note' => ['nullable', 'string', 'max:2000'],
            'items.*.responsibility' => ['required', Rule::in(CompReshootItem::RESPONSIBILITIES)],
            'items.*.responsible_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'items.*.photographer_compensation' => ['required', 'array'],
            'items.*.photographer_compensation.mode' => ['required', Rule::in(ShootCompensation::MODES)],
            'items.*.photographer_compensation.amount' => [
                'nullable',
                'required_if:items.*.photographer_compensation.mode,'.ShootCompensation::MODE_CUSTOM,
                'numeric',
                'gt:0',
                'max:999999.99',
            ],
            'sales_rep_compensation' => ['required', 'array'],
            'sales_rep_compensation.mode' => ['required', Rule::in(ShootCompensation::MODES)],
            'sales_rep_compensation.amount' => [
                'nullable',
                'required_if:sales_rep_compensation.mode,'.ShootCompensation::MODE_CUSTOM,
                'numeric',
                'gt:0',
                'max:999999.99',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (($item['reason_code'] ?? null) === ComplimentaryReshootReasonPolicy::OTHER
                    && trim((string) ($item['reason_note'] ?? '')) === '') {
                    $validator->errors()->add("items.{$index}.reason_note", 'A note is required when the reason is Other.');
                }

                if (data_get($item, 'photographer_compensation.mode') === ShootCompensation::MODE_CUSTOM
                    && ! is_numeric(data_get($item, 'photographer_compensation.amount'))) {
                    $validator->errors()->add("items.{$index}.photographer_compensation.amount", 'Enter the exact custom photographer amount.');
                }
            }

            if ($this->input('sales_rep_compensation.mode') === ShootCompensation::MODE_CUSTOM
                && ! is_numeric($this->input('sales_rep_compensation.amount'))) {
                $validator->errors()->add('sales_rep_compensation.amount', 'Enter the exact custom sales rep amount.');
            }
        });
    }
}
