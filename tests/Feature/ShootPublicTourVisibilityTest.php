<?php

namespace Tests\Feature;

use App\Jobs\NotifyShootDeliveryUpdate;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\DeliveryPlanService;
use App\Services\Shoots\PublicTourVisibility;
use App\Services\Shoots\ShootGuestTours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShootPublicTourVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const VIDEO = 'https://vimeo.com/900000001';
    private const DRONE = 'https://vimeo.com/900000002';
    private const MATTERPORT = 'https://my.matterport.com/show/?m=WALKTHROUGH1';
    private const MATTERPORT_MLS = 'https://my.matterport.com/show/?m=WALKTHROUGH1&brand=0';
    private const IGUIDE = 'https://youriguide.com/walkthrough_lane';
    private const IGUIDE_MLS = 'https://unbranded.youriguide.com/walkthrough_lane';
    private const CUBICASA = 'https://app.cubi.casa/tour/walkthrough-branded';
    private const CUBICASA_MLS = 'https://app.cubi.casa/tour/walkthrough-mls';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    /**
     * Client request, 2 Cherry Laurel Drive: "I have it hidden but it still
     * shows on the tour link when shared." Hiding the uploaded film only
     * removes the file; the tour plays the saved video links. The switch is
     * what takes the video off the shared pages.
     */
    public function test_hiding_the_video_file_leaves_the_saved_video_on_the_tour_until_the_switch_is_off(): void
    {
        [$shoot, $client, $staff] = $this->deliveredShoot([
            'video_branded' => self::VIDEO, 'video_mls' => self::VIDEO, 'video_generic' => self::VIDEO, 'video_link' => self::DRONE,
            'embeds' => [
                ['id' => 'film', 'title' => 'Film', 'branded' => '<iframe src="https://player.vimeo.com/video/900000001"></iframe>', 'mls' => 'https://player.vimeo.com/video/900000001'],
                ['id' => 'other', 'title' => 'Neighbourhood', 'branded' => 'https://example.test/area', 'mls' => 'https://example.test/area'],
            ],
            'featured_embed_id' => 'film',
        ]);
        $this->file($shoot, $staff, ['filename' => 'film.mp4', 'file_type' => 'video/mp4', 'media_type' => 'video',
            'web_path' => 'https://cdn.example.test/film.mp4', 'is_hidden' => true]);
        $this->file($shoot, $staff, ['filename' => 'teaser.mp4', 'file_type' => 'video/mp4', 'media_type' => 'video',
            'web_path' => 'https://cdn.example.test/teaser.mp4']);
        Queue::fake();
        $public = '/api/public/shoots/'.$shoot->id.'/';

        // The reported behaviour: the hidden file is gone, the video still plays.
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $response = $this->getJson($public.$variant)->assertOk()->assertJsonPath('video_link', self::VIDEO);
            $this->assertStringNotContainsString('film.mp4', $response->getContent());
            $this->assertSame(['https://cdn.example.test/teaser.mp4'], $response->json('videos'));
        }

        $settings = '/api/shoots/'.$shoot->id.'/tour-visibility';
        $this->actingAs($client)->patchJson($settings, ['show_video_on_branded' => false])->assertOk()
            ->assertJsonPath('data.show_video_on_branded', false)->assertJsonPath('data.show_video_on_mls', true)
            ->assertJsonPath('data.show_matterport_on_branded', true);
        $this->assertNoVideo($public.'branded');
        $this->getJson($public.'mls')->assertOk()->assertJsonPath('video_link', self::VIDEO)->assertJsonPath('tour_links.video_mls', self::VIDEO);
        $this->getJson($public.'g-mls')->assertOk()->assertJsonPath('video_link', self::VIDEO);

        $this->patchJson($settings, ['show_video_on_mls' => false])->assertOk();
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $this->assertNoVideo($public.$variant);
        }

        // Nothing was deleted, staff and the owner still see it, and no "delivery updated" notice went out.
        $saved = $shoot->fresh()->tour_links;
        $this->assertSame(self::VIDEO, $saved['video_branded']);
        $this->assertSame(self::DRONE, $saved['video_link']);
        $this->assertCount(2, $saved['embeds']);
        $this->assertSame(2, $shoot->files()->where('media_type', 'video')->count());
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.can_manage_tour_visibility', true)
            ->assertJsonPath('data.tour_links.video_branded', self::VIDEO)->assertJsonPath('data.tour_links.show_video_on_branded', false);
        Queue::assertNotPushed(NotifyShootDeliveryUpdate::class);

        $this->patchJson($settings, ['show_video_on_branded' => true, 'show_video_on_mls' => true])->assertOk();
        $this->getJson($public.'branded')->assertOk()->assertJsonPath('video_link', self::VIDEO)
            ->assertJsonPath('tour_links.video_link', self::DRONE)->assertJsonCount(2, 'embeds');
        $this->getJson($public.'mls')->assertOk()->assertJsonPath('video_link', self::VIDEO);
    }

    public function test_each_3d_provider_can_be_switched_off_per_page_without_losing_links_or_floor_plans(): void
    {
        [$shoot, $client, $staff] = $this->deliveredShoot([
            'matterport_branded' => self::MATTERPORT, 'matterport_mls' => self::MATTERPORT_MLS,
            'iguide_branded' => self::IGUIDE, 'iguide_mls' => self::IGUIDE_MLS, 'iguide_mls_source' => 'manual',
            'cubicasa_branded' => self::CUBICASA, 'cubicasa_mls' => self::CUBICASA_MLS,
            'embeds' => [
                ['id' => 'mp', 'branded' => '<iframe src="'.e(self::MATTERPORT).'"></iframe>', 'mls' => self::MATTERPORT_MLS],
                ['id' => 'ig', 'branded' => self::IGUIDE, 'mls' => self::IGUIDE_MLS],
                ['id' => 'cc', 'branded' => self::CUBICASA, 'mls' => self::CUBICASA_MLS],
                ['id' => 'other', 'branded' => 'https://example.test/area', 'mls' => 'https://example.test/area'],
            ],
        ], ['iguide_tour_url' => self::IGUIDE]);
        $this->file($shoot, $staff, ['filename' => 'floorplan.png', 'file_type' => 'image/png', 'media_type' => 'floorplan',
            'path' => 'https://example.test/floorplan.png', 'web_path' => 'https://example.test/floorplan.png', 'metadata' => ['label' => 'Floor 1']]);
        Queue::fake();
        $public = '/api/public/shoots/'.$shoot->id.'/';
        $settings = '/api/shoots/'.$shoot->id.'/tour-visibility';

        $this->getJson($public.'branded')->assertOk()->assertJsonPath('matterport_url', self::MATTERPORT)
            ->assertJsonPath('iguide_url', self::IGUIDE)->assertJsonPath('tour_links.cubicasa_branded', self::CUBICASA)->assertJsonCount(4, 'embeds');
        $this->getJson($public.'mls')->assertOk()->assertJsonPath('matterport_url', self::MATTERPORT_MLS)
            ->assertJsonPath('iguide_url', self::IGUIDE_MLS)->assertJsonPath('tour_links.cubicasa_mls', self::CUBICASA_MLS);

        $tokens = ['matterport' => 'matterport.com', 'iguide' => 'youriguide.com', 'cubicasa' => 'cubi.casa'];
        $this->actingAs($client);
        foreach ($tokens as $content => $token) {
            $this->patchJson($settings, ['show_'.$content.'_on_branded' => false])->assertOk();
            $branded = $this->getJson($public.'branded')->assertOk()->assertJsonPath('floorplans.0.label', 'Floor 1');
            $this->assertStringNotContainsString($token, $branded->getContent(), $content.' is still on the branded page');
            $this->assertStringContainsString($token, $this->getJson($public.'mls')->assertOk()->getContent(), $content.' left the MLS page too');

            $this->patchJson($settings, ['show_'.$content.'_on_mls' => false])->assertOk();
            foreach (['mls', 'g-mls'] as $variant) {
                $page = $this->getJson($public.$variant)->assertOk()->assertJsonPath('floorplans.0.label', 'Floor 1');
                $this->assertStringNotContainsString($token, $page->getContent(), $content.' is still on '.$variant);
            }
        }
        $this->getJson($public.'branded')->assertOk()->assertJsonPath('matterport_url', null)->assertJsonPath('iguide_url', null)
            ->assertJsonPath('iguide_viewer.inline_url', null)->assertJsonPath('embeds.0.id', 'other')->assertJsonCount(1, 'embeds');

        $fresh = $shoot->fresh();
        $this->assertSame(self::MATTERPORT, $fresh->tour_links['matterport_branded']);
        $this->assertSame(self::IGUIDE_MLS, $fresh->tour_links['iguide_mls']);
        $this->assertSame(self::CUBICASA, $fresh->tour_links['cubicasa_branded']);
        $this->assertSame(self::IGUIDE, $fresh->iguide_tour_url);
        $this->assertCount(4, $fresh->tour_links['embeds']);
        Queue::assertNotPushed(NotifyShootDeliveryUpdate::class);

        $this->patchJson($settings, ['show_iguide_on_branded' => true])->assertOk();
        $this->getJson($public.'branded')->assertOk()->assertJsonPath('iguide_url', self::IGUIDE)->assertJsonPath('matterport_url', null);
    }

    public function test_only_staff_the_owning_client_and_the_shoots_sales_rep_can_change_the_switches(): void
    {
        [$shoot, $client] = $this->deliveredShoot(['video_branded' => self::VIDEO]);
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot->update(['rep_id' => $rep->id]);
        $shared = User::factory()->create(['role' => 'client']);
        $shoot->ghostUsers()->attach($shared->id);
        $settings = '/api/shoots/'.$shoot->id.'/tour-visibility';

        $this->patchJson($settings, ['show_video_on_branded' => false])->assertUnauthorized();
        $allowed = [
            $client, $rep,
            User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'superadmin']),
            User::factory()->create(['role' => 'editing_manager']),
        ];
        foreach ($allowed as $index => $user) {
            $next = $index % 2 === 0 ? false : true;
            $this->actingAs($user)->patchJson($settings, ['show_video_on_branded' => $next])->assertOk()
                ->assertJsonPath('data.show_video_on_branded', $next);
            $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.can_manage_tour_visibility', true);
        }
        $denied = [
            $shared, User::factory()->create(['role' => 'client']), User::factory()->create(['role' => 'salesRep']),
            User::factory()->create(['role' => 'editor']), User::factory()->create(['role' => 'photographer']),
        ];
        foreach ($denied as $user) {
            $this->actingAs($user)->patchJson($settings, ['show_video_on_branded' => true])->assertForbidden();
        }
        $this->actingAs($shared)->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.can_manage_tour_visibility', false);
        $this->assertFalse($shoot->fresh()->tour_links['show_video_on_branded']);

        // The switches cannot be smuggled through a link edit or mixed with one.
        $this->actingAs($client)->patchJson($settings, ['show_video_on_branded' => true, 'video_branded' => self::DRONE])->assertUnprocessable();
        $this->patchJson($settings, ['show_video_on_branded' => 'maybe'])->assertUnprocessable();
        $this->patchJson($settings, [])->assertUnprocessable();
        $this->actingAs($allowed[3])->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'neo', 'show_video_on_branded' => true]])->assertOk();
        $this->assertFalse($shoot->fresh()->tour_links['show_video_on_branded']);
        $this->assertSame(self::VIDEO, $shoot->fresh()->tour_links['video_branded']);
    }

    public function test_a_legacy_shoot_without_its_own_rep_follows_the_clients_rep(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        [$shoot, $client] = $this->deliveredShoot(['video_branded' => self::VIDEO]);
        $client->forceFill(['metadata' => ['accountRepId' => $rep->id]])->save();
        $this->assertNull($shoot->rep_id);
        $settings = '/api/shoots/'.$shoot->id.'/tour-visibility';

        $this->actingAs($otherRep)->patchJson($settings, ['show_video_on_mls' => false])->assertForbidden();
        $this->actingAs($rep)->patchJson($settings, ['show_video_on_mls' => false])->assertOk()->assertJsonPath('data.show_video_on_mls', false);
    }

    public function test_unit_switches_are_independent_of_the_shoot_and_of_other_units(): void
    {
        [$shoot, $client] = $this->deliveredShoot(['video_branded' => self::VIDEO, 'matterport_branded' => self::MATTERPORT]);
        $units = [];
        foreach (['a', 'b'] as $key) {
            $units[$key] = $shoot->units()->create([
                'client_key' => 'unit-'.$key, 'label' => 'Unit '.strtoupper($key), 'kind' => 'unit',
                'tour_links' => ['video_branded' => self::VIDEO, 'video_mls' => self::VIDEO, 'matterport_branded' => self::MATTERPORT, 'matterport_mls' => self::MATTERPORT_MLS],
            ]);
            $shoot->serviceItems()->create([
                'service_id' => Service::factory()->create(['name' => 'Floor Plan '.$key])->id, 'shoot_unit_id' => $units[$key]->id,
                'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => 'ready',
            ]);
        }
        $settings = '/api/shoots/'.$shoot->id.'/tour-visibility';
        $public = fn (string $variant, string $key) => '/api/public/shoots/'.$shoot->id.'/'.$variant.'?unitId='.$units[$key]->id;

        $this->actingAs($client)->patchJson($settings, [
            'unit_id' => $units['a']->id, 'show_video_on_branded' => false, 'show_matterport_on_branded' => false,
        ])->assertOk()->assertJsonPath('data.show_video_on_branded', false)->assertJsonPath('data.show_matterport_on_branded', false);

        $hidden = $this->getJson($public('branded', 'a'))->assertOk()->assertJsonPath('video_link', null)->assertJsonPath('matterport_url', null);
        $this->assertStringNotContainsString('vimeo.com', $hidden->getContent());
        $this->assertStringNotContainsString('matterport.com', $hidden->getContent());
        $this->getJson($public('mls', 'a'))->assertOk()->assertJsonPath('video_link', self::VIDEO)->assertJsonPath('matterport_url', self::MATTERPORT_MLS);
        $this->getJson($public('branded', 'b'))->assertOk()->assertJsonPath('video_link', self::VIDEO)->assertJsonPath('matterport_url', self::MATTERPORT);

        $this->assertSame(self::VIDEO, $units['a']->fresh()->tour_links['video_branded']);
        $this->assertArrayNotHasKey('show_video_on_branded', $units['b']->fresh()->tour_links);
        $this->assertArrayNotHasKey('show_video_on_branded', $shoot->fresh()->tour_links);

        // A unit tour edit by staff cannot flip the switch back on the side.
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))->patchJson(
            '/api/shoots/'.$shoot->id.'/units/'.$units['a']->id.'/tour',
            ['tour_links' => ['video_mls' => self::DRONE, 'show_video_on_branded' => true]],
        )->assertOk();
        $this->assertFalse($units['a']->fresh()->tour_links['show_video_on_branded']);
        $this->assertSame(self::DRONE, $units['a']->fresh()->tour_links['video_mls']);

        $foreign = Shoot::factory()->create()->units()->create(['client_key' => 'foreign', 'label' => 'Foreign', 'kind' => 'unit']);
        $this->actingAs($client)->patchJson($settings, ['unit_id' => $foreign->id, 'show_video_on_branded' => false])->assertNotFound();
    }

    public function test_the_client_portfolio_and_link_previews_follow_the_branded_switch(): void
    {
        [$shoot, $client] = $this->deliveredShoot(
            ['video_branded' => self::VIDEO, 'matterport_branded' => self::MATTERPORT, 'iguide_branded' => self::IGUIDE],
            ['iguide_tour_url' => self::IGUIDE],
        );
        $profile = '/api/public/clients/'.$client->id.'/profile';
        $before = $this->getJson($profile)->assertOk()->getContent();
        foreach (['vimeo.com', 'matterport.com', 'youriguide.com'] as $token) {
            $this->assertStringContainsString($token, $before);
        }

        $this->actingAs($client)->patchJson('/api/shoots/'.$shoot->id.'/tour-visibility', [
            'show_video_on_branded' => false, 'show_matterport_on_branded' => false, 'show_iguide_on_branded' => false,
        ])->assertOk();
        $after = $this->getJson($profile)->assertOk()->assertJsonPath('shoots.0.iguide_tour_url', null)->getContent();
        foreach (['vimeo.com', 'matterport.com', 'youriguide.com', 'show_video_on_branded'] as $token) {
            $this->assertStringNotContainsString($token, $after);
        }
    }

    public function test_a_delivered_external_tour_is_not_handed_to_guests_once_it_is_switched_off(): void
    {
        [$shoot, $client, $staff] = $this->deliveredShoot(['matterport_branded' => self::MATTERPORT], ['bypass_paywall' => true]);
        $service = Service::factory()->noIntake()->create(['name' => 'Matterport 3D Tour']);
        $line = $shoot->serviceItems()->create([
            'service_id' => $service->id, 'price' => 100, 'quantity' => 1,
            'is_deliverable' => true, 'workflow_status' => 'ready', 'delivery_status' => 'ready',
        ]);
        $planner = app(DeliveryPlanService::class);
        $selection = $planner->validateSelection($shoot, $staff, [
            'component_ids' => [$line->id.':tour'], 'mode' => 'external', 'confirmed' => true,
        ]);
        DB::transaction(fn () => $planner->commitSelection($shoot, $staff, $selection));
        $receipt = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->sole();
        $tours = fn () => array_column(app(ShootGuestTours::class)->build($shoot->fresh(), collect(), 'token'), 'url');

        $this->assertContains(self::MATTERPORT, $tours());

        $this->actingAs($client)->patchJson('/api/shoots/'.$shoot->id.'/tour-visibility', ['show_matterport_on_branded' => false])->assertOk();
        $this->assertNotContains(self::MATTERPORT, $tours());
        $this->assertEquals($receipt, DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->sole());
        $this->assertSame('delivered', $planner->plan($shoot->fresh(), $staff)['components'][0]['status']);

        $this->patchJson('/api/shoots/'.$shoot->id.'/tour-visibility', ['show_matterport_on_branded' => true])->assertOk();
        $this->assertContains(self::MATTERPORT, $tours());
    }

    public function test_links_shared_outside_a_page_follow_the_switch_of_the_slot_they_were_saved_in(): void
    {
        $visibility = new PublicTourVisibility;
        $links = [
            'video_branded' => self::VIDEO, 'video_mls' => self::VIDEO, 'video_link' => self::DRONE,
            'iguide_branded' => self::IGUIDE, 'iguide_mls' => self::IGUIDE_MLS, 'iguide_mls_source' => 'manual',
            'zillow_3d' => 'https://www.zillow.com/view-imx/home', 'tour_style' => 'neo',
            'embeds' => [['id' => 'film', 'branded' => self::VIDEO, 'mls' => self::VIDEO]],
            'show_video_on_branded' => false, 'show_iguide_on_mls' => false, 'show_zillow_on_branded' => false,
        ];

        $shared = $visibility->shareableLinks($links);
        $this->assertSame(['video_mls', 'iguide_branded', 'tour_style', 'embeds'], array_keys($shared));
        $this->assertSame([['id' => 'film', 'mls' => self::VIDEO]], $shared['embeds']);

        // The same film is saved for both pages, so it stays shareable while one page still shows it.
        $this->assertFalse($visibility->hidesSharedUrl($links, 'video', self::VIDEO));
        $this->assertTrue($visibility->hidesSharedUrl($links, 'video', self::DRONE));
        $this->assertTrue($visibility->hidesSharedUrl($links, 'video', 'https://vimeo.com/unsaved'));
        $this->assertTrue($visibility->hidesSharedUrl($links, 'iguide', self::IGUIDE_MLS));
        $this->assertFalse($visibility->hidesSharedUrl($links, 'iguide', self::IGUIDE));
        $this->assertFalse($visibility->hidesSharedUrl($links, 'external', self::IGUIDE));

        $this->assertSame(['video', 'zillow'], $visibility->hiddenContent($links, true));
        $this->assertSame(['iguide'], $visibility->hiddenContent($links, false));
        // A page drops every slot of hidden content, because pages fall back across slots.
        $page = $visibility->publicLinks(['embeds' => [['id' => 'area', 'url' => 'https://example.test/area'], ['id' => 'film', 'mls' => self::VIDEO]]] + $links, true);
        $this->assertEqualsCanonicalizing(['iguide_branded', 'iguide_mls', 'iguide_mls_source', 'tour_style', 'embeds'], array_keys($page));
        $this->assertSame(['area'], array_column($page['embeds'], 'id'));
    }

    private function assertNoVideo(string $path): void
    {
        $response = $this->getJson($path)->assertOk()->assertJsonPath('video_link', null)
            ->assertJsonPath('video_thumbnail_url', null)->assertJsonPath('video_poster_url', null)
            ->assertJsonPath('videos', [])->assertJsonPath('embeds.0.id', 'other')->assertJsonCount(1, 'embeds')
            ->assertJsonMissingPath('tour_links.featured_embed_id')->assertJsonMissingPath('tour_links.show_video_on_branded');
        foreach (['video_branded', 'video_mls', 'video_generic', 'video_link'] as $key) {
            $response->assertJsonMissingPath('tour_links.'.$key);
        }
        $this->assertStringNotContainsString('vimeo', $response->getContent());
        $this->assertStringNotContainsString('.mp4', $response->getContent());
    }

    /** @return array{Shoot, User, User} */
    private function deliveredShoot(array $tourLinks, array $attributes = []): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $staff = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create($attributes + [
            'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'scheduled_date' => now()->subDay()->toDateString(),
            'total_quote' => 0, 'payment_status' => 'paid', 'tour_links' => $tourLinks,
        ]);

        return [$shoot, $client, $staff];
    }

    private function file(Shoot $shoot, User $staff, array $attributes): ShootFile
    {
        $file = new ShootFile;
        $file->forceFill($attributes + [
            'shoot_id' => $shoot->id, 'stored_filename' => $attributes['filename'], 'path' => $attributes['web_path'] ?? null,
            'file_size' => 10, 'uploaded_by' => $staff->id, 'workflow_stage' => 'verified', 'scan_status' => 'clean',
        ])->save();

        return $file;
    }
}
