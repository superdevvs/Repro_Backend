<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ReproAi\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class PropertyDescriptionGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_does_not_send_property_location_to_the_ai(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'address' => '987 Confidential Lane',
            'city' => 'Privateville',
            'state' => 'PX',
            'zip' => '98765',
            'listing_type' => 'for_sale',
            'property_details' => [
                'bedrooms' => 4,
                'bathrooms' => 3,
                'sqft' => 2400,
            ],
        ]);

        Sanctum::actingAs($admin);

        $capturedMessages = null;
        $this->mock(LlmClient::class, function (MockInterface $mock) use (&$capturedMessages): void {
            $mock->shouldReceive('chatCompletion')
                ->once()
                ->withArgs(function (array $messages, array $tools, bool $stream, array $options) use (&$capturedMessages): bool {
                    $capturedMessages = $messages;

                    return $tools === []
                        && $stream === false
                        && ($options['max_tokens'] ?? null) === 300;
                })
                ->andReturn([
                    'choices' => [[
                        'message' => [
                            'content' => 'A bright, spacious home with four bedrooms, three bathrooms, and thoughtfully designed living areas.',
                        ],
                    ]],
                ]);
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/generate-description");

        $response->assertOk()
            ->assertJsonPath('description', 'A bright, spacious home with four bedrooms, three bathrooms, and thoughtfully designed living areas.')
            ->assertJsonPath('description_tier', 'standard')
            ->assertJsonPath('character_limit', 650)
            ->assertJsonPath('characters_used', 100);

        $this->assertIsArray($capturedMessages);
        $serializedPrompt = json_encode($capturedMessages, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('987 Confidential Lane', $serializedPrompt);
        $this->assertStringNotContainsString('Privateville', $serializedPrompt);
        $this->assertStringNotContainsString('98765', $serializedPrompt);
        $this->assertStringContainsString('Do not include or infer the property address or any location details', $serializedPrompt);
        $this->assertStringContainsString('Never include or infer a property address', $serializedPrompt);
        $this->assertStringContainsString('no more than 650 characters including spaces', $serializedPrompt);
        $this->assertStringContainsString('at or below 650 characters including spaces', $serializedPrompt);
    }

    public function test_generation_hard_caps_an_oversized_ai_response(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['listing_type' => 'for_sale']);
        $sentence = 'Bright interiors and flexible living spaces create a welcoming home for everyday comfort.';
        $oversizedDescription = implode(' ', array_fill(0, 12, $sentence));

        Sanctum::actingAs($admin);

        $this->mock(LlmClient::class, function (MockInterface $mock) use ($oversizedDescription): void {
            $mock->shouldReceive('chatCompletion')
                ->once()
                ->andReturn([
                    'choices' => [[
                        'message' => ['content' => $oversizedDescription],
                    ]],
                ]);
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/generate-description");

        $response->assertOk()
            ->assertJsonPath('description_tier', 'standard')
            ->assertJsonPath('character_limit', 650);

        $description = $response->json('description');
        $this->assertIsString($description);
        $this->assertLessThanOrEqual(650, mb_strlen($description));
        $this->assertSame(mb_strlen($description), $response->json('characters_used'));
        $this->assertStringEndsWith('.', $description);
        $this->assertNotSame($oversizedDescription, $description);
    }
}
