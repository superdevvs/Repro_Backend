<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Jobs\GenerateShootMediaArchiveJob;
use App\Jobs\ScanShootFileJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\BrightMlsService;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\DeliveryFilenameFormatter;
use App\Services\Shoots\DeliveryMediaOrderService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootMediaInteractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Delivery ordering: what the admin arranges is what the client receives.
 *
 * Covers the whole chain — the sort_order semantics, the position-prefixed names
 * that make the order survive extraction, the finalize snapshot that keeps the
 * async delivery jobs in agreement, and the cache invalidation that stops a
 * re-sorted shoot from serving a stale ZIP.
 */
class ShootDeliveryMediaOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected User $editor;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([GenerateShootMediaArchiveJob::class, ScanShootFileJob::class]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->photographer = User::factory()->create(['role' => 'photographer']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        $this->service = Service::factory()->create(['name' => 'Delivery Order Service']);
    }

    // ---------------------------------------------------------------- ordering

    #[Test]
    public function positive_sort_orders_lead_and_unassigned_files_trail_deterministically(): void
    {
        $shoot = $this->createShoot();

        // Deliberately created out of order, and with the unplaced files mixed
        // in between the placed ones, so a naive `orderBy('sort_order')` would
        // float the zeros to the very front.
        $unplacedB = $this->createShootFile($shoot, ['filename' => 'unplaced-b.jpg', 'sort_order' => 0]);
        $second = $this->createShootFile($shoot, ['filename' => 'second.jpg', 'sort_order' => 2]);
        $unplacedA = $this->createShootFile($shoot, ['filename' => 'unplaced-a.jpg', 'sort_order' => 0]);
        $first = $this->createShootFile($shoot, ['filename' => 'first.jpg', 'sort_order' => 1]);
        $third = $this->createShootFile($shoot, ['filename' => 'third.jpg', 'sort_order' => 3]);

        $ordered = ShootFile::query()->where('shoot_id', $shoot->id)->inDeliveryOrder()->pluck('id')->all();

        $this->assertSame(
            [$first->id, $second->id, $third->id, $unplacedB->id, $unplacedA->id],
            array_map('intval', $ordered),
            'Curated positions must lead; unplaced files trail in id order.'
        );
    }

    #[Test]
    public function the_in_memory_sorter_matches_the_query_scope(): void
    {
        $shoot = $this->createShoot();

        $unplaced = $this->createShootFile($shoot, ['filename' => 'unplaced.jpg', 'sort_order' => 0]);
        $second = $this->createShootFile($shoot, ['filename' => 'second.jpg', 'sort_order' => 2]);
        $first = $this->createShootFile($shoot, ['filename' => 'first.jpg', 'sort_order' => 1]);

        // An eager-loaded relation cannot be re-queried, so the collection sorter
        // has to reproduce the scope exactly or the two paths would disagree.
        $sorted = ShootFile::sortCollectionInDeliveryOrder(
            ShootFile::query()->where('shoot_id', $shoot->id)->orderByDesc('id')->get()
        );

        $this->assertSame(
            [$first->id, $second->id, $unplaced->id],
            $sorted->pluck('id')->map('intval')->all()
        );
    }

    #[Test]
    public function new_uploads_append_to_the_end_of_an_existing_delivery_order(): void
    {
        $shoot = $this->createShoot();

        $a = $this->createShootFile($shoot, ['filename' => 'a.jpg', 'sort_order' => 1]);
        $b = $this->createShootFile($shoot, ['filename' => 'b.jpg', 'sort_order' => 2]);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/shoots/{$shoot->id}/files/reorder", [
            'file_ids' => [$b->id, $a->id],
        ])->assertOk();

        // A new upload does not pass sort_order, so the observer appends it.
        $late = ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'late-arrival.jpg',
            'stored_filename' => 'late-arrival.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/late-arrival.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ]);

        $this->assertSame(3, (int) $late->fresh()->sort_order, 'Should take max(sort_order) + 1.');
        $this->assertSame(
            [$b->id, $a->id, $late->id],
            array_map('intval', ShootFile::query()->where('shoot_id', $shoot->id)->inDeliveryOrder()->pluck('id')->all()),
            'A late upload must land after the curated block, never in front of it.'
        );
    }

    #[Test]
    public function an_explicit_sort_order_on_create_is_respected(): void
    {
        $shoot = $this->createShoot();
        $this->createShootFile($shoot, ['filename' => 'placed.jpg', 'sort_order' => 5]);

        // Callers that pin positions (seeders, imports, fixtures) must not be
        // silently overridden — including an explicit 0 meaning "unplaced".
        $pinned = $this->createShootFile($shoot, ['filename' => 'pinned.jpg', 'sort_order' => 0]);

        $this->assertSame(0, (int) $pinned->fresh()->sort_order);
    }

    #[Test]
    public function repeated_reorders_stay_consistent_and_bump_the_order_version(): void
    {
        $shoot = $this->createShoot();

        $a = $this->createShootFile($shoot, ['filename' => 'a.jpg']);
        $b = $this->createShootFile($shoot, ['filename' => 'b.jpg']);
        $c = $this->createShootFile($shoot, ['filename' => 'c.jpg']);

        $interaction = app(ShootMediaInteractionService::class);
        $orderService = app(DeliveryMediaOrderService::class);

        $startingVersion = $orderService->currentVersion($shoot->fresh());

        $interaction->reorderFiles($shoot->fresh(), [$c->id, $b->id, $a->id]);
        $this->assertSame([1, 2, 3], [
            (int) $c->fresh()->sort_order,
            (int) $b->fresh()->sort_order,
            (int) $a->fresh()->sort_order,
        ]);

        // Reordering again must fully replace the previous arrangement rather
        // than layering on top of it and leaving duplicate positions behind.
        $interaction->reorderFiles($shoot->fresh(), [$b->id, $a->id, $c->id]);
        $this->assertSame([1, 2, 3], [
            (int) $b->fresh()->sort_order,
            (int) $a->fresh()->sort_order,
            (int) $c->fresh()->sort_order,
        ]);

        $this->assertSame(
            [$b->id, $a->id, $c->id],
            array_map('intval', ShootFile::query()->where('shoot_id', $shoot->id)->inDeliveryOrder()->pluck('id')->all())
        );

        $this->assertSame(
            $startingVersion + 2,
            $orderService->currentVersion($shoot->fresh()),
            'Each saved reorder must bump the version so cached artifacts invalidate.'
        );
    }

    // ------------------------------------------------------------ zip naming

    #[Test]
    public function archive_entry_names_are_zero_padded_in_delivery_order(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $files = [];
        foreach (['alpha', 'bravo', 'charlie'] as $index => $name) {
            $path = "shoots/{$shoot->id}/completed/{$name}.jpg";
            Storage::disk('public')->put($path, "{$name}-bytes");
            $files[$name] = $this->createShootFile($shoot, [
                'filename' => "{$name}.jpg",
                'stored_filename' => "{$name}.jpg",
                'path' => $path,
                'storage_path' => $path,
                'sort_order' => $index + 1,
            ]);
        }

        // Curate a deliberately non-alphabetical order — the point of the prefix
        // is that this order survives even though the names sort differently.
        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [
            $files['charlie']->id,
            $files['alpha']->id,
            $files['bravo']->id,
        ]);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');

        $this->assertSame([
            '001_charlie.jpg' => 'charlie-bytes',
            '002_alpha.jpg' => 'alpha-bytes',
            '003_bravo.jpg' => 'bravo-bytes',
        ], $this->readArchive($archiveService, $shoot, 'edited', 'original'));
    }

    #[Test]
    public function archive_entry_names_keep_the_master_filenames_untouched(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $path = "shoots/{$shoot->id}/completed/master.jpg";
        Storage::disk('public')->put($path, 'master-bytes');
        $file = $this->createShootFile($shoot, [
            'filename' => 'master.jpg',
            'stored_filename' => 'master.jpg',
            'path' => $path,
            'storage_path' => $path,
            'sort_order' => 1,
        ]);

        app(ShootMediaArchiveService::class)->generateArchive($shoot->fresh(), 'edited', 'original');

        $file->refresh();
        $this->assertSame('master.jpg', $file->filename, 'The stored master filename must never be renamed.');
        $this->assertSame('master.jpg', $file->stored_filename);
        $this->assertSame($path, $file->path);
        $this->assertSame($path, $file->storage_path);
        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function padding_width_never_drops_below_three_digits_and_grows_with_the_set(): void
    {
        $formatter = app(DeliveryFilenameFormatter::class);

        $this->assertSame(3, $formatter->width(1));
        $this->assertSame(3, $formatter->width(999));
        $this->assertSame(4, $formatter->width(1000));

        $this->assertSame('001_front.jpg', $formatter->format(1, 8, 'front.jpg'));
        $this->assertSame('012_front.jpg', $formatter->format(12, 120, 'front.jpg'));
        $this->assertSame('0007_front.jpg', $formatter->format(7, 1200, 'front.jpg'));
    }

    #[Test]
    public function duplicate_master_filenames_do_not_collapse_into_one_archive_entry(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        // Same client-facing filename stored under two distinct paths: ZipArchive
        // would silently keep only one entry if both resolved to the same name.
        foreach ([['a', 1], ['b', 2]] as [$dir, $order]) {
            $path = "shoots/{$shoot->id}/completed/{$dir}/dupe.jpg";
            Storage::disk('public')->put($path, "{$dir}-bytes");
            $this->createShootFile($shoot, [
                'filename' => 'dupe.jpg',
                'stored_filename' => 'dupe.jpg',
                'path' => $path,
                'storage_path' => $path,
                'sort_order' => $order,
            ]);
        }

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');

        $entries = $this->readArchive($archiveService, $shoot, 'edited', 'original');

        $this->assertCount(2, $entries);
        $this->assertSame(['001_dupe.jpg', '002_dupe.jpg'], array_keys($entries));
    }

    // ------------------------------------------------------- cache behaviour

    #[Test]
    public function reordering_invalidates_a_cached_archive_and_rebuilds_it_in_the_new_order(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $first = $this->putFile($shoot, 'one', 1);
        $second = $this->putFile($shoot, 'two', 2);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');

        $this->assertSame(
            ['001_one.jpg', '002_two.jpg'],
            array_keys($this->readArchive($archiveService, $shoot, 'edited', 'original'))
        );
        $this->assertTrue($archiveService->hasFreshArchive($shoot->fresh(), 'edited', 'original'));

        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [$second->id, $first->id]);

        // Before positions were part of the signature a pure reorder hashed
        // identically, so the cached ZIP was considered fresh and the client kept
        // downloading the old sequence.
        $this->assertFalse(
            $archiveService->hasFreshArchive($shoot->fresh(), 'edited', 'original'),
            'A reorder must mark the cached archive stale.'
        );

        $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');

        $this->assertSame(
            ['001_two.jpg', '002_one.jpg'],
            array_keys($this->readArchive($archiveService, $shoot, 'edited', 'original'))
        );
    }

    #[Test]
    public function regenerating_without_changes_is_idempotent(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $this->putFile($shoot, 'one', 1);
        $this->putFile($shoot, 'two', 2);

        $archiveService = app(ShootMediaArchiveService::class);
        $firstManifest = $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');
        $secondManifest = $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');

        $this->assertSame(
            $firstManifest['source_signature'],
            $secondManifest['source_signature'],
            'A stable order must produce a stable signature so the cached ZIP is reused.'
        );
        $this->assertSame($firstManifest['generated_at'], $secondManifest['generated_at']);
        $this->assertSame(
            ['001_one.jpg', '002_two.jpg'],
            array_keys($this->readArchive($archiveService, $shoot, 'edited', 'original'))
        );
    }

    // ---------------------------------------------------- selected downloads

    #[Test]
    public function selected_file_downloads_are_numbered_in_delivery_order(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot(['payment_status' => 'paid', 'bypass_paywall' => true]);
        $one = $this->putFile($shoot, 'one', 1);
        $two = $this->putFile($shoot, 'two', 2);
        $three = $this->putFile($shoot, 'three', 3);

        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [
            $three->id,
            $two->id,
            $one->id,
        ]);

        Sanctum::actingAs($this->admin);

        // Ids handed over in an arbitrary order: the response must re-sequence
        // them, and number only the selected subset (1..n, no gaps).
        $response = $this->post("/api/shoots/{$shoot->id}/files/download", [
            'file_ids' => [$one->id, $three->id],
            'size' => 'original',
        ]);

        $response->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'sel_') . '.zip';
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($zipPath);

        $this->assertSame(['001_three.jpg', '002_one.jpg'], $names);
    }

    // ------------------------------------------------------------- snapshot

    #[Test]
    public function finalizing_snapshots_the_ordered_media_ids(): void
    {
        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);

        $one = $this->createShootFile($shoot, ['filename' => 'one.jpg', 'sort_order' => 1]);
        $two = $this->createShootFile($shoot, ['filename' => 'two.jpg', 'sort_order' => 2]);
        $three = $this->createShootFile($shoot, ['filename' => 'three.jpg', 'sort_order' => 3]);

        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [
            $two->id,
            $three->id,
            $one->id,
        ]);

        $this->runFinalize($shoot);

        $this->assertSame(
            [$two->id, $three->id, $one->id],
            app(DeliveryMediaOrderService::class)->snapshotIds($shoot->fresh()),
            'Finalize must freeze the order every delivery job then replays.'
        );
    }

    #[Test]
    public function a_reorder_after_finalizing_refreshes_the_snapshot(): void
    {
        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);

        $one = $this->createShootFile($shoot, ['filename' => 'one.jpg', 'sort_order' => 1]);
        $two = $this->createShootFile($shoot, ['filename' => 'two.jpg', 'sort_order' => 2]);

        $this->runFinalize($shoot);
        $orderService = app(DeliveryMediaOrderService::class);
        $this->assertSame([$one->id, $two->id], $orderService->snapshotIds($shoot->fresh()));

        // This models a reorder racing the finalize fan-out: the shoot row lock
        // serialises it after the snapshot, so it must fold itself in or the
        // change would never reach the client.
        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [$two->id, $one->id]);

        $this->assertSame(
            [$two->id, $one->id],
            $orderService->snapshotIds($shoot->fresh()),
            'A post-finalize reorder must refresh the snapshot, not be ignored.'
        );
    }

    #[Test]
    public function an_unfinalized_shoot_has_no_snapshot_and_falls_back_to_live_order(): void
    {
        $shoot = $this->createShoot();
        $one = $this->createShootFile($shoot, ['filename' => 'one.jpg', 'sort_order' => 1]);
        $two = $this->createShootFile($shoot, ['filename' => 'two.jpg', 'sort_order' => 2]);

        $orderService = app(DeliveryMediaOrderService::class);
        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [$two->id, $one->id]);

        $this->assertNull(
            $orderService->snapshotIds($shoot->fresh()),
            'No snapshot should be created before delivery.'
        );
        $this->assertSame(
            [$two->id, $one->id],
            $orderService->applyTo($shoot->fresh(), ShootFile::query()->where('shoot_id', $shoot->id)->get())
                ->pluck('id')->map('intval')->all()
        );
    }

    #[Test]
    public function the_snapshot_drives_archive_order_and_late_uploads_trail_it(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);
        $one = $this->putFile($shoot, 'one', 1);
        $two = $this->putFile($shoot, 'two', 2);

        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [$two->id, $one->id]);
        $this->runFinalize($shoot);

        // Uploaded after the snapshot: must still be delivered, but at the end.
        $late = $this->putFile($shoot, 'late', null);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');

        $this->assertSame(
            ['001_two.jpg', '002_one.jpg', '003_late.jpg'],
            array_keys($this->readArchive($archiveService, $shoot, 'edited', 'original'))
        );
    }

    // ------------------------------------------------------ direct downloads

    #[Test]
    public function a_single_direct_download_is_named_after_its_delivery_position(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot(['bypass_paywall' => true]);
        $one = $this->putFile($shoot, 'one', 1);
        $two = $this->putFile($shoot, 'two', 2);
        $three = $this->putFile($shoot, 'three', 3);

        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [
            $three->id,
            $one->id,
            $two->id,
        ]);

        $response = app(\App\Services\Shoots\Actions\DownloadShootMediaAction::class)
            ->downloadResponse($one->fresh());

        // `one.jpg` sits at position 2 of the curated order, so a one-off download
        // drops into the same slot as it would inside the full-set ZIP.
        $this->assertStringContainsString(
            '002_one.jpg',
            (string) $response->headers->get('content-disposition')
        );
    }

    #[Test]
    public function a_raw_download_is_numbered_against_the_raw_set_not_the_delivered_one(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();

        // Three delivered files occupy positions 1-3. A raw file must not be
        // numbered against them — the client never receives the raws, so mixing
        // the two sets would give the raw hand-off misleading positions.
        $this->putFile($shoot, 'delivered-a', 1);
        $this->putFile($shoot, 'delivered-b', 2);
        $this->putFile($shoot, 'delivered-c', 3);

        $rawFirst = $this->putFile($shoot, 'raw-a', 4);
        $rawSecond = $this->putFile($shoot, 'raw-b', 5);
        foreach ([$rawFirst, $rawSecond] as $file) {
            $file->forceFill(['workflow_stage' => ShootFile::STAGE_TODO])->save();
        }

        $response = app(\App\Services\Shoots\Actions\DownloadShootMediaAction::class)
            ->downloadResponse($rawSecond->fresh());

        $this->assertStringContainsString(
            '002_raw-b.jpg',
            (string) $response->headers->get('content-disposition'),
            'Raw files are numbered 1..n within the raw set.'
        );
    }

    // -------------------------------------------------------- client gallery

    #[Test]
    public function the_public_gallery_sequences_photos_the_same_way_as_the_archive(): void
    {
        Storage::fake('public');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'bypass_paywall' => true,
        ]);

        $one = $this->putFile($shoot, 'one', 1);
        $two = $this->putFile($shoot, 'two', 2);
        // Never arranged: must trail the curated block in the gallery exactly as
        // it does in the ZIP, instead of a sort_order of 0 floating it to the top.
        $unplaced = $this->putFile($shoot, 'unplaced', 0);

        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [$two->id, $one->id]);

        // The gallery payload is a flat list of URLs, so compare on basename.
        $galleryOrder = collect(
            app(\App\Services\Shoots\ShootPublicAssetsService::class)
                ->buildTypedPublicAssets($shoot->fresh(), 'unbranded')['photos'] ?? []
        )->map(fn ($url) => basename(parse_url((string) $url, PHP_URL_PATH) ?: (string) $url))
            ->values()
            ->all();

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot->fresh(), 'edited', 'original');
        $archiveOrder = array_map(
            // Strip the position prefix so the two sequences are directly comparable.
            fn (string $entry) => preg_replace('/^\d+_/', '', $entry),
            array_keys($this->readArchive($archiveService, $shoot, 'edited', 'original'))
        );

        $this->assertSame(['two.jpg', 'one.jpg', 'unplaced.jpg'], $archiveOrder);
        $this->assertSame(
            $archiveOrder,
            $galleryOrder,
            'What the client browses and what they download must be the same sequence.'
        );
    }

    // ------------------------------------------------------------ bright mls

    #[Test]
    public function bright_mls_auto_publish_follows_delivery_order_with_the_first_file_primary(): void
    {
        $this->configureBrightMls();
        Http::fake([
            '*/manifest' => Http::response(['uuid' => 'manifest-uuid-1'], 200),
        ]);

        $shoot = $this->createShoot(['mls_id' => 'MLS-987']);
        $one = $this->createShootFile($shoot, [
            'filename' => 'one.jpg',
            'storage_path' => 'https://cdn.example.com/shoots/one.jpg',
            'sort_order' => 1,
        ]);
        $two = $this->createShootFile($shoot, [
            'filename' => 'two.jpg',
            'storage_path' => 'https://cdn.example.com/shoots/two.jpg',
            'sort_order' => 2,
        ]);
        $three = $this->createShootFile($shoot, [
            'filename' => 'three.jpg',
            'storage_path' => 'https://cdn.example.com/shoots/three.jpg',
            'sort_order' => 3,
        ]);

        // Curated order deliberately differs from both id order and alphabetical
        // order, so a regression to either is visible.
        app(ShootMediaInteractionService::class)->reorderFiles($shoot->fresh(), [
            $three->id,
            $one->id,
            $two->id,
        ]);

        // Eager-load the unordered relation first: this is exactly the state that
        // used to make auto-publish emit primary-key order.
        $shootWithFiles = Shoot::query()->with('files')->findOrFail($shoot->id);
        app(BrightMlsService::class)->autoPublishForShoot($shootWithFiles);

        $listItems = $this->capturedListItems();
        $photos = array_values(array_filter($listItems, fn ($item) => ($item['mediaType'] ?? null) === 'photo'));

        $this->assertSame(
            ['three.jpg', 'one.jpg', 'two.jpg'],
            array_column($photos, 'fileName'),
            'Manifest sequence must follow the curated delivery order.'
        );

        // Bright has no explicit primary flag: listItems[0] / id 1 is the lead
        // image, so the first eligible delivered file has to land there.
        $this->assertSame('three.jpg', $listItems[0]['fileName']);
        $this->assertSame(1, $listItems[0]['id']);
        $this->assertSame([1, 2, 3], array_column($photos, 'id'));
    }

    #[Test]
    public function bright_mls_primary_skips_photos_without_a_resolvable_url(): void
    {
        $this->configureBrightMls();
        Http::fake([
            '*/manifest' => Http::response(['uuid' => 'manifest-uuid-2'], 200),
        ]);

        $shoot = $this->createShoot(['mls_id' => 'MLS-988']);
        // First in delivery order but unpublishable (no path to build a URL from)
        // — the primary slot must fall through to the next eligible file rather
        // than being wasted on an entry Bright would reject.
        $this->createShootFile($shoot, [
            'filename' => 'broken.jpg',
            'path' => '',
            'storage_path' => '',
            'sort_order' => 1,
        ]);
        $this->createShootFile($shoot, [
            'filename' => 'good.jpg',
            'storage_path' => 'https://cdn.example.com/shoots/good.jpg',
            'sort_order' => 2,
        ]);

        app(BrightMlsService::class)->autoPublishForShoot($shoot->fresh());

        $listItems = $this->capturedListItems();

        $this->assertSame('good.jpg', $listItems[0]['fileName']);
        $this->assertSame(1, $listItems[0]['id']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, string> entry name => contents, in archive order
     */
    protected function readArchive(
        ShootMediaArchiveService $archiveService,
        Shoot $shoot,
        string $type,
        string $size
    ): array {
        $zip = new ZipArchive();
        $path = Storage::disk('public')->path($archiveService->getArchivePath($shoot, $type, $size));
        $this->assertTrue($zip->open($path) === true, "Could not open archive at {$path}");

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $entries[$name] = $zip->getFromIndex($index);
        }
        $zip->close();

        return $entries;
    }

    protected function putFile(Shoot $shoot, string $name, ?int $sortOrder): ShootFile
    {
        $path = "shoots/{$shoot->id}/completed/{$name}.jpg";
        Storage::disk('public')->put($path, "{$name}-bytes");

        $attributes = [
            'filename' => "{$name}.jpg",
            'stored_filename' => "{$name}.jpg",
            'path' => $path,
            'storage_path' => $path,
        ];

        if ($sortOrder !== null) {
            $attributes['sort_order'] = $sortOrder;
        }

        return $this->createShootFile($shoot, $attributes);
    }

    protected function runFinalize(Shoot $shoot): void
    {
        (new FinalizeShootJob($shoot->id, $this->admin->id))
            ->handle(app(\App\Services\ShootActivityLogger::class));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function capturedListItems(): array
    {
        $items = [];
        Http::assertSent(function ($request) use (&$items) {
            $payload = $request->data();
            if (!empty($payload['listItems'])) {
                $items = $payload['listItems'];
            }

            return true;
        });

        $this->assertNotEmpty($items, 'No Bright MLS manifest was published.');

        return $items;
    }

    protected function configureBrightMls(): void
    {
        config([
            'services.bright_mls.enabled' => true,
            'services.bright_mls.api_mode' => 'legacy',
            'services.bright_mls.environment' => 'p1',
            'services.bright_mls.vendor_id' => 'vendor-test',
            'services.bright_mls.api_key' => 'api-key-test',
            'services.bright_mls.vendor_name' => 'Repro Photos',
            'services.dropbox.enabled' => false,
        ]);
    }

    protected function mockDropboxDisabled(): void
    {
        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        $dropbox->shouldReceive('getTemporaryLink')->andReturnNull();
        app()->instance(ShootMediaStorageService::class, $dropbox);
    }

    protected function createShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'editor_id' => $this->editor->id,
            'service_id' => $this->service->id,
            'address' => '412 Delivery Row',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 150,
            'tax_amount' => 9,
            'total_quote' => 159,
            'payment_status' => 'paid',
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
        ], $overrides));
    }

    protected function createShootFile(Shoot $shoot, array $overrides = []): ShootFile
    {
        $filename = $overrides['filename'] ?? 'media-file.jpg';

        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => 'shoots/' . $shoot->id . '/completed/' . $filename,
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ], $overrides));
    }
}
