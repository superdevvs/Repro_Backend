<?php

use App\Models\AutomationRule;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\ShootRequestRecipientRouting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_rules') || ! Schema::hasColumn('automation_rules', 'workflow_definition_json')) {
            return;
        }

        foreach (AutomationRule::whereIn('trigger_type', ShootRequestRecipientRouting::TRIGGERS)->get() as $rule) {
            $workflow = $rule->workflow_definition_json;
            $configured = (array) $rule->recipients_json;
            $roles = $configured['roles'] ?? $configured;
            $addStockRep = $rule->trigger_type === 'SHOOT_REQUESTED'
                && $rule->scope === 'SYSTEM'
                && ($rule->name === 'Shoot Request Received' || ($workflow['meta']['system_default_key'] ?? null) === 'Shoot Request Received')
                && $roles === ['client']
                && $this->isFactoryWorkflow($rule, $workflow);
            $roles = ShootRequestRecipientRouting::roles($rule->trigger_type, $roles);
            if ($addStockRep) {
                $roles[] = 'rep';
            }
            $recipients = array_key_exists('roles', $configured) ? array_replace($configured, ['roles' => $roles]) : $roles;

            if (is_array($workflow) && is_array($workflow['nodes'] ?? null)) {
                foreach ($workflow['nodes'] as &$node) {
                    if (! str_starts_with($node['type'] ?? '', 'action.')) {
                        continue;
                    }
                    if (isset($node['config']['recipientRoles']) && is_array($node['config']['recipientRoles'])) {
                        $node['config']['recipientRoles'] = ShootRequestRecipientRouting::roles($rule->trigger_type, $node['config']['recipientRoles']);
                        if ($addStockRep && $node['config']['recipientRoles'] === ['client']) {
                            $node['config']['recipientRoles'][] = 'rep';
                        }
                    }
                    if (isset($node['config']['contextKey'])) {
                        $node['config']['contextKey'] = ShootRequestRecipientRouting::role($rule->trigger_type, (string) $node['config']['contextKey']);
                    }
                }
                unset($node);
            }

            // Recipient routing only: preserve copy, branches, timing and disabled state.
            $rule->fill(['recipients_json' => $recipients, 'workflow_definition_json' => $workflow]);
            if ($rule->isDirty()) {
                $rule->save();
            }
        }
    }

    private function isFactoryWorkflow(AutomationRule $rule, ?array $workflow): bool
    {
        if (! $workflow) {
            return true;
        }
        $expected = app(AutomationWorkflowConverter::class)->buildLegacyWorkflow($rule);
        $nodes = fn (array $definition) => collect($definition['nodes'] ?? [])
            ->map(fn ($node) => ['id' => $node['id'], 'type' => $node['type'], 'config' => $node['config'] ?? []])->all();
        $edges = fn (array $definition) => collect($definition['edges'] ?? [])
            ->map(fn ($edge) => ['source' => $edge['source'], 'target' => $edge['target'], 'branchKey' => $edge['branchKey'] ?? null])->all();

        return $nodes($workflow) == $nodes($expected) && $edges($workflow) == $edges($expected);
    }

    public function down(): void
    {
        // Do not restore obsolete photographer routing or overwrite later operator edits.
    }
};
