<?php

namespace App\Services\Messaging;

class AutomationWorkflowValidator
{
    private const ACTION_NODE_TYPES = [
        'action.email',
        'action.sms',
        'action.internal_notification',
    ];

    private const TRIGGER_NODE_TYPES = [
        'trigger.event',
        'trigger.schedule',
    ];

    public function validate(array $workflow): array
    {
        $nodes = collect($workflow['nodes'] ?? []);
        $edges = collect($workflow['edges'] ?? []);
        $meta = is_array($workflow['meta'] ?? null) ? $workflow['meta'] : [];

        $errors = [];
        $warnings = [];
        $nodeErrors = [];

        if ($nodes->isEmpty()) {
            return [
                'valid' => false,
                'errors' => ['Workflow must contain at least one node.'],
                'warnings' => [],
                'node_errors' => [],
                'summary' => [
                    'node_count' => 0,
                    'edge_count' => 0,
                    'reachable_action_count' => 0,
                ],
            ];
        }

        $nodeMap = $nodes->keyBy('id');
        $triggerNodes = $nodes->filter(fn (array $node) => in_array($node['type'] ?? null, self::TRIGGER_NODE_TYPES, true))->values();

        if ($triggerNodes->count() !== 1) {
            $errors[] = 'Workflow must contain exactly one trigger node.';
        }

        foreach ($edges as $edge) {
            if (!$nodeMap->has($edge['source'] ?? null) || !$nodeMap->has($edge['target'] ?? null)) {
                $errors[] = 'Workflow contains an edge that references a missing node.';
            }
        }

        $triggerId = $triggerNodes->first()['id'] ?? null;
        $reachable = $triggerId ? $this->findReachableNodeIds($triggerId, $edges) : [];
        $reachableActionCount = $nodes->filter(function (array $node) use ($reachable) {
            return in_array($node['id'] ?? '', $reachable, true)
                && in_array($node['type'] ?? '', self::ACTION_NODE_TYPES, true);
        })->count();

        if ($reachableActionCount < 1 && empty($meta['system_command'])) {
            $errors[] = 'Workflow must contain at least one reachable action node.';
        }

        $unreachableNodes = $nodes->pluck('id')->filter(fn (string $id) => !in_array($id, $reachable, true));
        foreach ($unreachableNodes as $nodeId) {
            $nodeErrors[$nodeId][] = 'This node is not connected to the main flow.';
        }
        if ($unreachableNodes->isNotEmpty()) {
            $errors[] = 'Workflow contains orphan nodes that are not reachable from the trigger.';
        }

        if ($triggerId && $this->hasCycle($triggerId, $edges)) {
            $errors[] = 'Workflow cannot contain loops or cycles in v1.';
        }

        foreach ($nodes as $node) {
            $configErrors = $this->validateNode($node, $edges);
            if ($configErrors !== []) {
                $nodeErrors[$node['id']] = array_merge($nodeErrors[$node['id']] ?? [], $configErrors);
            }
        }

        if ($nodeErrors !== []) {
            $errors[] = 'One or more workflow nodes are incomplete.';
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'node_errors' => $nodeErrors,
            'summary' => [
                'node_count' => $nodes->count(),
                'edge_count' => $edges->count(),
                'reachable_action_count' => $reachableActionCount,
            ],
        ];
    }

    private function validateNode(array $node, \Illuminate\Support\Collection $edges): array
    {
        $type = $node['type'] ?? null;
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $errors = [];

        switch ($type) {
            case 'trigger.event':
                if (empty($config['triggerType'])) {
                    $errors[] = 'Trigger event type is required.';
                }
                break;

            case 'trigger.schedule':
                if (empty($config['triggerType'])) {
                    $errors[] = 'Scheduled trigger type is required.';
                }
                if (empty($config['schedule']['type']) && empty($config['schedule']['time'])) {
                    $errors[] = 'Scheduled trigger must define a schedule.';
                }
                break;

            case 'condition.if':
                if (empty($config['rules']) || !is_array($config['rules'])) {
                    $errors[] = 'Condition node must contain at least one rule.';
                }
                $outgoing = $edges->where('source', $node['id'] ?? '');
                $branches = $outgoing->pluck('branchKey')->filter()->values()->all();
                if (!in_array('true', $branches, true) || !in_array('false', $branches, true)) {
                    $errors[] = 'Condition node must have both true and false branches connected.';
                }
                break;

            case 'wait.duration':
                if (empty($config['amount']) || empty($config['unit'])) {
                    $errors[] = 'Duration wait node requires amount and unit.';
                }
                break;

            case 'wait.datetime_offset':
                if (empty($config['referenceField']) || empty($config['amount']) || empty($config['unit']) || empty($config['direction'])) {
                    $errors[] = 'Datetime offset wait node is incomplete.';
                }
                break;

            case 'action.email':
                if (empty($config['templateId']) && empty($config['bodyHtml']) && empty($config['bodyText']) && empty($config['subject'])) {
                    $errors[] = 'Email action requires a template or message content.';
                }
                break;

            case 'action.sms':
                if (empty($config['templateId']) && empty($config['bodyText'])) {
                    $errors[] = 'SMS action requires a template or body text.';
                }
                break;

            case 'action.internal_notification':
                if (empty($config['title']) || empty($config['body']) || empty($config['destinationUrl'])) {
                    $errors[] = 'Internal notification requires title, body, and destination link.';
                }
                break;
        }

        return $errors;
    }

    private function findReachableNodeIds(string $triggerId, \Illuminate\Support\Collection $edges): array
    {
        $visited = [];
        $queue = [$triggerId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (in_array($current, $visited, true)) {
                continue;
            }

            $visited[] = $current;
            $targets = $edges
                ->where('source', $current)
                ->pluck('target')
                ->filter()
                ->values()
                ->all();

            foreach ($targets as $target) {
                if (!in_array($target, $visited, true)) {
                    $queue[] = $target;
                }
            }
        }

        return $visited;
    }

    private function hasCycle(string $triggerId, \Illuminate\Support\Collection $edges): bool
    {
        $graph = [];
        foreach ($edges as $edge) {
            $graph[$edge['source']][] = $edge['target'];
        }

        $visited = [];
        $active = [];

        $visit = function (string $nodeId) use (&$visit, &$visited, &$active, $graph): bool {
            if (isset($active[$nodeId])) {
                return true;
            }
            if (isset($visited[$nodeId])) {
                return false;
            }

            $visited[$nodeId] = true;
            $active[$nodeId] = true;

            foreach ($graph[$nodeId] ?? [] as $nextNodeId) {
                if ($visit($nextNodeId)) {
                    return true;
                }
            }

            unset($active[$nodeId]);

            return false;
        };

        return $visit($triggerId);
    }
}
