<?php
namespace App\Services\IguideLab;
class LabDeclutterStorage extends \App\Services\ListingStudio\ListingStudioPrivateStorage
{
    public function preparePlan(string $projectId,string $id): void
    {
        $this->uuid($projectId);$this->uuid($id);
        $this->prepare(['iguide-lab',$projectId,'declutter-removal-plans',$id]);
    }
}
