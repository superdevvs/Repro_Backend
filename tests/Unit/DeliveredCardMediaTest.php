<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\DeliveredCardMedia;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DeliveredCardMediaTest extends TestCase
{
    private function media(): DeliveredCardMedia
    {
        $files = Mockery::mock(ShootFileAccessService::class);
        $files->shouldReceive('resolvePublicStorageUrl')->andReturnUsing(fn ($path) => 'https://reprodashboard.test/storage/'.$path);
        $authorization = Mockery::mock(ShootAuthorizationSupport::class);
        $authorization->shouldReceive('isClientUser')->andReturnUsing(fn ($user) => $user?->role === 'client');

        return new DeliveredCardMedia($files, $authorization);
    }

    private function shoot(array $attributes = []): Shoot
    {
        return new Shoot(array_merge(['payment_status' => 'paid', 'total_quote' => 200, 'total_paid' => 200], $attributes));
    }

    private function photo(string $name, array $attributes = []): ShootFile
    {
        return new ShootFile(array_merge([
            'filename' => $name.'.jpg', 'media_type' => 'edited', 'mime_type' => 'image/jpeg',
            'workflow_stage' => ShootFile::STAGE_VERIFIED, 'is_hidden' => false,
            'grid_path' => 'photos/'.$name.'-grid.jpg', 'web_path' => 'photos/'.$name.'-web.jpg',
        ], $attributes));
    }

    public function test_cover_is_first_class_and_duplicate_preview_urls_do_not_repeat(): void
    {
        $first = $this->photo('interior');
        $cover = $this->photo('hero', ['is_cover' => true]);
        $value = $this->media()->forShoot($this->shoot(), collect([$first, $cover, $cover]), new User(['role' => 'admin']));
        $this->assertSame('https://reprodashboard.test/storage/photos/hero-grid.jpg', $value['hero_image']);
        $this->assertCount(2, $value['preview_images']);
        $this->assertNull($value['count']);
        $this->assertSame(1, $value['version']);
    }

    public function test_locked_clients_get_watermarked_renditions_without_generating_them(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $files = collect([
            $this->photo('hero', ['is_cover' => true, 'watermarked_web_path' => 'watermarked/hero.jpg']),
            $this->photo('room', ['watermarked_thumbnail_path' => 'watermarked/room-thumb.jpg']),
            $this->photo('not-ready'),
        ]);
        $value = $this->media()->forShoot($this->shoot(['payment_status' => 'unpaid', 'total_paid' => 0]), $files, new User(['role' => 'client']));
        $this->assertTrue($value['uses_watermark']);
        $this->assertCount(2, $value['preview_images']);
        $this->assertStringNotContainsString('/photos/', json_encode($value));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_missing_safe_client_previews_never_fall_back_to_originals_or_stored_hero(): void
    {
        $value = $this->media()->forShoot($this->shoot(['payment_status' => 'partial', 'hero_image' => 'private/original.jpg']), collect([$this->photo('room')]), new User(['role' => 'client']));
        $this->assertSame([], $value['preview_images']);
        $this->assertNull($value['hero_image']);
    }

    public function test_a_watermark_field_pointing_to_an_original_is_not_a_safe_preview(): void
    {
        $photo = $this->photo('hero', ['watermarked_web_path' => 'photos/hero-web.jpg']);
        $value = $this->media()->forShoot($this->shoot(['payment_status' => 'unpaid']), collect([$photo]), new User(['role' => 'client']));
        $this->assertSame([], $value['preview_images']);
        $this->assertNull($value['hero_image']);
    }

    public function test_paid_and_bypassed_clients_use_existing_optimized_previews(): void
    {
        foreach ([['payment_status' => 'paid'], ['payment_status' => 'full'], ['payment_status' => 'unpaid', 'bypass_paywall' => true]] as $attrs) {
            $value = $this->media()->forShoot($this->shoot($attrs), collect([$this->photo('hero')]), new User(['role' => 'client']));
            $this->assertFalse($value['uses_watermark']);
            $this->assertSame(['https://reprodashboard.test/storage/photos/hero-grid.jpg'], $value['preview_images']);
        }
    }

    public function test_hidden_blocked_raw_unverified_and_nonphoto_media_never_appear(): void
    {
        $files = collect([
            $this->photo('hidden', ['is_hidden' => true]),
            $this->photo('pending-scan', ['scan_status' => 'pending']),
            $this->photo('raw', ['media_type' => 'raw']),
            $this->photo('unverified', ['workflow_stage' => 'todo']),
            $this->photo('plan', ['media_type' => 'floorplan']),
            $this->photo('video', ['media_type' => 'video']),
        ]);
        $value = $this->media()->forShoot($this->shoot(), $files, new User(['role' => 'admin']));
        $this->assertSame([], $value['preview_images']);
        $this->assertNull($value['hero_image']);
    }

    public function test_preview_window_is_bounded_without_claiming_total(): void
    {
        $value = $this->media()->forShoot($this->shoot(), collect(range(1, 9))->map(fn ($id) => $this->photo('photo-'.$id)), new User(['role' => 'salesRep']));
        $this->assertCount(6, $value['preview_images']);
        $this->assertNull($value['count']);
    }

    public function test_unauthenticated_viewer_has_no_previews(): void
    {
        $value = $this->media()->forShoot($this->shoot(), collect([$this->photo('hero')]), null);
        $this->assertSame([], $value['preview_images']);
        $this->assertNull($value['hero_image']);
    }

    public function test_pending_payment_status_does_not_lazy_load_payment_records(): void
    {
        $shoot = Mockery::mock(Shoot::class)->makePartial();
        $shoot->setRawAttributes(['payment_status' => 'pending', 'bypass_paywall' => false]);
        $shoot->shouldReceive('getTotalPaidAttribute')->never();
        $value = $this->media()->forShoot($shoot, collect([$this->photo('hero')]), new User(['role' => 'client']));
        $this->assertTrue($value['uses_watermark']);
        $this->assertSame([], $value['preview_images']);
    }

    public function test_delivery_time_does_not_fall_back_to_an_appointment_or_completion(): void
    {
        $shoot = $this->shoot(['scheduled_date' => '2026-11-01', 'completed_at' => '2026-11-01 11:00:00']);
        $value = $this->media()->forShoot($shoot, collect(), new User(['role' => 'admin']));
        $this->assertNull($value['delivered_at']);
        $shoot->admin_verified_at = '2026-11-02 14:30:00';
        $value = $this->media()->forShoot($shoot, collect(), new User(['role' => 'admin']));
        $this->assertStringStartsWith('2026-11-02T14:30:00', $value['delivered_at']);
    }
}
