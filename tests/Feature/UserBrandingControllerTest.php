<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserBrandingControllerTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function visible_role_can_update_their_own_branding(): void
    {
        $user = User::factory()->create([
            'role' => 'salesRep',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/users/{$user->id}/branding", [
            'branding' => [
                'about' => 'Portfolio bio',
                'show_map' => true,
                'facebook_url' => 'https://facebook.com/repro',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.branding.about', 'Portfolio bio')
            ->assertJsonPath('data.branding.show_map', true)
            ->assertJsonPath('data.branding.facebook_url', 'https://facebook.com/repro');

        $this->assertDatabaseHas('user_branding', [
            'user_id' => $user->id,
            'about' => 'Portfolio bio',
            'show_map' => 1,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function hidden_role_cannot_update_their_own_branding(): void
    {
        $user = User::factory()->create([
            'role' => 'photographer',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/users/{$user->id}/branding", [
            'branding' => [
                'about' => 'Should not save',
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('user_branding', [
            'user_id' => $user->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function privileged_editor_can_update_someone_elses_branding_without_wiping_unsent_fields(): void
    {
        $editor = User::factory()->create([
            'role' => 'editing_manager',
        ]);
        $target = User::factory()->create([
            'role' => 'salesRep',
        ]);

        DB::table('user_branding')->insert([
            'user_id' => $target->id,
            'logo' => 'existing-logo.png',
            'banner' => 'existing-banner.png',
            'primary_color' => '#111111',
            'secondary_color' => '#222222',
            'font_family' => 'Inter',
            'custom_domain' => 'brand.example.com',
            'about' => 'Keep this about',
            'hero_headline' => 'Keep headline',
            'hero_subtitle' => 'Keep subtitle',
            'hero_image' => 'header-4',
            'facebook_url' => 'https://facebook.com/original',
            'linkedin_url' => null,
            'instagram_url' => null,
            'show_map' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($editor);

        $response = $this->putJson("/api/users/{$target->id}/branding", [
            'branding' => [
                'logo' => 'updated-logo.png',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.branding.logo', 'updated-logo.png')
            ->assertJsonPath('data.branding.banner', 'existing-banner.png')
            ->assertJsonPath('data.branding.about', 'Keep this about')
            ->assertJsonPath('data.branding.hero_headline', 'Keep headline')
            ->assertJsonPath('data.branding.show_map', true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_profile_route_is_public_and_uses_branding_backed_fields(): void
    {
        $owner = User::factory()->create([
            'role' => 'salesRep',
            'name' => 'Portfolio Owner',
            'email' => 'owner@example.com',
        ]);
        $linkedClient = User::factory()->create([
            'role' => 'client',
        ]);

        DB::table('user_branding')->insert([
            'user_id' => $owner->id,
            'logo' => 'owner-logo.png',
            'banner' => 'owner-banner.png',
            'primary_color' => '#123456',
            'secondary_color' => '#654321',
            'font_family' => 'Inter',
            'custom_domain' => null,
            'about' => 'Branding-backed about text',
            'hero_headline' => 'Welcome Home',
            'hero_subtitle' => 'Fresh subtitle',
            'hero_image' => 'header-2',
            'facebook_url' => 'https://facebook.com/owner',
            'linkedin_url' => 'https://linkedin.com/in/owner',
            'instagram_url' => 'https://instagram.com/owner',
            'show_map' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_branding_clients')->insert([
            'user_id' => $owner->id,
            'client_id' => $linkedClient->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shoot = \App\Models\Shoot::factory()->create([
            'client_id' => $linkedClient->id,
            'status' => \App\Models\Shoot::STATUS_DELIVERED,
            'workflow_status' => \App\Models\Shoot::STATUS_DELIVERED,
        ]);

        $response = $this->getJson("/api/public/clients/{$owner->id}/profile");

        $response->assertOk()
            ->assertJsonPath('client.id', $owner->id)
            ->assertJsonPath('client.about', 'Branding-backed about text')
            ->assertJsonPath('client.banner_image', 'owner-banner.png')
            ->assertJsonPath('client.logo', 'owner-logo.png')
            ->assertJsonPath('client.facebook_url', 'https://facebook.com/owner')
            ->assertJsonPath('client.linkedin_url', 'https://linkedin.com/in/owner')
            ->assertJsonPath('client.instagram_url', 'https://instagram.com/owner')
            ->assertJsonPath('client.show_map', true)
            ->assertJsonPath('shoots.0.id', $shoot->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_contact_route_accepts_client_ids(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);

        $response = $this->postJson("/api/public/clients/{$client->id}/contact", [
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
            'message' => 'Interested in this listing.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Your message has been sent successfully.');

        $this->assertDatabaseHas('contact_submissions', [
            'client_id' => $client->id,
            'sender_name' => 'Visitor',
            'sender_email' => 'visitor@example.com',
        ]);
    }
}
