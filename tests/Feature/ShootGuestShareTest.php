<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Message, Service, Shoot, ShootFile, ShootGuestShare, User};
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\EmailNotificationPermissions;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{DeliveryPlanService, ShootGuestShareService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Notification, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class ShootGuestShareTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32)), 'app.url' => 'https://repro.test', 'app.frontend_url' => 'https://repro.test']);
        Cache::flush(); Http::preventStrayRequests(); Queue::fake(); Notification::fake(); Storage::fake('public');
        $this->mock(MessagingService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function (array $payload) {
                $this->sent[] = $payload;
                return new Message(['status' => 'SENT']);
            });
        });
    }

    private function fixture(string $kind = 'photo'): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $actor = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'ready', 'workflow_status' => 'ready',
            'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(), 'timezone' => 'UTC',
            'total_quote' => 100, 'payment_status' => 'paid', 'admin_verified_at' => null, 'completed_at' => null,
            'property_details' => ['bedrooms' => 3, 'bathrooms' => 2, 'sqft' => 1700, 'lockbox_code' => 'SECRET-CODE'],
            'notes' => 'INTERNAL-SECRET']);
        $service = Service::factory()->create(['name' => ucfirst($kind).' with floorplan', 'upload_intake_type' => $kind, 'requires_editing' => false]);
        $item = $shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 100, 'quantity' => 1,
            'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $item->forceFill(['delivery_requirements' => [
            ['key' => $kind, 'kind' => $kind, 'label' => $kind === 'video' ? 'Video' : 'Photos'],
            ['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans'],
        ]])->save();
        $filename = $kind === 'video' ? 'released.mp4' : 'released.jpg';
        $mime = $kind === 'video' ? 'video/mp4' : 'image/jpeg';
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => $filename,
            'stored_filename' => $filename, 'path' => 'shoots/'.$shoot->id.'/'.$filename, 'web_path' => 'shoots/'.$shoot->id.'/web.jpg',
            'thumbnail_path' => 'shoots/'.$shoot->id.'/thumbnail.jpg',
            'file_size' => 100, 'file_type' => $mime, 'mime_type' => $mime, 'media_type' => $kind === 'video' ? 'video' : 'edited',
            'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor->id])->save();
        Storage::disk('public')->put($file->path, 'photo');
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot->fresh(), $actor, [
            'mode' => 'auto', 'component_ids' => [$item->id.':'.$kind], 'confirmed' => true,
        ]);
        $job = new FinalizeShootJob($shoot->id, $actor->id, null, null, false, $intent['idempotency_key'], $intent);
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));
        $token = Str::random(64);
        $share = ShootGuestShare::create(['shoot_id' => $shoot->id, 'created_by' => $client->id, 'email' => 'guest@example.test',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)]);
        return [$shoot->fresh(), $client, $file->fresh(), $share, $token, $item];
    }

    private function verifiedSession(ShootGuestShare $share): string
    {
        $service = app(ShootGuestShareService::class);
        $service->requestCode($share);
        preg_match('/\b([0-9]{6})\b/', end($this->sent)['body_text'], $match);
        return $service->verify($share, $match[1]);
    }

    public function test_owner_can_email_a_magic_link_but_unrelated_client_and_editor_cannot(): void
    {
        [$shoot, $client] = $this->fixture();
        foreach (['client', 'editor', 'photographer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson('/api/shoots/'.$shoot->id.'/guest-shares', ['email' => 'other@example.test'])->assertForbidden();
        }
        $this->actingAs($client)->postJson('/api/shoots/'.$shoot->id.'/guest-shares', ['email' => 'Guest2@example.test'])
            ->assertCreated()->assertJsonPath('data.email', 'guest2@example.test');
        $this->assertStringContainsString('/guest/shoot/', end($this->sent)['body_text']);
        $this->assertStringContainsString('Magic Link', end($this->sent)['subject']);
        $this->assertNull(app(EmailNotificationPermissions::class)->category(end($this->sent)));
        $this->assertSame(2, ShootGuestShare::count());
        $this->assertSame(now()->addDays(30)->toDateString(), ShootGuestShare::latest('id')->first()->expires_at->toDateString());
        $this->actingAs($client)->getJson('/api/shoots/'.$shoot->id.'/guest-shares')->assertOk()
            ->assertJsonMissingPath('data.0.token_hash')->assertJsonMissingPath('data.0.code_hash');
    }

    public function test_guest_emails_use_repro_branding_without_private_shoot_details_or_media(): void
    {
        [$shoot, $client, $file] = $this->fixture();
        $address = '1211 Ripple Court <img src=x onerror=alert(1)> & Suite "A"';
        $shoot->update(['address' => $address]);
        $service = app(ShootGuestShareService::class);
        $share = $service->create($shoot, $client, 'guest2@example.test');
        $invitation = end($this->sent);
        preg_match('#https://repro\.test/guest/shoot/[A-Za-z0-9]{64}#', $invitation['body_text'], $match);
        $this->assertNotEmpty($match);
        $this->assertSame('Your Magic Link to '.$address, $invitation['subject']);
        $this->assertSame("Your Magic Link to released media and tours for {$address}: {$match[0]}. Open the link and request an email verification code. No account is required. Access expires in 30 days.", $invitation['body_text']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $invitation['body_html']);
        $this->assertStringContainsString($address, html_entity_decode(strip_tags($invitation['body_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringNotContainsString('<img src=x', $invitation['body_html']);
        $this->assertStringContainsString('href="'.$match[0].'"', $invitation['body_html']);
        $this->assertStringContainsString('Open Magic Link</a>', $invitation['body_html']);
        $this->assertStringContainsString('Access expires in 30 days.', $invitation['body_html']);

        $service->requestCode($share);
        $codeEmail = end($this->sent);
        preg_match('/\b([0-9]{6})\b/', $codeEmail['body_text'], $codeMatch);
        $this->assertSame('Your property access code', $codeEmail['subject']);
        $this->assertSame("Your verification code is {$codeMatch[1]}. It expires in 10 minutes. If you did not request this code, ignore this email.", $codeEmail['body_text']);
        $this->assertStringContainsString($codeMatch[1], $codeEmail['body_html']);
        $this->assertStringContainsString('10 minutes', $codeEmail['body_html']);
        $this->assertStringNotContainsString('/guest/shoot/', $codeEmail['body_html']);
        $this->assertStringNotContainsString(e($address), $codeEmail['body_html']);
        foreach ([$invitation, $codeEmail] as $payload) {
            $this->assertSame('guest2@example.test', $payload['to']);
            $this->assertSame('SHOOT_GUEST_ACCESS', $payload['send_source']);
            $this->assertNull(app(EmailNotificationPermissions::class)->category($payload));
            $this->assertStringContainsString('data-email-design="atelier-v6"', $payload['body_html']);
            $this->assertStringContainsString('https://repro.test/images/repro-email-logo-grey.png', $payload['body_html']);
            foreach (['SECRET-CODE', 'INTERNAL-SECRET', $file->path, $client->email, '/tour/', 'data-shoot-photo=', 'data-artwork='] as $privateContent) {
                $this->assertStringNotContainsString($privateContent, $payload['body_html']);
            }
        }
    }

    public function test_unverified_link_exposes_no_property_or_media_and_code_is_single_use(): void
    {
        [$shoot, , $file, $share, $token] = $this->fixture();
        $base = '/api/public/guest-shares/'.$token;
        $this->getJson($base)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('data', null)
            ->assertDontSee($shoot->address)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($base.'/files/'.$file->id)->assertUnauthorized();
        $this->postJson($base.'/code')->assertOk();
        $this->assertNull(app(EmailNotificationPermissions::class)->category(end($this->sent)));
        preg_match('/\b([0-9]{6})\b/', end($this->sent)['body_text'], $match);
        $this->assertNotSame($match[1], $share->fresh()->code_hash);
        $this->postJson($base.'/verify', ['code' => $match[1]])->assertOk();
        $this->postJson($base.'/verify', ['code' => $match[1]])->assertUnprocessable();
        $this->assertNull($share->fresh()->code_hash);
    }

    public function test_verified_guest_sees_only_released_accessible_media_and_allowlisted_details(): void
    {
        [$shoot, , $file, $share, $token] = $this->fixture();
        $pending = $file->replicate();
        $pending->forceFill(['filename' => 'pending.jpg', 'path' => 'pending.jpg', 'workflow_stage' => 'completed'])->save();
        $raw = $file->replicate(); $raw->forceFill(['filename' => 'raw.jpg', 'media_type' => 'raw'])->save();
        $session = $this->verifiedSession($share);
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $session);
        $response = $this->getJson('/api/public/guest-shares/'.$token)->assertOk()->assertJsonPath('verified', true)
            ->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.id', $file->id)
            ->assertJsonPath('data.services.0.components.1.status', 'pending');
        foreach (['INTERNAL-SECRET', 'SECRET-CODE', 'total_quote', 'client_id', 'pending.jpg', 'raw.jpg'] as $secret) $response->assertDontSee($secret);
        $this->getJson('/api/public/guest-shares/'.$token.'/files/'.$pending->id)->assertNotFound();
        $this->getJson('/api/public/guest-shares/'.$token.'/files/'.$raw->id)->assertNotFound();
        $this->get('/api/public/guest-shares/'.$token.'/files/'.$file->id)->assertRedirect();
    }

    public function test_payment_hidden_files_foreign_files_and_revocation_are_rechecked_on_every_request(): void
    {
        [$shoot, $client, $file, $share, $token] = $this->fixture();
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $this->verifiedSession($share));
        $base = '/api/public/guest-shares/'.$token;
        $foreign = $file->replicate(); $foreign->forceFill(['shoot_id' => Shoot::factory()->create()->id])->save();
        $this->getJson($base.'/files/'.$foreign->id)->assertNotFound();
        $file->update(['is_hidden' => true]); app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->getJson($base.'/files/'.$file->id)->assertNotFound();
        $file->update(['is_hidden' => false]); $shoot->update(['payment_status' => 'unpaid']); app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data.files')->assertJsonCount(0, 'data.tours');
        $this->getJson($base.'/files/'.$file->id)->assertNotFound();
        $this->actingAs($client)->deleteJson('/api/shoots/'.$shoot->id.'/guest-shares/'.$share->id)->assertOk();
        $this->getJson($base)->assertGone();
        $this->getJson($base.'/files/'.$file->id)->assertGone();
    }

    public function test_codes_expire_and_failed_attempts_are_bounded_and_sessions_are_share_scoped(): void
    {
        [, , , $share, $token] = $this->fixture();
        $service = app(ShootGuestShareService::class);
        $service->requestCode($share);
        preg_match('/\b([0-9]{6})\b/', end($this->sent)['body_text'], $match);
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/public/guest-shares/'.$token.'/verify', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/public/guest-shares/'.$token.'/verify', ['code' => $match[1]])->assertUnprocessable();
        $this->travel(61)->seconds(); $service->requestCode($share);
        preg_match('/\b([0-9]{6})\b/', end($this->sent)['body_text'], $match);
        $this->travel(11)->minutes();
        $this->postJson('/api/public/guest-shares/'.$token.'/verify', ['code' => $match[1]])->assertUnprocessable();
        $session = $this->verifiedSession($share);
        $otherToken = Str::random(64); $other = $share->replicate(); $other->token_hash = hash('sha256', $otherToken); $other->save();
        $this->withUnencryptedCookie($service->cookieName($other), $session)->getJson('/api/public/guest-shares/'.$otherToken)
            ->assertOk()->assertJsonPath('verified', false);
        $share->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/public/guest-shares/'.$token)->assertGone();
    }

    public function test_video_preview_uses_the_released_original_instead_of_image_derivatives(): void
    {
        [, , $file, $share, $token] = $this->fixture('video');
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $this->verifiedSession($share));
        $this->getJson('/api/public/guest-shares/'.$token)->assertOk()->assertJsonPath('data.files.0.kind', 'video');
        $response = $this->get('/api/public/guest-shares/'.$token.'/files/'.$file->id)->assertRedirect();
        $location = rawurldecode($response->headers->get('Location'));
        $this->assertStringContainsString('/'.$file->path, $location);
        $this->assertStringNotContainsString('web.jpg', $location);
        $this->assertStringNotContainsString('thumbnail.jpg', $location);
    }

    public function test_a_media_receipt_does_not_release_an_unreviewed_external_video_url(): void
    {
        [$shoot, , $file, $share, $token, $item] = $this->fixture('video');
        $unreviewed = 'https://video.example.test/unreviewed';
        $shoot->update(['tour_links' => ['video_link' => $unreviewed]]);
        $plan = app(DeliveryPlanService::class);
        $plan->forgetReleasedFiles($shoot);
        $component = collect($plan->plan($shoot->fresh(), null)['components'])->firstWhere('id', $item->id.':video');
        $this->assertSame('delivered', $component['status']);
        $this->assertSame('media', $component['delivery_method']);
        $this->assertSame($unreviewed, $component['external_url']);
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $this->verifiedSession($share));
        $response = $this->getJson('/api/public/guest-shares/'.$token)->assertOk()
            ->assertJsonPath('data.files.0.id', $file->id);
        $this->assertNotContains($unreviewed, array_column($response->json('data.tours'), 'url'));
    }

    public function test_an_invitation_is_revoked_when_the_email_provider_does_not_send_it(): void
    {
        [$shoot, $client] = $this->fixture();
        $this->mock(MessagingService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->once()->andReturnUsing(function (array $payload) {
                $this->sent[] = $payload;
                return new Message(['status' => 'FAILED']);
            });
        });
        $this->actingAs($client)->postJson('/api/shoots/'.$shoot->id.'/guest-shares', ['email' => 'failed@example.test'])
            ->assertStatus(503);
        $failed = ShootGuestShare::where('email', 'failed@example.test')->sole();
        $this->assertNotNull($failed->revoked_at);
        preg_match('#/guest/shoot/([A-Za-z0-9]{64})#', end($this->sent)['body_text'], $match);
        $this->assertSame(hash('sha256', $match[1]), $failed->token_hash);
        $this->getJson('/api/public/guest-shares/'.$match[1])->assertGone();
    }

    public function test_issued_secure_cookie_unlocks_the_overview_and_expires_after_twelve_hours(): void
    {
        $this->freezeTime();
        [, , $file, $share, $token] = $this->fixture();
        $path = '/api/public/guest-shares/'.$token;
        $base = 'https://repro.test'.$path;
        $this->postJson($base.'/code')->assertOk();
        preg_match('/\b([0-9]{6})\b/', end($this->sent)['body_text'], $match);
        $response = $this->postJson($base.'/verify', ['code' => $match[1]])->assertOk();
        $cookie = $response->getCookie(app(ShootGuestShareService::class)->cookieName($share), false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame($path, $cookie->getPath());
        $this->assertSame(now()->addHours(12)->timestamp, $cookie->getExpiresTime());
        $this->assertSame(64, strlen($cookie->getValue()));
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $this->getJson($base)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('data.files.0.id', $file->id);
        $this->get($base.'/files/'.$file->id)->assertRedirect();
        $this->travel(12)->hours(); $this->travel(1)->seconds();
        $this->getJson($base)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('data', null);
        $this->getJson($base.'/files/'.$file->id)->assertUnauthorized();
    }

    public function test_existing_access_stops_when_the_issuer_loses_ownership_or_is_disabled(): void
    {
        [$shoot, $client, $file, $share, $token] = $this->fixture();
        $base = '/api/public/guest-shares/'.$token;
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $this->verifiedSession($share));
        $this->getJson($base)->assertOk()->assertJsonPath('verified', true);
        $shoot->update(['client_id' => User::factory()->create(['role' => 'client'])->id]);
        $this->getJson($base)->assertForbidden();
        $this->getJson($base.'/files/'.$file->id)->assertForbidden();
        $shoot->update(['client_id' => $client->id]);
        $this->getJson($base)->assertOk()->assertJsonPath('verified', true);
        $client->update(['account_status' => 'inactive']);
        $this->getJson($base)->assertForbidden();
        $this->getJson($base.'/files/'.$file->id)->assertForbidden();
        $this->postJson($base.'/code')->assertForbidden();
    }

    public function test_staff_manage_denial_blocks_new_invitations_and_previously_verified_access(): void
    {
        [$shoot, , $file, $share, $token] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $share->update(['created_by' => $admin->id]);
        $base = '/api/public/guest-shares/'.$token;
        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $this->verifiedSession($share));
        $this->getJson($base)->assertOk()->assertJsonPath('verified', true);
        $admin->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-manage']]]);
        $this->getJson($base)->assertForbidden();
        $this->getJson($base.'/files/'.$file->id)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/shoots/'.$shoot->id.'/guest-shares', ['email' => 'denied@example.test'])
            ->assertForbidden();
        $this->assertDatabaseMissing('shoot_guest_shares', ['email' => 'denied@example.test']);
    }
}
