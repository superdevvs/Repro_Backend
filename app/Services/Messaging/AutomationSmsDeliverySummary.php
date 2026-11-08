<?php

namespace App\Services\Messaging;

use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;

/** Read current delivery outcomes without rewriting or re-running completed workflows. */
class AutomationSmsDeliverySummary
{
    public function annotate(Collection $runs): Collection
    {
        $runs->loadMissing('steps');
        $idsForRun = fn ($run) => $run->steps->filter(fn ($step) => $step->node_type === 'action.sms')
            ->flatMap(fn ($step) => array_merge(
                (array) data_get($step->output_json, 'message_ids', []),
                (array) data_get($step->output_json, 'blocked_message_ids', []),
            ))->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values();
        $ids = $runs->flatMap($idsForRun)->unique();
        $messages = Message::query()->where('channel', 'SMS')->where('direction', 'OUTBOUND')
            ->whereIn('id', $ids)->get(['id', 'status', 'error_message', 'updated_at'])->keyBy('id');

        foreach ($runs as $run) {
            $runIds = $idsForRun($run);
            if ($runIds->isEmpty()) {
                continue;
            }
            $found = $messages->only($runIds->all());
            $counts = ['delivered' => 0, 'failed' => 0, 'blocked' => 0, 'pending' => 0, 'unknown' => $runIds->count() - $found->count()];
            foreach ($found as $message) {
                $key = match ($message->status) {
                    'DELIVERED' => 'delivered', 'FAILED' => 'failed', 'BLOCKED' => 'blocked',
                    'SENT', 'QUEUED', 'SCHEDULED' => 'pending', default => 'unknown',
                };
                $counts[$key]++;
            }
            $status = collect(['failed', 'blocked', 'unknown', 'pending'])->first(fn ($key) => $counts[$key] > 0) ?? 'delivered';
            $run->setAttribute('sms_delivery', $counts + [
                'status' => $status, 'total' => $runIds->count(),
                'errors' => $found->filter(fn ($message) => in_array($message->status, ['FAILED', 'BLOCKED'], true))
                    ->map(fn ($message) => $message->attributesToArray()['error_message'])
                    ->filter()->unique()->take(5)->values()->all(),
                'updated_at' => $found->max('updated_at')?->toIso8601String(),
            ]);
        }

        return $runs;
    }
}
