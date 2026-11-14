<?php

namespace App\Services\IguideLab;

use App\Services\ListingStudio\ListingStudioPrivateStorage;

/** Reuse the already provisioned private, group-writable Studio disk. */
class LabStorage extends ListingStudioPrivateStorage
{
    public function prepareStagingAssessment(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'staging-assessments', $id]);
    }
    public function prepareWorkflow(string $projectId, string $itemId): void
    {
        $this->uuid($projectId); $this->uuid($itemId);
        $this->prepare(['iguide-lab', $projectId, 'workflow-items', $itemId]);
    }
    public function prepareEvaluation(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'workflow-evaluations', $id]);
    }
    public function prepareImportUpload(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'import-uploads', $id]);
    }
    public function prepareImportUploadLocks(string $projectId): void
    {
        $this->uuid($projectId); $this->prepare(['iguide-lab', $projectId, 'import-upload-locks']);
    }
    public function prepareImport(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'imports', $id]);
    }
    public function prepareMachineSegmentation(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'machine-segmentations', $id]);
    }
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

    public function prepareGeometry(string $projectId, string $kind, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        if (! in_array($kind, ['sections', 'masks', 'compositions'], true)) throw new \InvalidArgumentException('Invalid geometry directory.');
        $this->prepare(['iguide-lab', $projectId, $kind, $id]);
    }
}
