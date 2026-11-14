<?php
namespace App\Services\IguideLab;
use App\Models\IguideLabCase;
use Illuminate\Support\Facades\Storage;

/** Actual owned bytes, not a metadata assertion of an original native scan. */
class LabWorkflowSources
{
    public function capture(IguideLabCase $case): array
    {
        $disk = Storage::disk(LabService::DISK); $prefix = 'iguide-lab/'.$case->project_id.'/cases/'.$case->id.'.';
        $authority = null;
        if (! str_starts_with($case->source_path, $prefix)) {
            $pattern = '~\Aiguide-lab/'.preg_quote($case->project_id, '~').'/imports/([a-f0-9-]{36})/'.preg_quote($case->id, '~').'\.(png|jpg|webp)\z~D';
            abort_unless(preg_match($pattern, $case->source_path, $parts) === 1 && class_exists(\App\Models\IguideLabImport::class), 422, 'The source is not an owned image.');
            $import = \App\Models\IguideLabImport::where('project_id', $case->project_id)->where('status', 'completed')->findOrFail($parts[1]);
            $mapping = collect($import->sources)->firstWhere('caseId', $case->id);
            abort_unless(is_array($mapping) && ($mapping['sourceSha256'] ?? null) === $case->source_sha256
                && [$mapping['width'] ?? null, $mapping['height'] ?? null] === [$case->width, $case->height]
                && is_string($mapping['importSourceKey'] ?? null) && $mapping['importSourceKey'] !== '', 422, 'The completed import does not own this source.');
            $authority = ['kind' => 'scan-import', 'importId' => $import->id, 'importSourceKey' => $mapping['importSourceKey']];
        } else {
            abort_unless(in_array(substr($case->source_path, strlen($prefix)), ['png', 'jpg', 'webp'], true), 422, 'The source is not an owned image.');
        }
        $path = $disk->path($case->source_path); $real = realpath($path); $root = realpath($disk->path('iguide-lab'));
        $relative = substr($case->source_path, strlen('iguide-lab/'));
        abort_unless($real && $root && $real === $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)
            && ! is_link($path) && filesize($real) <= 30 * 1024 * 1024 && hash_equals($case->source_sha256, hash_file('sha256', $real)), 422, 'The source image changed.');
        $size = @getimagesize($real);
        abort_unless($size && [$size[0], $size[1]] === [$case->width, $case->height] && $case->projection === 'equirectangular'
            && $size[0] >= 2048 && $size[0] === 2 * $size[1] && $size[0] * $size[1] <= 64_000_000, 422, 'Choose a full 2:1 source panorama.');
        $snapshot = ['caseId' => $case->id, 'sha256' => $case->source_sha256, 'width' => $case->width, 'height' => $case->height,
            'projection' => $case->projection, 'mime' => $size['mime']];
        if ($authority !== null) $snapshot['authority'] = $authority;
        return $snapshot;
    }
    public function verify(string $projectId, array $snapshot): IguideLabCase
    {
        $case = IguideLabCase::where('project_id', $projectId)->findOrFail($snapshot['caseId']);
        abort_unless($this->capture($case) === $snapshot, 422, 'The workflow source changed.');
        return $case;
    }
}
