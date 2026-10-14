<?php

namespace Tests\Feature;

use App\Models\{AccountLink, Service, Shoot, ShootFile, User};
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\Actions\AssignHeroMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Event, Http, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerMediaCurationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $rep;
    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); Storage::fake('local'); Queue::fake(); Event::fake(); Cache::flush(); Http::preventStrayRequests();
        $this->client = User::factory()->create(['role' => 'client']);
        $this->rep = User::factory()->create(['role' => 'salesRep']);
        $this->shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'rep_id' => $this->rep->id,
            'status' => 'delivered', 'workflow_status' => 'delivered', 'payment_status' => 'paid', 'total_quote' => 100, 'hero_image' => null]);
    }

    private function file(array $extra = []): ShootFile
    {
        $name = 'photo-'.bin2hex(random_bytes(3));
        $path = 'shoots/'.$this->shoot->id.'/completed/'.$name.'.jpg';
        $web = 'shoots/'.$this->shoot->id.'/web/'.$name.'_web.jpg';
        // Distinct bytes prove curation previews serve the derived image.
        Storage::disk('public')->put($path, 'original-image-private');
        Storage::disk('public')->put($web, 'derived-preview');
        return ShootFile::create(array_merge(['shoot_id' => $this->shoot->id, 'filename' => $name.'.jpg',
            'stored_filename' => $name.'.jpg', 'path' => $path, 'storage_path' => $path,
            'web_path' => $web, 'thumbnail_path' => $web, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 22, 'uploaded_by' => $this->client->id, 'media_type' => 'edited',
            'workflow_stage' => 'verified', 'scan_status' => 'clean', 'is_hidden' => false, 'is_cover' => false], $extra));
    }

    public static function actorRoles(): array
    {
        return ['owner client' => ['client'], 'assigned salesRep' => ['salesRep'], 'assigned sales_rep' => ['sales_rep'],
            'assigned representative' => ['representative'], 'secondary photographer representative' => ['secondary_photographer'],
            'secondary editor representative' => ['secondary_editor']];
    }

    private function actor(string $role): User
    {
        if ($role === 'client') return $this->client;
        if (str_starts_with($role, 'secondary_')) {
            $this->rep->forceFill(['role' => substr($role, strlen('secondary_')), 'secondary_roles' => ['sales_rep']])->save();
        } else {
            $this->rep->forceFill(['role' => $role])->save();
        }
        return $this->rep->fresh();
    }

    #[DataProvider('secondaryRoles')]
    public function test_rep_only_secondary_assignment_exposes_curation_without_production_file_access(string $primaryRole): void
    {
        $actor = $this->actor('secondary_'.$primaryRole);
        $this->shoot->forceFill(['photographer_id' => User::factory()->create(['role' => 'photographer'])->id,
            'editor_id' => User::factory()->create(['role' => 'editor'])->id])->save();
        $edited = $this->file();
        $raw = $this->file(['workflow_stage' => 'todo', 'media_type' => 'raw']);
        Sanctum::actingAs($actor);
        $endpoint = '/api/shoots/'.$this->shoot->id;
        $authorization = app(\App\Services\Shoots\ShootAuthorizationSupport::class);
        $this->assertTrue($authorization->scopeAccessibleShootMedia(Shoot::query(), $actor)->whereKey($this->shoot->id)->exists());
        $this->assertFalse($authorization->canInteractWithShootMediaFile($this->shoot->fresh(), $edited, $actor));
        $this->getJson($endpoint.'/files?type=edited&include_hidden=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($endpoint.'/files?type=raw&include_hidden=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($endpoint.'/media/'.$raw->id.'/download')->assertForbidden();
        $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$raw->id], 'hidden' => true])->assertUnprocessable();
        $this->postJson($endpoint.'/media/'.$edited->id.'/cover')->assertOk();
    }

    public static function secondaryRoles(): array
    {
        return ['photographer' => ['photographer'], 'editor' => ['editor']];
    }

    #[DataProvider('extraReaderRoles')]
    public function test_assigned_rep_can_read_clean_visible_extra_intake_without_gaining_curation_rights(string $role): void
    {
        $actor = $this->actor($role);
        $extra = app(\App\Services\ShootMediaStorageService::class)->uploadToExtra(
            $this->shoot, \Illuminate\Http\UploadedFile::fake()->image('extra.jpg'), $this->client->id
        );
        $extra->forceFill(['scan_status' => ShootFile::SCAN_STATUS_CLEAN])->save();
        $this->assertSame(ShootFile::STAGE_TODO, $extra->workflow_stage);
        $this->assertSame('extra', $extra->media_type);
        foreach ([['is_hidden' => true], ['scan_status' => 'quarantined'], ['scan_status' => 'infected'],
            ['media_type' => 'raw', 'is_extra' => true], ['filename' => 'capture.CR3']] as $attributes) {
            $this->file(array_merge(['media_type' => 'extra', 'workflow_stage' => 'todo'], $attributes));
        }
        $this->file(['media_type' => 'edited', 'workflow_stage' => 'todo']);
        $endpoint = '/api/shoots/'.$this->shoot->id;
        Sanctum::actingAs($actor);
        foreach (['', 'type=extra&'] as $query) {
            $response = $this->getJson($endpoint.'/files?'.$query.'include_hidden=1')->assertOk();
            $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $extra->id)
                ->assertJsonPath('data.0.can_manage_visibility', false)->assertJsonPath('data.0.can_set_hero', false)
                ->assertJsonPath('data.0.media_curation_scope', null);
        }
        $this->getJson($endpoint.'/files?type=edited&include_hidden=1')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$extra->id], 'hidden' => true])->assertUnprocessable();
        $this->postJson($endpoint.'/media/'.$extra->id.'/cover')->assertUnprocessable();
        $this->assertFalse($extra->fresh()->is_hidden);
        Sanctum::actingAs($this->client);
        $this->getJson($endpoint.'/files?type=extra&include_hidden=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public static function extraReaderRoles(): array
    {
        return ['assigned salesRep' => ['salesRep'], 'secondary photographer' => ['secondary_photographer'],
            'secondary editor' => ['secondary_editor']];
    }

    #[DataProvider('contractorScopes')]
    public function test_secondary_rep_preserves_only_actual_contractor_files_even_with_another_rep(string $role, bool $isShootRep): void
    {
        $actor = $this->actor('secondary_'.$role);
        $other = User::factory()->create(['role' => $role]);
        $this->shoot->forceFill([$role === 'photographer' ? 'photographer_id' : 'editor_id' => $actor->id,
            'rep_id' => $isShootRep ? $actor->id : User::factory()->create(['role' => 'salesRep'])->id])->save();
        $services = Service::factory()->count(2)->create();
        foreach ($services as $index => $service) {
            $this->shoot->services()->attach($service->id, ['photographer_id' => $role === 'photographer' ? ($index === 0 ? $actor->id : $other->id) : null,
                'editor_id' => $role === 'editor' ? ($index === 0 ? $actor->id : $other->id) : null]);
        }
        $items = $this->shoot->serviceItems()->orderBy('id')->get();
        $own = $this->file(['shoot_service_id' => $items[0]->id]);
        $unassigned = $this->file(['shoot_service_id' => $items[1]->id, 'is_hidden' => true]);
        $raw = $this->file(['shoot_service_id' => $items[0]->id, 'workflow_stage' => 'todo', 'media_type' => 'raw']);
        Sanctum::actingAs($actor);
        $endpoint = '/api/shoots/'.$this->shoot->id;
        $this->postJson($endpoint.'/media/'.$own->id.'/cover')->assertOk();
        $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$own->id], 'hidden' => true])->assertOk();
        $row = collect($this->getJson($endpoint.'/files?type=edited&include_hidden=1')->assertOk()->json('data'))->firstWhere('id', $own->id);
        $this->assertSame('contractor', $row['media_curation_scope']);
        $this->assertTrue($row['can_manage_visibility']);
        $this->assertStringNotContainsString('/curation-preview/', $row['thumbnail_url']);
        $this->assertSame($role === 'photographer', $row['can_download']);
        if ($role === 'photographer') $this->get($endpoint.'/media/'.$own->id.'/download')->assertOk();
        else $this->getJson($endpoint.'/media/'.$own->id.'/download')->assertForbidden();
        $this->assertSame([$raw->id], collect($this->getJson($endpoint.'/files?type=raw')->assertOk()->json('data'))->pluck('id')->all());
        $this->get($endpoint.'/media/'.$raw->id.'/download')->assertOk();
        if (! $isShootRep) {
            $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$unassigned->id], 'hidden' => false])->assertStatus(422);
            $this->assertTrue($unassigned->fresh()->is_hidden);
        }
        $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$own->id], 'hidden' => false])->assertOk();
    }

    public static function contractorScopes(): array
    {
        return ['photographer other rep' => ['photographer', false], 'editor other rep' => ['editor', false],
            'photographer also rep' => ['photographer', true], 'editor also rep' => ['editor', true]];
    }

    #[DataProvider('actorRoles')]
    public function test_owner_or_assigned_rep_can_hide_restore_and_set_hero(string $role): void
    {
        $actor = $this->actor($role);
        Sanctum::actingAs($actor);
        $file = $this->file();
        $endpoint = '/api/shoots/'.$this->shoot->id;
        $row = collect($this->getJson($endpoint.'/files?include_hidden=1')->assertOk()->json('data'))->firstWhere('id', $file->id);
        $this->assertTrue($row['can_manage_visibility']); $this->assertTrue($row['can_set_hero']);
        $this->postJson($endpoint.'/media/'.$file->id.'/cover')->assertOk();
        $this->assertTrue($file->fresh()->is_cover);
        $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => true])->assertOk();
        $this->assertTrue($file->fresh()->is_hidden); $this->assertFalse($file->fresh()->is_cover);
        $this->assertNull($this->shoot->fresh()->hero_image);
        $row = collect($this->getJson($endpoint.'/files?include_hidden=1')->assertOk()->json('data'))->firstWhere('id', $file->id);
        $this->assertNotNull($row); $this->assertTrue($row['can_manage_visibility']); $this->assertFalse($row['can_set_hero']);
        $this->assertNull($row['original_url']); $this->assertNull($row['path']);
        $this->assertStringContainsString('/curation-preview/', $row['thumbnail_url']);
        $this->getJson($endpoint.'/media/'.$file->id.'/download')->assertForbidden();
        $this->postJson($endpoint.'/media/'.$file->id.'/cover')->assertUnprocessable();
        $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => false])->assertOk();
        $this->assertFalse($file->fresh()->is_hidden);
        $this->postJson($endpoint.'/media/'.$file->id.'/cover')->assertOk();
    }

    public function test_hidden_owner_management_listing_does_not_publish_or_enable_downloads(): void
    {
        Sanctum::actingAs($this->client);
        $hidden = $this->file(['is_hidden' => true]); $visible = $this->file();
        $endpoint = '/api/shoots/'.$this->shoot->id;
        $this->assertSame([$visible->id], collect($this->getJson($endpoint.'/files')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson($endpoint.'/files?include_hidden=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($endpoint.'/media/'.$hidden->id.'/download')->assertForbidden();
        $archive = app(ShootMediaArchiveService::class)->getFilesForType($this->shoot->fresh(), 'edited');
        $this->assertFalse($archive->contains('id', $hidden->id));
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $response = $this->getJson('/api/public/shoots/'.$this->shoot->id.'/'.$variant)->assertOk()->json();
            $this->assertStringNotContainsString(basename($hidden->path), json_encode($response));
            $this->assertStringNotContainsString(basename($hidden->web_path), json_encode($response));
        }
        Sanctum::actingAs($this->rep);
        $this->getJson($endpoint.'/media/'.$hidden->id.'/download')->assertForbidden();
    }

    public function test_hiding_current_cover_cannot_reappear_as_public_client_profile_preview(): void
    {
        Sanctum::actingAs($this->client);
        $hero = $this->file(); $visible = $this->file();
        $this->file(['is_hidden' => false, 'media_type' => 'raw']);
        $this->file(['is_hidden' => false, 'scan_status' => 'infected']);
        $endpoint = '/api/shoots/'.$this->shoot->id;
        $this->postJson($endpoint.'/media/'.$hero->id.'/cover')->assertOk();
        $this->patchJson($endpoint.'/files/toggle-hidden', ['file_ids' => [$hero->id], 'hidden' => true])->assertOk();
        $profile = app(\App\Services\Shoots\ShootPublicAssetsService::class)->buildPublicClientProfilePayload($this->client);
        $preview = collect($profile['shoots'])->firstWhere('id', $this->shoot->id)['preview_image'];
        $this->assertStringNotContainsString(basename($hero->web_path), $preview);
        $this->assertStringContainsString(basename($visible->web_path), $preview);
    }

    #[DataProvider('ineligibleLegacyHeroes')]
    public function test_public_client_profile_ignores_known_ineligible_stored_hero_without_mutating_selection(array $extra): void
    {
        $legacy = $this->file($extra); $visible = $this->file();
        $stored = 'https://reprodashboard.com/storage/'.$legacy->web_path;
        $this->shoot->update(['hero_image' => $stored]);
        $profile = app(\App\Services\Shoots\ShootPublicAssetsService::class)->buildPublicClientProfilePayload($this->client);
        $preview = collect($profile['shoots'])->firstWhere('id', $this->shoot->id)['preview_image'];
        $this->assertStringContainsString(basename($visible->web_path), $preview);
        $this->assertSame($stored, $this->shoot->fresh()->hero_image);
    }

    public static function ineligibleLegacyHeroes(): array
    {
        return ['hidden' => [['is_hidden' => true]], 'unsafe' => [['scan_status' => 'infected']], 'raw' => [['media_type' => 'raw']]];
    }

    public function test_public_client_profile_preserves_unmatched_manual_hero_even_with_same_basename(): void
    {
        $hidden = $this->file(['is_hidden' => true]);
        $manual = 'https://media.example.test/manual/'.basename($hidden->web_path);
        $this->shoot->update(['hero_image' => $manual]);
        $profile = app(\App\Services\Shoots\ShootPublicAssetsService::class)->buildPublicClientProfilePayload($this->client);
        $this->assertSame($manual, collect($profile['shoots'])->firstWhere('id', $this->shoot->id)['preview_image']);
        $this->assertSame($manual, $this->shoot->fresh()->hero_image);
    }

    public function test_unrelated_reps_clients_and_linked_readers_cannot_curate(): void
    {
        $file = $this->file(['is_hidden' => true]);
        $client = User::factory()->create(['role' => 'client']);
        $rep = User::factory()->create(['role' => 'salesRep']);
        foreach ([$client, $rep] as $actor) {
            Sanctum::actingAs($actor);
            $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => false])->assertForbidden();
            $this->postJson('/api/shoots/'.$this->shoot->id.'/media/'.$file->id.'/cover')->assertForbidden();
        }
        AccountLink::create(['main_account_id' => $client->id, 'linked_account_id' => $this->client->id,
            'status' => 'active', 'shared_details' => ['shoots' => true], 'created_by' => $client->id]);
        Sanctum::actingAs($client);
        $this->getJson('/api/shoots/'.$this->shoot->id.'/files?include_hidden=1')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => false])->assertForbidden();
        $this->assertTrue($file->fresh()->is_hidden);
    }

    public static function unsafeFiles(): array
    {
        return ['raw JPEG' => [['media_type' => 'raw']], 'RAW extension' => [['filename' => 'capture.CR3']],
            'unfinished' => [['workflow_stage' => 'todo']], 'quarantined' => [['scan_status' => 'quarantined']],
            'infected' => [['scan_status' => 'infected']], 'failed' => [['scan_status' => 'failed']]];
    }

    #[DataProvider('unsafeFiles')]
    public function test_customer_curators_cannot_hide_restore_or_set_hero_on_raw_or_unsafe_files(array $extra): void
    {
        $file = $this->file($extra);
        foreach ([$this->client, $this->rep] as $actor) {
            Sanctum::actingAs($actor);
            $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => true])->assertUnprocessable();
            $this->postJson('/api/shoots/'.$this->shoot->id.'/media/'.$file->id.'/cover')->assertUnprocessable();
        }
        $this->assertFalse($file->fresh()->is_hidden); $this->assertFalse($file->fresh()->is_cover);
        $file->update(['is_hidden' => true]);
        $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => false])->assertUnprocessable();
        $this->assertTrue($file->fresh()->is_hidden);
    }

    public function test_hidden_management_listing_excludes_hidden_raw_and_quarantined_files(): void
    {
        Sanctum::actingAs($this->client);
        $safe = $this->file(['is_hidden' => true]);
        $this->file(['is_hidden' => true, 'media_type' => 'raw']);
        $this->file(['is_hidden' => true, 'scan_status' => 'quarantined']);
        $this->file(['is_hidden' => true, 'workflow_stage' => 'todo']);
        $this->getJson('/api/shoots/'.$this->shoot->id.'/files?include_hidden=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $safe->id);
    }

    public function test_hidden_floorplans_and_videos_can_be_restored_but_cannot_be_hero(): void
    {
        $floor = $this->file(['media_type' => 'floorplan']);
        $video = $this->file(['media_type' => 'video', 'filename' => 'tour.mp4', 'file_type' => 'video/mp4']);
        foreach ([$this->client, $this->rep] as $actor) {
            Sanctum::actingAs($actor);
            foreach ([$floor, $video] as $file) {
                $this->postJson('/api/shoots/'.$this->shoot->id.'/media/'.$file->id.'/cover')->assertUnprocessable();
                $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => true])->assertOk();
                $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => false])->assertOk();
            }
        }
    }

    public function test_signed_curation_preview_serves_only_derived_bytes_and_rechecks_assignment(): void
    {
        Sanctum::actingAs($this->rep);
        $file = $this->file(['is_hidden' => true]);
        $row = collect($this->getJson('/api/shoots/'.$this->shoot->id.'/files?include_hidden=1')->assertOk()->json('data'))->firstWhere('id', $file->id);
        $url = $row['thumbnail_url'];
        $response = $this->get($url)->assertOk();
        $this->assertPreviewBytes('derived-preview', $response);
        $this->get($url.'&viewer=123456')->assertForbidden();
        $this->shoot->rep_id = User::factory()->create(['role' => 'salesRep'])->id; $this->shoot->save();
        $this->get($url)->assertForbidden();
    }

    public function test_client_unpaid_curation_response_and_preview_keep_originals_locked(): void
    {
        $this->shoot->update(['payment_status' => 'unpaid', 'bypass_paywall' => false]);
        Sanctum::actingAs($this->client);
        $file = $this->file();
        $watermark = 'shoots/'.$this->shoot->id.'/watermarked/photo.jpg'; Storage::disk('public')->put($watermark, 'watermarked-preview');
        $file->watermarked_web_path = $watermark; $file->watermarked_storage_path = $watermark; $file->save();
        $cover = $this->postJson('/api/shoots/'.$this->shoot->id.'/media/'.$file->id.'/cover')->assertOk()->json();
        $this->assertSame(['id' => $file->id, 'is_cover' => true], $cover['file']);
        $this->assertStringNotContainsString(basename($file->path), $cover['hero_image']);
        $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$file->id], 'hidden' => true])->assertOk();
        $row = $this->getJson('/api/shoots/'.$this->shoot->id.'/files?include_hidden=1')->assertOk()->json('data.0');
        $this->assertNull($row['original_url']);
        $this->assertNull($row['watermarked_storage_path']);
        $response = $this->get($row['thumbnail_url'])->assertOk();
        $this->assertPreviewBytes('watermarked-preview', $response);
        $this->getJson('/api/shoots/'.$this->shoot->id.'/media/'.$file->id.'/download')->assertForbidden();
    }

    public function test_curation_preview_rejects_original_reused_as_preview_and_unsafe_file(): void
    {
        Sanctum::actingAs($this->client);
        $file = $this->file(['is_hidden' => true]);
        $row = $this->getJson('/api/shoots/'.$this->shoot->id.'/files?include_hidden=1')->assertOk()->json('data.0');
        $file->update(['web_path' => $file->path, 'thumbnail_path' => $file->path]);
        $this->get($row['thumbnail_url'])->assertStatus(409);
        $file->update(['scan_status' => 'quarantined']);
        $this->get($row['thumbnail_url'])->assertForbidden();
    }

    public function test_mixed_bulk_request_cannot_change_another_shoot_and_staff_controls_remain(): void
    {
        $own = $this->file(); $other = $this->file(['shoot_id' => Shoot::factory()->create()->id]);
        Sanctum::actingAs($this->client);
        $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$own->id, $other->id], 'hidden' => true])->assertUnprocessable();
        $this->assertFalse($own->fresh()->is_hidden); $this->assertFalse($other->fresh()->is_hidden);
        Sanctum::actingAs(User::factory()->admin()->create(['secondary_roles' => ['sales_rep']]));
        $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$own->id], 'hidden' => true])->assertOk();
        $this->patchJson('/api/shoots/'.$this->shoot->id.'/files/toggle-hidden', ['file_ids' => [$own->id], 'hidden' => false])->assertOk();
        $this->postJson('/api/shoots/'.$this->shoot->id.'/media/'.$own->id.'/cover')->assertOk();
    }

    public function test_atomic_hero_selection_rechecks_stale_hidden_file_and_keeps_one_cover(): void
    {
        Sanctum::actingAs($this->client);
        $first = $this->file(); $second = $this->file();
        app(AssignHeroMediaAction::class)->execute($this->shoot, $first, $this->client);
        app(AssignHeroMediaAction::class)->execute($this->shoot, $second, $this->client);
        $this->assertSame([$second->id], $this->shoot->files()->where('is_cover', true)->pluck('id')->all());
        ShootFile::whereKey($first->id)->update(['is_hidden' => true]);
        try {
            app(AssignHeroMediaAction::class)->execute($this->shoot, $first, $this->client);
            $this->fail('A stale hidden file cannot be selected as hero.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertSame([$second->id], $this->shoot->files()->where('is_cover', true)->pluck('id')->all());
        $this->assertSame($second->web_path, $this->shoot->fresh()->hero_image);
    }

    private function assertPreviewBytes(string $expected, \Illuminate\Testing\TestResponse $response): void
    {
        $body = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? file_get_contents($response->baseResponse->getFile()->getPathname()) : $response->streamedContent();
        $this->assertSame($expected, $body);
    }
}
