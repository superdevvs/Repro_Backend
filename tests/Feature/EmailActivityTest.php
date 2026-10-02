<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailActivityTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $provider = 'RESEND'): Message
    {
        $message = app(MessagingService::class)->scheduleEmail(['to' => 'client@example.com', 'subject' => 'Test', 'body_text' => 'Test'], now());
        $message->update(['provider' => $provider, 'provider_message_id' => 'provider-123', 'status' => 'SENT', 'sent_at' => now()]);

        return $message;
    }

    public function test_activity_requires_authentication_and_existing_message_visibility(): void
    {
        $message = $this->message();
        $this->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'salesRep']))
            ->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertOk()->assertJsonPath('status', 'SENT');
        $message->update(['provider' => 'INTERNAL']);
        $this->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertNotFound();
    }

    public function test_saved_events_preserve_counts_and_hide_link_query_tokens(): void
    {
        $message = $this->message();
        $message->update(['metadata' => ['open_count' => 4, 'click_count' => 2, 'email_activity' => [
            ['id' => 'open', 'type' => 'opened', 'at' => now()->toIso8601String()],
            ['id' => 'click', 'type' => 'clicked', 'at' => now()->toIso8601String(), 'link' => 'https://example.com/reset?token=secret#private'],
        ]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertOk()
            ->assertJsonPath('open_count', 4)->assertJsonPath('click_count', 2)->assertDontSee('secret')->assertDontSee('private');
    }

    public function test_cakemail_suppression_is_visible_without_mutating_original_message(): void
    {
        $message = $this->message('CAKEMAIL');
        $this->mock(CakemailProvider::class, function ($mock) {
            $mock->shouldReceive('getLogs')->once()->with(['email_id' => 'provider-123', 'per_page' => 100])->andReturn([
                ['id' => 'rejection', 'email_id' => 'provider-123', 'type' => 'rejected', 'time' => now()->timestamp, 'metadata' => ['reason' => 'suppressed']],
                ['id' => 'unrelated', 'email_id' => 'another-email', 'type' => 'opened', 'time' => now()->timestamp],
            ]);
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertOk()
            ->assertJsonPath('status', 'FAILED')->assertJsonPath('open_count', 0)->assertSee('suppressed')->assertDontSee('unrelated');
        $this->assertSame('SENT', $message->refresh()->status);
    }

    public function test_provider_failure_keeps_saved_evidence_available(): void
    {
        $message = $this->message('CAKEMAIL');
        $message->update(['metadata' => ['open_count' => 3, 'opened_at' => now()->toIso8601String()]]);
        $this->mock(CakemailProvider::class, fn ($mock) => $mock->shouldReceive('getLogs')->andThrow(new \RuntimeException('provider secret')));
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertOk()
            ->assertJsonPath('provider_logs_available', false)->assertJsonPath('open_count', 3)->assertDontSee('provider secret');
    }

    public function test_timeline_orders_by_instant_across_timezone_offsets(): void
    {
        $message = $this->message();
        $message->update(['sent_at' => null, 'metadata' => ['email_activity' => [
            ['id' => 'later', 'type' => 'opened', 'at' => '2026-10-02T04:30:00+00:00'],
            ['id' => 'earlier', 'type' => 'delivered', 'at' => '2026-10-02T09:49:28+05:30'],
        ]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/messaging/email/messages/'.$message->id.'/activity')->assertOk()
            ->assertJsonPath('events.0.id', 'earlier')->assertJsonPath('events.1.id', 'later');
    }
}
