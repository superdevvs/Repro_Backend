<?php

namespace Tests\Feature;

use App\Http\Controllers\API\StudioController;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Studio API foundation route and authorization coverage.
 *
 * Validates: Requirements 15.1, 15.2, 15.3, 15.7, 15.8, 15.9, 16.1
 */
class StudioApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public static function plannedRouteProvider(): array
    {
        return [
            ['studio.metrics.summary', 'GET', 'api/studio/metrics/summary', 'StudioMetricsController@summary'],
            ['studio.search', 'GET', 'api/studio/search', 'StudioSearchController@index'],
            ['studio.queue.index', 'GET', 'api/studio/queue', 'StudioQueueController@index'],
            ['studio.queue.show', 'GET', 'api/studio/queue/{id}', 'StudioQueueController@show'],
            ['studio.projects.index', 'GET', 'api/studio/projects', 'StudioProjectController@index'],
            ['studio.projects.store', 'POST', 'api/studio/projects', 'StudioProjectController@store'],
            ['studio.projects.show', 'GET', 'api/studio/projects/{project}', 'StudioProjectController@show'],
            ['studio.shoots.search', 'GET', 'api/studio/shoots/search', 'StudioSourceController@searchShoots'],
            ['studio.shoots.media', 'GET', 'api/studio/shoots/{shoot}/media', 'StudioSourceController@shootMedia'],
            ['studio.uploads.store', 'POST', 'api/studio/uploads', 'StudioSourceController@upload'],
            ['studio.templates.index', 'GET', 'api/studio/templates', 'StudioTemplateController@index'],
            ['studio.templates.store', 'POST', 'api/studio/templates', 'StudioTemplateController@store'],
            ['studio.templates.update', 'PUT', 'api/studio/templates/{template}', 'StudioTemplateController@update'],
            ['studio.templates.destroy', 'DELETE', 'api/studio/templates/{template}', 'StudioTemplateController@destroy'],
            ['studio.brand.show', 'GET', 'api/studio/brand', 'StudioBrandController@show'],
            ['studio.brand.update', 'PUT', 'api/studio/brand', 'StudioBrandController@update'],
            ['studio.deep-links.resolve', 'POST', 'api/studio/deep-links/resolve', 'StudioDeepLinkController@resolve'],
        ];
    }

    #[DataProvider('plannedRouteProvider')]
    public function test_planned_route_is_registered_with_shared_security_boundary(
        string $name,
        string $method,
        string $uri,
        string $controllerAction
    ): void {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route);
        $this->assertSame($uri, $route->uri());
        $this->assertContains($method, $route->methods());
        $this->assertStringEndsWith($controllerAction, $route->getActionName());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains(
            'role:admin,superadmin,editing_manager,editor',
            $route->gatherMiddleware()
        );
    }

    public function test_foundation_routes_reject_unauthenticated_and_disallowed_roles(): void
    {
        $this->getJson('/api/studio/search')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/studio/search')->assertForbidden();
    }

    public function test_scope_helpers_apply_team_scope_and_editor_ownership(): void
    {
        $probe = app(StudioAuthorizationProbe::class);
        $admin = User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 44]]);
        $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['team_id' => 44]]);

        $this->assertNull($probe->userScope($admin));
        $this->assertSame($editor->id, $probe->userScope($editor));
        $this->assertSame(44, $probe->teamScope($admin));

        $adminWheres = $probe->scope(User::query(), $admin)->getQuery()->wheres;
        $editorWheres = $probe->scope(User::query(), $editor)->getQuery()->wheres;

        $this->assertSame([44], array_column($adminWheres, 'value'));
        $this->assertSame([44, $editor->id], array_column($editorWheres, 'value'));
    }

    public function test_record_authorization_requires_same_team_and_editor_ownership(): void
    {
        $probe = app(StudioAuthorizationProbe::class);
        $admin = User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 7]]);
        $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['team_id' => 7]]);
        $record = new class extends Model {
            protected $guarded = [];
        };
        $record->forceFill(['team_id' => 7, 'created_by' => $editor->id]);

        $probe->authorize($admin, 'update', $record);
        $probe->authorize($editor, 'update', $record);
        $this->assertTrue(true);

        $record->setAttribute('created_by', $admin->id);
        $this->expectException(AuthorizationException::class);
        $probe->authorize($editor, 'delete', $record);
    }

    public function test_record_authorization_rejects_cross_team_access(): void
    {
        $probe = app(StudioAuthorizationProbe::class);
        $admin = User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 7]]);
        $record = new class extends Model {
            protected $guarded = [];
        };
        $record->forceFill(['team_id' => 8, 'created_by' => $admin->id]);

        $this->expectException(AuthorizationException::class);
        $probe->authorize($admin, 'view', $record);
    }
}


class StudioAuthorizationProbe extends StudioController
{
    public function userScope(?Authenticatable $user): ?int
    {
        return $this->scopeUserId($user);
    }

    public function teamScope(Authenticatable $user): int
    {
        return $this->scopeTeamId($user);
    }

    public function scope(Builder $query, Authenticatable $user): Builder
    {
        return $this->scopeStudioQuery($query, $user);
    }

    public function authorize(
        ?Authenticatable $user,
        string $action,
        ?Model $record = null
    ): void {
        $this->authorizeStudioAction($user, $action, $record);
    }
}
