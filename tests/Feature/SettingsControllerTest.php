<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_save_an_empty_json_setting_value(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/settings', [
            'key' => 'integrations.zillow.address_overrides',
            'value' => [],
            'type' => 'json',
            'description' => 'Manual property fact overrides keyed by address',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('settings', [
            'key' => 'integrations.zillow.address_overrides',
            'type' => 'json',
        ]);

        $this->assertSame(
            '[]',
            DB::table('settings')->where('key', 'integrations.zillow.address_overrides')->value('value'),
        );
    }
}
