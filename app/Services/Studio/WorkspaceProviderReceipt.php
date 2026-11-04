<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use Illuminate\Support\Facades\Storage;

/** Preserve accepted IDs independently of SQLite before updating the workspace checkpoint. */
class WorkspaceProviderReceipt
{
    public function read(string $workspaceId, string $operationId, string $key, string $receiptId): ?array
    {
        $disk = Storage::disk('studio_receipts');
        $path = $this->path($workspaceId, $operationId, $key, $receiptId);
        if (! $disk->exists($path)) {
            return null;
        }
        $result = json_decode($disk->get($path), true);
        if (! is_array($result) || ! is_string($result['id'] ?? null) || $result['id'] === '') {
            throw new StudioProviderException('The saved photo receipt needs administrator review before another submission.', true);
        }

        return $result;
    }

    public function save(string $workspaceId, string $operationId, string $key, string $receiptId, array $result): void
    {
        $disk = Storage::disk('studio_receipts');
        $path = $this->path($workspaceId, $operationId, $key, $receiptId);
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (! $disk->put($temporary, json_encode($result, JSON_THROW_ON_ERROR)) || ! $disk->move($temporary, $path)) {
                throw new StudioProviderException('The accepted photo request could not be saved. An administrator must check it before another submission.', true);
            }
        } finally {
            $disk->delete($temporary);
        }
    }

    private function path(string $workspaceId, string $operationId, string $key, string $receiptId): string
    {
        return hash('sha256', $workspaceId).'/'.hash('sha256', json_encode([$operationId, $key, $receiptId])).'.json';
    }
}
