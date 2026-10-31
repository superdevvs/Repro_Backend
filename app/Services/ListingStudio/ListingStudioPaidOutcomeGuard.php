<?php

namespace App\Services\ListingStudio;

use App\Contracts\ListingStudio\ListingStudioOutcomeGuard;
use App\Exceptions\PublicApiException;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioRevisionDelivery;
use App\Models\ListingStudioRevisionTask;
use App\Models\StudioWorkspace;

class ListingStudioPaidOutcomeGuard implements ListingStudioOutcomeGuard
{
    public function assertDeliverableOutput(ListingStudioOrderItem $item, array $descriptor): void
    {
        if ($item->kind === 'human_revision') {
            $task = ListingStudioRevisionTask::where('order_item_id', $item->id)->first();
            $delivery = $task?->delivery_attempt_id ? ListingStudioRevisionDelivery::where('task_id', $task->id)->find($task->delivery_attempt_id) : null;
            if (! $delivery || ! in_array($delivery->status, ['pending', 'finalizing'], true)
                || ($delivery->descriptor['sha256'] ?? '') !== ($descriptor['sha256'] ?? '')
                || ($delivery->descriptor['path'] ?? '') !== ($descriptor['path'] ?? '')
                || ($descriptor['disk'] ?? '') !== ListingStudioPaidStorage::DISK
                || ! str_starts_with($descriptor['path'] ?? '', 'revision-deliveries/'.$delivery->id.'/result.')) {
                $this->unresolved();
            }
        } else {
            $workspace = $item->workspace_id ? StudioWorkspace::find($item->workspace_id) : null;
            if (! $workspace || $workspace->listing_studio_order_item_id !== $item->id || $workspace->status !== 'completed'
                || (int) $workspace->created_by !== (int) $item->client_id
                || ! collect($workspace->outputs ?? [])->contains(fn ($output) => ($output['path'] ?? '') === ($descriptor['path'] ?? '')
                    && ($output['status'] ?? '') === 'completed' && ($output['kind'] ?? '') === 'image')) {
                $this->unresolved();
            }
        }
    }

    /** Called under the core financial write transaction; database checks only. */
    public function assertDefinitiveStop(ListingStudioOrderItem $item): void
    {
        if ($item->status === 'completed' || $item->output_descriptor) {
            $this->unresolved();
        }
        $task = ListingStudioRevisionTask::where('order_item_id', $item->id)->first();
        if ($task && ($task->status === 'delivered' || ListingStudioRevisionDelivery::where('task_id', $task->id)
            ->whereIn('status', ['pending', 'finalizing', 'needs_attention', 'completed'])->exists())) {
            $this->unresolved();
        }
        $workspace = $item->workspace_id ? StudioWorkspace::find($item->workspace_id) : null;
        if ($item->workspace_id && (! $workspace || $workspace->listing_studio_order_item_id !== $item->id)) {
            $this->unresolved();
        }
        if ($workspace && ($workspace->isBusy() || $workspace->status === 'completed' || ! empty($workspace->outputs)
            || (! ($workspace->operation['paidFailureDefinitive'] ?? false) && app(ListingStudioPaidPipeline::class)->hasCheckpoint($workspace)))) {
            $this->unresolved();
        }
    }

    private function unresolved(): never
    {
        throw new PublicApiException('An accepted result still needs recovery. Resolve it before cancelling or failing this order.', 'outcome_unresolved', 409);
    }
}
