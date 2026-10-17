<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportSearchTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $requester, string $subject = 'Ordinary request'): SupportTicket
    {
        return SupportTicket::create(['requester_id' => $requester->id, 'request_key' => (string) Str::uuid(), 'subject' => $subject, 'category' => 'other']);
    }

    public function test_support_search_finds_requester_and_historical_messages_with_status_and_pagination(): void
    {
        $requester = User::factory()->create(['role' => 'client', 'name' => 'Window Client', 'email' => 'window@example.test']);
        $first = $this->ticket($requester);
        $second = $this->ticket($requester);
        $second->update(['status' => 'resolved']);
        $third = $this->ticket(User::factory()->create(['role' => 'photographer']));
        $third->messages()->create(['author_id' => $third->requester_id, 'request_key' => (string) Str::uuid(), 'body' => 'An old WINDOW revision']);
        $third->messages()->create(['author_id' => $third->requester_id, 'request_key' => (string) Str::uuid(), 'body' => 'A newer unrelated message']);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');

        $response = $this->getJson('/api/support/tickets?query=window&status=open&per_page=1')->assertOk()->assertJsonPath('meta.pagination.total', 2)->assertJsonCount(1, 'data');
        $page2 = $this->getJson('/api/support/tickets?query=window&status=open&per_page=1&page=2')->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $third->id], [$response->json('data.0.id'), $page2->json('data.0.id')]);
        $this->getJson('/api/support/tickets?query=window%40example.test')->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->getJson('/api/support/tickets?query=SUP-'.str_pad($third->id, 6, '0', STR_PAD_LEFT))->assertOk()->assertJsonPath('data.0.id', $third->id);
    }

    public function test_support_search_keeps_internal_notes_and_other_requesters_private_and_treats_wildcards_literally(): void
    {
        $requester = User::factory()->create(['role' => 'client']);
        $owner = $this->ticket($requester, '100% draft_final');
        $owner->messages()->create(['author_id' => $requester->id, 'request_key' => (string) Str::uuid(), 'body' => 'Private investigation token', 'internal' => true]);
        $other = $this->ticket(User::factory()->create(['role' => 'client']), '1000 draftXfinal');
        $other->messages()->create(['author_id' => $other->requester_id, 'request_key' => (string) Str::uuid(), 'body' => 'Public garden question']);
        $this->actingAs($requester, 'sanctum');

        $this->getJson('/api/support/tickets?query=investigation')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/support/tickets?query=garden')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/support/tickets?query=100%25')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $owner->id);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->getJson('/api/support/tickets?query=investigation')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/support/tickets?query=draft_final')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $owner->id);
        $this->getJson('/api/support/tickets?query=0')->assertOk()->assertJsonPath('meta.pagination.total', 2);
    }
}
