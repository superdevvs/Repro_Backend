<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabCase;
use App\Models\IguideLabImport;
use App\Models\IguideLabTourSurvey;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Survey evidence (spec 5.2, D7), all free: the stored 2048x1024 preview (or the recomputed one) as source.png, and the
 * four horizon views of the unchanged analysis_views.py. LabAnalysisViews cannot be reused: it needs an analysis row.
 * Never reads import private_input.
 */
class LabTourSurveyViews
{
    public const MANIFEST_IDS = ['front', 'right', 'back', 'left', 'zenith', 'nadir'];
    /** The four horizon views sent to the model (zenith and nadir are ignored, D7) and their manifest yaw. */
    private const HORIZON = ['front' => 0, 'right' => 90, 'back' => 180, 'left' => 270];
    private const PREVIEW_MAX_BYTES = 24 * 1024 * 1024;
    private const ORIGINAL_MAX_BYTES = 32 * 1024 * 1024;
    private const VIEW_MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @return array{source:array{id:string,path:string,sha256:string,width:int,height:int}, views:list<array{id:string,path:string,sha256:string,yaw:int}>,
     *     preparedFrom:string, adapterSha256:string, geometrySha256:string} paths relative to LabService::DISK
     * @throws LabTourSourceException when the source cannot honour the survey identity
     */
    public function prepare(IguideLabTourSurvey $survey): array
    {
        $projectId = $survey->project_id;
        $case = IguideLabCase::where('project_id', $projectId)->find($survey->case_id);
        if ($case === null || ! is_string($case->source_sha256) || ! hash_equals($survey->case_source_sha256, $case->source_sha256)) {
            throw new LabTourSourceException();
        }
        $bytes = $this->storedPreview($survey, $case);
        $preparedFrom = 'import_preview';
        if ($bytes === null) {
            $bytes = $this->recomputedPreview($survey, $case);
            $preparedFrom = 'recomputed';
        }

        $storage = app(LabTourSurveyStorage::class);
        $storage->prepareSurvey($projectId, $survey->id);
        $base = LabTourSurveyStorage::base($projectId, $survey->id);
        $disk = Storage::disk(LabService::DISK);
        $storage->putOnce($base.'/source.png', $bytes);
        $request = ['schemaVersion' => 1, 'source' => ['path' => $disk->path($base.'/source.png'), 'sha256' => $survey->preview_sha256],
            'outputDirectory' => $disk->path($base.'/views')];
        $storage->putOnce($base.'/views-request.json', json_encode($request, JSON_THROW_ON_ERROR));
        $result = $this->execute($disk->path($base.'/views-request.json'));
        if (($result['status'] ?? null) !== 'completed') throw new LabTourSourceException();

        $adapter = hash_file('sha256', resource_path('iguide-lab/analysis_views.py'));
        $geometry = hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'));
        $manifest = $result['manifest'] ?? null;
        $manifestPath = $disk->path($base.'/views/manifest.json');
        if (! is_array($manifest) || ! is_file($manifestPath) || is_link($manifestPath) || ($manifest['adapterSha256'] ?? null) !== $adapter
            || ($manifest['geometrySha256'] ?? null) !== $geometry || ($manifest['source']['sha256'] ?? null) !== $survey->preview_sha256
            || ! is_array($manifest['views'] ?? null) || array_column($manifest['views'], 'id') !== self::MANIFEST_IDS) {
            throw new \RuntimeException('tour_survey_views_changed');
        }
        $views = [];
        foreach (self::HORIZON as $id => $yaw) {
            $view = $manifest['views'][array_search($id, self::MANIFEST_IDS, true)];
            $relative = $base.'/views/views/'.$id.'.png';
            $file = $this->file($projectId, $relative, is_string($view['sha256'] ?? null) ? $view['sha256'] : '', self::VIEW_MAX_BYTES);
            if (! is_string($view['path'] ?? null) || realpath($view['path']) !== $file || ! is_numeric($view['yaw_degrees'] ?? null)
                || (float) $view['yaw_degrees'] !== (float) $yaw || ! is_numeric($view['pitch_degrees'] ?? null) || (float) $view['pitch_degrees'] !== 0.0
                || ! is_numeric($view['hfov_degrees'] ?? null) || (float) $view['hfov_degrees'] !== 100.0 || [$view['width'] ?? null, $view['height'] ?? null] !== [1024, 1024]) {
                throw new \RuntimeException('tour_survey_views_changed');
            }
            $views[] = ['id' => 'view_'.$id, 'path' => $relative, 'sha256' => $view['sha256'], 'yaw' => $yaw];
        }

        return ['source' => ['id' => 'source_panorama', 'path' => $base.'/source.png', 'sha256' => $survey->preview_sha256, 'width' => 2048, 'height' => 1024],
            'views' => $views, 'preparedFrom' => $preparedFrom, 'adapterSha256' => $adapter, 'geometrySha256' => $geometry];
    }

    /**
     * analysis_views.py in its own scrubbed process (pattern LabGrassSourceAdmissionContext.php:82-94). Tests override
     * this method; the script imports `resource` and runs only on Linux.
     */
    protected function execute(string $requestPath): array
    {
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'PYTHONDONTWRITEBYTECODE' => '1',
            'OMP_NUM_THREADS' => '2', 'OPENBLAS_NUM_THREADS' => '2']);
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/analysis_views.py'), '--request', $requestPath],
            null, $env, null, LabTourConfig::viewsTimeoutSeconds());
        try {
            $process->run();
        } catch (\Throwable $error) {
            throw new LabTourSourceException('source_unavailable', $error);
        }
        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result) || ($result['status'] ?? null) !== 'completed') throw new LabTourSourceException();

        return $result;
    }

    /** realpath under the project root, no link, size bound, sha match (LabStagingAssessmentContext.php:121-130; call only). */
    protected function file(string $projectId, string $relative, string $sha, int $maxBytes): string
    {
        return app(LabStagingAssessmentContext::class)->file($projectId, $relative, $sha, $maxBytes);
    }

    /** Step 2: the completed import's stored preview; null when the import, its entry or the file cannot be used. */
    private function storedPreview(IguideLabTourSurvey $survey, IguideLabCase $case): ?string
    {
        $import = IguideLabImport::query()->select(['id', 'project_id', 'status', 'sources'])->where('project_id', $survey->project_id)
            ->where('status', 'completed')->find($survey->source_import_id);
        $entry = null;
        foreach ($import?->sources ?? [] as $source) {
            if (is_array($source) && ($source['caseId'] ?? null) === $survey->case_id) { $entry = $source; break; }
        }
        if ($entry === null || ($entry['sourceSha256'] ?? null) !== $case->source_sha256 || ($entry['previewSha256'] ?? null) !== $survey->preview_sha256
            || ! is_string($entry['previewPath'] ?? null)) {
            return null;
        }
        try {
            $bytes = file_get_contents($this->file($survey->project_id, $entry['previewPath'], $survey->preview_sha256, self::PREVIEW_MAX_BYTES));
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($bytes)) return null;
        // The bytes already carry the survey identity; a preview that is not the 2048x1024 PNG cannot be honoured.
        if (! self::isPreview($bytes)) throw new LabTourSourceException();

        return $bytes;
    }

    /** Step 3: LabImages::prepared() of the case original; its sha must be the survey's preview sha (the cache identity). */
    private function recomputedPreview(IguideLabTourSurvey $survey, IguideLabCase $case): string
    {
        try {
            $original = file_get_contents($this->file($survey->project_id, (string) $case->source_path, (string) $case->source_sha256, self::ORIGINAL_MAX_BYTES));
            $bytes = is_string($original) ? LabImages::prepared($original) : '';
        } catch (\Throwable $error) {
            throw new LabTourSourceException('source_unavailable', $error);
        }
        if (! hash_equals($survey->preview_sha256, hash('sha256', $bytes)) || ! self::isPreview($bytes)) throw new LabTourSourceException();

        return $bytes;
    }

    private static function isPreview(string $bytes): bool
    {
        $size = @getimagesizefromstring($bytes);

        return $size !== false && [$size[0], $size[1], $size[2]] === [2048, 1024, IMAGETYPE_PNG];
    }
}
