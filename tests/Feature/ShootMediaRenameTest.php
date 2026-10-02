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

class ShootMediaRenameTest extends TestCase
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
            'email' => 'rename-admin@test.com',
        ]);

        $this->editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'rename-editor@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'email' => 'rename-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'rename-photographer@test.com',
        ]);

        $this->salesRep = User::factory()->create([
            'role' => 'salesRep',
            'email' => 'rename-sales-rep@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Rename Media Service',
            'price' => 150.00,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_rename_media_display_filename(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'original-room.jpg',
            'stored_filename' => 'stored-hash-abc.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/stored-hash-abc.jpg',
        ]);

        $response = $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'living-room-01.jpg',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Filename updated')
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.filename', 'living-room-01.jpg')
            ->assertJsonPath('data.stored_filename', 'stored-hash-abc.jpg');

        $file->refresh();
        $this->assertSame('living-room-01.jpg', $file->filename);
        $this->assertSame('stored-hash-abc.jpg', $file->stored_filename);
        $this->assertSame('shoots/'.$shoot->id.'/completed/stored-hash-abc.jpg', $file->path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rename_appends_original_extension_when_omitted(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'kitchen.png',
            'stored_filename' => 'stored-kitchen.png',
        ]);

        $response = $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'kitchen-hero',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.filename', 'kitchen-hero.png');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rename_removes_commas_from_an_address(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'SNAP5129.CR3',
            'stored_filename' => 'stored-5129.CR3',
            'path' => 'shoots/'.$shoot->id.'/raw/stored-5129.CR3',
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);
        $filename = '18502 Boysenberry Dr 156 Gaithersburg MD_5129.CR3';

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => '18502 Boysenberry Dr 156, Gaithersburg, MD_5129.CR3',
        ])->assertOk()
            ->assertJsonPath('data.filename', $filename)
            ->assertJsonPath('data.stored_filename', 'stored-5129.CR3');

        $fresh = $file->fresh();
        $this->assertSame($filename, $fresh->filename);
        $this->assertSame('shoots/'.$shoot->id.'/raw/stored-5129.CR3', $fresh->path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rename_rejects_extension_change(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, [
            'filename' => 'living-room.jpg',
            'stored_filename' => 'stored-living-room.jpg',
        ]);

        $response = $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'living-room.png',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filename']);

        $this->assertSame('living-room.jpg', $file->fresh()->filename);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rename_removes_path_syntax_and_unsupported_characters(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot);

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => '../etc/passwd.jpg',
        ])->assertOk()->assertJsonPath('data.filename', 'etcpasswd.jpg');

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'bad/name.jpg',
        ])->assertOk()->assertJsonPath('data.filename', 'badname.jpg');

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'has<script>.jpg',
        ])->assertOk()->assertJsonPath('data.filename', 'hasscript.jpg');

        foreach (['bad,\\name.jpg', 'bad,:name.jpg', 'bad,*name.jpg', 'bad,?name.jpg', 'bad,|name.jpg', "bad,\r\nname.jpg", 'bad,"name.jpg'] as $filename) {
            $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
                'filename' => $filename,
            ])->assertOk()->assertJsonPath('data.filename', 'badname.jpg');
        }

        $this->assertSame('badname.jpg', $file->fresh()->filename);
        $this->assertSame('media-file.jpg', $file->fresh()->stored_filename);
        $this->assertSame('shoots/'.$shoot->id.'/completed/media-file.jpg', $file->fresh()->path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rename_rejects_a_name_that_is_empty_after_cleanup(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot);

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => ',/?*',
        ])->assertStatus(422)->assertJsonValidationErrors(['filename']);

        $this->assertSame('media-file.jpg', $file->fresh()->filename);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function client_cannot_rename_media(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->client);

        $shoot = $this->createShoot([
            'client_id' => $this->client->id,
        ]);
        $file = $this->createShootFile($shoot);

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'client-rename.jpg',
        ])->assertForbidden();

        $this->assertSame('media-file.jpg', $file->fresh()->filename);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function assigned_editor_can_rename_accessible_media(): void
    {
        Sanctum::actingAs($this->editor);

        $shoot = $this->createShoot([
            'editor_id' => $this->editor->id,
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);
        $file = $this->createShootFile($shoot, [
            'filename' => 'edit-me.jpg',
            'stored_filename' => 'stored-edit-me.jpg',
        ]);

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'edited-name.jpg',
        ])->assertOk()
            ->assertJsonPath('data.filename', 'edited-name.jpg');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function sales_rep_can_rename_media(): void
    {
        Sanctum::actingAs($this->salesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
        ]);
        $file = $this->createShootFile($shoot, [
            'filename' => 'rep-file.jpg',
            'stored_filename' => 'stored-rep-file.jpg',
        ]);

        $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', [
            'filename' => 'rep-renamed.jpg',
        ])->assertOk()
            ->assertJsonPath('data.filename', 'rep-renamed.jpg')
            ->assertJsonPath('data.stored_filename', 'stored-rep-file.jpg');
    }

    protected function createShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'editor_id' => $this->editor->id,
            'service_id' => $this->service->id,
            'address' => '250 Rename Lane',
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
