<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\User;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class EmailOpsSummaryCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_email_ops_summary_command_returns_success_when_no_blocking_issues_exist(): void
    {
        $this->createDefaultCakeMailChannel();
        $this->mockHealthyProvider();

        $exitCode = Artisan::call('messaging:email-ops-summary', [
            '--sample' => 2,
            '--queued-minutes' => 5,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('healthy=true', $output);
        $this->assertStringContainsString('blocking_issues_present=false', $output);
        $this->assertStringContainsString('count.failed_outbound_messages=0', $output);
    }

    public function test_email_ops_summary_command_returns_failure_when_blocking_issues_exist(): void
    {
        $this->createDefaultCakeMailChannel();
        $this->mockHealthyProvider();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'ops-command-client@example.com',
        ]);

        Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'CAKEMAIL',
            'from_address' => 'contact@reprophotos.com',
            'to_address' => $client->email,
            'subject' => 'Failed Ops Command',
            'body_text' => 'Failed Ops Command',
            'body_html' => '<p>Failed Ops Command</p>',
            'status' => 'FAILED',
            'send_source' => 'SHOOT_SCHEDULED',
            'related_account_id' => $client->id,
            'error_message' => 'Provider failure',
            'failed_at' => now()->subMinutes(10),
        ]);

        $exitCode = Artisan::call('messaging:email-ops-summary', [
            '--sample' => 2,
            '--queued-minutes' => 5,
        ]);

        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('blocking_issues_present=true', $output);
        $this->assertStringContainsString('count.failed_outbound_messages=1', $output);
        $this->assertStringContainsString('kind=failed_outbound_message', $output);
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

    private function mockHealthyProvider(): void
    {
        $provider = Mockery::mock(CakemailProvider::class);
        $provider->shouldReceive('testConnection')
            ->andReturn([
                'success' => true,
                'account' => ['name' => 'Ops Account'],
                'senders' => [['id' => 'sender-1']],
                'lists' => [['id' => 1]],
            ]);
        $this->app->instance(CakemailProvider::class, $provider);
    }
}
