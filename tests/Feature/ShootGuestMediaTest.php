<?php

namespace Tests\Feature;

use App\Jobs\{FinalizeShootJob, GenerateShootGuestArchive};
use App\Models\{Service, Shoot, ShootFile, ShootGuestShare, User};
use App\Services\Media\LocalMediaDelivery;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{DeliveryPlanService, ShootGuestArchiveFiles, ShootGuestArchiveService, ShootGuestShareService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Notification, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class ShootGuestMediaTest extends TestCase
{
    use RefreshDatabase;

    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->withCredentials();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32)), 'app.url' => 'https://repro.test',
            'app.frontend_url' => 'https://repro.test', 'media.local_disk' => 'local']);
        Cache::flush(); Http::preventStrayRequests(); Queue::fake(); Notification::fake();
        Storage::fake('public'); Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }

    private function fixture(string $kind = 'photo'): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $actor = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'ready', 'workflow_status' => 'ready',
            'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(), 'timezone' => 'UTC',
            'total_quote' => 100, 'payment_status' => 'paid', 'admin_verified_at' => null, 'completed_at' => null]);
        $service = Service::factory()->create(['name' => ucfirst($kind).' package', 'upload_intake_type' => $kind, 'requires_editing' => false]);
        $item = $shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 100, 'quantity' => 1,
            'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $item->forceFill(['delivery_requirements' => [
            ['key' => $kind, 'kind' => $kind, 'label' => ucfirst($kind)],
            ['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans'],
        ]])->save();
        $files = [];
        foreach (['a', 'b'] as $name) {
            $extension = $kind === 'video' ? 'mp4' : 'jpg';
            $mime = $kind === 'video' ? 'video/mp4' : 'image/jpeg';
            $path = 'shoots/'.$shoot->id.'/'.$name.'.'.$extension;
            $web = 'shoots/'.$shoot->id.'/web-'.$name.'.jpg';
            $file = new ShootFile;
            $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => $name.'.'.$extension,
                'stored_filename' => $name.'.'.$extension, 'path' => $path, 'web_path' => $web, 'file_size' => 6,
                'file_type' => $mime, 'mime_type' => $mime, 'media_type' => $kind === 'video' ? 'video' : 'edited',
                'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor->id,
                'metadata' => ['width' => 6000, 'height' => 4000, 'private' => 'DO-NOT-EXPOSE']])->save();
            Storage::disk('public')->put($path, 'FULL-'.$name);
            Storage::disk('public')->put($web, 'MLS-'.$name);
            $files[] = $file;
        }
        $this->release($shoot, $actor, [$item->id.':'.$kind]);
        $token = Str::random(64);
        $share = ShootGuestShare::create(['shoot_id' => $shoot->id, 'created_by' => $client->id, 'email' => 'guest@example.test',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)]);
        return [$shoot->fresh(), $share, $token, collect($files)->map->fresh(), $actor, $item];
    }

    private function release(Shoot $shoot, User $actor, array $ids, string $mode = 'auto'): void
    {
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot->fresh(), $actor,
            ['mode' => $mode, 'component_ids' => $ids, 'confirmed' => true]);
        $job = new FinalizeShootJob($shoot->id, $actor->id, null, null, false, $intent['idempotency_key'], $intent);
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));
    }

    private function verify(ShootGuestShare $share): void
    {
        $session = Str::random(64);
        Cache::put('guest-session:'.hash('sha256', $session), (int) $share->id, 3600);
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $session);
    }

    private function generated(ShootGuestShare $share, string $id): string
    {
        $service = app(ShootGuestArchiveService::class);
        $service->generate((int) $share->id, $id);
        $record = $service->record($share, $id);
        $this->assertSame('ready', $record['status']);
        $path = $service->path($record);
        $this->archives[] = $path;
        return $path;
    }

    private function zipBytes(string $path): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        try {
            return array_map(fn ($index) => $zip->getFromIndex($index), range(0, $zip->numFiles - 1));
        } finally { $zip->close(); }
    }

    public function test_archives_require_verification_and_reject_raw_pending_foreign_and_empty_selections(): void
    {
        [$shoot, $share, $token, $files] = $this->fixture();
        $base = '/api/public/guest-shares/'.$token;
        $this->postJson($base.'/downloads', ['size' => 'original'])->assertUnauthorized();
        $this->verify($share);
        $raw = $files[0]->replicate(); $raw->forceFill(['media_type' => 'raw', 'workflow_stage' => 'todo'])->save();
        $pending = $files[0]->replicate(); $pending->forceFill(['workflow_stage' => 'completed'])->save();
        $foreign = $files[0]->replicate(); $foreign->forceFill(['shoot_id' => Shoot::factory()->create()->id])->save();
        foreach ([$raw, $pending, $foreign] as $file) {
            $this->postJson($base.'/downloads', ['size' => 'original', 'file_ids' => [$files[0]->id, $file->id]])->assertNotFound();
            $this->getJson($base.'/files/'.$file->id.'/mls')->assertNotFound();
        }
        $this->postJson($base.'/downloads', ['size' => 'small', 'file_ids' => []])->assertUnprocessable();
        $this->postJson($base.'/downloads', ['size' => 'invalid'])->assertUnprocessable();
        $response = $this->postJson($base.'/downloads', ['size' => 'original'])->assertStatus(202)
            ->assertJsonPath('file_count', 2)->assertJsonPath('status', 'preparing')->assertHeader('Cache-Control', 'no-store, private');
        $path = $this->generated($share, $response->json('id'));
        $this->assertSame(['FULL-a', 'FULL-b'], $this->zipBytes($path));
        Queue::assertPushed(GenerateShootGuestArchive::class, 1);
    }

    public function test_selected_archives_use_the_original_and_mls_renditions_and_remain_cookie_gated(): void
    {
        [, $share, $token, $files] = $this->fixture();
        $this->verify($share);
        $base = '/api/public/guest-shares/'.$token;
        foreach (['original' => 'FULL-b', 'small' => 'MLS-b'] as $size => $expected) {
            $id = $this->postJson($base.'/downloads', ['size' => $size, 'file_ids' => [$files[1]->id]])
                ->assertStatus(202)->assertJsonPath('file_count', 1)->json('id');
            $path = $this->generated($share, $id);
            $this->assertSame([$expected], $this->zipBytes($path));
            $status = $this->getJson($base.'/downloads/'.$id)->assertOk()->assertJsonPath('status', 'ready');
            $this->assertSame($base.'/downloads/'.$id.'/file', parse_url($status->json('download_url'), PHP_URL_PATH));
            $status->assertJsonMissingPath('basename')->assertJsonMissingPath('signature')->assertJsonMissingPath('ids');
            $this->get($base.'/downloads/'.$id.'/file')->assertOk()->assertHeader('Content-Type', 'application/zip');
        }
        $this->get($base.'/files/'.$files[1]->id.'/mls')->assertOk()->assertStreamedContent('MLS-b');
        $this->getJson($base)->assertOk()->assertJsonPath('data.files.0.width', 6000)
            ->assertJsonPath('data.files.0.height', 4000)->assertJsonPath('data.files.0.mime_type', 'image/jpeg')
            ->assertDontSee('DO-NOT-EXPOSE');
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), '');
        $this->getJson($base.'/downloads/'.$id)->assertUnauthorized();
        $this->getJson($base.'/downloads/'.$id.'/file')->assertUnauthorized();
    }

    public function test_archive_poll_download_and_worker_revalidate_access_content_and_revocation(): void
    {
        [$shoot, $share, $token, $files] = $this->fixture();
        $this->verify($share);
        $base = '/api/public/guest-shares/'.$token;
        $id = $this->postJson($base.'/downloads', ['size' => 'original', 'file_ids' => [$files[0]->id]])->assertStatus(202)->json('id');
        $this->generated($share, $id);
        $otherToken = Str::random(64); $otherShare = $share->replicate();
        $otherShare->token_hash = hash('sha256', $otherToken); $otherShare->save(); $this->verify($otherShare);
        $this->getJson('/api/public/guest-shares/'.$otherToken.'/downloads/'.$id)->assertNotFound();
        $shoot->update(['payment_status' => 'unpaid']);
        $this->getJson($base.'/downloads/'.$id)->assertStatus(409);
        $this->getJson($base.'/downloads/'.$id.'/file')->assertStatus(409);
        $shoot->update(['payment_status' => 'paid']);
        $this->getJson($base.'/downloads/'.$id)->assertOk();
        $files[0]->update(['is_hidden' => true]);
        $this->getJson($base.'/downloads/'.$id.'/file')->assertStatus(409);
        $files[0]->update(['is_hidden' => false, 'content_version' => 2]);
        $this->getJson($base.'/downloads/'.$id)->assertStatus(409);
        $files[0]->update(['content_version' => 1]);
        $pendingId = $this->postJson($base.'/downloads', ['size' => 'original', 'file_ids' => [$files[1]->id]])->assertStatus(202)->json('id');
        $share->update(['revoked_at' => now()]);
        app(ShootGuestArchiveService::class)->generate((int) $share->id, $pendingId);
        $this->assertSame('failed', app(ShootGuestArchiveService::class)->record($share, $pendingId)['status']);
        $this->getJson($base.'/downloads/'.$id)->assertGone();
        $this->getJson($base.'/downloads/'.$id.'/file')->assertGone();
    }

    public function test_discard_during_generation_prevents_archive_and_cache_resurrection(): void
    {
        [, $share, $token, $files] = $this->fixture();
        $this->verify($share);
        $base = '/api/public/guest-shares/'.$token;
        $service = app(ShootGuestArchiveService::class);
        $readyId = $this->postJson($base.'/downloads', ['size' => 'original', 'file_ids' => [$files[0]->id]])->json('id');
        $readyPath = $this->generated($share, $readyId);
        $service->discard((int) $share->id, $readyId);
        $this->assertFileDoesNotExist($readyPath);
        $this->getJson($base.'/downloads/'.$readyId)->assertNotFound();

        $id = $this->postJson($base.'/downloads', ['size' => 'original', 'file_ids' => [$files[1]->id]])->json('id');
        $directory = app(LocalMediaDelivery::class)->temporaryDirectory();
        $before = glob($directory.'/*') ?: [];
        $resolver = app(ShootGuestArchiveFiles::class);
        $proxy = \Mockery::mock($resolver);
        $proxy->shouldReceive('stage')->once()->andReturnUsing(function (array &$entry) use ($resolver, $service, $share, $id) {
            $service->discard((int) $share->id, $id);
            return $resolver->stage($entry);
        });
        $this->instance(ShootGuestArchiveFiles::class, $proxy);
        $service->generate((int) $share->id, $id);
        $service->failed((int) $share->id, $id);
        $service->generate((int) $share->id, $id);
        $this->assertNull(Cache::get('guest-archive:'.$share->id.':'.$id));
        $this->assertSame($before, glob($directory.'/*') ?: []);
        $this->getJson($base.'/downloads/'.$id)->assertNotFound();
    }

    public function test_video_files_have_video_tours_but_unreviewed_external_links_stay_private(): void
    {
        [$shoot, $share, $token, $files] = $this->fixture('video');
        $shoot->update(['tour_links' => ['video_link' => 'https://video.example.test/pending', 'matterport_branded' => 'https://my.matterport.com/show/?m=pending']]);
        $this->verify($share);
        $response = $this->getJson('/api/public/guest-shares/'.$token)->assertOk()
            ->assertDontSee('video.example.test/pending')->assertDontSee('my.matterport.com');
        $videos = collect($response->json('data.tours'))->where('kind', 'video');
        $this->assertCount(2, $videos);
        $this->assertSame('repro', $videos->first()['provider']);
        $this->assertSame((int) $files[0]->id, $videos->first()['file_id']);
        $this->assertNull($response->json('data.files.0.mls_download_url'));
    }

    public function test_only_reviewed_and_unlocked_external_tour_destinations_are_exposed(): void
    {
        [$shoot, $share, $token, , $actor, $item] = $this->fixture();
        $item->forceFill(['delivery_requirements' => [
            ['key' => 'photo', 'kind' => 'photo', 'label' => 'Photos'],
            ['key' => 'tour', 'kind' => 'tour', 'label' => 'Matterport 3D', 'provider' => 'matterport'],
        ]])->save();
        $url = 'https://my.matterport.com/show/?m=reviewed';
        $shoot->update(['tour_links' => ['matterport_branded' => $url, 'zillow_3d' => 'https://zillow.example.test/pending']]);
        $this->verify($share);
        $base = '/api/public/guest-shares/'.$token;
        $this->getJson($base)->assertOk()->assertDontSee('my.matterport.com')->assertDontSee('zillow.example.test');
        $this->release($shoot, $actor, [$item->id.':tour'], 'external');
        $response = $this->getJson($base)->assertOk()->assertDontSee('zillow.example.test');
        $tour = collect($response->json('data.tours'))->firstWhere('kind', '3d');
        $this->assertSame($url, $tour['url']);
        $this->assertSame('matterport', $tour['provider']);
        $shoot->update(['payment_status' => 'unpaid']);
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data.tours');
    }
}
