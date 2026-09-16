<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicPhotographerListPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_photographer_list_omits_email_and_email_health(): void
    {
        Cache::flush();

        $photographer = User::factory()->photographer()->create([
            'name' => 'Alex Photographer',
            'email' => 'alex.photographer@example.test',
            'avatar' => 'https://cdn.example.test/alex.jpg',
            'email_status' => 'bounced',
            'email_bounce_reason' => 'Mailbox does not exist',
        ]);

        $response = $this->getJson('/api/photographers');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $photographer->id);
        $this->assertNotNull($row);
        $this->assertSame($photographer->id, $row['id']);
        $this->assertSame('Alex Photographer', $row['name']);
        $this->assertSame('https://cdn.example.test/alex.jpg', $row['avatar']);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('email_health', $row);
        $this->assertStringNotContainsString('alex.photographer@example.test', $response->getContent());
        $this->assertStringNotContainsString('Mailbox does not exist', $response->getContent());
    }
}
