<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\Shoots\{ShootAuthorizationSupport, ShootGuestOverview, ShootMediaArchiveService, ShootShareLinkService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Staff "Hidden" marking keeps extra photos away from every client surface. */
class ClientHiddenMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $rep;
    private User $admin;
    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();
        // Model events stay live: the saving hook keeps client-hidden media tour-hidden.
        Storage::fake('public'); Storage::fake('local'); Queue::fake(); Cache::flush(); Http::preventStrayRequests();
        $this->client = User::factory()->create(['role' => 'client']);
        $this->rep = User::factory()->create(['role' => 'salesRep']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'rep_id' => $this->rep->id,
            'status' => 'delivered', 'workflow_status' => 'delivered', 'payment_status' => 'paid', 'total_quote' => 100, 'hero_image' => null]);
    }

    private function file(array $extra = []): ShootFile
    {
        $name = 'photo-'.bin2hex(random_bytes(3));
        $path = 'shoots/'.$this->shoot->id.'/completed/'.$name.'.jpg';
        $web = 'shoots/'.$this->shoot->id.'/web/'.$name.'_web.jpg';
        Storage::disk('public')->put($path, 'original-image');
        Storage::disk('public')->put($web, 'derived-preview');

        return ShootFile::create(array_merge(['shoot_id' => $this->shoot->id, 'filename' => $name.'.jpg',
            'stored_filename' => $name.'.jpg', 'path' => $path, 'storage_path' => $path,
            'web_path' => $web, 'thumbnail_path' => $web, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 14, 'uploaded_by' => $this->admin->id, 'media_type' => 'edited',
            'workflow_stage' => 'verified', 'scan_status' => 'clean', 'is_hidden' => false, 'is_cover' => false], $extra));
    }

    private function endpoint(string $suffix = ''): string
    {
        return '/api/shoots/'.$this->shoot->id.$suffix;
    }

    private function hide(array $ids, bool $hidden = true)
    {
        return $this->patchJson($this->endpoint('/files/client-visibility'), ['file_ids' => $ids, 'hidden' => $hidden]);
    }

    #[DataProvider('staffRoles')]
    public function test_admins_superadmins_and_editing_managers_can_hide_and_restore(string $role): void
    {
        $file = $this->file();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->hide([$file->id])->assertOk()->assertJsonPath('updated_count', 1)->assertJsonPath('hidden_from_client', true);
        $fresh = $file->fresh();
        $this->assertTrue($fresh->is_client_hidden);
        $this->assertTrue($fresh->is_hidden, 'Client-hidden media is always tour-hidden too.');
        $this->assertNotNull($fresh->client_hidden_at);

        $this->hide([$file->id], false)->assertOk()->assertJsonPath('updated_count', 1);
        $this->assertFalse($file->fresh()->is_client_hidden);
        $this->assertFalse($file->fresh()->is_hidden);
    }

    public static function staffRoles(): array
    {
        return ['admin' => ['admin'], 'superadmin' => ['superadmin'], 'editing manager' => ['editing_manager']];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_hide_or_restore(string $role): void
    {
        $file = $this->file();
        $actor = match ($role) { 'client' => $this->client, 'salesRep' => $this->rep, default => User::factory()->create(['role' => $role]) };
        if ($role === 'photographer') $this->shoot->forceFill(['photographer_id' => $actor->id])->save();
        if ($role === 'editor') $this->shoot->forceFill(['editor_id' => $actor->id])->save();
        Sanctum::actingAs($actor);
        $this->hide([$file->id])->assertForbidden();
        $this->assertFalse($file->fresh()->is_client_hidden);

        $hidden = $this->file(['is_client_hidden' => true]);
        $this->hide([$hidden->id], false)->assertForbidden();
        $this->assertTrue($hidden->fresh()->is_client_hidden);
    }

    public static function deniedRoles(): array
    {
        return ['client' => ['client'], 'sales rep' => ['salesRep'], 'photographer' => ['photographer'], 'editor' => ['editor']];
    }

    public function test_only_edited_media_of_this_shoot_can_be_marked(): void
    {
        Sanctum::actingAs($this->admin);
        $raw = $this->file(['workflow_stage' => 'todo', 'media_type' => 'raw']);
        $this->hide([$raw->id])->assertUnprocessable();
        $this->assertFalse($raw->fresh()->is_hidden, 'A raw must stay in the editing queue.');
        $own = $this->file(); $other = $this->file(['shoot_id' => Shoot::factory()->create()->id]);
        $this->hide([$own->id, $other->id])->assertUnprocessable();
        $this->assertFalse($own->fresh()->is_client_hidden); $this->assertFalse($other->fresh()->is_client_hidden);
    }

    #[DataProvider('clientSideRoles')]
    public function test_client_side_gallery_and_curation_view_never_include_hidden_media(string $role): void
    {
        $visible = $this->file(); $hidden = $this->file(['is_client_hidden' => true]);
        Sanctum::actingAs($role === 'client' ? $this->client : $this->rep);
        foreach (['/files?type=edited', '/files?type=edited&include_hidden=1', '/files?include_hidden=1', '/media?type=edited'] as $suffix) {
            $ids = collect($this->getJson($this->endpoint($suffix))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$visible->id], $ids, $suffix);
        }
        $this->getJson($this->endpoint('/files/'.$hidden->id.'/preview'))->assertForbidden();
        $this->postJson($this->endpoint('/media/'.$hidden->id.'/cover'))->assertUnprocessable();
        // Clients cannot "restore" it through their own curation toggle.
        $this->patchJson($this->endpoint('/files/toggle-hidden'), ['file_ids' => [$hidden->id], 'hidden' => false])->assertUnprocessable();
        $this->assertTrue($hidden->fresh()->is_client_hidden);
        $this->assertTrue($hidden->fresh()->is_hidden);
    }

    public static function clientSideRoles(): array
    {
        return ['owner client' => ['client'], 'assigned rep' => ['salesRep']];
    }

    #[DataProvider('clientSideRoles')]
    public function test_client_side_downloads_are_refused(string $role): void
    {
        $visible = $this->file(); $hidden = $this->file(['is_client_hidden' => true]);
        Sanctum::actingAs($role === 'client' ? $this->client : $this->rep);
        $this->getJson($this->endpoint('/media/'.$hidden->id.'/download'))->assertForbidden();
        $this->postJson($this->endpoint('/files/download'), ['file_ids' => [$hidden->id]])->assertForbidden();
        $this->postJson($this->endpoint('/files/download'), ['file_ids' => [$visible->id, $hidden->id]])->assertForbidden();
    }

    public function test_full_and_scoped_archives_share_links_guest_shares_and_public_pages_exclude_hidden_media(): void
    {
        $item = ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => Service::factory()->create()->id,
            'quantity' => 1, 'price' => 100]);
        $visible = $this->file(['shoot_service_id' => $item->id]);
        $hidden = $this->file(['shoot_service_id' => $item->id]);
        Sanctum::actingAs($this->admin);
        $this->hide([$hidden->id])->assertOk();
        $shoot = $this->shoot->fresh();

        $archive = app(ShootMediaArchiveService::class);
        foreach ([$archive->getFilesForType($shoot, 'edited'), $archive->getFilesForType($shoot, 'edited', $item->id),
            app(ShootShareLinkService::class)->selectEditorShareFiles($shoot, 'edited'),
            app(ShootGuestOverview::class)->files($shoot)] as $files) {
            $this->assertFalse($files->contains('id', $hidden->id));
        }
        $this->assertTrue($archive->getFilesForType($shoot, 'edited')->contains('id', $visible->id));
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $body = json_encode($this->getJson('/api/public/shoots/'.$this->shoot->id.'/'.$variant)->assertOk()->json());
            $this->assertStringNotContainsString(pathinfo($hidden->filename, PATHINFO_FILENAME), $body);
        }
    }

    public function test_admin_still_sees_hidden_media_marked_and_can_restore_it(): void
    {
        $file = $this->file();
        Sanctum::actingAs($this->admin);
        $this->hide([$file->id])->assertOk();
        $row = collect($this->getJson($this->endpoint('/files?type=edited'))->assertOk()->json('data'))->firstWhere('id', $file->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['is_client_hidden']);
        $this->assertTrue($row['isClientHidden']);
        $this->assertTrue($row['is_hidden']);
        // The tour-visibility toggle cannot silently reveal it.
        $this->patchJson($this->endpoint('/files/toggle-hidden'), ['file_ids' => [$file->id], 'hidden' => false])->assertUnprocessable();
        $this->assertTrue($file->fresh()->is_client_hidden);

        $this->hide([$file->id], false)->assertOk();
        Sanctum::actingAs($this->client);
        $ids = collect($this->getJson($this->endpoint('/files?type=edited'))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$file->id], $ids);
    }

    public function test_restoring_returns_the_clients_own_curation_choice(): void
    {
        $curated = $this->file(['is_hidden' => true]);
        Sanctum::actingAs($this->admin);
        $this->hide([$curated->id])->assertOk();
        $this->hide([$curated->id], false)->assertOk();
        $fresh = $curated->fresh();
        $this->assertFalse($fresh->is_client_hidden);
        $this->assertTrue($fresh->is_hidden, 'The client hid it from tours before staff did.');
        $this->assertArrayNotHasKey('client_hidden_restore_is_hidden', $fresh->metadata ?? []);
    }

    public function test_hiding_releases_cover_and_hero(): void
    {
        $file = $this->file(['is_cover' => true]);
        $this->shoot->forceFill(['hero_image' => $file->web_path])->save();
        Sanctum::actingAs($this->admin);
        $this->hide([$file->id])->assertOk();
        $this->assertFalse($file->fresh()->is_cover);
        $this->assertNull($this->shoot->fresh()->hero_image);
    }

    public function test_other_writers_cannot_clear_tour_hiding_while_client_hidden(): void
    {
        $file = $this->file(['is_client_hidden' => true]);
        $file->forceFill(['is_hidden' => false])->save();
        $this->assertTrue($file->fresh()->is_hidden);
    }

    public function test_client_side_delivered_counts_exclude_hidden_media(): void
    {
        $this->file(); $this->file(); $hidden = $this->file();
        $this->shoot->updatePhotoCounts();
        $this->assertSame(3, (int) $this->shoot->fresh()->edited_photo_count);
        Sanctum::actingAs($this->admin);
        $this->hide([$hidden->id])->assertOk();
        $this->assertSame(3, (int) $this->shoot->fresh()->edited_photo_count, 'The stored operational counter is unchanged.');

        $access = app(ShootAuthorizationSupport::class);
        $this->assertSame(2, $access->clientVisibleEditedPhotoCount($this->shoot->fresh(), $this->client));
        $this->assertSame(2, $access->clientVisibleEditedPhotoCount($this->shoot->fresh(), $this->rep));
        $this->assertNull($access->clientVisibleEditedPhotoCount($this->shoot->fresh(), $this->admin));

        $this->assertSame(3, $this->getJson($this->endpoint('/media?type=edited'))->assertOk()->json('counts.edited_photo_count'));
        Sanctum::actingAs($this->client);
        $this->assertSame(2, $this->getJson($this->endpoint('/media?type=edited'))->assertOk()->json('counts.edited_photo_count'));
        Sanctum::actingAs($this->rep);
        $this->assertSame(2, $this->getJson($this->endpoint('/media?type=edited'))->assertOk()->json('counts.edited_photo_count'));
    }
}
