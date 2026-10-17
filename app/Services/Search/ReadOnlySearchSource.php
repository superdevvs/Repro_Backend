<?php

namespace App\Services\Search;

use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Runs an allowlisted list action through its existing route authorization.
 * No HTTP request, user-supplied destination, or detail action is permitted.
 * Keeping the source route pipeline preserves voice-line, Studio, linked-account,
 * per-user permission and inbox rules as those modules evolve.
 */
class ReadOnlySearchSource
{
    public function get(Request $parent, string $path, array $parameters): array
    {
        $request = Request::create('/api'.$path, 'GET', $parameters, [], [], $parent->server->all());
        $request->headers->replace($parent->headers->all());
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $parent->user());
        $request->attributes->set('contextual_search_source', true);
        $router = app(Router::class);
        $route = $router->getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $previous = app('request');
        try {
            app()->instance('request', $request);
            // The parent has already passed API authentication, impersonation and
            // account gates. Repeating those would impersonate an effective user
            // twice and produce duplicate telemetry. Keep every source-specific
            // role, permission and policy middleware, including future additions.
            $middleware = array_values(array_filter($route->gatherMiddleware(), fn ($item) => !in_array(explode(':', $item)[0], ['api', 'auth', 'throttle'], true)));
            $response = (new Pipeline(app()))->send($request)
                ->through($router->resolveMiddleware($middleware))
                ->then(fn () => $route->run());
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new HttpException($status, 'Search source unavailable.');
            }
            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            app()->instance('request', $previous);
        }
    }
}
