<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootMediaBatchRenameTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $editor;

    protected User $client;

    protected User $photographer;

    protected User $salesRep;

    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'batch-rename-admin@test.com',
        ]);

        $this->editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'batch-rename-editor@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'email' => 'batch-rename-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'batch-rename-photographer@test.com',
        ]);

        $this->salesRep = User::factory()->create([
            'role' => 'salesRep',
            'email' => 'batch-rename-sales-rep@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Batch Rename Media Service',
            'price' => 150.00,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function prefix_mode_prepends_value_to_basename(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $a = $this->createShootFile($shoot, [
            'filename' => 'IMG_001.jpg',
            'stored_filename' => 'stored-a.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/stored-a.jpg',
        ]);
        $b = $this->createShootFile($shoot, [
            'filename' => 'IMG_002.jpg',
            'stored_filename' => 'stored-b.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/stored-b.jpg',
        ]);

        $response = $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$a->id, $b->id],
            'mode' => 'prefix',
            'value' => 'Kitchen-',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Batch rename completed')
            ->assertJsonPath('data.updated.0.filename', 'Kitchen-IMG_001.jpg')
            ->assertJsonPath('data.updated.1.filename', 'Kitchen-IMG_002.jpg')
            ->assertJsonPath('data.updated.0.stored_filename', 'stored-a.jpg')
            ->assertJsonPath('data.failed', []);

        $this->assertSame('Kitchen-IMG_001.jpg', $a->fresh()->filename);
        $this->assertSame('stored-a.jpg', $a->fresh()->stored_filename);
        $this->assertSame('shoots/'.$shoot->id.'/completed/stored-a.jpg', $a->fresh()->path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function suffix_mode_appends_value_before_extension(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'living-room.png',
            'stored_filename' => 'stored-living.png',
        ]);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'suffix',
            'value' => '-final',
        ])->assertOk()
            ->assertJsonPath('data.updated.0.filename', 'living-room-final.png')
            ->assertJsonPath('data.updated.0.stored_filename', 'stored-living.png');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function replace_mode_replaces_all_occurrences_on_basename(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'IMG_room_IMG_view.jpg',
            'stored_filename' => 'stored-replace.jpg',
        ]);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'replace',
            'find' => 'IMG_',
            'replace' => 'Room-',
        ])->assertOk()
            ->assertJsonPath('data.updated.0.filename', 'Room-room_Room-view.jpg');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function replace_mode_rejects_empty_find(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'replace',
            'find' => '',
            'replace' => 'Room-',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['find']);

        $this->assertSame('media-file.jpg', $file->fresh()->filename);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function sequence_mode_numbers_in_file_ids_order(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $a = $this->createShootFile($shoot, [
            'filename' => 'alpha.jpg',
            'stored_filename' => 'stored-alpha.jpg',
        ]);
        $b = $this->createShootFile($shoot, [
            'filename' => 'beta.jpg',
            'stored_filename' => 'stored-beta.jpg',
        ]);
        $c = $this->createShootFile($shoot, [
            'filename' => 'gamma.jpg',
            'stored_filename' => 'stored-gamma.jpg',
        ]);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$c->id, $a->id, $b->id],
            'mode' => 'sequence',
            'value' => 'Kitchen',
            'start' => 1,
            'digits' => 2,
            'separator' => '-',
        ])->assertOk()
            ->assertJsonPath('data.updated.0.id', $c->id)
            ->assertJsonPath('data.updated.0.filename', 'Kitchen-01.jpg')
            ->assertJsonPath('data.updated.1.id', $a->id)
            ->assertJsonPath('data.updated.1.filename', 'Kitchen-02.jpg')
            ->assertJsonPath('data.updated.2.id', $b->id)
            ->assertJsonPath('data.updated.2.filename', 'Kitchen-03.jpg');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function sequence_mode_uses_original_stem_when_value_omitted(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'porch.jpg',
            'stored_filename' => 'stored-porch.jpg',
        ]);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'sequence',
            'start' => 5,
            'digits' => 3,
            'separator' => '_',
        ])->assertOk()
            ->assertJsonPath('data.updated.0.filename', 'porch_005.jpg');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function batch_rename_partial_failure_returns_200_with_mixed_results(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $ok = $this->createShootFile($shoot, [
            'filename' => 'keep-me.jpg',
            'stored_filename' => 'stored-keep.jpg',
        ]);
        $missingId = 999999;

        $response = $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$ok->id, $missingId],
            'mode' => 'prefix',
            'value' => 'A-',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Batch rename completed')
            ->assertJsonPath('data.updated.0.id', $ok->id)
            ->assertJsonPath('data.updated.0.filename', 'A-keep-me.jpg')
            ->assertJsonPath('data.failed.0.id', $missingId)
            ->assertJsonPath('data.failed.0.error', 'File not found.');

        $this->assertSame('A-keep-me.jpg', $ok->fresh()->filename);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function batch_rename_all_failed_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [111111, 222222],
            'mode' => 'prefix',
            'value' => 'X-',
        ])->assertStatus(422)
            ->assertJsonPath('data.updated', [])
            ->assertJsonCount(2, 'data.failed');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function batch_rename_empty_file_ids_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [],
            'mode' => 'prefix',
            'value' => 'X-',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['file_ids']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function client_cannot_batch_rename_media(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->client);

        $shoot = $this->createShoot([
            'client_id' => $this->client->id,
        ]);
        $file = $this->createShootFile($shoot);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'prefix',
            'value' => 'Nope-',
        ])->assertForbidden();

        $this->assertSame('media-file.jpg', $file->fresh()->filename);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function batch_rename_rejects_unsafe_characters_per_file(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'safe.jpg',
            'stored_filename' => 'stored-safe.jpg',
        ]);

        $response = $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'prefix',
            'value' => '../evil/',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.updated', [])
            ->assertJsonPath('data.failed.0.id', $file->id);

        $this->assertSame('safe.jpg', $file->fresh()->filename);
        $this->assertSame('stored-safe.jpg', $file->fresh()->stored_filename);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function batch_rename_keeps_extension_locked_and_storage_untouched(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'hero.jpg',
            'stored_filename' => 'hash-hero.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/hash-hero.jpg',
        ]);

        // Constructed names always re-append the original extension; suffix that
        // looks like another extension still leaves the locked .jpg at the end.
        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'suffix',
            'value' => '.png-backup',
        ])->assertOk()
            ->assertJsonPath('data.updated.0.filename', 'hero.png-backup.jpg')
            ->assertJsonPath('data.updated.0.stored_filename', 'hash-hero.jpg');

        $fresh = $file->fresh();
        $this->assertSame('hero.png-backup.jpg', $fresh->filename);
        $this->assertSame('hash-hero.jpg', $fresh->stored_filename);
        $this->assertSame('shoots/'.$shoot->id.'/completed/hash-hero.jpg', $fresh->path);
        $this->assertSame('jpg', strtolower(pathinfo($fresh->filename, PATHINFO_EXTENSION)));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function sales_rep_can_batch_rename_media(): void
    {
        Sanctum::actingAs($this->salesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
        ]);
        $file = $this->createShootFile($shoot, [
            'filename' => 'rep-file.jpg',
            'stored_filename' => 'stored-rep.jpg',
        ]);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/batch-rename', [
            'file_ids' => [$file->id],
            'mode' => 'prefix',
            'value' => 'Rep-',
        ])->assertOk()
            ->assertJsonPath('data.updated.0.filename', 'Rep-rep-file.jpg');
    }

    protected function createShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'editor_id' => $this->editor->id,
            'service_id' => $this->service->id,
            'address' => '250 Batch Rename Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 150,
            'tax_amount' => 9,
            'total_quote' => 159,
            'payment_status' => 'paid',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
        ], $overrides));
    }

    protected function createShootFile(Shoot $shoot, array $overrides = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => 'media-file.jpg',
            'stored_filename' => 'media-file.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/media-file.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'sort_order' => 0,
        ], $overrides));
    }
}
