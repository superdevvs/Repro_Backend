<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootEditingAssignmentService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 11: Editing payload excludes non-required extras
 *
 * Validates: Requirements 13.1, 13.2, 13.3, 13.4
 *
 * For any randomly generated set of shoot files (mix of standard, non-required
 * extras, and required-for-editing extras), the following universal invariants
 * must hold:
 *
 *   1. editableFiles($shoot) excludes every non-required extra
 *      (is_extra = true && required_for_editing = false). [Req 13.1]
 *   2. editableFiles($shoot) includes every required extra
 *      (is_extra = true && required_for_editing = true).  [Req 13.3]
 *   3. editableFiles($shoot) includes every standard file
 *      (is_extra = false).                                [Req 13.1, 13.3]
 *   4. filterFilesForEditor() applied to an editor with assigned lanes also
 *      excludes non-required extras (in addition to lane filtering). [Req 13.2, 13.4]
 *   5. canEditorAccessFile() returns false for any non-required extra and
 *      true for required extras (when otherwise authorized via lane).  [Req 13.2, 13.4]
 *
 * No PBT library is configured for the backend, so the test follows the same
 * "deterministic generator" approach used by ShootDatePreservationPropertyTest:
 * a seeded PRNG produces 25+ randomized file mixes plus a fixed table of
 * deterministic edge cases (all-standard, all-required-extras, all-non-required,
 * empty, single-file mixes). Each generated case asserts every invariant above.
 */
class ShootEditingPayloadFilteringPropertyTest extends TestCase
{
    private ShootEditingAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ShootEditingAssignmentService();
    }

    /**
     * Build an in-memory ShootFile with explicit extras flags and a media_type
     * so getFileLane() can resolve a lane (photo|video|floorplan). No DB.
     */
    private function file(int $id, bool $isExtra, bool $requiredForEditing, string $mediaType): ShootFile
    {
        $file = new ShootFile();
        $file->id = $id;
        $file->setAttribute('is_extra', $isExtra);
        $file->setAttribute('required_for_editing', $requiredForEditing);
        $file->setAttribute('media_type', $mediaType);

        return $file;
    }

    private function shootWithFiles(Collection $files, ?User $editor = null): Shoot
    {
        $shoot = new Shoot();
        $shoot->id = 1;
        if ($editor) {
            $shoot->editor_id = $editor->id;
        }
        // Empty services collection makes getAssignedLanesForEditor() return
        // both LANE_PHOTO and LANE_VIDEO when editor_id matches the editor —
        // i.e. the editor "is otherwise authorized via lane" so the only
        // remaining filter is the extras flag.
        $shoot->setRelation('services', collect());
        $shoot->setRelation('files', $files);

        return $shoot;
    }

    private function editor(int $id = 99): User
    {
        $editor = new User();
        $editor->id = $id;
        $editor->role = 'editor';

        return $editor;
    }

    /**
     * Seeded PRNG generator producing 25 randomized file mixes plus 7
     * deterministic edge cases (all-standard, all-required-extras,
     * all-non-required, empty, single-standard, single-required, single-hidden).
     *
     * Each case yields [string $label, list<array{is_extra:bool, required:bool, media:string}>].
     *
     * @return iterable<string, array{0: string, 1: list<array{is_extra: bool, required: bool, media: string}>}>
     */
    public static function fileMixProvider(): iterable
    {
        // Deterministic edge cases — exercise the boundary shapes the property
        // must hold over (Req 13.1-13.4).
        $edges = [
            'edge: empty file set' => [],
            'edge: all standard photos' => [
                ['is_extra' => false, 'required' => false, 'media' => 'photo'],
                ['is_extra' => false, 'required' => false, 'media' => 'photo'],
                ['is_extra' => false, 'required' => false, 'media' => 'photo'],
            ],
            'edge: all required extras' => [
                ['is_extra' => true, 'required' => true, 'media' => 'photo'],
                ['is_extra' => true, 'required' => true, 'media' => 'video'],
                ['is_extra' => true, 'required' => true, 'media' => 'floorplan'],
            ],
            'edge: all non-required extras' => [
                ['is_extra' => true, 'required' => false, 'media' => 'photo'],
                ['is_extra' => true, 'required' => false, 'media' => 'video'],
                ['is_extra' => true, 'required' => false, 'media' => 'extra'],
            ],
            'edge: single standard photo' => [
                ['is_extra' => false, 'required' => false, 'media' => 'photo'],
            ],
            'edge: single required extra' => [
                ['is_extra' => true, 'required' => true, 'media' => 'photo'],
            ],
            'edge: single non-required extra' => [
                ['is_extra' => true, 'required' => false, 'media' => 'photo'],
            ],
            'edge: mixed kinds' => [
                ['is_extra' => false, 'required' => false, 'media' => 'photo'],
                ['is_extra' => true,  'required' => true,  'media' => 'photo'],
                ['is_extra' => true,  'required' => false, 'media' => 'photo'],
                ['is_extra' => false, 'required' => false, 'media' => 'video'],
                ['is_extra' => true,  'required' => true,  'media' => 'video'],
                ['is_extra' => true,  'required' => false, 'media' => 'video'],
            ],
        ];

        foreach ($edges as $label => $files) {
            yield $label => [$label, $files];
        }

        // Seeded PRNG so the generator is reproducible across runs.
        // mt_srand applies process-wide state, but each iteration is fully
        // determined by the seed and the case index.
        mt_srand(20260613);

        $mediaPool = ['photo', 'video', 'floorplan'];
        $randomCases = 25;
        for ($i = 0; $i < $randomCases; $i++) {
            $count = mt_rand(0, 12);
            $files = [];
            for ($j = 0; $j < $count; $j++) {
                // Random file kind: 0 = standard, 1 = required extra, 2 = non-required extra.
                $kind = mt_rand(0, 2);
                $isExtra = $kind !== 0;
                $required = $kind === 1;
                $files[] = [
                    'is_extra' => $isExtra,
                    'required' => $required,
                    'media'    => $mediaPool[mt_rand(0, count($mediaPool) - 1)],
                ];
            }

            yield "random: case {$i} (n={$count})" => ["random: case {$i}", $files];
        }
    }

    /**
     * Materialize a generated file spec list into in-memory ShootFile models
     * with sequential ids so the assertions can refer to them stably.
     *
     * @param  list<array{is_extra: bool, required: bool, media: string}>  $specs
     * @return Collection<int, ShootFile>
     */
    private function materialize(array $specs): Collection
    {
        $files = [];
        foreach ($specs as $index => $spec) {
            $files[] = $this->file(
                $index + 1,
                isExtra: $spec['is_extra'],
                requiredForEditing: $spec['required'],
                mediaType: $spec['media'],
            );
        }

        return collect($files);
    }

    /**
     * Property 11 (editableFiles):
     * editableFiles($shoot) contains exactly the standard files plus
     * required-for-editing extras, and excludes every non-required extra.
     */
    #[Test]
    #[DataProvider('fileMixProvider')]
    public function editable_files_partitions_by_extras_required_flags(string $label, array $specs): void
    {
        $files = $this->materialize($specs);
        $shoot = $this->shootWithFiles($files);

        $expectedIncluded = $files
            ->filter(fn (ShootFile $f) => !$f->isExtra() || (bool) $f->required_for_editing)
            ->pluck('id')
            ->all();
        $expectedExcluded = $files
            ->filter(fn (ShootFile $f) => $f->isExtra() && !$f->required_for_editing)
            ->pluck('id')
            ->all();

        $resultIds = $this->service->editableFiles($shoot)->pluck('id')->all();

        // Every required-for-editing file (standard or required extra) is included.
        foreach ($expectedIncluded as $id) {
            $this->assertContains(
                $id,
                $resultIds,
                "[{$label}] editableFiles must include required-for-editing file id={$id}"
            );
        }

        // Every non-required extra is excluded.
        foreach ($expectedExcluded as $id) {
            $this->assertNotContains(
                $id,
                $resultIds,
                "[{$label}] editableFiles must exclude non-required extra id={$id}"
            );
        }

        // Result set equals the expected partition exactly (no other files appear).
        sort($resultIds);
        sort($expectedIncluded);
        $this->assertSame(
            $expectedIncluded,
            $resultIds,
            "[{$label}] editableFiles must equal exactly the required-for-editing partition"
        );
    }

    /**
     * Property 11 (filterFilesForEditor):
     * For an editor with both lanes assigned (so lane filtering passes for any
     * file), filterFilesForEditor() additionally excludes non-required extras.
     * The result equals editableFiles() exactly because the lane filter
     * accepts every file in this configuration.
     */
    #[Test]
    #[DataProvider('fileMixProvider')]
    public function filter_files_for_editor_also_excludes_non_required_extras(string $label, array $specs): void
    {
        $editor = $this->editor();
        $files = $this->materialize($specs);
        $shoot = $this->shootWithFiles($files, $editor);

        $expectedIncluded = $files
            ->filter(fn (ShootFile $f) => !$f->isExtra() || (bool) $f->required_for_editing)
            ->pluck('id')
            ->all();

        $resultIds = $this->service
            ->filterFilesForEditor($files, $shoot, $editor)
            ->pluck('id')
            ->all();

        // Lane filtering passes for every file (both lanes assigned), so the
        // remaining filter is the extras flag — result equals editableFiles().
        sort($resultIds);
        sort($expectedIncluded);
        $this->assertSame(
            $expectedIncluded,
            $resultIds,
            "[{$label}] filterFilesForEditor must exclude non-required extras while keeping every other file the editor's lanes allow"
        );

        // No non-required extra leaks through.
        foreach ($files as $file) {
            if ($file->isExtra() && !$file->required_for_editing) {
                $this->assertNotContains(
                    $file->id,
                    $resultIds,
                    "[{$label}] filterFilesForEditor must exclude non-required extra id={$file->id}"
                );
            }
        }
    }

    /**
     * Property 11 (canEditorAccessFile):
     * For an editor authorized via lane, canEditorAccessFile() returns false
     * for every non-required extra and true for every required extra and
     * standard file.
     */
    #[Test]
    #[DataProvider('fileMixProvider')]
    public function can_editor_access_file_denies_non_required_extras_only(string $label, array $specs): void
    {
        $editor = $this->editor();
        $files = $this->materialize($specs);
        $shoot = $this->shootWithFiles($files, $editor);

        // Anchor an assertion on every case (empty file mixes are a valid
        // shape too, and the property must hold trivially over them).
        $this->assertSame(
            count($specs),
            $files->count(),
            "[{$label}] generated file set should match the spec list size"
        );

        foreach ($files as $file) {
            $allowed = $this->service->canEditorAccessFile($shoot, $file, $editor);

            if ($file->isExtra() && !$file->required_for_editing) {
                $this->assertFalse(
                    $allowed,
                    "[{$label}] canEditorAccessFile must deny non-required extra id={$file->id}"
                );
            } else {
                $this->assertTrue(
                    $allowed,
                    "[{$label}] canEditorAccessFile must allow required-for-editing file id={$file->id} (is_extra={$file->isExtra()}, required_for_editing={$file->required_for_editing})"
                );
            }
        }
    }

    /**
     * Property 11 (cross-method consistency):
     * The set of files the editor can access via canEditorAccessFile() equals
     * the editableFiles() set, and equals the filterFilesForEditor() result
     * when the editor has every lane assigned. This is the design's
     * "files an editor can view for an assigned shoot equal that payload"
     * clause stated as a single round-trip property.
     */
    #[Test]
    #[DataProvider('fileMixProvider')]
    public function editor_view_equals_editable_payload(string $label, array $specs): void
    {
        $editor = $this->editor();
        $files = $this->materialize($specs);
        $shoot = $this->shootWithFiles($files, $editor);

        $editableIds = $this->service->editableFiles($shoot)->pluck('id')->all();
        $editorFilteredIds = $this->service
            ->filterFilesForEditor($files, $shoot, $editor)
            ->pluck('id')
            ->all();
        $editorAccessibleIds = $files
            ->filter(fn (ShootFile $f) => $this->service->canEditorAccessFile($shoot, $f, $editor))
            ->pluck('id')
            ->all();

        sort($editableIds);
        sort($editorFilteredIds);
        sort($editorAccessibleIds);

        $this->assertSame(
            $editableIds,
            $editorFilteredIds,
            "[{$label}] filterFilesForEditor and editableFiles must agree when the editor holds every lane"
        );
        $this->assertSame(
            $editableIds,
            $editorAccessibleIds,
            "[{$label}] canEditorAccessFile and editableFiles must agree when the editor holds every lane"
        );
    }
}
