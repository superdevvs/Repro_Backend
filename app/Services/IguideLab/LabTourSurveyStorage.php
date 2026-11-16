<?php
namespace App\Services\IguideLab;

use Illuminate\Support\Facades\Storage;

/**
 * Private survey storage (spec 5.1): iguide-lab/{projectId}/tour-surveys/{surveyId}/ on LabService::DISK, prepared like
 * LabGrassSourceAdmissionStorage.php:4-10. views/ is created by analysis_views.py and is never prepared here.
 */
class LabTourSurveyStorage extends \App\Services\ListingStudio\ListingStudioPrivateStorage
{
    public static function base(string $projectId, string $surveyId): string
    {
        return 'iguide-lab/'.$projectId.'/tour-surveys/'.$surveyId;
    }

    public function prepareSurvey(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'tour-surveys', $id]);
    }

    public function prepareCompiled(string $projectId, string $id): void
    {
        $this->uuid($projectId); $this->uuid($id);
        $this->prepare(['iguide-lab', $projectId, 'tour-surveys', $id, 'compiled']);
    }

    /** Write once (LabGrassSourceAdmissionContext.php:99-104): an existing file must hold exactly these bytes. */
    public function putOnce(string $path, string $bytes): void
    {
        $disk = Storage::disk(LabService::DISK);
        if ($disk->exists($path)) {
            $real = $disk->path($path);
            if (is_link($real) || ! is_file($real) || ! hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $real))) {
                throw new \RuntimeException('tour_survey_file_changed');
            }

            return;
        }
        if ($disk->put($path, $bytes, ['visibility' => 'private']) !== true) throw new \RuntimeException('tour_survey_storage_unavailable');
    }
}
