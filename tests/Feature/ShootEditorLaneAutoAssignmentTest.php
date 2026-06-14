<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootEditingAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShootEditorLaneAutoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $photographer;
    private User $photoEditor;
    private User $videoEditor;
    private ShootWorkflowService $workflow;
    private ShootEditingAssignmentService $assignments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => 'client']);
        $this->photographer = User::factory()->photographer()->create();
        $this->photoEditor = User::factory()->create([
            'role' => 'editor',
            'name' => 'QA Photo Editor',
            'metadata' => ['editing_capabilities' => ['photo']],
        ]);
        $this->videoEditor = User::factory()->create([
            'role' => 'editor',
            'name' => 'QA Video Editor',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);

        $this->workflow = app(ShootWorkflowService::class);
        $this->assignments = app(ShootEditingAssignmentService::class);
    }

    public function test_photo_only_shoot_assigns_the_photo_editor_lane(): void
    {
        $photo = $this->serviceInCategory('Photos', 'QA Still Photos');
        $shoot = $this->uploadedShootWithServices([$photo]);

        $this->workflow->startEditing($shoot);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_EDITING, $shoot->status);
        $this->assertSame($this->photoEditor->id, (int) $shoot->editor_id);
        $this->assertServiceEditor($shoot, $photo, $this->photoEditor);
        $this->assertEditorAssignments($shoot, [
            'photo' => $this->photoEditor->id,
        ]);
    }

    public function test_video_only_shoot_assigns_the_video_editor_lane(): void
    {
        $video = $this->serviceInCategory('Video', 'QA Walkthrough Video');
        $shoot = $this->uploadedShootWithServices([$video]);

        $this->workflow->startEditing($shoot);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_EDITING, $shoot->status);
        $this->assertSame($this->videoEditor->id, (int) $shoot->editor_id);
        $this->assertServiceEditor($shoot, $video, $this->videoEditor);
        $this->assertEditorAssignments($shoot, [
            'video' => $this->videoEditor->id,
        ]);
    }

    public function test_mixed_photo_video_shoot_assigns_both_lane_editors_per_service(): void
    {
        $photo = $this->serviceInCategory('Photos', 'QA Still Photos');
        $video = $this->serviceInCategory('Video', 'QA Walkthrough Video');
        $shoot = $this->uploadedShootWithServices([$photo, $video]);

        $this->workflow->startEditing($shoot);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_EDITING, $shoot->status);
        $this->assertNull(
            $shoot->editor_id,
            'Legacy shoot.editor_id should be null when different editors own different lanes.'
        );
        $this->assertServiceEditor($shoot, $photo, $this->photoEditor);
        $this->assertServiceEditor($shoot, $video, $this->videoEditor);
        $this->assertEditorAssignments($shoot, [
            'photo' => $this->photoEditor->id,
            'video' => $this->videoEditor->id,
        ]);
    }

    private function serviceInCategory(string $categoryName, string $serviceName): Service
    {
        $category = Category::firstOrCreate(['name' => $categoryName]);

        return Service::factory()->create([
            'category_id' => $category->id,
            'name' => $serviceName,
            'price' => 100,
        ]);
    }

    /**
     * @param  list<Service>  $services
     */
    private function uploadedShootWithServices(array $services): Shoot
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $services[0]->id,
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
            'editor_id' => null,
        ]);

        foreach ($services as $service) {
            $shoot->services()->attach($service->id, [
                'price' => $service->price,
                'quantity' => 1,
                'photographer_pay' => 40,
                'photographer_id' => $this->photographer->id,
            ]);
        }

        return $shoot->fresh(['services.category']);
    }

    private function assertServiceEditor(Shoot $shoot, Service $service, User $editor): void
    {
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'editor_id' => $editor->id,
        ]);
    }

    /**
     * @param  array<string, int>  $expected
     */
    private function assertEditorAssignments(Shoot $shoot, array $expected): void
    {
        $payload = collect($this->assignments->buildEditorAssignmentsPayload($shoot->fresh(['services.category'])))
            ->mapWithKeys(fn (array $assignment) => [
                $assignment['lane'] => (int) $assignment['editor_id'],
            ])
            ->all();

        $this->assertSame($expected, $payload);

        foreach ($expected as $lane => $editorId) {
            $this->assertTrue(
                DB::table('shoot_service')
                    ->where('shoot_id', $shoot->id)
                    ->where('editor_id', $editorId)
                    ->exists(),
                "Expected a persisted {$lane} lane assignment for editor {$editorId}."
            );
        }
    }
}
