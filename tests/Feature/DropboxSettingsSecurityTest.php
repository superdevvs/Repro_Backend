<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DropboxSettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    #[DataProvider('protectedKeys')]
    public function test_legacy_dropbox_settings_never_return_values_or_descriptions(string $key): void
    {
        $stored = json_encode([
            'access_token' => 'test-access-secret',
            'refreshToken' => 'test-refresh-secret',
            'clientSecret' => 'test-client-secret',
            'nested' => ['token' => 'test-nested-secret'],
        ]);
        $this->storeLegacySetting($key, $stored);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/admin/settings/'.rawurlencode($key));

        $response->assertStatus(410)->assertJsonMissingPath('data');
        foreach (['test-access-secret', 'test-refresh-secret', 'test-client-secret', 'test-nested-secret', 'test-description-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame($stored, DB::table('settings')->where('key', $key)->value('value'));
        Http::assertNothingSent();
    }

    #[DataProvider('protectedKeys')]
    public function test_generic_settings_cannot_replace_dropbox_connection_credentials(string $key): void
    {
        $stored = '{"access_token":"existing-test-token"}';
        $this->storeLegacySetting($key, $stored);

        foreach (['admin', 'superadmin', 'editing_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson('/api/admin/settings', [
                'key' => $key,
                'type' => 'json',
                'value' => ['access_token' => 'replacement-test-token', 'account_id' => 'different-account'],
            ])->assertStatus(422)->assertJsonValidationErrors('key');
            $this->assertSame($stored, DB::table('settings')->where('key', $key)->value('value'));
        }

        Http::assertNothingSent();
    }

    public static function protectedKeys(): array
    {
        return [
            'provider settings' => ['integrations.dropbox'],
            'nested token' => ['integrations.dropbox.access_token'],
            'flat token alias' => ['DROPBOX_CLIENT_SECRET'],
            'bare provider alias' => ['dropbox.refreshToken'],
            'service config alias' => ['services.dropbox.client_secret'],
        ];
    }

    public function test_changing_serialization_type_cannot_create_a_dropbox_setting(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));

        foreach (['string', 'json', 'boolean', 'integer'] as $type) {
            $this->postJson('/api/admin/settings', [
                'key' => 'integrations.dropbox',
                'type' => $type,
                'value' => 'browser-supplied-token',
            ])->assertStatus(422);
        }

        $this->assertDatabaseMissing('settings', ['key' => 'integrations.dropbox']);
        Http::assertNothingSent();
    }

    private function storeLegacySetting(string $key, string $value): void
    {
        DB::table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'type' => 'json',
            'description' => 'test-description-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
