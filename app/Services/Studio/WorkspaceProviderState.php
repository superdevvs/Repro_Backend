<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** Server-owned checkpoints survive retries and are never included in workspace responses. */
class WorkspaceProviderState
{
    public function __construct(private StudioWorkspace $workspace, private string $operationId) {}

    public function get(string $key): mixed
    {
        $this->assertActive();

        $saved = $this->workspace->operation['providerState'][$key] ?? null;
        if (is_array($saved) && ! empty($saved['submitting']) && ! empty($saved['receiptId'])) {
            $result = app(WorkspaceProviderReceipt::class)->read($this->workspace->id, $this->operationId, $key, $saved['receiptId']);
            if ($result) {
                $this->put($key, $result);

                return $result;
            }
        }

        return $saved;
    }

    public function put(string $key, mixed $value): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($key, $value): void {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($this->workspace->id);
            if (! $record->isBusy() || ($record->operation['id'] ?? null) !== $this->operationId) {
                throw new StudioProviderException('This operation is no longer active.');
            }
            $operation = $record->operation;
            if ($value === null) {
                unset($operation['providerState'][$key]);
            } else {
                $operation['providerState'][$key] = $value;
            }
            $record->update(['operation' => $operation, 'version' => $record->version + 1]);
        }), 'studio.provider-checkpoint');
        $this->workspace->refresh();
    }

    public function assertActive(): void
    {
        $this->workspace->refresh();
        if (! $this->workspace->isBusy() || ($this->workspace->operation['id'] ?? null) !== $this->operationId) {
            throw new StudioProviderException('This operation is no longer active.');
        }
    }

    public function saveReceipt(string $key, string $receiptId, array $result): void
    {
        app(WorkspaceProviderReceipt::class)->save($this->workspace->id, $this->operationId, $key, $receiptId, $result);
    }

    public function assertSubmissionAllowed(): void
    {
        $this->assertActive();
        if ($this->workspace->listing_studio_trial_id) {
            app(\App\Services\ListingStudio\ListingStudioTrialPipeline::class)->assertMaySubmit($this->workspace);
        }
        if ($this->workspace->listing_studio_order_item_id) {
            app(\App\Services\ListingStudio\ListingStudioPaidPipeline::class)->assertMaySubmit($this->workspace);
        }
    }

    public function route(string $service): array
    {
        $this->assertActive();
        $route = $this->workspace->operation['routing'][$service] ?? null;
        if ($route) {
            return $route;
        }

        // Existing, pre-routing jobs must resume their original fal model.
        return app(StudioProviderSettings::class)->legacyRoute($service, []);
    }

    public function requestId(string $mediaId): ?string
    {
        $this->assertActive();

        return $this->workspace->operation['requests'][$mediaId] ?? null;
    }

    public function saveRequest(string $mediaId, ?string $id): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($mediaId, $id): void {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($this->workspace->id);
            if (! $record->isBusy() || ($record->operation['id'] ?? null) !== $this->operationId) {
                throw new StudioProviderException('This operation is no longer active.');
            }
            $operation = $record->operation;
            if ($id === null) {
                unset($operation['requests'][$mediaId]);
            } else {
                $operation['requests'][$mediaId] = $id;
            }
            $record->update(['operation' => $operation, 'version' => $record->version + 1]);
        }), 'studio.provider-request');
        $this->workspace->refresh();
    }
}
