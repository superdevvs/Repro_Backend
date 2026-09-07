<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityCredentialInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_reports_presence_without_credentials_or_provider_mutation(): void
    {
        Http::preventStrayRequests();
        config(['services.google.client_secret' => 'sensitive-synthetic-value-not-for-output']);
        config(['services.dropbox.client_secret' => 'retired-synthetic-value-not-for-output']);
        config(['filesystems.disks.s3.secret' => null]);
        $this->assertSame(0, Artisan::call('security:credential-inventory'));
        $output = Artisan::output();
        $this->assertStringNotContainsString('sensitive-synthetic-value-not-for-output', $output);
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($data['read_only']);
        $this->assertTrue($data['credentials']['GOOGLE_CLIENT_SECRET']['configured']);
        $this->assertFalse($data['credentials']['DROPBOX_CLIENT_SECRET']['configured']);
        $this->assertSame('retired_integration_historical_inventory_only', $data['credentials']['DROPBOX_CLIENT_SECRET']['consumer']);
        $this->assertStringNotContainsString('retired-synthetic-value-not-for-output', $output);
        $this->assertFalse($data['credentials']['AWS_SECRET_ACCESS_KEY']['configured']);
        $this->assertFalse($data['credentials']['DROPBOX_CLIENT_SECRET']['provider_revocation_verified']);
        $this->assertFalse($data['archive_values_compared']);
        Http::assertNothingSent();
    }
}
