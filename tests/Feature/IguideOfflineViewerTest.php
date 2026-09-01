<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\IguideOfflineViewerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class IguideOfflineViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('v', 32)));
        Config::set('iguide.offline_viewer.url_ttl_minutes', 60);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function authorized_staff_receive_a_path_signed_link_for_the_current_clean_package(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:00:00 UTC'));
        [$shoot, $file] = $this->readyPackage();
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson($this->viewLinkEndpoint($shoot));

        $response->assertOk()
            ->assertJsonPath('file_id', $file->id)
            ->assertJsonStructure(['viewer_url', 'expires_at']);

        $url = (string) $response->json('viewer_url');
        $this->assertMatchesRegularExpression(
            "#/api/iguide/offline-view/{$shoot->id}/{$file->id}/[0-9]+/[a-f0-9]{64}/tour/index\.html$#",
            rawurldecode((string) parse_url($url, PHP_URL_PATH))
        );
        $this->assertSame(
            now()->addMinutes(60)->timestamp,
            Carbon::parse((string) $response->json('expires_at'))->timestamp
        );

        // Trusted staff retain the existing source-package download access.
        $this->get("/api/images/{$file->id}/download/original")->assertOk();
        $this->postJson('/api/images/download/batch', ['file_ids' => [$file->id]])->assertOk();
    }

    #[Test]
    public function owning_client_can_view_but_cannot_upload_replace_or_download_the_private_zip(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        [$shoot, $file] = $this->readyPackage();
        $shoot->update(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->postJson($this->viewLinkEndpoint($shoot))
            ->assertOk()
            ->assertJsonPath('file_id', $file->id)
            ->assertJsonStructure(['viewer_url', 'expires_at']);

        // Both the legacy one-request upload and the resumable replacement
        // workflow remain staff-only even for the shoot's owning client.
        $this->postJson("/api/integrations/shoots/{$shoot->id}/iguide/offline-package")
            ->assertForbidden();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/integrations/shoots/{$shoot->id}/iguide/offline-package/uploads", [
                'filename' => 'replacement.zip',
                'size_bytes' => 1024,
            ])
            ->assertForbidden();

        // The signed viewer streams only validated members from the archive;
        // it does not grant access to the original private ZIP download route.
        $this->get("/api/shoots/{$shoot->id}/media/{$file->id}/download")
            ->assertForbidden();
        $this->get("/api/images/{$file->id}/download/original")
            ->assertForbidden();
        $this->postJson('/api/images/download/batch', ['file_ids' => [$file->id]])
            ->assertNotFound()
            ->assertJsonPath('error', 'No files available for download');
    }

    #[Test]
    public function linked_and_delivered_ghost_clients_follow_the_existing_shoot_access_scope(): void
    {
        $linkedOwner = User::factory()->create(['role' => 'client']);
        $linkedClient = User::factory()->create(['role' => 'client']);
        [$linkedShoot] = $this->readyPackage();
        $linkedShoot->update(['client_id' => $linkedClient->id]);
        AccountLink::create([
            'main_account_id' => $linkedOwner->id,
            'linked_account_id' => $linkedClient->id,
            'shared_details' => ['shoots' => true],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $linkedOwner->id,
        ]);

        Sanctum::actingAs($linkedOwner);
        $this->postJson($this->viewLinkEndpoint($linkedShoot))->assertOk();

        $ghost = User::factory()->create(['role' => 'client']);
        [$ghostShoot] = $this->readyPackage();
        $ghostShoot->ghostUsers()->attach($ghost->id);
        $ghostShoot->update(['workflow_status' => Shoot::STATUS_DELIVERED]);

        Sanctum::actingAs($ghost);
        $this->postJson($this->viewLinkEndpoint($ghostShoot))->assertOk();

        $ghostShoot->update(['workflow_status' => Shoot::STATUS_SCHEDULED]);
        $this->postJson($this->viewLinkEndpoint($ghostShoot))->assertForbidden();
    }

    #[Test]
    public function unrelated_clients_and_editors_cannot_issue_viewer_links(): void
    {
        [$shoot] = $this->readyPackage();

        foreach (['client', 'editor'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson($this->viewLinkEndpoint($shoot))->assertForbidden();
        }
    }

    #[Test]
    public function unauthenticated_users_cannot_issue_viewer_links(): void
    {
        [$shoot] = $this->readyPackage();

        $this->postJson($this->viewLinkEndpoint($shoot))->assertUnauthorized();
    }

    #[Test]
    public function public_branded_tours_resolve_a_ready_package_without_exposing_the_zip(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:00:00 UTC'));
        [$shoot, $file] = $this->readyPackage();
        $shoot->fill([
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'iguide_tour_url' => 'https://youriguide.com/provider-version/',
        ]);
        $shoot->tour_links = [
            'iguide_branded' => 'https://youriguide.com/manually-entered-version/',
        ];
        $shoot->save();

        $response = $this->getJson("/api/public/shoots/{$shoot->id}/branded");

        $response->assertOk();
        $viewerUrl = (string) $response->json('iguide_tour_url');
        $this->assertSame($viewerUrl, $response->json('iguide_url'));
        $this->assertSame($viewerUrl, $response->json('tour_links.iguide_branded'));
        $this->assertSame('published_offline_package', $response->json('iguide_viewer.source'));
        $this->assertSame($viewerUrl, $response->json('iguide_viewer.inline_url'));
        $this->assertSame($viewerUrl, $response->json('iguide_viewer.open_url'));
        $this->assertSame(
            now()->addMinutes(60)->toIso8601String(),
            $response->json('iguide_viewer.expires_at')
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');
        $this->assertMatchesRegularExpression(
            "#/api/iguide/offline-view/{$shoot->id}/{$file->id}/[0-9]+/[a-f0-9]{64}/tour/index\.html$#",
            rawurldecode((string) parse_url($viewerUrl, PHP_URL_PATH))
        );

        $serializedPayload = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('offline-tour.zip', $serializedPayload);
        $this->assertStringNotContainsString('secure/iguide-packages', $serializedPayload);

        // The public bearer streams only the validated viewer member. It does
        // not make the original private archive's download endpoint public.
        $this->get((string) parse_url($viewerUrl, PHP_URL_PATH))->assertOk();
        $this->get("/api/images/{$file->id}/download/original")->assertUnauthorized();
    }

    #[Test]
    public function public_mls_tours_use_the_local_viewer_without_falling_back_to_a_branded_external_url(): void
    {
        [$shoot] = $this->readyPackage();
        $shoot->fill([
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'iguide_tour_url' => 'https://youriguide.com/provider-branded/',
        ]);
        $shoot->tour_links = [
            'iguide_branded' => 'https://youriguide.com/provider-branded/',
            'iguide_mls' => 'https://unbranded.youriguide.com/provider-unbranded/',
            'iguide_mls_source' => 'unbranded_url',
        ];
        $shoot->save();

        $mls = $this->getJson("/api/public/shoots/{$shoot->id}/mls");

        $mls->assertOk()
            ->assertJsonPath('iguide_viewer.source', 'published_offline_package');
        $localViewer = (string) $mls->json('iguide_tour_url');
        $this->assertStringContainsString('/iguide/offline-view/', $localViewer);
        $this->assertSame($localViewer, $mls->json('iguide_url'));
        $this->assertSame($localViewer, $mls->json('tour_links.iguide_mls'));
        $this->assertArrayNotHasKey('iguide_branded', $mls->json('tour_links'));

        // Once the package ceases to be ready, the verified provider URL is
        // used. It remains isolated from the branded provider destination.
        $iguideData = $shoot->fresh()->iguide_data;
        $iguideData['manual_offline_package']['status'] = 'failed';
        $shoot->fill([
            'iguide_data' => $iguideData,
            'iguide_tour_url' => 'https://youriguide.com/provider-branded/',
        ]);
        $shoot->tour_links = [
            'iguide_branded' => 'https://youriguide.com/provider-branded/',
            'iguide_mls' => 'https://unbranded.youriguide.com/provider-unbranded/',
            'iguide_mls_source' => 'unbranded_url',
        ];
        $shoot->save();
        $shoot->refresh();
        $providerMls = $this->getJson("/api/public/shoots/{$shoot->id}/mls");
        $providerMls->assertOk()
            ->assertJsonPath('iguide_tour_url', 'https://unbranded.youriguide.com/provider-unbranded/')
            ->assertJsonPath('iguide_viewer.source', 'provider_unbranded');

        $shoot->tour_links = [
            'iguide_branded' => 'https://youriguide.com/provider-branded/',
        ];
        $shoot->save();

        $withoutAttestedMlsUrl = $this->getJson("/api/public/shoots/{$shoot->id}/mls");
        $withoutAttestedMlsUrl->assertOk()
            ->assertJsonPath('iguide_tour_url', null)
            ->assertJsonPath('iguide_url', null);
        $this->assertArrayNotHasKey('iguide_branded', $withoutAttestedMlsUrl->json('tour_links'));
        $this->assertArrayNotHasKey('iguide_mls', $withoutAttestedMlsUrl->json('tour_links'));
    }

    #[Test]
    public function an_undelivered_public_tour_never_receives_an_offline_viewer_bearer(): void
    {
        [$shoot] = $this->readyPackage();
        $shoot->update([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'iguide_tour_url' => 'https://youriguide.com/provider-branded/',
        ]);

        $response = $this->getJson("/api/public/shoots/{$shoot->id}/branded");

        $response->assertOk()
            ->assertJsonPath('iguide_tour_url', 'https://youriguide.com/provider-branded/')
            ->assertJsonPath('iguide_viewer.source', 'provider_fetched')
            ->assertJsonPath('iguide_viewer.expires_at', null);
        $this->assertStringNotContainsString('/iguide/offline-view/', (string) $response->getContent());
    }

    #[Test]
    public function the_data_migration_attests_an_existing_clean_ready_staff_upload(): void
    {
        [$shoot] = $this->readyPackage();
        $iguideData = $shoot->iguide_data;
        unset($iguideData['manual_offline_package']['publication_attestation']);
        $shoot->update(['iguide_data' => $iguideData]);

        $migration = require database_path(
            'migrations/2026_09_01_040000_attest_existing_ready_iguide_offline_packages.php'
        );
        $migration->up();

        $attestation = data_get($shoot->fresh()->iguide_data, 'manual_offline_package.publication_attestation');
        $this->assertSame('authorized_staff_official_iguide_export', $attestation['policy']);
        $this->assertSame(1, $attestation['version']);
        $this->assertSame(['branded', 'mls'], $attestation['audiences']);
        $this->assertTrue($attestation['backfilled']);

        $migration->down();
        $this->assertNull(data_get(
            $shoot->fresh()->iguide_data,
            'manual_offline_package.publication_attestation'
        ));
    }

    #[Test]
    public function the_signed_route_isolates_html_and_still_serves_relative_assets_to_its_opaque_origin(): void
    {
        [$shoot] = $this->readyPackage([
            'tour/index.html' => '<!doctype html><html><head><script>window.tourValue=localStorage.getItem("tour");</script></head><body>Private tour</body></html>',
            'tour/assets/app.css' => 'body { color: blue; }',
        ]);
        $url = $this->issueLink($shoot);
        $indexPath = (string) parse_url($url, PHP_URL_PATH);

        $index = $this->get($indexPath);
        $index->assertOk()
            ->assertStreamed()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'inline; filename=index.html')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $indexBody = $index->streamedContent();
        $this->assertStringStartsWith('<!doctype html><html><head>', $indexBody);
        $this->assertStringContainsString('data-repro-iguide-storage-shim', $indexBody);
        $this->assertStringContainsString('Object.defineProperty(window,name', $indexBody);
        $this->assertStringContainsString('BYTE_LIMIT=1048576,ITEM_LIMIT=1024', $indexBody);
        $this->assertStringContainsString('QuotaExceededError', $indexBody);
        $this->assertLessThan(
            strpos($indexBody, '<script>window.tourValue'),
            strpos($indexBody, 'data-repro-iguide-storage-shim')
        );
        $this->assertSame(strlen($indexBody), (int) $index->headers->get('Content-Length'));

        $csp = (string) $index->headers->get('Content-Security-Policy');
        $this->assertStringContainsString(
            'sandbox allow-scripts allow-pointer-lock allow-modals',
            $csp
        );
        foreach (['allow-same-origin', 'allow-top-navigation', 'allow-forms', 'allow-popups', 'allow-downloads'] as $forbiddenToken) {
            $this->assertStringNotContainsString($forbiddenToken, $csp);
        }
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
        $this->assertStringContainsString("frame-src 'none'", $csp);
        $this->assertMatchesRegularExpression('/connect-src http:\/\/localhost(?::[0-9]+)?;/', $csp);
        $this->assertStringNotContainsString('connect-src *', $csp);
        $this->assertStringContainsString('private', (string) $index->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=', (string) $index->headers->get('Cache-Control'));
        preg_match('/max-age=([0-9]+)/', (string) $index->headers->get('Cache-Control'), $cacheAge);
        $this->assertNotEmpty($cacheAge[1] ?? null);
        $this->assertGreaterThan(0, (int) $cacheAge[1]);
        $this->assertLessThanOrEqual(3600, (int) $cacheAge[1]);

        $assetPath = Str::beforeLast($indexPath, '/index.html').'/assets/app.css';
        $this->withHeader('Origin', 'null')->get($assetPath.'?cache-bust=123')
            ->assertOk()
            ->assertStreamedContent('body { color: blue; }')
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');

        // Omitting the asset suffix is a supported entry point and resolves to
        // the package's validated root index.html.
        $defaultEntry = $this->get(Str::beforeLast($indexPath, '/tour/index.html'))->assertOk();
        $this->assertStringContainsString('Private tour', $defaultEntry->streamedContent());
    }

    #[Test]
    public function every_browser_active_document_type_is_sandboxed_without_breaking_svg_assets(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>window.parent.document.body.textContent="owned"</script><circle cx="5" cy="5" r="5"/></svg>';
        $xhtml = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><script>window.parent.document.body.textContent="owned"</script></head><body>Tour</body></html>';
        $xml = '<?xml version="1.0"?><tour><name>Private tour</name></tour>';
        $pdf = "%PDF-1.4\n% private brochure\n";
        [$shoot] = $this->readyPackage([
            'tour/index.html' => '<!doctype html><html><head></head><body>Private tour</body></html>',
            'tour/assets/marker.svg' => $svg,
            'tour/assets/payload.xhtml' => $xhtml,
            'tour/assets/data.xml' => $xml,
            'tour/assets/brochure.pdf' => $pdf,
        ]);
        $indexPath = (string) parse_url($this->issueLink($shoot), PHP_URL_PATH);
        $assetPrefix = Str::beforeLast($indexPath, '/index.html').'/assets/';

        foreach ([
            'marker.svg' => [$svg, 'image/svg+xml; charset=UTF-8'],
            'payload.xhtml' => [$xhtml, 'application/xhtml+xml; charset=UTF-8'],
            'data.xml' => [$xml, 'application/xml; charset=UTF-8'],
            'brochure.pdf' => [$pdf, 'application/pdf'],
        ] as $filename => [$body, $contentType]) {
            $response = $this->get($assetPrefix.$filename);
            $response->assertOk()
                ->assertStreamedContent($body)
                ->assertHeader('Content-Type', $contentType)
                ->assertHeader('Content-Disposition', "inline; filename={$filename}")
                ->assertHeader('Access-Control-Allow-Origin', '*')
                ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');

            $this->assertOpaqueDocumentPolicy((string) $response->headers->get('Content-Security-Policy'));
        }
    }

    #[Test]
    public function a_package_without_a_wrapper_uses_its_root_index(): void
    {
        [$shoot] = $this->readyPackage([
            'index.html' => '<html>root tour</html>',
            'app.js' => 'console.log("root tour")',
        ], null);
        $path = (string) parse_url($this->issueLink($shoot), PHP_URL_PATH);

        $this->assertStringEndsWith('/index.html', $path);
        $this->assertStringNotContainsString('/tour/index.html', $path);
        $rootIndex = $this->get($path)->assertOk();
        $this->assertStringContainsString('<html>root tour</html>', $rootIndex->streamedContent());
    }

    #[Test]
    public function mixed_case_index_paths_are_preserved_and_legacy_rows_use_an_unambiguous_fallback(): void
    {
        [$shoot, $file] = $this->readyPackage([
            'Tour/Index.HTML' => '<!doctype html><html><head></head><body>Mixed case tour</body></html>',
            'Tour/app.js' => 'console.log("mixed")',
        ], 'Tour');

        $exactPath = rawurldecode((string) parse_url($this->issueLink($shoot), PHP_URL_PATH));
        $this->assertStringEndsWith('/Tour/Index.HTML', $exactPath);
        $this->assertStringContainsString('Mixed case tour', $this->get($exactPath)->assertOk()->streamedContent());

        $metadata = $file->metadata;
        unset($metadata['index_entry_path']);
        $file->update(['metadata' => $metadata]);
        $iguideData = $shoot->fresh()->iguide_data;
        unset($iguideData['manual_offline_package']['index_entry_path']);
        $shoot->update(['iguide_data' => $iguideData]);

        $legacyPath = rawurldecode((string) parse_url($this->issueLink($shoot->fresh()), PHP_URL_PATH));
        $this->assertStringEndsWith('/Tour/index.html', $legacyPath);
        $this->assertStringContainsString('Mixed case tour', $this->get($legacyPath)->assertOk()->streamedContent());
    }

    #[Test]
    public function expired_and_tampered_links_are_rejected_before_archive_access(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:00:00 UTC'));
        [$shoot] = $this->readyPackage();
        $path = (string) parse_url($this->issueLink($shoot), PHP_URL_PATH);

        $segments = explode('/', trim($path, '/'));
        $this->assertSame('offline-view', $segments[2]);
        $segments[6] = str_repeat($segments[6][0] === 'a' ? 'b' : 'a', 64);
        $this->get('/'.implode('/', $segments))->assertForbidden();

        Carbon::setTestNow(now()->addMinutes(61));
        $this->get($path)->assertForbidden();

        // Oversized route numbers fail cleanly instead of coercing into an
        // integer or throwing before signature validation.
        $segments[3] = str_repeat('9', 100);
        $this->get('/'.implode('/', $segments))->assertForbidden();
    }

    #[Test]
    public function traversal_and_paths_outside_the_validated_wrapper_are_not_served(): void
    {
        [$shoot, $file] = $this->readyPackage([
            'tour/index.html' => '<html>tour</html>',
            'tour/assets/app.js' => 'console.log("tour")',
        ]);
        $path = (string) parse_url($this->issueLink($shoot), PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));
        $expires = (int) $segments[5];
        $signature = $segments[6];
        $viewer = app(IguideOfflineViewerService::class);

        foreach (['tour/../secret.txt', '../tour/index.html', 'outside/index.html', "tour/asset\0.js"] as $unsafePath) {
            try {
                $viewer->streamAsset($shoot->id, $file->id, $expires, $signature, $unsafePath);
                $this->fail("Unsafe viewer path was accepted: {$unsafePath}");
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
    }

    #[Test]
    public function a_link_stops_working_when_its_file_is_no_longer_the_current_ready_package(): void
    {
        [$shoot] = $this->readyPackage();
        $path = (string) parse_url($this->issueLink($shoot), PHP_URL_PATH);

        $data = $shoot->fresh()->iguide_data;
        $data['manual_offline_package']['file_id'] = 999999;
        $shoot->update(['iguide_data' => $data]);

        $this->get($path)->assertNotFound();
    }

    #[Test]
    public function a_ready_pointer_cannot_issue_or_serve_when_the_file_is_not_clean(): void
    {
        [$shoot, $file] = $this->readyPackage();
        Sanctum::actingAs(User::factory()->admin()->create());
        $path = (string) parse_url(
            (string) $this->postJson($this->viewLinkEndpoint($shoot))->json('viewer_url'),
            PHP_URL_PATH
        );

        $file->update(['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);

        $this->postJson($this->viewLinkEndpoint($shoot))->assertNotFound();
        $this->get($path)->assertNotFound();
    }

    /**
     * @param  array<string,string>  $entries
     * @return array{0:Shoot,1:ShootFile}
     */
    private function readyPackage(array $entries = [
        'tour/index.html' => '<html>tour</html>',
        'tour/assets/app.js' => 'console.log("tour")',
    ], ?string $wrapper = 'tour'): array
    {
        $uploader = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'total_quote' => 0,
            'payment_status' => 'paid',
            'bypass_paywall' => true,
        ]);
        $uploadId = (string) Str::uuid();
        $storagePath = "secure/iguide-packages/{$shoot->id}/offline-tour.zip";
        $zipPath = tempnam(sys_get_temp_dir(), 'iguide-viewer-');
        $this->assertIsString($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $entry => $contents) {
            $this->assertTrue($zip->addFromString($entry, $contents));
        }
        $zip->close();
        $contents = file_get_contents($zipPath);
        @unlink($zipPath);
        $this->assertIsString($contents);
        Storage::disk('local')->put($storagePath, $contents);

        $indexEntryPath = null;
        foreach (array_keys($entries) as $entryPath) {
            $relative = $wrapper === null
                ? $entryPath
                : (str_starts_with($entryPath, $wrapper.'/')
                    ? substr($entryPath, strlen($wrapper) + 1)
                    : null);
            if (is_string($relative) && strcasecmp($relative, 'index.html') === 0) {
                $indexEntryPath = $entryPath;
                break;
            }
        }
        $this->assertIsString($indexEntryPath);

        $file = ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'offline-tour.zip',
            'stored_filename' => 'offline-tour.zip',
            'path' => $storagePath,
            'file_type' => 'application/zip',
            'file_size' => strlen($contents),
            'media_type' => ShootFile::MEDIA_TYPE_IGUIDE,
            'uploaded_by' => $uploader->id,
            'workflow_stage' => ShootFile::STAGE_ARCHIVED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'scan_result' => 'clean',
            'metadata' => [
                'kind' => ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND,
                'upload_id' => $uploadId,
                'wrapper_directory' => $wrapper,
                'index_entry_path' => $indexEntryPath,
            ],
        ]);

        $shoot->update([
            'iguide_data' => [
                'manual_offline_package' => [
                    'id' => $uploadId,
                    'upload_id' => $uploadId,
                    'status' => 'ready',
                    'file_id' => $file->id,
                    'uploaded_by' => $uploader->id,
                    'uploaded_at' => now()->toIso8601String(),
                    'original_filename' => 'offline-tour.zip',
                    'wrapper_directory' => $wrapper,
                    'index_entry_path' => $indexEntryPath,
                    'ready_at' => now()->toIso8601String(),
                    'publication_attestation' => [
                        'policy' => 'authorized_staff_official_iguide_export',
                        'version' => 1,
                        'audiences' => ['branded', 'mls'],
                        'attested_by' => $uploader->id,
                        'attested_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ]);

        return [$shoot->fresh(), $file->fresh()];
    }

    private function issueLink(Shoot $shoot): string
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        return (string) $this->postJson($this->viewLinkEndpoint($shoot))
            ->assertOk()
            ->json('viewer_url');
    }

    private function viewLinkEndpoint(Shoot $shoot): string
    {
        return "/api/integrations/shoots/{$shoot->id}/iguide/offline-package/view-link";
    }

    private function assertOpaqueDocumentPolicy(string $policy): void
    {
        $this->assertStringContainsString(
            'sandbox allow-scripts allow-pointer-lock allow-modals',
            $policy
        );
        foreach (['allow-same-origin', 'allow-top-navigation', 'allow-forms', 'allow-popups', 'allow-downloads'] as $forbiddenToken) {
            $this->assertStringNotContainsString($forbiddenToken, $policy);
        }
        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringNotContainsString('connect-src *', $policy);
    }
}
