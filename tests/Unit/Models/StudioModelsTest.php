<?php

namespace Tests\Unit\Models;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\BrandState;
use App\Models\GeneratedAsset;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\Shoot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validates: Requirements 10.10, 16.8.
 */
class StudioModelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function project_relationships_traverse_to_studio_media_jobs_shoot_and_creator(): void
    {
        $creator = User::factory()->create();
        $shoot = Shoot::factory()->create(['client_id' => $creator->id]);
        $project = $this->createProject($creator, $shoot);

        $media = ProjectMedia::create([
            'project_id' => $project->id,
            'team_id' => $creator->id,
            'created_by' => $creator->id,
            'media_ref' => 'studio/source/front.jpg',
            'kind' => 'source',
        ]);
        $photoJob = AiEditingJob::create([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $creator->id,
            'status' => AiEditingJob::STATUS_PENDING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => 'https://example.test/front.jpg',
        ]);
        $videoJob = AiListingVideoJob::create([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $creator->id,
            'provider' => 'fal',
            'selected_file_ids' => [10, 11],
            'target_seconds' => 30,
            'status' => AiListingVideoJob::STATUS_QUEUED,
        ]);

        $project->refresh()->load([
            'createdBy',
            'shoot',
            'media',
            'aiEditingJobs',
            'aiListingVideoJobs',
        ]);

        $this->assertTrue($project->createdBy->is($creator));
        $this->assertTrue($project->shoot->is($shoot));
        $this->assertSame([$media->id], $project->media->pluck('id')->all());
        $this->assertSame([$photoJob->id], $project->aiEditingJobs->pluck('id')->all());
        $this->assertSame([$videoJob->id], $project->aiListingVideoJobs->pluck('id')->all());
        $this->assertTrue($media->fresh()->project->is($project));
        $this->assertTrue($media->fresh()->createdBy->is($creator));
        $this->assertTrue($photoJob->fresh()->project->is($project));
        $this->assertTrue($videoJob->fresh()->project->is($project));
        $this->assertTrue($shoot->fresh()->projects->contains('id', $project->id));
    }

    #[Test]
    public function scoped_studio_models_resolve_owners_and_round_trip_json_state(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $templateConfig = ['preset' => 'bright', 'corrections' => ['verticals', 'windows']];
        $brandSettings = ['logo' => 'brands/acme.svg', 'palette' => ['navy', 'gold']];

        $template = Template::create([
            'team_id' => $creator->id,
            'created_by' => $creator->id,
            'name' => 'Listing polish',
            'workflow_id' => 'photo-enhancement',
            'config' => $templateConfig,
        ]);
        $brand = BrandState::create([
            'team_id' => $creator->id,
            'created_by' => $creator->id,
            'updated_by' => $updater->id,
            'settings' => $brandSettings,
        ]);
        $asset = GeneratedAsset::create([
            'team_id' => $creator->id,
            'created_by' => $creator->id,
            'instruction_index' => 1,
            'instruction_text' => 'Twilight exterior',
            'asset_path' => 'studio/generated/twilight.webp',
            'placement' => 'hero',
            'alt_text' => 'Twilight exterior of a modern home',
            'status' => 'produced',
        ]);

        $this->assertTrue($template->fresh()->createdBy->is($creator));
        $this->assertSame($templateConfig, $template->fresh()->config);
        $this->assertTrue($brand->fresh()->createdBy->is($creator));
        $this->assertTrue($brand->fresh()->updatedBy->is($updater));
        $this->assertSame($brandSettings, $brand->fresh()->settings);
        $this->assertTrue($asset->fresh()->createdBy->is($creator));
    }


    #[Test]
    #[DataProvider('mutableStudioModels')]
    public function every_mutable_studio_model_advances_its_server_managed_version_on_save(string $modelClass): void
    {
        $creator = User::factory()->create();
        $project = $this->createProject($creator);
        $model = $this->createMutableModel($modelClass, $creator, $project);

        $this->assertSame(1, $model->fresh()->version, "{$modelClass} must start at version 1");

        $this->mutateBusinessAttribute($model);
        $model->save();
        $this->assertSame(2, $model->refresh()->version, "{$modelClass} must advance after an update");

        $model->version = 999;
        $model->save();
        $this->assertSame(
            3,
            $model->refresh()->version,
            "{$modelClass} must derive the next version from persisted server state"
        );
    }

    public static function mutableStudioModels(): iterable
    {
        yield 'project' => [Project::class];
        yield 'project media' => [ProjectMedia::class];
        yield 'template' => [Template::class];
        yield 'brand state' => [BrandState::class];
        yield 'generated asset' => [GeneratedAsset::class];
    }

    private function createProject(User $creator, ?Shoot $shoot = null): Project
    {
        return Project::create([
            'team_id' => $creator->id,
            'created_by' => $creator->id,
            'shoot_id' => $shoot?->id,
            'name' => '123 Studio Lane',
            'address' => '123 Studio Lane',
            'source_type' => $shoot ? 'shoot' : 'upload',
            'workflow_id' => 'photo-enhancement',
            'status' => 'draft',
        ]);
    }

    private function createMutableModel(string $modelClass, User $creator, Project $project): Model
    {
        return match ($modelClass) {
            Project::class => $project,
            ProjectMedia::class => ProjectMedia::create([
                'project_id' => $project->id,
                'team_id' => $creator->id,
                'created_by' => $creator->id,
                'media_ref' => 'studio/source/version.jpg',
                'kind' => 'source',
            ]),
            Template::class => Template::create([
                'team_id' => $creator->id,
                'created_by' => $creator->id,
                'name' => 'Versioned template',
                'workflow_id' => 'photo-enhancement',
                'config' => ['preset' => 'natural'],
            ]),
            BrandState::class => BrandState::create([
                'team_id' => $creator->id,
                'created_by' => $creator->id,
                'updated_by' => $creator->id,
                'settings' => ['logo' => null],
            ]),
            GeneratedAsset::class => GeneratedAsset::create([
                'team_id' => $creator->id,
                'created_by' => $creator->id,
                'instruction_index' => 1,
                'instruction_text' => 'Version test image',
                'placement' => 'workflow:photo-enhancement',
                'status' => 'failed',
            ]),
            default => throw new \InvalidArgumentException("Unsupported Studio model: {$modelClass}"),
        };
    }

    private function mutateBusinessAttribute(Model $model): void
    {
        match ($model::class) {
            Project::class => $model->status = 'processing',
            ProjectMedia::class => $model->media_ref = 'studio/source/version-updated.jpg',
            Template::class => $model->name = 'Updated versioned template',
            BrandState::class => $model->settings = ['logo' => 'brands/updated.svg'],
            GeneratedAsset::class => $model->status = 'produced',
            default => throw new \InvalidArgumentException('Unsupported Studio model: '.$model::class),
        };
    }
}
