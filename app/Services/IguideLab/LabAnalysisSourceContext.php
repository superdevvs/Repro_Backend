<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabCase;
use App\Models\IguideLabImport;
use App\Models\IguideLabSection;
use Illuminate\Support\Facades\Storage;

/** Same-capture context from verified server-owned native bytes, never a client reference. */
class LabAnalysisSourceContext
{
    public const VERSION = 'section-parent-context-v1';
    public const VIEW_RECIPE = 'source-cut-parent-cardinals/1';
    public const IDS = ['source', 'parent_source', 'parent_front', 'parent_right', 'parent_back', 'parent_left'];
    public const IMPORT_AUTHORITY = 'completed-import-native/1';

    public function capture(IguideLabCase $case): ?array
    {
        if ($case->projection !== 'rectilinear') return null;
        $section = IguideLabSection::where('project_id', $case->project_id)->where('derived_case_id', $case->id)->first();
        if (! $section) {
            abort_if(isset($case->metadata['sectionId']), 422, 'The section source has no verified geometry association.');
            return null; // An ordinary uploaded cut has no server-owned wider source.
        }
        abort_unless($section->status === 'completed' && ($case->metadata['sectionId'] ?? null) === $section->id, 422, 'The source section is incomplete or inconsistent.');
        $native = IguideLabCase::where('project_id', $case->project_id)->findOrFail($section->case_id);
        $authority = null; $origin = 'uploaded_native_attachment';
        if (str_starts_with($native->source_path, 'iguide-lab/'.$case->project_id.'/imports/')
            || ($native->metadata['role'] ?? null) === 'imported-panorama') {
            $authority = $this->importAuthority($native);
            $visible = $native; // An imported native panorama is already the visible source.
            $origin = 'completed_scan_import';
        } else {
            // Only the server-owned native_source backlink establishes attachment provenance.
            $visibleId = $native->metadata['parentCaseId'] ?? null;
            if (($native->metadata['role'] ?? null) !== 'native-source' || ! is_string($visibleId)) return null;
            $visible = IguideLabCase::where('project_id', $case->project_id)->findOrFail($visibleId);
            $attachment = $visible->native_source;
            abort_unless(is_array($attachment) && ($attachment['caseId'] ?? null) === $native->id
                && ($attachment['sha256'] ?? null) === $native->source_sha256
                && [$attachment['width'] ?? null, $attachment['height'] ?? null] === [$native->width, $native->height],
                422, 'Parent context requires the exact server-owned native attachment.');
        }
        abort_unless($section->source_sha256 === $native->source_sha256 && $case->width === 2048 && $case->height === 2048
            && $native->projection === 'equirectangular' && $native->width >= 2048 && $native->width === $native->height * 2,
            422, 'Parent context requires the exact server-owned native attachment.');
        $disk = Storage::disk(LabService::DISK); $nativePath = $this->checkedCase($native); $this->checkedCase($case);
        $base = 'iguide-lab/'.$case->project_id.'/sections/'.$section->id.'/geometry/cut/';
        $geometryPath = $base.'manifest.json';
        abort_unless(($section->data['geometryPath'] ?? null) === $geometryPath && $disk->exists($geometryPath)
            && ($section->data['cutSha256'] ?? null) === $case->source_sha256
            && hash_equals($section->data['geometrySha256'] ?? '', hash_file('sha256', $disk->path($geometryPath))),
            422, 'The source geometry fingerprint changed.');
        $geometry = json_decode($disk->get($geometryPath), true, 32, JSON_THROW_ON_ERROR);
        $view = $geometry['views'][0] ?? [];
        abort_unless(count($geometry['views'] ?? []) === 1 && ($view['id'] ?? null) === 'section'
            && ($geometry['source']['sha256'] ?? null) === $native->source_sha256
            && realpath($geometry['source']['path'] ?? '') === $nativePath
            && [$geometry['source']['width'] ?? null, $geometry['source']['height'] ?? null] === [$native->width, $native->height]
            && [$view['width'] ?? null, $view['height'] ?? null] === [2048, 2048]
            && ($view['sha256'] ?? null) === $case->source_sha256
            && realpath($view['path'] ?? '') === realpath($disk->path($base.'views/section.png'))
            && hash_equals($case->source_sha256, hash_file('sha256', $disk->path($base.'views/section.png'))),
            422, 'The source geometry no longer identifies this native capture and cut.');
        foreach (['yaw' => 'yaw_degrees', 'pitch' => 'pitch_degrees', 'hFov' => 'hfov_degrees'] as $key => $geometryKey)
            abort_unless(isset($view[$geometryKey], $section->view[$key]) && (float) $view[$geometryKey] === (float) $section->view[$key], 422, 'The source pose changed.');
        $data = ['version' => self::VERSION, 'sectionId' => $section->id, 'nativeCaseId' => $native->id, 'visibleParentCaseId' => $visible->id,
            'sourceSha256' => $case->source_sha256, 'nativeSourceSha256' => $native->source_sha256,
            'nativeDimensions' => ['width' => $native->width, 'height' => $native->height], 'geometrySha256' => $section->data['geometrySha256'],
            'view' => ['yaw' => (float) $section->view['yaw'], 'pitch' => (float) $section->view['pitch'], 'hFov' => (float) $section->view['hFov'], 'width' => 2048, 'height' => 2048],
            'sourceOrigin' => $origin, 'sourceRole' => 'authoritative_edit_cut', 'contextRole' => 'same_capture_context_only',
            'scopeExpansion' => false, 'independentCameraEvidence' => false, 'dedicatedPoleViews' => false,
            'viewRecipe' => self::VIEW_RECIPE, 'preparationHashes' => $this->preparationHashes()];
        if ($authority !== null) $data['sourceAuthority'] = $authority;
        return ['binding' => $data, 'bindingSha256' => LabAnalysisRecipes::hash($data)];
    }

    public function verify(IguideLabCase $case, array $snapshot): void
    {
        $current = $this->capture($case);
        abort_unless($current !== null && hash_equals(LabAnalysisRecipes::hash($snapshot), LabAnalysisRecipes::hash($current)), 422, 'Reserved source context changed before analysis.');
    }

    public function preparationHashes(): array
    {
        return ['preparer' => hash_file('sha256', __DIR__.'/LabSectionAnalysisViews.php'), 'imagePreparation' => hash_file('sha256', __DIR__.'/LabImages.php'),
            'diagnosticAdapter' => hash_file('sha256', resource_path('iguide-lab/analysis_views.py')),
            'geometry' => hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'))];
    }

    private function checkedCase(IguideLabCase $case): string
    {
        $disk = Storage::disk(LabService::DISK); $prefix = 'iguide-lab/'.$case->project_id.'/cases/'.$case->id.'.';
        $directory = 'cases';
        if (! str_starts_with($case->source_path, $prefix)) {
            $authority = $this->importAuthority($case);
            $directory = 'imports/'.$authority['importId'];
            $prefix = 'iguide-lab/'.$case->project_id.'/'.$directory.'/'.$case->id.'.';
        }
        abort_unless(str_starts_with($case->source_path, $prefix) && in_array(substr($case->source_path, strlen($prefix)), ['png', 'jpg', 'webp'], true)
            && $disk->exists($case->source_path), 422, 'The source is outside its owned image storage.');
        $path = realpath($disk->path($case->source_path)); $root = realpath($disk->path('iguide-lab/'.$case->project_id.'/'.$directory)); $labRoot = realpath($disk->path('iguide-lab'));
        abort_unless($path && $root && $labRoot && $root === $labRoot.DIRECTORY_SEPARATOR.$case->project_id.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory)
            && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && ! is_link($disk->path($case->source_path))
            && hash_equals($case->source_sha256, hash_file('sha256', $path)), 422, 'The source image fingerprint changed.');
        $size = @getimagesize($path);
        abort_unless($size && [$size[0], $size[1]] === [$case->width, $case->height] && $size[0] * $size[1] <= 64_000_000, 422, 'The source dimensions changed.');
        return $path;
    }

    private function importAuthority(IguideLabCase $case): array
    {
        $pattern = '~\Aiguide-lab/'.preg_quote($case->project_id, '~').'/imports/([a-f0-9-]{36})/'.preg_quote($case->id, '~').'\.(png|jpg|webp)\z~D';
        abort_unless(preg_match($pattern, $case->source_path, $parts) === 1
            && ($case->metadata['importId'] ?? null) === $parts[1], 422, 'Imported context requires its exact creating-import source path.');
        $import = IguideLabImport::where('project_id', $case->project_id)->where('status', 'completed')->findOrFail($parts[1]);
        $matches = collect($import->sources)->where('caseId', $case->id)->values(); $mapping = $matches->first();
        abort_unless($matches->count() === 1 && is_array($mapping) && ($mapping['sourceSha256'] ?? null) === $case->source_sha256
            && [$mapping['width'] ?? null, $mapping['height'] ?? null] === [$case->width, $case->height]
            && is_string($mapping['importSourceKey'] ?? null) && $mapping['importSourceKey'] !== ''
            && strlen($mapping['importSourceKey']) <= 512, 422, 'The completed import does not own this native context.');
        return ['version' => self::IMPORT_AUTHORITY, 'importId' => $import->id, 'importSourceKey' => $mapping['importSourceKey'],
            'mappingSha256' => LabAnalysisRecipes::hash($mapping)];
    }
}
