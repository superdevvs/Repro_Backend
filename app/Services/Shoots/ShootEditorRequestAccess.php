<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;

class ShootEditorRequestAccess
{
    public function requests(Shoot $shoot, ?User $editor): array
    {
        if (! $editor || strtolower((string) $editor->role) !== 'editor' || $shoot->isImportDraft()) {
            return [];
        }

        return array_values(array_filter(app(ShootIssueParsingService::class)->requestEntries($shoot),
            fn (array $request) => $request['assignedToRole'] === 'editor'
                && (! $request['assignedToUserId'] || (string) $request['assignedToUserId'] === (string) $editor->id)
                && in_array($request['status'], ['open', 'in-progress', 'resolved'], true)));
    }

    public function allowsFile(Shoot $shoot, ShootFile $file, ?User $editor): bool
    {
        if ((string) $file->shoot_id !== (string) $shoot->id) return false;
        foreach ($this->requests($shoot, $editor) as $request) {
            if (in_array((string) $file->id, array_map('strval', $request['mediaIds']), true)) return true;
        }
        return false;
    }

    public function allowsRequest(Shoot $shoot, string $requestId, ?User $editor): bool
    {
        foreach ($this->requests($shoot, $editor) as $request) {
            if ($request['id'] === $requestId) return true;
        }
        return false;
    }

    public function hasOpenRequest(Shoot $shoot, ShootFile $file): bool
    {
        foreach (app(ShootIssueParsingService::class)->requestEntries($shoot) as $request) {
            if (in_array($request['status'], ['open', 'in-progress'], true)
                && in_array((string) $file->id, array_map('strval', $request['mediaIds']), true)) return true;
        }
        return false;
    }
}
