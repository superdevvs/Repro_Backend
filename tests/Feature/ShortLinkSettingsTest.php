<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShortLinkSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admins_receive_defaults_when_no_settings_row_exists(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/short-links/settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.code_length', 10)
            ->assertJsonPath('data.types.iguide_offline_viewer', true)
            ->assertJsonPath('data.types.share_download', false)
            ->assertJsonPath('data.types.media_zip', false)
            ->assertJsonPath('data.types.payment', false);
    }

    #[Test]
    public function admins_can_change_which_link_types_are_shortened(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/admin/short-links/settings', [
            'enabled' => true,
            'code_length' => 8,
            'types' => [
                'iguide_offline_viewer' => true,
                'share_download' => true,
                'media_zip' => true,
                'payment' => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.code_length', 8)
            ->assertJsonPath('data.types.share_download', true)
            ->assertJsonPath('data.types.payment', false);

        $this->getJson('/api/admin/short-links/settings')
            ->assertOk()
            ->assertJsonPath('data.types.media_zip', true);
    }

    #[Test]
    public function clients_cannot_read_or_change_short_link_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));

        $this->getJson('/api/admin/short-links/settings')->assertForbidden();
        $this->putJson('/api/admin/short-links/settings', [
            'enabled' => false,
            'code_length' => 10,
            'types' => [
                'iguide_offline_viewer' => false,
                'share_download' => false,
                'media_zip' => false,
                'payment' => false,
            ],
        ])->assertForbidden();
    }

    #[Test]
    public function unknown_types_and_short_codes_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/admin/short-links/settings', [
            'enabled' => true,
            'code_length' => 4,
            'types' => [
                'iguide_offline_viewer' => true,
                'share_download' => false,
                'media_zip' => false,
                'payment' => false,
            ],
        ])->assertUnprocessable();

        $this->putJson('/api/admin/short-links/settings', [
            'enabled' => true,
            'code_length' => 10,
            'types' => [
                'iguide_offline_viewer' => true,
                'mystery' => true,
            ],
        ])->assertUnprocessable();
    }
}
