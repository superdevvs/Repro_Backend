<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Message, Service, Shoot, ShootFile, ShootGuestShare, User};
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\EmailNotificationPermissions;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{DeliveryPlanService, ShootGuestShareService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http, Notification, Queue, Storage};
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
        $this->assertStringContainsString('src="https://repro.test/brand/magic-link-glass.png"', $invitation['body_html']);
        $this->assertStringContainsString('alt="Magic Link" width="200"', $invitation['body_html']);

        $service->requestCode($share);
        $codeEmail = end($this->sent);
        preg_match('/\b([0-9]{6})\b/', $codeEmail['body_text'], $codeMatch);
        $this->assertSame('Your property access code', $codeEmail['subject']);
        $this->assertSame("Your verification code is {$codeMatch[1]}. It expires in 10 minutes. If you did not request this code, ignore this email.", $codeEmail['body_text']);
        $this->assertStringContainsString($codeMatch[1], $codeEmail['body_html']);
        $this->assertStringContainsString('10 minutes', $codeEmail['body_html']);
        $this->assertStringNotContainsString('/guest/shoot/', $codeEmail['body_html']);
        $this->assertStringNotContainsString(e($address), $codeEmail['body_html']);
        $this->assertStringNotContainsString('magic-link-glass.png', $codeEmail['body_html']);
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

    public function test_guest_overview_and_invitation_preserve_the_unit_without_private_property_details(): void
    {
        [$shoot, $client, , $share, $token] = $this->fixture();
        $street = '2061 Alice Avenue';
        $displayAddress = $street.', Unit 204';
        $shoot->update(['address' => $street, 'property_details' => array_merge($shoot->property_details, [
            'aptSuite' => '204', 'private_notes' => 'PRIVATE-PROPERTY-NOTES',
        ])]);
        $base = '/api/public/guest-shares/'.$token;
        $this->getJson($base)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('data', null)
            ->assertDontSee($street)->assertDontSee('Unit 204')->assertDontSee('PRIVATE-PROPERTY-NOTES');
        $service = app(ShootGuestShareService::class);
        $this->withUnencryptedCookie($service->cookieName($share), $this->verifiedSession($share));

        foreach ([$street, $displayAddress] as $savedAddress) {
            $shoot->update(['address' => $savedAddress]);
            $this->getJson($base)->assertOk()->assertJsonPath('data.property.address', $displayAddress)
                ->assertJsonMissingPath('data.property.property_details')->assertDontSee('PRIVATE-PROPERTY-NOTES')
                ->assertDontSee('SECRET-CODE')->assertDontSee('INTERNAL-SECRET');
            $service->create($shoot, $client, 'unit-guest@example.test');
            $invitation = end($this->sent);
            $this->assertSame('Your Magic Link to '.$displayAddress, $invitation['subject']);
            foreach (['body_text', 'body_html'] as $body) {
                $this->assertStringContainsString($displayAddress, $invitation[$body]);
                $this->assertSame(1, substr_count($invitation[$body], 'Unit 204'));
                foreach (['PRIVATE-PROPERTY-NOTES', 'SECRET-CODE', 'INTERNAL-SECRET'] as $privateContent) {
                    $this->assertStringNotContainsString($privateContent, $invitation[$body]);
                }
            }
            $this->assertSame($savedAddress, $shoot->fresh()->address);
        }
    }

    public function test_unverified_invitation_exposes_only_the_actual_senders_display_name(): void
    {
        [$shoot, $client, , $share, $token] = $this->fixture();
        $client->update(['name' => "  Jordan   Client\n", 'email' => 'private-owner@example.test', 'phone' => 'PRIVATE-PHONE']);
        $base = '/api/public/guest-shares/'.$token;
        $this->getJson($base)->assertOk()->assertExactJson([
            'verified' => false, 'recipient' => 'g***@example.test', 'sender_name' => 'Jordan Client', 'data' => null,
        ])->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee($client->email)->assertDontSee('PRIVATE-PHONE')->assertDontSee($shoot->address);

        $staff = User::factory()->create(['role' => 'superadmin', 'name' => 'Casey Staff', 'email' => 'private-staff@example.test']);
        $share->update(['created_by' => $staff->id]);
        $this->getJson($base)->assertOk()->assertExactJson([
            'verified' => false, 'recipient' => 'g***@example.test', 'sender_name' => 'Casey Staff', 'data' => null,
        ])->assertDontSee($staff->email)->assertDontSee('Jordan Client')->assertDontSee($shoot->address);

        $this->withUnencryptedCookie(app(ShootGuestShareService::class)->cookieName($share), $this->verifiedSession($share));
        $this->getJson($base)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('sender_name', 'Casey Staff')
            ->assertJsonMissingPath('creator')->assertJsonMissingPath('sender_email')->assertDontSee($staff->email);
    }

    public function test_sender_name_never_falls_back_to_email_or_username(): void
    {
        [, $client, , , $token] = $this->fixture();
        $client->update(['email' => 'private-sender@example.test', 'username' => 'PRIVATE-USERNAME']);
        foreach (['', " \n\t ", 'private-sender@example.test', 'Contact <private-sender@example.test>'] as $name) {
            $client->update(['name' => $name]);
            $this->getJson('/api/public/guest-shares/'.$token)->assertOk()->assertExactJson([
                'verified' => false, 'recipient' => 'g***@example.test', 'sender_name' => 'Your sender', 'data' => null,
            ])->assertDontSee($client->email)->assertDontSee('PRIVATE-USERNAME');
        }
    }

    public function test_sender_name_is_not_exposed_when_the_invitation_or_creator_is_unavailable(): void
    {
        [, $client, , $share, $token] = $this->fixture();
        $client->update(['name' => 'PRIVATE-SENDER-NAME']);
        $base = '/api/public/guest-shares/'.$token;
        $share->update(['revoked_at' => now()]);
        $this->getJson($base)->assertGone()->assertJsonMissingPath('sender_name')->assertDontSee($client->name);
        $share->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $this->getJson($base)->assertGone()->assertJsonMissingPath('sender_name')->assertDontSee($client->name);
        $share->update(['expires_at' => now()->addDay()]);
        $client->update(['account_status' => 'inactive']);
        $this->getJson($base)->assertForbidden()->assertJsonMissingPath('sender_name')->assertDontSee($client->name);
        $client->update(['account_status' => 'active']);
        $client->delete();
        $this->getJson($base)->assertGone()->assertJsonMissingPath('sender_name')->assertDontSee($client->name);
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

    public function test_batch_normalizes_deduplicates_and_reuses_active_recipient_invitations(): void
    {
        [$shoot, $client] = $this->fixture();
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $input = ['emails' => [' First@example.test ', 'first@EXAMPLE.test', 'second@example.test']];
        $response = $this->actingAs($client)->postJson($base, $input)->assertOk()
            ->assertJsonPath('summary.sent', 2)->assertJsonPath('summary.existing', 0)->assertJsonPath('summary.failed', 0)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.email', 'first@example.test')
            ->assertJsonPath('data.0.share.can_copy', true)->assertJsonPath('data.0.share.status', 'active');
        $this->assertCount(2, $this->sent);
        $this->postJson($base, $input)->assertOk()->assertJsonPath('summary.sent', 0)->assertJsonPath('summary.existing', 2);
        $this->assertCount(2, $this->sent);
        $this->assertSame(3, ShootGuestShare::count());
        $links = [];
        foreach ($response->json('data') as $row) {
            $share = ShootGuestShare::findOrFail($row['share']['id']);
            $url = $this->getJson($base.'/'.$share->id.'/link')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->json('data.url');
            $token = basename($url);
            $this->assertSame($token, $share->token_encrypted);
            $this->assertSame(hash('sha256', $token), $share->token_hash);
            $this->assertStringNotContainsString($token, DB::table('shoot_guest_shares')->where('id', $share->id)->value('token_encrypted'));
            $this->assertArrayNotHasKey('token_encrypted', $share->toArray());
            $this->assertArrayNotHasKey('token_hash', $share->toArray());
            $this->getJson('/api/public/guest-shares/'.$token)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('data', null);
            $links[] = $url;
        }
        $this->assertNotSame($links[0], $links[1]);
        $list = $this->getJson($base)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.next_cursor', null);
        foreach (['token_encrypted', 'token_hash', 'code_hash', '/guest/shoot/'] as $secret) $list->assertDontSee($secret);
    }

    public function test_batch_validates_every_address_and_limit_before_any_email_is_sent(): void
    {
        [$shoot, $client] = $this->fixture();
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $this->actingAs($client)->postJson($base, ['emails' => ['valid@example.test', 'not-an-email']])
            ->assertUnprocessable()->assertJsonValidationErrors('emails.1');
        $this->postJson($base, ['emails' => array_map(fn ($index) => "guest{$index}@example.test", range(1, 21))])
            ->assertUnprocessable()->assertJsonValidationErrors('emails');
        $this->postJson($base, ['emails' => []])->assertUnprocessable();
        $this->assertCount(0, $this->sent);
        $this->assertSame(1, ShootGuestShare::count());
    }

    public function test_partial_batch_failure_can_be_retried_without_resending_successful_recipients(): void
    {
        [$shoot, $client] = $this->fixture();
        $fail = true;
        $this->mock(MessagingService::class, function ($mock) use (&$fail) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function (array $payload) use (&$fail) {
                $this->sent[] = $payload;
                return new Message(['status' => $fail && $payload['to'] === 'retry@example.test' ? 'FAILED' : 'SENT']);
            });
        });
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $input = ['emails' => ['good@example.test', 'retry@example.test']];
        $this->actingAs($client)->postJson($base, $input)->assertOk()->assertJsonPath('summary.sent', 1)
            ->assertJsonPath('summary.failed', 1)->assertJsonPath('data.1.outcome', 'failed');
        $failed = ShootGuestShare::where('email', 'retry@example.test')->sole();
        $this->assertNotNull($failed->revoked_at);
        $this->assertSame('failed', $failed->invitation_status);
        $fail = false;
        $this->postJson($base, $input)->assertOk()->assertJsonPath('summary.sent', 1)
            ->assertJsonPath('summary.existing', 1)->assertJsonPath('summary.failed', 0);
        $this->assertCount(3, $this->sent);
        $this->assertSame(1, collect($this->sent)->where('to', 'good@example.test')->count());
        $this->assertSame(2, ShootGuestShare::where('email', 'retry@example.test')->count());
        $this->assertNotNull($failed->fresh()->revoked_at);
    }

    public function test_assigned_reps_can_share_without_global_management_and_lose_access_after_reassignment(): void
    {
        [$shoot, $client] = $this->fixture();
        $rep = User::factory()->create(['role' => 'salesRep', 'permission_overrides' => ['deny' => ['shoots-manage'], 'allow' => []]]);
        $other = User::factory()->create(['role' => 'salesRep']);
        $shoot->update(['rep_id' => $rep->id]);
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $service = app(ShootGuestShareService::class);
        $this->assertFalse(app(\App\Services\RolePermissionService::class)->userCan($rep, 'shoots', 'manage'));
        $this->assertTrue($service->canManage($shoot->fresh(), $rep));
        $response = $this->actingAs($rep)->postJson($base, ['emails' => ['assigned@example.test']])->assertOk()->assertJsonPath('summary.sent', 1);
        $shareId = $response->json('data.0.share.id');
        $url = $this->getJson($base.'/'.$shareId.'/link')->assertOk()->json('data.url');
        $this->actingAs($other)->getJson($base)->assertForbidden();
        $this->postJson($base, ['emails' => ['unrelated@example.test']])->assertForbidden();
        $this->getJson($base.'/'.$shareId.'/link')->assertForbidden();
        $this->postJson($base.'/'.$shareId.'/resend')->assertForbidden();
        $this->deleteJson($base.'/'.$shareId)->assertForbidden();
        $shoot->update(['rep_id' => $other->id]);
        $this->getJson('/api/public/guest-shares/'.basename($url))->assertForbidden();
        $this->actingAs($rep)->getJson($base)->assertForbidden();
        $shoot->update(['rep_id' => null]);
        $client->update(['metadata' => ['accountRepId' => $rep->id]]);
        $this->assertTrue($service->canManage($shoot->fresh(), $rep));
        $this->getJson($base)->assertOk();
        $rep->update(['permission_overrides' => ['deny' => ['shoots-view'], 'allow' => []]]);
        $this->actingAs($rep->fresh())->getJson($base)->assertForbidden();
        $this->getJson('/api/public/guest-shares/'.basename($url))->assertForbidden();
    }

    public function test_copy_resend_and_revoke_require_authority_and_the_matching_shoot(): void
    {
        [$shoot, $client] = $this->fixture();
        $share = app(ShootGuestShareService::class)->create($shoot, $client, 'managed@example.test');
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        foreach (['client', 'editor', 'photographer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson($base)->assertForbidden();
            $this->getJson($base.'/'.$share->id.'/link')->assertForbidden();
            $this->postJson($base.'/'.$share->id.'/resend')->assertForbidden();
            $this->deleteJson($base.'/'.$share->id)->assertForbidden();
        }
        $foreignShoot = Shoot::factory()->create(['client_id' => $client->id]);
        $foreign = '/api/shoots/'.$foreignShoot->id.'/guest-shares/'.$share->id;
        $this->actingAs($client)->getJson($foreign.'/link')->assertNotFound();
        $this->postJson($foreign.'/resend')->assertNotFound();
        $this->deleteJson($foreign)->assertNotFound();
        $this->assertCount(1, $this->sent);
        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_resending_an_active_link_preserves_it_and_throttles_retries_even_after_email_failure(): void
    {
        [$shoot, $client] = $this->fixture();
        $share = app(ShootGuestShareService::class)->create($shoot, $client, 'resend@example.test');
        $base = '/api/shoots/'.$shoot->id.'/guest-shares/'.$share->id;
        $this->actingAs($client);
        $originalUrl = $this->getJson($base.'/link')->assertOk()->json('data.url');
        $originalExpiry = $share->expires_at->toIso8601String();
        $this->postJson($base.'/resend')->assertTooManyRequests();
        $fail = true;
        $this->mock(MessagingService::class, function ($mock) use (&$fail) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function (array $payload) use (&$fail) {
                $this->sent[] = $payload;
                return new Message(['status' => $fail ? 'FAILED' : 'SENT']);
            });
        });
        $this->travel(61)->seconds();
        $this->postJson($base.'/resend')->assertStatus(503);
        $this->assertNull($share->fresh()->revoked_at);
        $this->getJson($base.'/link')->assertOk()->assertJsonPath('data.url', $originalUrl);
        $this->postJson($base.'/resend')->assertTooManyRequests();
        $fail = false;
        $this->travel(61)->seconds();
        $this->postJson($base.'/resend')->assertOk()->assertJsonPath('replacement', false)
            ->assertJsonPath('data.id', $share->id)->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.expires_at', $originalExpiry);
        $this->getJson($base.'/link')->assertOk()->assertJsonPath('data.url', $originalUrl);
        $this->assertCount(3, $this->sent);
        $this->assertStringContainsString('Access expires on '.$share->expires_at->copy()->utc()->format('F j, Y').' (UTC).', end($this->sent)['body_text']);
        $this->assertSame(2, ShootGuestShare::count());
    }

    public function test_explicit_legacy_and_expired_resends_create_new_links_without_revoking_old_ones(): void
    {
        [$shoot, $client, , $legacy, $legacyToken] = $this->fixture();
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $this->actingAs($client)->getJson($base.'/'.$legacy->id.'/link')->assertStatus(409);
        $replacement = $this->postJson($base.'/'.$legacy->id.'/resend')->assertOk()->assertJsonPath('replacement', true)
            ->assertJsonPath('data.can_copy', true)->json('data.id');
        $this->assertNotSame($legacy->id, $replacement);
        $this->assertNull($legacy->fresh()->revoked_at);
        $this->getJson('/api/public/guest-shares/'.$legacyToken)->assertOk()->assertJsonPath('verified', false);
        $expired = ShootGuestShare::findOrFail($replacement);
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->getJson($base.'/'.$expired->id.'/link')->assertGone();
        $this->travel(61)->seconds();
        $renewed = $this->postJson($base.'/'.$expired->id.'/resend')->assertOk()->assertJsonPath('replacement', true)
            ->assertJsonPath('data.can_copy', true)->json('data.id');
        $this->assertNotSame($expired->id, $renewed);
        $this->assertNull($expired->fresh()->revoked_at);
        $this->assertTrue($expired->fresh()->expires_at->isPast());
        $this->assertCount(2, $this->sent);
    }

    public function test_revoked_links_cannot_be_copied_or_resent_but_a_new_invitation_is_explicitly_allowed(): void
    {
        [$shoot, $client] = $this->fixture();
        $share = app(ShootGuestShareService::class)->create($shoot, $client, 'revoked@example.test');
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $this->actingAs($client)->deleteJson($base.'/'.$share->id)->assertOk();
        $this->getJson($base.'/'.$share->id.'/link')->assertGone();
        $this->postJson($base.'/'.$share->id.'/resend')->assertStatus(409);
        $row = collect($this->getJson($base)->assertOk()->json('data'))->firstWhere('id', $share->id);
        $this->assertSame('revoked', $row['status']);
        $this->assertFalse($row['can_copy']);
        $this->assertFalse($row['can_resend']);
        $newId = $this->postJson($base, ['emails' => [$share->email]])->assertOk()->assertJsonPath('summary.sent', 1)->json('data.0.share.id');
        $this->assertNotSame($share->id, $newId);
        $this->assertNotNull($share->fresh()->revoked_at);
        $this->assertCount(2, $this->sent);
    }

    public function test_explicit_revocation_after_failed_delivery_disables_retrying_that_invitation(): void
    {
        [$shoot, $client] = $this->fixture();
        $this->mock(MessagingService::class, fn ($mock) => $mock->shouldReceive('sendEmail')
            ->once()->andReturn(new Message(['status' => 'FAILED'])));
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $this->actingAs($client)->postJson($base, ['emails' => ['failed-revoked@example.test']])->assertOk()
            ->assertJsonPath('summary.failed', 1);
        $share = ShootGuestShare::where('email', 'failed-revoked@example.test')->sole();
        $this->assertTrue(app(ShootGuestShareService::class)->managementData($share)['can_resend']);
        $this->deleteJson($base.'/'.$share->id)->assertOk();
        $this->travel(61)->seconds();
        $this->postJson($base.'/'.$share->id.'/resend')->assertStatus(409);
        $row = collect($this->getJson($base)->assertOk()->json('data'))->firstWhere('id', $share->id);
        $this->assertSame('revoked', $row['status']);
        $this->assertFalse($row['can_resend']);
        $this->assertFalse($row['can_copy']);
    }

    public function test_explicit_revocation_survives_in_flight_delivery_success_or_failure(): void
    {
        [$shoot, $client] = $this->fixture();
        $status = 'SENT';
        $this->mock(MessagingService::class, function ($mock) use ($shoot, $client, &$status) {
            $mock->shouldReceive('sendEmail')->twice()->andReturnUsing(function (array $payload) use ($shoot, $client, &$status) {
                $request = \Illuminate\Http\Request::create('/', 'DELETE');
                $request->setUserResolver(fn () => $client);
                app(\App\Http\Controllers\API\ShootGuestShareController::class)
                    ->revoke($request, $shoot, $payload['metadata']['guest_share_id']);
                return new Message(['status' => $status]);
            });
        });
        $base = '/api/shoots/'.$shoot->id.'/guest-shares';
        $this->actingAs($client);
        foreach (['SENT', 'FAILED'] as $status) {
            $email = strtolower($status).'-revoked@example.test';
            $this->postJson($base, ['emails' => [$email]])->assertOk();
            $share = ShootGuestShare::where('email', $email)->sole();
            $this->assertNotNull($share->revoked_at);
            $this->assertSame('revoked', $share->invitation_status);
            $this->getJson($base.'/'.$share->id.'/link')->assertGone();
            $this->postJson($base.'/'.$share->id.'/resend')->assertStatus(409);
            $this->assertFalse(app(ShootGuestShareService::class)->managementData($share)['can_resend']);
        }
    }
}
