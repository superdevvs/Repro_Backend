<?php
namespace App\Services\IguideLab;

class LabGrassStorage extends \App\Services\ListingStudio\ListingStudioPrivateStorage
{
    public function prepareAssessment(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'grass-assessments', $id]);
    }
}
