<?php

namespace Tests\Feature;

use App\Jobs\IngestIguideAssetsJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ProviderAssetRecoveryThrottle;
use App\Services\Shoots\ProviderFloorplanRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Production 2026-11: about 200 delivered shoots' iGUIDE tours were Protected Views. Their stored
 * floorplan links carried no access token, so youriguide.com redirected every download to the
 * viewer's sign-in page and the import retried "not ready" forever. A token only comes with the
 * ready event (valid 3 weeks), which iGUIDE serves again on request when the work order is known.
 */
class IguideProtectedFloorplanTest extends TestCase
{
    use RefreshDatabase;

    private const DOC = 'https://youriguide.com/sample/doc/floorplan_imperial.pdf';

    private const PORTAL = 'https://manage.youriguide.com/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        Cache::flush();
        config()->set('services.iguide.app_id', 'fixture-app-id');
        config()->set('services.iguide.app_token', 'fixture-app-token');
        config()->set('services.iguide.base_url', self::PORTAL);
        User::factory()->create(['role' => 'admin']);
    }

    private function jwt(int $expiresIn): string
    {
        $part = fn (array $value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');

        return $part(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$part(['iat' => time(), 'exp' => time() + $expiresIn, 'action' => 'view']).'.signature';
    }

    private function floorplans(): array
    {
        return [['asset_key' => 'pdf_imperial', 'url' => self::DOC, 'filename' => 'floorplan_imperial.pdf', 'type' => 'pdf', 'units' => 'imperial']];
    }

    private function shoot(?string $workOrderId = null, ?string $savedToken = null): Shoot
    {
        $shoot = Shoot::factory()->create(['status' => 'delivered']);
        DB::table('shoots')->where('id', $shoot->id)->update([
            'iguide_property_id' => 'igPROTECTED',
            'iguide_work_order_id' => $workOrderId,
            'iguide_floorplans' => json_encode($this->floorplans()),
            'iguide_data' => json_encode(array_filter(['property_id' => 'igPROTECTED', 'authtoken' => $savedToken])),
        ]);

        return $shoot->fresh();
    }

    /** youriguide.com answers a tokenless document on a protected view with a redirect to the viewer page. */
    private function fakeProvider(?string $freshToken = null, int $portalStatus = 200): void
    {
        Http::fake(function (Request $request) use ($freshToken, $portalStatus) {
            if (str_starts_with($request->url(), self::PORTAL.'/iguides/igPROTECTED/events/ready/object')) {
                return $portalStatus === 200
                    ? Http::response(['type' => 'ready', 'authtoken' => $freshToken, 'workOrderId' => 'WO-1'])
                    : Http::response(['code' => 'failed_precondition', 'message' => 'Task ID is not set'], $portalStatus);
            }
            if (str_starts_with($request->url(), self::DOC)) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                if (($query['accessToken'] ?? null) !== null && ($query['accessToken'] === $freshToken || str_contains((string) $query['accessToken'], '.'))) {
                    return Http::response('%PDF-1.4 floorplan', 200, ['Content-Type' => 'application/pdf']);
                }

                return Http::response('<!doctype html><html><div id="app"></div></html>', 200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'X-Guzzle-Redirect-History' => 'https://youriguide.com/sample/',
                    'X-Guzzle-Redirect-Status-History' => '307',
                ]);
            }

            return Http::response('unexpected', 500);
        });
    }

    private function ingest(Shoot $shoot): IngestIguideAssetsJob
    {
        $job = (new IngestIguideAssetsJob($shoot->id, $this->floorplans()))->withFakeQueueInteractions();
        $job->handle(app(\App\Services\ShootActivityLogger::class));

        return $job;
    }

    private function portalCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::PORTAL))->count();
    }

    public function test_protected_view_without_any_token_is_recorded_once_and_never_retried(): void
    {
        $shoot = $this->shoot();
        $throttle = app(ProviderAssetRecoveryThrottle::class);
        $throttle->record('recovery-iguide', $shoot->id, null, $this->floorplans(), null);
        $this->fakeProvider();

        $job = $this->ingest($shoot);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(0, ShootFile::where('shoot_id', $shoot->id)->count());
        $this->assertSame(0, $this->portalCalls(), 'Without a work order there is nothing to ask iGUIDE for.');
        $row = DB::table('provider_asset_recovery_attempts')->where('shoot_id', $shoot->id)->first();
        $this->assertSame(ProviderAssetRecoveryThrottle::OUTCOME_PROTECTED, $row->outcome);
        $this->assertNotNull($row->gave_up_at);

        $this->travel(2)->days();
        $result = app(ProviderFloorplanRecovery::class)->recover('iguide');
        $this->assertSame(0, $result['imports'], 'A protected tour is not re-imported by the sweep.');
        $this->assertSame(1, $result['abandoned']);
    }

    public function test_protected_view_gets_a_fresh_token_from_the_ready_event_when_the_work_order_is_known(): void
    {
        $fresh = $this->jwt(21 * 86400);
        $shoot = $this->shoot('WO-1', $this->jwt(-3600));
        $this->fakeProvider($fresh);

        $job = $this->ingest($shoot);

        $job->assertNotReleased();
        $file = ShootFile::where('shoot_id', $shoot->id)->where('media_type', 'floorplan')->sole();
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame(self::DOC, $file->metadata['original_url'], 'The stored source link stays untokenized.');
        $this->assertSame($fresh, $shoot->fresh()->iguide_data['authtoken'], 'The fresh token is kept for later imports.');
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::PORTAL.'/iguides/igPROTECTED/events/ready/object')
            && $request['taskId'] === 'WO-1'
            && $request->hasHeader('X-Plntr-App-Id', 'fixture-app-id'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::DOC.'?accessToken='));
    }

    public function test_a_token_for_another_tour_is_used_but_never_saved_on_the_shoot(): void
    {
        $fresh = $this->jwt(21 * 86400);
        $shoot = $this->shoot('WO-1');
        $this->fakeProvider($fresh);
        // As in a unit-scoped view: same shoot ID, different tour identifiers.
        $view = clone $shoot;
        $view->iguide_property_id = 'igPROTECTED';
        DB::table('shoots')->where('id', $shoot->id)->update(['iguide_property_id' => 'igOTHER']);

        $this->assertSame($fresh, app(\App\Services\IguideService::class)->viewAccessToken($view));

        $this->assertArrayNotHasKey('authtoken', $shoot->fresh()->iguide_data ?? []);
    }

    public function test_a_saved_token_that_is_still_valid_is_used_without_asking_iguide(): void
    {
        $saved = $this->jwt(5 * 86400);
        $shoot = $this->shoot('WO-1', $saved);
        $this->fakeProvider();

        $this->ingest($shoot)->assertNotReleased();

        $this->assertSame(1, ShootFile::where('shoot_id', $shoot->id)->where('media_type', 'floorplan')->count());
        $this->assertSame(0, $this->portalCalls());
    }

    public function test_when_iguide_cannot_supply_a_token_the_tour_is_recorded_as_protected(): void
    {
        $shoot = $this->shoot('WO-1');
        app(ProviderAssetRecoveryThrottle::class)->record('recovery-iguide', $shoot->id, null, $this->floorplans(), null);
        $this->fakeProvider(null, 400);

        $job = $this->ingest($shoot);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(1, $this->portalCalls());
        $this->assertSame(ProviderAssetRecoveryThrottle::OUTCOME_PROTECTED, DB::table('provider_asset_recovery_attempts')->where('shoot_id', $shoot->id)->value('outcome'));

        // A refused lookup is not repeated for every asset or job within the hour.
        $this->ingest($shoot);
        $this->assertSame(1, $this->portalCalls());
    }

    public function test_a_waiting_page_without_a_redirect_still_retries_as_not_ready(): void
    {
        $shoot = $this->shoot();
        Http::fake([self::DOC.'*' => Http::response('<!doctype html><html>Generating…</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->ingest($shoot)->assertReleased();

        $this->assertSame(0, $this->portalCalls());
    }

    public function test_new_provider_links_lift_the_protected_mark(): void
    {
        $shoot = $this->shoot();
        $throttle = app(ProviderAssetRecoveryThrottle::class);
        $throttle->record('recovery-iguide', $shoot->id, null, $this->floorplans(), null);
        $throttle->markProtected($shoot->id);

        DB::table('shoots')->where('id', $shoot->id)->update(['iguide_floorplans' => json_encode([
            ['asset_key' => 'pdf_imperial', 'url' => self::DOC.'?accessToken='.$this->jwt(86400)],
        ])]);

        $this->assertSame(1, app(ProviderFloorplanRecovery::class)->recover('iguide')['imports']);
        $this->assertNull(DB::table('provider_asset_recovery_attempts')->where('shoot_id', $shoot->id)->value('outcome'));
    }

    public function test_staff_can_list_protected_tours(): void
    {
        $protected = $this->shoot();
        $other = $this->shoot();
        $throttle = app(ProviderAssetRecoveryThrottle::class);
        foreach ([$protected, $other] as $shoot) $throttle->record('recovery-iguide', $shoot->id, null, $this->floorplans(), null);
        $throttle->markProtected($protected->id);

        $this->artisan('iguide:protected-floorplans', ['--json' => true])
            ->expectsOutputToContain('"shoot_id":'.$protected->id)
            ->doesntExpectOutputToContain('"shoot_id":'.$other->id)
            ->assertSuccessful();
    }
}
