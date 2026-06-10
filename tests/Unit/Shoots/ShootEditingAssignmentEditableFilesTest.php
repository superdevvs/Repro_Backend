<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootEditingAssignmentService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShootEditingAssignmentEditableFilesTest extends TestCase
{
    private ShootEditingAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ShootEditingAssignmentService();
    }

    /**
     * Build an in-memory ShootFile with explicit extras flags (no DB), keeping
     * the editable-payload logic under test pure.
     */
    private function file(int $id, bool $isExtra, bool $requiredForEditing): ShootFile
    {
        $file = new ShootFile();
        $file->id = $id;
        $file->setAttribute('is_extra', $isExtra);
        $file->setAttribute('required_for_editing', $requiredForEditing);
        $file->setAttribute('media_type', 'photo');

        return $file;
    }

    private function shootWithFiles(Collection $files): Shoot
    {
        $shoot = new Shoot();
        $shoot->setRelation('files', $files);

        return $shoot;
    }

    #[Test]
    public function non_extra_files_are_editable(): void
    {
        $file = $this->file(1, isExtra: false, requiredForEditing: false);

        $this->assertTrue($this->service->isEditable($file));
    }

    #[Test]
    public function non_required_extras_are_not_editable(): void
    {
        $file = $this->file(1, isExtra: true, requiredForEditing: false);

        $this->assertFalse($this->service->isEditable($file));
    }

    #[Test]
    public function required_extras_are_always_editable(): void
    {
        $file = $this->file(1, isExtra: true, requiredForEditing: true);

        $this->assertTrue($this->service->isEditable($file));
    }

    #[Test]
    public function editable_files_excludes_non_required_extras_and_keeps_required_ones(): void
    {
        $standard = $this->file(1, isExtra: false, requiredForEditing: false);
        $requiredExtra = $this->file(2, isExtra: true, requiredForEditing: true);
        $hiddenExtra = $this->file(3, isExtra: true, requiredForEditing: false);

        $shoot = $this->shootWithFiles(collect([$standard, $requiredExtra, $hiddenExtra]));

        $result = $this->service->editableFiles($shoot);

        $this->assertSame([1, 2], $result->pluck('id')->all());
    }

    #[Test]
    public function editable_files_reindexes_results_sequentially(): void
    {
        $hiddenExtra = $this->file(1, isExtra: true, requiredForEditing: false);
        $standard = $this->file(2, isExtra: false, requiredForEditing: false);

        $shoot = $this->shootWithFiles(collect([$hiddenExtra, $standard]));

        $result = $this->service->editableFiles($shoot);

        $this->assertSame([0], $result->keys()->all());
        $this->assertSame([2], $result->pluck('id')->all());
    }

    private function editor(int $id = 99): User
    {
        $editor = new User();
        $editor->id = $id;
        $editor->role = 'editor';

        return $editor;
    }

    /**
     * Build a shoot whose primary editor is the given user and whose tracked
     * service assignments are empty, so getAssignedLanesForEditor() reports
     * both lanes (LANE_PHOTO + LANE_VIDEO) to the editor without touching the
     * database.
     */
    private function shootAssignedToEditor(User $editor): Shoot
    {
        $shoot = new Shoot();
        $shoot->id = 1;
        $shoot->editor_id = $editor->id;
        $shoot->setRelation('services', collect());

        return $shoot;
    }

    #[Test]
    public function filter_files_for_editor_excludes_non_required_extras_keeps_required_extras_and_standard_files(): void
    {
        $editor = $this->editor();
        $shoot = $this->shootAssignedToEditor($editor);

        $standard = $this->file(1, isExtra: false, requiredForEditing: false);
        $requiredExtra = $this->file(2, isExtra: true, requiredForEditing: true);
        $hiddenExtra = $this->file(3, isExtra: true, requiredForEditing: false);

        $result = $this->service->filterFilesForEditor(
            collect([$standard, $requiredExtra, $hiddenExtra]),
            $shoot,
            $editor
        );

        $this->assertSame([1, 2], $result->pluck('id')->all());
    }

    #[Test]
    public function filter_files_for_editor_returns_empty_when_no_lanes_are_assigned(): void
    {
        $editor = $this->editor();
        $shoot = new Shoot();
        $shoot->id = 1;
        $shoot->editor_id = null;
        $shoot->setRelation('services', collect());

        $standard = $this->file(1, isExtra: false, requiredForEditing: false);

        $result = $this->service->filterFilesForEditor(
            collect([$standard]),
            $shoot,
            $editor
        );

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function can_editor_access_file_denies_non_required_extras(): void
    {
        $editor = $this->editor();
        $shoot = $this->shootAssignedToEditor($editor);

        $hiddenExtra = $this->file(3, isExtra: true, requiredForEditing: false);

        $this->assertFalse($this->service->canEditorAccessFile($shoot, $hiddenExtra, $editor));
    }

    #[Test]
    public function can_editor_access_file_allows_required_extras(): void
    {
        $editor = $this->editor();
        $shoot = $this->shootAssignedToEditor($editor);

        $requiredExtra = $this->file(2, isExtra: true, requiredForEditing: true);

        $this->assertTrue($this->service->canEditorAccessFile($shoot, $requiredExtra, $editor));
    }

    #[Test]
    public function can_editor_access_file_allows_standard_files(): void
    {
        $editor = $this->editor();
        $shoot = $this->shootAssignedToEditor($editor);

        $standard = $this->file(1, isExtra: false, requiredForEditing: false);

        $this->assertTrue($this->service->canEditorAccessFile($shoot, $standard, $editor));
    }
}
