<?php

namespace Tests\Feature;

use App\Models\IguideLabCase;
use App\Models\IguideLabComposite;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * D17: existing lab DTOs that Deploy 1 must leave byte-identical (zip imports, non-tour
 * workflows), plus the link-import DTO that D16 changes on purpose.
 *
 * Every row is a fixed literal (ids, sha256 values, byte counts, timestamps); no ZIP or GD
 * bytes are produced, so the responses do not depend on the PHP build. The goldens in
 * tests/Fixtures/iguide-lab/d1-snapshots were written on the untouched base with
 * D1_SNAPSHOT_WRITE=1, which only writes a golden that does not exist yet; delete a golden
 * on purpose before regenerating it. The link-import golden is pre-D16 (stored kind
 * "iguide_link") and is only read, never written, after D16.
 */
class IguideLabDtoSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'd1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1';
    private const NOW = '2026-11-16 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Queue::fake(); Http::preventStrayRequests();
        $this->travelTo(Carbon::parse(self::NOW, 'UTC'));
        IguideLabProject::create(['id' => $this->id(0x1), 'name' => 'D1 snapshot', 'token_hash' => hash('sha256', self::TOKEN),
            'expires_at' => Carbon::parse('2026-12-16 09:00:00', 'UTC'), 'max_runs' => 20, 'used_runs' => 0,
            'created_at' => Carbon::parse('2026-11-16 07:00:00', 'UTC'), 'updated_at' => Carbon::parse('2026-11-16 07:00:00', 'UTC')]);
    }

    public function test_zip_import_dtos_match_pre_d1_goldens(): void
    {
        $this->import(0x101, 'zip', 'queued', '08:00:00', audit: $this->audit('zip-queued', null));
        $this->import(0x102, 'zip', 'inspecting', '08:01:00', audit: $this->audit('zip-inspecting', null, metadata: true), startedAt: '08:01:05');
        $this->import(0x103, 'zip', 'completed', '08:02:00', audit: $this->completedAudit('zip-completed', null, 'iguide_viewObject', 2,
            ['Some panoramas have no authoritative room assignment.']), sources: [
                $this->source(0x103, 0x301, 'zip-a', room: true, entry: 'tour/spheres/p1-s.jpg', reused: false),
                $this->source(0x103, 0x302, 'zip-b', room: false, entry: 'tour/spheres/p2-s.jpg', reused: true),
            ], startedAt: '08:02:05', completedAt: '08:03:00');
        $this->import(0x104, 'zip', 'needs_attention', '08:04:00', audit: $this->audit('zip-attention', null) + ['format' => 'generic_360_zip', 'warnings' => []],
            error: 'The complete import does not fit the remaining source capacity. No partial import was created.', startedAt: '08:04:05', completedAt: '08:05:00');

        $document = ['show' => [], 'index' => null];
        foreach (['queued' => 0x101, 'inspecting' => 0x102, 'completed' => 0x103, 'needsAttention' => 0x104] as $name => $n) {
            $document['show'][$name] = $this->wire($this->withToken(self::TOKEN)->getJson('/api/iguide-lab/imports/'.$this->id($n))->assertOk());
        }
        $document['index'] = $this->wire($this->withToken(self::TOKEN)->getJson('/api/iguide-lab/imports')->assertOk());

        $this->assertGolden('zip-import.json', $this->encode($document));
    }

    public function test_link_import_dto_differs_only_in_source_kind(): void
    {
        $this->import(0x111, 'iguide_link', 'queued', '08:10:00', audit: $this->audit('link-queued', 'https://youriguide.com/sample/', archive: false));
        $this->import(0x112, 'iguide_link', 'completed', '08:11:00', audit: $this->completedAudit('link-completed', 'https://youriguide.com/sample/', 'iguide_viewObject', 1, []),
            sources: [$this->source(0x112, 0x311, 'link-a', room: true, entry: 'sample/spheres/p1-s.jpg', reused: false)], startedAt: '08:11:05', completedAt: '08:12:00');
        $this->import(0x113, 'iguide_link', 'needs_attention', '08:13:00', audit: $this->audit('link-attention', 'https://youriguide.com/sample/', archive: false),
            error: 'The tour metadata does not match the requested source.', startedAt: '08:13:05', completedAt: '08:13:30');
        $this->import(0x114, 'zip', 'completed', '08:14:00', audit: $this->completedAudit('mixed-zip', null, 'generic_360_zip', 1, []),
            sources: [$this->source(0x114, 0x314, 'mixed-zip', room: false, entry: 'room.png', reused: false)], startedAt: '08:14:05', completedAt: '08:15:00');

        $document = ['show' => [], 'index' => null];
        foreach (['queued' => 0x111, 'completed' => 0x112, 'needsAttention' => 0x113] as $name => $n) {
            $document['show'][$name] = $this->wire($this->withToken(self::TOKEN)->getJson('/api/iguide-lab/imports/'.$this->id($n))->assertOk());
        }
        $document['index'] = $this->wire($this->withToken(self::TOKEN)->getJson('/api/iguide-lab/imports')->assertOk());

        // D16: the pre-D1 golden carries the stored kind "iguide_link" in the three shown link DTOs and the
        // three link rows of the index; the DTO now says "link" there and nothing else may differ.
        $path = __DIR__.'/../Fixtures/iguide-lab/d1-snapshots/link-import.json';
        $this->assertFileExists($path, 'Missing pre-D1 golden link-import.json; it is never regenerated after D16.');
        $expected = str_replace('"sourceKind": "iguide_link"', '"sourceKind": "link"', file_get_contents($path), $patched);
        $this->assertSame(6, $patched);
        $actual = $this->encode($document);
        $this->assertStringNotContainsString('iguide_link', $actual);
        $this->assertSame($expected, $actual);
    }

    public function test_non_tour_workflow_dtos_match_pre_d1_goldens(): void
    {
        $case = $this->id(0x401); $project = $this->id(0x1);
        IguideLabCase::create(['id' => $case, 'project_id' => $project, 'label' => 'Kitchen · Panorama 1', 'room_id' => 'scan:'.substr($this->sha('room'), 0, 48),
            'pano_id' => '1', 'tour_url' => null, 'projection' => 'equirectangular', 'width' => 4096, 'height' => 2048, 'source_sha256' => $this->sha('case-source'),
            'source_path' => 'iguide-lab/'.$project.'/cases/'.$case.'.png', 'source_mime' => 'image/png', 'metadata' => ['role' => 'source'],
            'created_at' => $this->at('07:30:00'), 'updated_at' => $this->at('07:30:00')]);
        $source = ['caseId' => $case, 'sha256' => $this->sha('case-source'), 'width' => 4096, 'height' => 2048, 'projection' => 'equirectangular', 'mime' => 'image/png'];

        // Queued: just reserved, nothing admitted yet.
        $this->workflow(0x501, 'queued', '08:20:00', $this->policy(), null);
        $this->item(0x601, 0x501, 0, 'twilight', null, 'queued', $source, 0, ['reviewReasons' => [], 'planRevision' => 1], '08:20:00');
        $this->item(0x602, 0x501, 1, 'staging', 'modern', 'queued', $source, 0, ['reviewReasons' => [], 'planRevision' => 1], '08:20:00', instructions: 'Keep all existing architecture.');

        // Held: the scheduler parked the first item behind tour capacity; the second item already stopped for review.
        $scheduler = ['version' => 'bounded-sequential-tour/1', 'maxItems' => 80, 'maxActiveItems' => 1, 'activeItemSeconds' => 1800, 'capacityWaitSeconds' => 3600,
            'itemCount' => 2, 'createdAt' => $this->at('08:30:00')->getTimestamp(), 'recordAllowances' => ['analysis' => 2, 'section' => 0, 'image' => 6, 'segmentation' => 0, 'composition' => 2, 'evaluation' => 4],
            'expiresAt' => $this->at('10:30:00')->getTimestamp()];
        $this->workflow(0x502, 'queued', '08:30:00', $this->policy() + ['scheduler' => $scheduler], null, updatedAt: '08:31:00');
        $this->item(0x603, 0x502, 0, 'golden-hour', null, 'queued', $source, 0, ['reviewReasons' => [], 'planRevision' => 1], '08:30:00', updatedAt: '08:31:00',
            scheduler: ['version' => 'bounded-sequential-tour/1', 'admittedAt' => null, 'deadlineAt' => null, 'waitedSeconds' => 0,
                'waitingSince' => $this->at('08:31:00')->getTimestamp(), 'nextEligibleAt' => $this->at('08:36:00')->getTimestamp(), 'capacityReason' => 'tour_capacity_wait']);
        $this->item(0x604, 0x502, 1, 'twilight', null, 'needs_review', $source, 0, ['reviewReasons' => [['code' => 'tour_capacity_expired', 'message' => 'The tour capacity window ended before this capture started. Existing results were retained.']],
            'planRevision' => 1, 'qualityStatus' => 'needs_review'], '08:30:00', updatedAt: '08:31:00', completedAt: '08:31:00');

        // Cancelled after one item finished with a local composite.
        $composite = $this->id(0x701);
        IguideLabComposite::create(['id' => $composite, 'project_id' => $project, 'case_id' => $case, 'request_id' => $this->id(0x702), 'request_hash' => $this->sha('composite-request'),
            'label' => 'Twilight result', 'status' => 'completed', 'preview_path' => 'iguide-lab/'.$project.'/composites/'.$composite.'-preview.png',
            'native_path' => 'iguide-lab/'.$project.'/composites/'.$composite.'-native.png', 'manifest' => $this->manifest($case),
            'created_at' => $this->at('08:44:00'), 'updated_at' => $this->at('08:44:00')]);
        $this->workflow(0x503, 'cancelled', '08:40:00', $this->policy(), '08:46:00', updatedAt: '08:46:00', cancelledAt: '08:46:00');
        $this->item(0x605, 0x503, 0, 'twilight', null, 'ready', $source, 1, ['reviewReasons' => [], 'planRevision' => 1, 'compositeId' => $composite,
            'qualityStatus' => 'passed_automated_checks'], '08:40:00', updatedAt: '08:45:00', completedAt: '08:45:00');
        $this->item(0x606, 0x503, 1, 'golden-hour', null, 'cancelled', $source, 0, ['reviewReasons' => [], 'planRevision' => 1], '08:40:00', updatedAt: '08:46:00', completedAt: '08:46:00');

        $document = ['show' => [], 'cancelReplay' => null, 'index' => null];
        foreach (['queued' => 0x501, 'held' => 0x502, 'cancelled' => 0x503] as $name => $n) {
            $document['show'][$name] = $this->wire($this->withToken(self::TOKEN)->getJson('/api/iguide-lab/workflows/'.$this->id($n))->assertOk());
        }
        $document['cancelReplay'] = $this->wire($this->withToken(self::TOKEN)->postJson('/api/iguide-lab/workflows/'.$this->id(0x503).'/cancel')->assertOk());
        $index = $this->wire($this->withToken(self::TOKEN)->getJson('/api/iguide-lab/workflows')->assertOk());
        // Capabilities reflect runtime readiness and environment flags (LabWorkflowPolicy, never edited by D1),
        // so only the key list and the workflow DTOs are part of the golden.
        $this->assertSame(['capabilities', 'workflows'], array_keys((array) $index->data));
        $document['index'] = ['data' => ['workflows' => $index->data->workflows]];

        $this->assertGolden('workflows.json', $this->encode($document));
    }

    private function import(int $n, string $kind, string $status, string $created, array $audit, array $sources = [], ?string $error = null, ?string $startedAt = null, ?string $completedAt = null): void
    {
        $project = $this->id(0x1); $id = $this->id($n); $prefix = 'iguide-lab/'.$project.'/imports/'.$id;
        $tour = $kind === 'iguide_link' ? ['alias' => 'sample', 'publicUrl' => 'https://youriguide.com/sample/', 'pageUrl' => 'https://youriguide.com/sample/',
            'archiveUrl' => 'https://youriguide.com/sample/doc/spheres.zip'] : null;
        IguideLabImport::create(['id' => $id, 'project_id' => $project, 'request_id' => $this->id($n + 0x1000), 'request_hash' => $this->sha('request-'.$n),
            'source_kind' => $kind, 'private_input' => ['tour' => $tour, 'archivePath' => $prefix.'/source.zip', 'archiveSha256' => $audit['archiveSha256'],
                'metadataPath' => $audit['metadataSha256'] ? $prefix.'/metadata.txt' : null, 'metadataSha256' => $audit['metadataSha256']],
            'status' => $status, 'error' => $error, 'audit' => $audit, 'sources' => $sources,
            'started_at' => $startedAt ? $this->at($startedAt) : null, 'completed_at' => $completedAt ? $this->at($completedAt) : null,
            'created_at' => $this->at($created), 'updated_at' => $this->at($completedAt ?? $startedAt ?? $created)]);
    }

    private function audit(string $label, ?string $sourceUrl, bool $metadata = false, bool $archive = true): array
    {
        return ['parserVersion' => 'scan-import-json-1', 'sourceUrl' => $sourceUrl, 'archiveSha256' => $archive ? $this->sha($label.'-archive') : null,
            'metadataSha256' => $metadata ? $this->sha($label.'-metadata') : null, 'providerCalls' => 0];
    }

    /** Key order of LabImports::process: reservation audit, then format/warnings, then the publication merge. */
    private function completedAudit(string $label, ?string $sourceUrl, string $format, int $count, array $warnings): array
    {
        return ['parserVersion' => 'scan-import-json-1', 'sourceUrl' => $sourceUrl, 'archiveSha256' => $this->sha($label.'-archive'),
            'metadataSha256' => $format === 'iguide_viewObject' ? $this->sha($label.'-metadata') : null, 'providerCalls' => 0,
            'format' => $format, 'warnings' => $warnings, 'expandedBytes' => 48_234_496 * $count, 'archiveBytes' => 47_185_920 * $count, 'sourceCount' => $count];
    }

    /** Stored source binding in the key order of LabImports::process; previewPath is private and dropped by dto(). */
    private function source(int $import, int $case, string $label, bool $room, string $entry, bool $reused): array
    {
        return ['caseId' => $this->id($case), 'importSourceKey' => $this->sha($label.'-key'), 'sourceSha256' => $this->sha($label.'-source'),
            'width' => 4096, 'height' => 2048, 'panoId' => $room ? '1' : null, 'roomId' => $room ? 'scan:'.substr($this->sha($label.'-room'), 0, 48) : null,
            'originalRoomId' => $room ? '7' : null, 'roomName' => $room ? 'Kitchen' : null, 'floorId' => $room ? '1' : null, 'floorName' => $room ? 'Ground' : null,
            'metadataAuthority' => $room ? 'iguide_viewObject' : 'unassigned', 'entry' => $entry, 'reused' => $reused,
            'previewPath' => 'iguide-lab/'.$this->id(0x1).'/imports/'.$this->id($import).'/'.$this->id($case).'-preview.png',
            'previewSha256' => $this->sha($label.'-preview'), 'previewDimensions' => [2048, 1024]];
    }

    private function policy(): array
    {
        return ['version' => 'automatic-tour-edit-workflow/1', 'modelId' => 'gpt-image-2.6-sunburst', 'quality' => 'high', 'complexity' => 'low',
            'credentialPolicy' => 'lab-primary-explicit-quota-fallback', 'maxImageAttemptsPerItem' => 3, 'maxPlanRevisionsPerItem' => 2,
            'maxSegmentationsPerImageAttempt' => 2, 'maxItems' => 20, 'maxActiveItems' => 1, 'recipeVersion' => 'whole-panorama-lighting/1',
            'manualAcceptance' => false, 'operatorInterventions' => 0, 'artifacts' => ['recipeVersion' => 'fixture/1']];
    }

    private function workflow(int $n, string $status, string $created, array $policy, ?string $completedAt, ?string $updatedAt = null, ?string $cancelledAt = null): void
    {
        IguideLabWorkflow::create(['id' => $this->id($n), 'project_id' => $this->id(0x1), 'request_id' => $this->id($n + 0x1000), 'request_hash' => $this->sha('workflow-'.$n),
            'status' => $status, 'policy' => $policy, 'cancelled_at' => $cancelledAt ? $this->at($cancelledAt) : null, 'completed_at' => $completedAt ? $this->at($completedAt) : null,
            'created_at' => $this->at($created), 'updated_at' => $this->at($updatedAt ?? $created)]);
    }

    private function item(int $n, int $workflow, int $position, string $preset, ?string $style, string $status, array $source, int $attempts, array $data, string $created,
        ?string $updatedAt = null, ?string $completedAt = null, ?array $scheduler = null, string $instructions = ''): void
    {
        IguideLabWorkflowItem::create(['id' => $this->id($n), 'workflow_id' => $this->id($workflow), 'project_id' => $this->id(0x1), 'case_id' => $source['caseId'],
            'position' => $position, 'preset' => $preset, 'style' => $style, 'instructions' => $instructions, 'source' => $source, 'status' => $status,
            'attempt_count' => $attempts, 'data' => $data, 'scheduler' => $scheduler, 'completed_at' => $completedAt ? $this->at($completedAt) : null,
            'created_at' => $this->at($created), 'updated_at' => $this->at($updatedAt ?? $created)]);
    }

    private function manifest(string $case): array
    {
        return ['requestId' => $this->id(0x702), 'label' => 'Twilight result', 'preset' => 'twilight', 'style' => null, 'schemaVersion' => 1,
            'kind' => 'locally_composited_panorama', 'projection' => 'equirectangular', 'resolutionStrategy' => 'native_canvas', 'compositionMode' => 'masked_whole_panorama',
            'source' => ['caseId' => $case, 'sourceSha256' => $this->sha('case-source'), 'originalSha256' => $this->sha('case-source'), 'originalWidth' => 4096, 'originalHeight' => 2048],
            'preview' => ['sha256' => $this->sha('composite-preview'), 'width' => 2048, 'height' => 1024],
            'native' => ['sha256' => $this->sha('composite-native'), 'width' => 4096, 'height' => 2048],
            'generatedDetail' => ['width' => 1536, 'height' => 1024, 'description' => 'Whole-panorama twilight lighting.'],
            'recipeSha256' => $this->sha('recipe'), 'geometryManifestSha256' => null, 'scenePlanSha256' => null, 'qaManifestSha256' => $this->sha('qa'),
            'children' => [['runId' => $this->id(0x703), 'requestId' => $this->id(0x704), 'outputSha256' => $this->sha('child-output'), 'projection' => 'equirectangular',
                'editScope' => 'entire_view', 'maskSha256' => null, 'view' => null]]];
    }

    /** Decodes a response body as objects and proves the decoded form re-encodes to the exact wire bytes. */
    private function wire(TestResponse $response): object
    {
        $raw = $response->getContent(); $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($raw, json_encode($decoded, JSON_THROW_ON_ERROR), 'The golden form must re-encode to the exact response bytes.');
        return $decoded;
    }

    /** Pretty JSON with every UUID and every 64-hex value replaced by its order of first appearance. */
    private function encode(array $document): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $uuids = []; $shas = [];
        return preg_replace_callback('/(?<![0-9A-Fa-f])(?:([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})|([a-f0-9]{64}))(?![0-9A-Fa-f])/',
            function (array $m) use (&$uuids, &$shas) {
                if (($m[1] ?? '') !== '') { $uuids[strtolower($m[1])] ??= '<uuid-'.(count($uuids) + 1).'>'; return $uuids[strtolower($m[1])]; }
                $shas[$m[2]] ??= '<sha-'.(count($shas) + 1).'>'; return $shas[$m[2]];
            }, $json)."\n";
    }

    private function assertGolden(string $name, string $actual): void
    {
        $path = __DIR__.'/../Fixtures/iguide-lab/d1-snapshots/'.$name;
        if (getenv('D1_SNAPSHOT_WRITE') === '1' && ! is_file($path)) {
            if (! is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
            file_put_contents($path, $actual);
        }
        $this->assertFileExists($path, 'Missing pre-D1 golden '.$name.'; it is written only on the untouched base with D1_SNAPSHOT_WRITE=1.');
        $this->assertSame(file_get_contents($path), $actual);
    }

    private function id(int $n): string { return sprintf('d1500000-0000-4000-8000-%012x', $n); }
    private function sha(string $label): string { return hash('sha256', 'd1-snapshot:'.$label); }
    private function at(string $time): Carbon { return Carbon::parse('2026-11-16 '.$time, 'UTC'); }
}
