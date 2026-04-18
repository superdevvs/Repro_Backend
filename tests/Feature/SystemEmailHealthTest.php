<?php

namespace Tests\Feature;

use App\Models\MessageChannel;
use App\Models\User;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SystemEmailHealthTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_admin_can_view_healthy_system_email_status(): void
    {
        $this->createDefaultCakeMailChannel();

        $provider = Mockery::mock(CakemailProvider::class);
        $provider->shouldReceive('testConnection')
            ->once()
            ->andReturn([
                'success' => true,
                'account' => ['name' => 'Ops Account'],
                'senders' => [['id' => 'sender-1']],
                'lists' => [['id' => 1]],
            ]);
        $this->app->instance(CakemailProvider::class, $provider);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'email' => 'admin-email-health@example.com',
        ]));

        $this->getJson('/api/system/email-health')
            ->assertOk()
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('provider', 'CAKEMAIL')
            ->assertJsonPath('checks.default_channel.success', true)
            ->assertJsonPath('checks.provider_connection.success', true)
            ->assertJsonPath('checks.send_capability.success', true);
    }

    public function test_system_email_health_reports_missing_config(): void
    {
        $provider = Mockery::mock(CakemailProvider::class);
        $provider->shouldReceive('testConnection')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Cakemail base URL is not configured. Set CAKEMAIL_BASE_URL before sending transactional email.',
            ]);
        $this->app->instance(CakemailProvider::class, $provider);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'email' => 'admin-email-health-missing@example.com',
        ]));

        $this->getJson('/api/system/email-health')
            ->assertStatus(503)
            ->assertJsonPath('healthy', false)
            ->assertJsonPath('failure_type', 'missing_config')
            ->assertJsonPath('checks.default_channel.success', false)
            ->assertJsonPath('checks.send_capability.success', false);
    }

    public function test_system_email_health_reports_authentication_failures(): void
    {
        $this->createDefaultCakeMailChannel();

        $provider = Mockery::mock(CakemailProvider::class);
        $provider->shouldReceive('testConnection')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Failed to authenticate. Check your credentials.',
            ]);
        $this->app->instance(CakemailProvider::class, $provider);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'superadmin',
            'email' => 'superadmin-email-health@example.com',
        ]));

        $this->getJson('/api/system/email-health')
            ->assertStatus(503)
            ->assertJsonPath('healthy', false)
            ->assertJsonPath('failure_type', 'authentication')
            ->assertJsonPath('checks.default_channel.success', true)
            ->assertJsonPath('checks.provider_connection.success', false)
            ->assertJsonPath('checks.send_capability.success', false);
    }

    public function test_non_admin_cannot_view_system_email_health(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'client',
            'email' => 'client-email-health@example.com',
        ]));

        $this->getJson('/api/system/email-health')
            ->assertForbidden();
    }

    private function createDefaultCakeMailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'CAKEMAIL',
            'display_name' => 'Cakemail Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }
}
