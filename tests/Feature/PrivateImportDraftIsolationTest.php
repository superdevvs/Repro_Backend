<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootPublicAssetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PrivateImportDraftIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    private function draft(): Shoot
    {
        return Shoot::factory()->create([
            'status' => Shoot::STATUS_IMPORT_DRAFT,
            'external_booking_payload' => ['source_record' => ['notes' => 'Private imported context']],
        ]);
    }

    public function test_only_admin_review_sessions_can_query_private_shoots_and_files(): void
    {
        $draft = $this->draft();
        $file = ShootFile::create([
            'shoot_id' => $draft->id, 'filename' => 'private.jpg', 'stored_filename' => 'private.jpg',
            'path' => 'private.jpg', 'media_type' => 'image', 'file_type' => 'image/jpeg',
            'file_size' => 10, 'uploaded_by' => $draft->client_id, 'workflow_stage' => 'completed',
        ]);
        $this->assertFalse(Shoot::whereKey($draft->id)->exists());
        $this->assertFalse(ShootFile::whereKey($file->id)->exists());

        foreach (['client', 'photographer', 'editor', 'editing_manager', 'salesRep', 'admin', 'superadmin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            $allowed = in_array($role, ['admin', 'superadmin'], true);
            $this->assertSame($allowed, Shoot::whereKey($draft->id)->exists(), $role);
            $this->assertSame($allowed, ShootFile::whereKey($file->id)->exists(), $role);
            $access = app(ShootAuthorizationSupport::class);
            $this->assertSame($allowed, $access->canAccessShootMedia($draft, $user), $role);
            $this->assertFalse($access->canUploadShootMedia($draft, $user), $role);
            $this->assertFalse($access->canClientAccessShoot($draft, $user), $role);
        }
    }

    public function test_public_routes_hide_drafts_even_in_an_admin_session(): void
    {
        $draft = $this->draft();
        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(Shoot::whereKey($draft->id)->exists());
        $request = Request::create('/api/public/shoots/'.$draft->id, 'GET');
        $this->app->instance('request', $request);
        $this->assertFalse(Shoot::canReviewImportDrafts());
        $this->assertFalse(Shoot::whereKey($draft->id)->exists());
        $this->assertNull(app(ShootPublicAssetsService::class)->resolvePublicShoot($request, $draft->id));
    }

    public function test_private_drafts_cannot_be_released_through_an_ordinary_model_save(): void
    {
        $draft = $this->draft();
        $this->actingAs(User::factory()->admin()->create());
        try {
            $draft->update(['status' => Shoot::STATUS_SCHEDULED]);
            $this->fail('An ordinary update must not release a private import.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('status', $error->errors());
        }
        $this->assertDatabaseHas('shoots', ['id' => $draft->id, 'status' => Shoot::STATUS_IMPORT_DRAFT]);
        $this->assertArrayNotHasKey('external_booking_payload', $draft->toArray());
    }

    public function test_notification_suppression_finds_hidden_drafts_on_every_invoice_relation(): void
    {
        $draft = $this->draft();
        $this->actingAs(User::factory()->create(['role' => 'client']));
        $this->assertNull(Shoot::find($draft->id));
        $this->assertTrue($draft->suppressesExternalNotifications());
        $direct = Invoice::factory()->create(['shoot_id' => $draft->id]);
        $pivot = Invoice::factory()->create(['shoot_id' => null]);
        $pivot->shoots()->attach($draft->id);
        $item = Invoice::factory()->create(['shoot_id' => null]);
        $item->items()->create(['shoot_id' => $draft->id, 'type' => 'charge', 'description' => 'Historical record', 'quantity' => 1, 'unit_amount' => 100, 'total_amount' => 100]);
        foreach ([$direct, $pivot, $item] as $invoice) {
            $this->assertTrue($invoice->suppressesExternalNotifications());
        }
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_legacy_link_requires_the_matching_company_and_source_mapping(): void
    {
        $shoot = Shoot::factory()->create(['external_booking_payload' => ['external_key' => 'viewshoot:7:42']]);
        DB::table('legacy_shoot_imports')->insert([
            'source_system' => 'pro.reprophotos.com', 'source_id' => '42', 'shoot_id' => $shoot->id,
            'batch_id' => 'qa-import', 'action' => 'imported', 'source_snapshot' => '{}', 'created_at' => now(),
        ]);
        $assets = app(ShootPublicAssetsService::class);
        $request = fn (string $company, string $source) => Request::create('/api/public/shoots', 'GET', ['legacyCompanyId' => $company, 'legacyShootId' => $source]);
        $this->assertSame($shoot->id, $assets->resolvePublicShoot($request('7', '42'))?->id);
        $this->assertNull($assets->resolvePublicShoot($request('8', '42')));
        $this->assertNull($assets->resolvePublicShoot($request('7', '999')));
        $this->assertNull($assets->resolvePublicShoot($request('7', '../42')));
    }
}
