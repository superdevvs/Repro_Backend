<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VoiceAutomationDefinition
{
    public const TRIGGERS = ['missed_call_callback', 'failed_transfer_callback', 'shoot_reminder', 'delivery_follow_up', 'unpaid_invoice_reminder'];

    public const FIELDS = [
        'missed_call_callback' => ['known_caller', 'direction', 'intent'],
        'failed_transfer_callback' => ['known_caller', 'direction', 'intent'],
        'shoot_reminder' => ['known_caller', 'shoot_status'],
        'delivery_follow_up' => ['known_caller', 'shoot_status'],
        'unpaid_invoice_reminder' => ['known_caller', 'amount_due', 'days_overdue'],
    ];

    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'trigger_type' => ['required', Rule::in(self::TRIGGERS)],
            'enabled' => ['required', 'boolean'],
            'conditions' => ['present', 'array', 'max:10'],
            'conditions.*' => ['array:field,operator,value'],
            'conditions.*.field' => ['required', 'string'],
            'conditions.*.operator' => ['required', Rule::in(['eq', 'gte', 'lte'])],
            'conditions.*.value' => ['present'],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'quiet_hours' => ['required', 'array:enabled,timezone,start,end'],
            'quiet_hours.enabled' => ['required', 'boolean'],
            'quiet_hours.timezone' => ['required', 'timezone:all_with_bc'],
            'quiet_hours.start' => ['required', 'date_format:H:i'],
            'quiet_hours.end' => ['required', 'date_format:H:i'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:5'],
            'retry_delay_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'action_type' => ['required', Rule::in(['ai_callback', 'internal_task'])],
            'action_config' => ['present', 'array:task_title,assigned_to_user_id'],
            'action_config.task_title' => ['required_if:action_type,internal_task', 'string', 'max:160'],
            'action_config.assigned_to_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['admin', 'superadmin', 'editing_manager', 'salesRep']))],
        ])->validate();

        if (trim($data['name']) === '') {
            throw ValidationException::withMessages(['name' => 'Give this rule a name.']);
        }
        if ($data['action_type'] === 'ai_callback' && $data['action_config'] !== []) {
            throw ValidationException::withMessages(['action_config' => 'Task fields only apply to the internal task action.']);
        }
        if ($data['action_type'] === 'internal_task' && trim($data['action_config']['task_title']) === '') {
            throw ValidationException::withMessages(['action_config.task_title' => 'Give the task a title.']);
        }
        foreach ($data['conditions'] as $index => $condition) {
            $field = $condition['field'];
            $value = $condition['value'];
            $numeric = in_array($field, ['amount_due', 'days_overdue'], true);
            $valid = in_array($field, self::FIELDS[$data['trigger_type']], true)
                && ($numeric ? in_array($condition['operator'], ['gte', 'lte'], true) : $condition['operator'] === 'eq');
            $valid = $valid && match ($field) {
                'known_caller' => is_bool($value),
                'direction' => in_array($value, ['INBOUND', 'OUTBOUND'], true),
                'intent', 'shoot_status' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 80,
                'amount_due', 'days_overdue' => is_numeric($value) && $value >= 0 && $value <= 100000000,
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages(["conditions.{$index}" => 'Choose a supported condition, operator and value for this trigger.']);
            }
        }

        return $data;
    }
}
