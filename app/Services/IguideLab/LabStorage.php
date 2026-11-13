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
        $this->prepare(['iguide-lab', $projectId, 'composites']);
        $this->prepare(['iguide-lab', $projectId, 'pipelines']);
    }

    public function preparePipeline(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'pipelines', $id]);
        $this->prepare(['iguide-lab', $projectId, 'pipelines', $id, 'composed']);
    }

    public function prepareAnalysis(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'analyses', $id]);
    }
}
