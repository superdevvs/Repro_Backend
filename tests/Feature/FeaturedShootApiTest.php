<?php

namespace Tests\Feature;

use App\Models\FeaturedShootImage;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeaturedShootApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function featured_shoot_endpoint_requires_the_configured_bearer_token(): void
    {
        config(['services.repro_dashboard.api_key' => 'secret-token']);

        $this->getJson('/api/v1/featured-shoot')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer wrong-token')
            ->getJson('/api/v1/featured-shoot')
            ->assertUnauthorized();
    }

    #[Test]
    public function featured_shoot_endpoint_returns_null_when_nothing_is_featured(): void
    {
        config(['services.repro_dashboard.api_key' => 'secret-token']);

        $this->withHeader('Authorization', 'Bearer secret-token')
            ->getJson('/api/v1/featured-shoot')
            ->assertOk()
            ->assertContent('null');
    }

    #[Test]
    public function featured_shoot_endpoint_returns_ordered_images_and_excludes_unusable_files(): void
    {
        config(['services.repro_dashboard.api_key' => 'secret-token']);
        Storage::fake('public');
        Storage::disk('public')->put('shoots/1/hero-a-640.webp', 'a');
        Storage::disk('public')->put('shoots/1/hero-a-1280.webp', 'a');
        Storage::disk('public')->put('shoots/1/hero-a-1920.webp', 'a');
        Storage::disk('public')->put('shoots/1/hero-b-640.webp', 'b');
        Storage::disk('public')->put('shoots/1/hero-b-1280.webp', 'b');
        Storage::disk('public')->put('shoots/1/hero-b-1920.webp', 'b');

        $uploader = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'is_featured' => true,
            'address' => '123 Modern Lane',
            'city' => 'Arlington',
            'state' => 'VA',
            'featured_homepage_title' => 'Modern Arlington Townhouse',
            'featured_homepage_location' => 'Arlington, VA',
            'featured_homepage_subtitle' => 'Twilight + Drone',
            'featured_homepage_cta_label' => 'See the shoot',
            'featured_homepage_cta_href' => '/projects/modern-arlington',
        ]);

        $first = $this->makeShootFile($shoot, $uploader, 'hero-a.jpg', 'shoots/1/hero-a-1920.webp');
        $second = $this->makeShootFile($shoot, $uploader, 'hero-b.jpg', 'shoots/1/hero-b-1920.webp');
        $hidden = $this->makeShootFile($shoot, $uploader, 'hidden.jpg', 'shoots/1/hidden.webp', ['is_hidden' => true]);
        $infected = $this->makeShootFile($shoot, $uploader, 'infected.jpg', 'shoots/1/infected.webp', ['scan_status' => ShootFile::SCAN_STATUS_INFECTED]);

        FeaturedShootImage::create([
            'shoot_id' => $shoot->id,
            'shoot_file_id' => $second->id,
            'sort_order' => 1,
            'alt_text' => 'Kitchen at dusk',
            'focal_point' => '45% 35%',
            'variant_640_path' => 'shoots/1/hero-b-640.webp',
            'variant_1280_path' => 'shoots/1/hero-b-1280.webp',
            'variant_1920_path' => 'shoots/1/hero-b-1920.webp',
            'width' => 1920,
            'height' => 1080,
        ]);
        FeaturedShootImage::create([
            'shoot_id' => $shoot->id,
            'shoot_file_id' => $first->id,
            'sort_order' => 2,
            'alt_text' => 'Living room at dusk',
            'focal_point' => '50% 35%',
            'variant_640_path' => 'shoots/1/hero-a-640.webp',
            'variant_1280_path' => 'shoots/1/hero-a-1280.webp',
            'variant_1920_path' => 'shoots/1/hero-a-1920.webp',
            'width' => 1920,
            'height' => 1080,
        ]);
        FeaturedShootImage::create([
            'shoot_id' => $shoot->id,
            'shoot_file_id' => $hidden->id,
            'sort_order' => 3,
        ]);
        FeaturedShootImage::create([
            'shoot_id' => $shoot->id,
            'shoot_file_id' => $infected->id,
            'sort_order' => 4,
        ]);

        $this->withHeader('Authorization', 'Bearer secret-token')
            ->getJson('/api/v1/featured-shoot')
            ->assertOk()
            ->assertJsonPath('id', 'shoot_' . $shoot->id)
            ->assertJsonPath('title', 'Modern Arlington Townhouse')
            ->assertJsonPath('images.0.alt', 'Kitchen at dusk')
            ->assertJsonPath('images.0.focal', '45% 35%')
            ->assertJsonPath('images.0.sort', 1)
            ->assertJsonPath('images.1.alt', 'Living room at dusk')
            ->assertJsonCount(2, 'images')
            ->assertJsonPath('cta.href', '/projects/modern-arlington');
    }

    #[Test]
    public function setting_one_shoot_featured_unsets_the_previous_featured_shoot(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $previous = Shoot::factory()->create(['is_featured' => true]);
        $next = Shoot::factory()->create(['is_featured' => false]);

        $this->patchJson('/api/shoots/' . $next->id, ['is_featured' => true])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true);

        $this->assertFalse((bool) $previous->fresh()->is_featured);
        $this->assertTrue((bool) $next->fresh()->is_featured);
    }

    private function makeShootFile(Shoot $shoot, User $uploader, string $filename, string $path, array $overrides = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => $path,
            'storage_path' => $path,
            'thumbnail_path' => $path,
            'web_path' => $path,
            'file_type' => 'image/webp',
            'mime_type' => 'image/webp',
            'media_type' => 'image',
            'file_size' => 1234,
            'uploaded_by' => $uploader->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'metadata' => ['width' => 1920, 'height' => 1080],
        ], $overrides));
    }
}
