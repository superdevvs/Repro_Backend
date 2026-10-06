<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssignedRepTourAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_rep_can_change_appearance_without_replacing_links_or_billing(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create([
            'rep_id' => $rep->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'tour_links' => ['branded' => 'https://tour.example/original', 'video_link' => 'https://video.example/original'],
        ]);
        $originalQuote = $shoot->total_quote;
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => [
            'tour_style' => 'landor', 'tour_palette' => 'repro', 'header_position' => 'left',
            'tour_version' => 'standard', 'autoplay' => true, 'show_garage' => false,
        ]])->assertOk();
        $shoot->refresh();
        $this->assertSame('landor', $shoot->tour_links['tour_style']);
        $this->assertSame('https://tour.example/original', $shoot->tour_links['branded']);
        $this->assertSame('https://video.example/original', $shoot->tour_links['video_link']);
        $this->assertEquals($originalQuote, $shoot->total_quote);
    }

    public function test_other_reps_and_clients_cannot_change_appearance(): void
    {
        Bus::fake();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['rep_id' => $rep->id, 'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered']);
        foreach ([$other, $client] as $actor) {
            $this->actingAs($actor)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'landor']])->assertForbidden();
        }
        foreach ([['tour_links' => ['video_link' => 'https://video.example/changed']], ['tour_links' => ['tour_style' => 'landor'], 'total_quote' => 1]] as $payload) {
            $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, $payload)->assertForbidden();
        }
    }

    public function test_legacy_client_rep_can_change_only_appearance_without_overriding_an_explicit_assignment(): void
    {
        Bus::fake();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => $rep->id]]);
        $shoot = Shoot::factory()->create(['rep_id' => null, 'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered']);
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'landor']])->assertOk();
        $this->assertSame('landor', $shoot->fresh()->tour_links['tour_style']);
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['video_link' => 'https://video.example/changed']])->assertForbidden();
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'default'], 'total_quote' => 1])->assertForbidden();
        $this->actingAs($other)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'default']])->assertForbidden();
        Shoot::withoutEvents(fn () => $shoot->update(['rep_id' => $other->id]));
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'default']])->assertForbidden();
    }
}
