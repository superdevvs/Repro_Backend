<?php

namespace App\Services\IguideLab;

use App\Services\ListingStudio\ListingStudioPrivateStorage;

/** Reuse the already provisioned private, group-writable Studio disk. */
class LabStorage extends ListingStudioPrivateStorage
{
    public function prepareProject(string $projectId): void
    {
        $this->uuid($projectId);
        $this->prepare(['iguide-lab', $projectId, 'cases']);
        $this->prepare(['iguide-lab', $projectId, 'runs']);
    }
}
