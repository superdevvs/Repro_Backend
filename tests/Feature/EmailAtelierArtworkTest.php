<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\SystemEmails\EmailArtwork;
use App\Services\SystemEmails\EmailShootPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailAtelierArtworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_approved_catalog_uses_local_wide_transparent_artwork(): void
    {
        $catalog = app(EmailArtwork::class)->catalog();
        $this->assertCount(72, $catalog['templates']);
        $this->assertCount(45, $catalog['assets']);
        foreach ($catalog['assets'] as $asset => $figmaHash) {
            $path = public_path('images/email-atelier/v6/'.$asset.'.png');
            $this->assertFileExists($path);
            $size = getimagesize($path);
            $this->assertEqualsWithDelta(2.4, $size[0] / $size[1], 0.02, $asset);
            $this->assertSame(IMAGETYPE_PNG, $size[2]);
            $this->assertSame(6, ord(file_get_contents($path, false, null, 25, 1)), $asset.' must retain alpha');
        }
        foreach ($catalog['templates'] as $design => $entry) {
            $resolved = app(EmailArtwork::class)->resolve($design);
            $this->assertSame($entry['kind'] === 'existing_shoot_photos' ? 'delivery_access' : $entry['asset'], $resolved['asset'], $design);
            $this->assertStringNotContainsString('figma.com', (string) $resolved['src']);
        }
    }

    public function test_offline_payment_and_onboarding_use_their_specific_artwork(): void
    {
        $art = app(EmailArtwork::class);
        $this->assertSame('offline_payment_intent_submitted', $art->resolve('offline_payment_intent_submitted', ['payment_method_label' => 'Cash'])['asset']);
        $this->assertSame('offline_payment_intent_submitted__cheque', $art->resolve('offline_payment_intent_submitted', ['payment_method_label' => 'Cheque'])['design']);
        $this->assertNotSame($art->resolve('client_email_verification')['asset'], $art->resolve('client_email_verified')['asset']);
        $this->assertNotSame($art->resolve('photographer_equipment_verification')['asset'], $art->resolve('photographer_equipment_approved')['asset']);
        $this->assertSame('none', $art->resolve('password_reset')['kind']);
    }

    public function test_existing_seed_slugs_resolve_without_protected_email_type_metadata(): void
    {
        foreach ([
            'weekly-invoice-generated' => 'invoice_generated',
            'shoot-ready' => 'shoot_delivered',
            'payment-thank-you' => 'payment_confirmation',
            'shoot-deleted' => 'shoot_removed',
            'photographer-assigned' => 'shoot_scheduled__photographer',
        ] as $slug => $design) {
            $resolved = app(EmailArtwork::class)->forTemplate(new MessageTemplate(['slug' => $slug, 'channel' => 'EMAIL']));
            $this->assertSame($design, $resolved['design']);
            $this->assertNotNull($resolved['src']);
        }
    }

    public function test_photos_require_same_recipient_approved_media_and_released_shoot(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['media.read_from_r2' => false, 'media.r2_only' => false]);
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered', 'payment_status' => 'paid']);
        $key = 'shoots/'.$shoot->id.'/web/approved.jpg';
        Storage::disk('public')->put($key, 'fixture');
        Storage::disk('public')->put('shoots/999999/web/other.jpg', 'foreign fixture');
        $file = ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'approved.jpg', 'stored_filename' => 'approved.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/original.jpg', 'web_path' => $key,
            'file_type' => 'image/jpeg', 'file_size' => 100, 'media_type' => 'edited',
            'uploaded_by' => $client->id, 'workflow_stage' => ShootFile::STAGE_VERIFIED,
            'is_hidden' => false, 'is_extra' => false, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ]);
        $data = ['shoot' => ['id' => $shoot->id], 'recipient' => ['id' => $client->id, 'email' => $client->email]];
        $photos = app(EmailShootPhotos::class);
        $this->assertSame($file->id, $photos->resolve($data)[0]['file_id']);
        $this->assertSame([], $photos->resolve(array_replace($data, ['recipient' => ['id' => $other->id, 'email' => $other->email]])));
        $this->assertSame([], $photos->resolve(array_replace($data, ['recipient' => ['id' => $client->id, 'email' => $other->email]])));
        $this->assertSame([], $photos->resolve(['shoot_id' => $shoot->id]));
        foreach ([['is_hidden' => true], ['is_extra' => true], ['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED], ['workflow_stage' => ShootFile::STAGE_TODO], ['web_path' => 'shoots/999999/web/other.jpg'], ['web_path' => 'shoots/'.$shoot->id.'/../999999/web/other.jpg'], ['web_path' => 'shoots/'.$shoot->id.'/%2e%2e/999999/web/other.jpg'], ['web_path' => 'shoots/'.$shoot->id.'/..\\999999\\web\\other.jpg']] as $change) {
            $before = $file->only(array_keys($change));
            $file->update($change);
            $this->assertSame([], $photos->resolve($data), json_encode($change));
            $file->update($before);
        }
        $shoot->updateQuietly(['payment_status' => 'unpaid', 'total_paid' => 0, 'total_quote' => 250]);
        $this->assertSame([], $photos->resolve($data));
        $shoot->updateQuietly(['payment_status' => 'paid', 'workflow_status' => 'scheduled', 'status' => 'scheduled', 'editing_completed_at' => null]);
        $this->assertSame([], $photos->resolve($data));
        $this->assertSame('illustration', app(EmailArtwork::class)->resolve('shoot_delivered', $data)['kind']);
        $this->assertSame('illustration', app(EmailArtwork::class)->resolve('shoot_requested', $data)['kind']);
    }
}
