<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Req 13.2 / 13.3: files delivered to an editor via a share link must withhold
 * non-required extras while always including standard files and required extras.
 */
class ShootShareLinkExcludesExtrasTest extends TestCase
{
    use RefreshDatabase;

    private ShootShareLinkService $service;
    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShootShareLinkService::class);
        $this->uploader = User::factory()->create(['role' => 'admin']);
    }

    public function test_raw_share_file_set_excludes_non_required_extras(): void
    {
        $shoot = Shoot::factory()->create();

        $standard = $this->rawFile($shoot, 'standard.jpg', isExtra: false, requiredForEditing: false);
        $requiredExtra = $this->rawFile($shoot, 'required-extra.jpg', isExtra: true, requiredForEditing: true);
        $this->rawFile($shoot, 'hidden-extra.jpg', isExtra: true, requiredForEditing: false);

        $files = $this->service->selectEditorShareFiles($shoot, 'raw');

        $this->assertEqualsCanonicalizing(
            [$standard->id, $requiredExtra->id],
            $files->pluck('id')->all(),
            'Share link file set must include standard files and required extras but exclude non-required extras.'
        );
    }

    public function test_explicit_file_ids_still_drop_non_required_extras(): void
    {
        $shoot = Shoot::factory()->create();

        $standard = $this->rawFile($shoot, 'standard.jpg', isExtra: false, requiredForEditing: false);
        $hiddenExtra = $this->rawFile($shoot, 'hidden-extra.jpg', isExtra: true, requiredForEditing: false);

        // Even if a non-required extra id is explicitly requested it must be withheld.
        $files = $this->service->selectEditorShareFiles(
            $shoot,
            'raw',
            [$standard->id, $hiddenExtra->id]
        );

        $this->assertSame([$standard->id], $files->pluck('id')->all());
    }

    private function rawFile(Shoot $shoot, string $filename, bool $isExtra, bool $requiredForEditing): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => 'raw/' . $filename,
            'file_type' => 'image',
            'file_size' => 1024,
            'media_type' => 'photo',
            'uploaded_by' => $this->uploader->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            'is_extra' => $isExtra,
            'required_for_editing' => $requiredForEditing,
        ]);
    }
}
