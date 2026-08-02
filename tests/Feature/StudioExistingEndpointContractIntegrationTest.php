<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the pre-Studio AI endpoint routes and security boundary.
 *
 * Validates: Requirements 14.4 and 14.8.
 */
class StudioExistingEndpointContractIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public static function existingEndpointProvider(): array
    {
        return [
            ['GET', 'api/autoenhance/connection-status', 'AutoenhanceController@connectionStatus'],
            ['GET', 'api/autoenhance/editing-types', 'AutoenhanceController@getEditingTypes'],
            ['POST', 'api/autoenhance/edit', 'AutoenhanceController@submitEditing'],
            ['GET', 'api/autoenhance/jobs', 'AutoenhanceController@listJobs'],
            ['GET', 'api/autoenhance/jobs/{jobId}', 'AutoenhanceController@getJobStatus'],
            ['POST', 'api/autoenhance/jobs/{jobId}/cancel', 'AutoenhanceController@cancelJob'],
            ['POST', 'api/autoenhance/jobs/{jobId}/retry', 'AutoenhanceController@retryJob'],
            ['POST', 'api/listing-videos/generate', 'ListingVideoController@generate'],
            ['GET', 'api/listing-videos/jobs', 'ListingVideoController@index'],
            ['GET', 'api/listing-videos/jobs/{job}', 'ListingVideoController@show'],
            ['POST', 'api/listing-videos/jobs/{job}/cancel', 'ListingVideoController@cancel'],
            ['POST', 'api/reels/generate', 'ReelController@generate'],
            ['GET', 'api/reels/jobs', 'ReelController@index'],
            ['GET', 'api/reels/jobs/{job}', 'ReelController@show'],
            ['POST', 'api/reels/jobs/{job}/cancel', 'ReelController@cancel'],
        ];
    }

    #[DataProvider('existingEndpointProvider')]
    public function test_existing_endpoint_route_contract_is_preserved(
        string $method,
        string $uri,
        string $controllerAction
    ): void {
        /** @var IlluminateRoute|null $route */
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn (IlluminateRoute $candidate): bool => $candidate->uri() === $uri
                && in_array($method, $candidate->methods(), true)
        );

        $this->assertNotNull($route, "{$method} {$uri} is no longer registered.");
        $this->assertStringEndsWith($controllerAction, $route->getActionName());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains(
            'role:admin,superadmin,editing_manager,editor',
            $route->gatherMiddleware()
        );
    }

    public function test_existing_endpoint_authentication_and_validation_shapes_remain_intact(): void
    {
        $this->getJson('/api/autoenhance/jobs')->assertUnauthorized();
        $this->getJson('/api/listing-videos/jobs')->assertUnauthorized();
        $this->getJson('/api/reels/jobs')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/autoenhance/jobs')->assertForbidden();
        $this->getJson('/api/listing-videos/jobs')->assertForbidden();
        $this->getJson('/api/reels/jobs')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/listing-videos/generate', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors']);
        $this->postJson('/api/reels/generate', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors']);
    }
}
