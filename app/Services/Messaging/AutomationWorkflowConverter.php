<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;

class AutomationWorkflowConverter
{
    public function getWorkflowDefinition(AutomationRule $automation): array
    {
        $workflow = is_array($automation->workflow_definition_json) ? $automation->workflow_definition_json : null;

        if ($workflow && isset($workflow['nodes'], $workflow['edges'])) {
            return $workflow;
        }

        return $this->buildLegacyWorkflow($automation);
    }

    public function getEntryTrigger(AutomationRule $automation): array
    {
        $workflow = $this->getWorkflowDefinition($automation);
        $triggerNode = collect($workflow['nodes'] ?? [])->first(fn (array $node) => str_starts_with((string) ($node['type'] ?? ''), 'trigger.'));

        return [
            'trigger_type' => $automation->trigger_type,
            'node_id' => $triggerNode['id'] ?? null,
            'node_type' => $triggerNode['type'] ?? null,
            'config' => $triggerNode['config'] ?? [],
        ];
    }

    public function buildLegacyWorkflow(AutomationRule $automation): array
    {
        $nodes = [];
        $edges = [];

        $isScheduledSystem = ($automation->scope === 'SYSTEM')
            && (($automation->schedule_json['type'] ?? null) === 'weekly');

        $triggerNodeId = 'trigger_' . $automation->id;
        $nodes[] = [
            'id' => $triggerNodeId,
            'type' => $isScheduledSystem ? 'trigger.schedule' : 'trigger.event',
            'position' => ['x' => 80, 'y' => 140],
            'config' => $isScheduledSystem
                ? [
                    'triggerType' => $automation->trigger_type,
                    'schedule' => $automation->schedule_json ?? [],
                    'command' => $automation->schedule_json['command']
                        ?? $automation->condition_json['command']
                        ?? null,
                ]
                : [
                    'triggerType' => $automation->trigger_type,
                ],
            'validation' => [],
        ];

        $currentNodeId = $triggerNodeId;
        $currentX = 320;

        $conditionJson = is_array($automation->condition_json) ? $automation->condition_json : [];
        $hasLegacyCondition = $conditionJson !== [] && array_diff(array_keys($conditionJson), ['schedule', 'day', 'time', 'command']) !== [];
        if ($hasLegacyCondition) {
            $conditionNodeId = 'condition_' . $automation->id;
            $nodes[] = [
                'id' => $conditionNodeId,
                'type' => 'condition.if',
                'position' => ['x' => $currentX, 'y' => 140],
                'config' => [
                    'match' => 'all',
                    'rules' => $this->legacyConditionsToRules($conditionJson),
                ],
                'validation' => [],
            ];
            $edges[] = [
                'id' => $currentNodeId . '_' . $conditionNodeId,
                'source' => $currentNodeId,
                'target' => $conditionNodeId,
            ];
            $currentNodeId = $conditionNodeId;
            $currentX += 260;
        }

        $schedule = is_array($automation->schedule_json) ? $automation->schedule_json : [];
        if (!empty($schedule['offset'])) {
            $waitNodeId = 'wait_' . $automation->id;
            $offset = $this->parseLegacyOffset((string) $schedule['offset']);

            $nodes[] = [
                'id' => $waitNodeId,
                'type' => 'wait.datetime_offset',
                'position' => ['x' => $currentX, 'y' => 140],
                'config' => [
                    'referenceField' => 'shoot_datetime',
                    'direction' => $offset['direction'],
                    'amount' => $offset['amount'],
                    'unit' => $offset['unit'],
                ],
                'validation' => [],
            ];
            $edges[] = [
                'id' => $currentNodeId . '_' . $waitNodeId,
                'source' => $currentNodeId,
                'target' => $waitNodeId,
                'branchKey' => $currentNodeId === $triggerNodeId ? null : 'true',
            ];
            $currentNodeId = $waitNodeId;
            $currentX += 260;
        }

        if ($automation->template_id) {
            $actionNodeId = 'action_' . $automation->id;
            $actionType = ($automation->template?->channel ?? 'EMAIL') === 'SMS'
                ? 'action.sms'
                : 'action.email';

            $nodes[] = [
                'id' => $actionNodeId,
                'type' => $actionType,
                'position' => ['x' => $currentX, 'y' => 140],
                'config' => [
                    'templateId' => $automation->template_id,
                    'channelId' => $automation->channel_id,
                    'recipientMode' => 'automation_default',
                    'recipientRoles' => $this->normalizeRecipientRoles($automation->recipients_json),
                ],
                'validation' => [],
            ];
            $edges[] = [
                'id' => $currentNodeId . '_' . $actionNodeId,
                'source' => $currentNodeId,
                'target' => $actionNodeId,
                'branchKey' => $currentNodeId !== $triggerNodeId && str_starts_with($currentNodeId, 'condition_') ? 'true' : null,
            ];
            $currentNodeId = $actionNodeId;
            $currentX += 240;
        }

        $endNodeId = 'end_' . $automation->id;
        $nodes[] = [
            'id' => $endNodeId,
            'type' => 'end',
            'position' => ['x' => $currentX, 'y' => 140],
            'config' => [],
            'validation' => [],
        ];
        $edges[] = [
            'id' => $currentNodeId . '_' . $endNodeId,
            'source' => $currentNodeId,
            'target' => $endNodeId,
            'branchKey' => str_starts_with($currentNodeId, 'condition_') ? 'false' : null,
        ];

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            'meta' => [
                'converted_from_legacy' => true,
                'legacy_summary' => [
                    'template_id' => $automation->template_id,
                    'channel_id' => $automation->channel_id,
                    'schedule_json' => $automation->schedule_json,
                    'condition_json' => $automation->condition_json,
                    'recipients_json' => $automation->recipients_json,
                ],
                'system_command' => $automation->schedule_json['command']
                    ?? $automation->condition_json['command']
                    ?? null,
            ],
        ];
    }

    private function legacyConditionsToRules(array $conditionJson): array
    {
        $rules = [];

        foreach ($conditionJson as $field => $value) {
            if (in_array($field, ['schedule', 'day', 'time', 'command'], true)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $operator => $operand) {
                    $rules[] = [
                        'field' => $field,
                        'operator' => $operator,
                        'value' => $operand,
                    ];
                }
                continue;
            }

            $rules[] = [
                'field' => $field,
                'operator' => 'eq',
                'value' => $value,
            ];
        }

        return $rules;
    }

    private function parseLegacyOffset(string $offset): array
    {
        if (!preg_match('/^([+-]?)(\d+)([hdm])$/', $offset, $matches)) {
            return [
                'direction' => 'before',
                'amount' => 24,
                'unit' => 'hours',
            ];
        }

        return [
            'direction' => $matches[1] === '-' ? 'before' : 'after',
            'amount' => (int) $matches[2],
            'unit' => match ($matches[3]) {
                'd' => 'days',
                'm' => 'minutes',
                default => 'hours',
            },
        ];
    }

    private function normalizeRecipientRoles(mixed $recipientsJson): array
    {
        if (is_array($recipientsJson) && array_is_list($recipientsJson)) {
            return array_values(array_map('strval', $recipientsJson));
        }

        if (is_array($recipientsJson) && isset($recipientsJson['roles']) && is_array($recipientsJson['roles'])) {
            return array_values(array_map('strval', $recipientsJson['roles']));
        }

        return ['client'];
    }
}
