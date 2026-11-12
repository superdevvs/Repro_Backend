<?php

namespace App\Services\MobileAssistant;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Internal adapter: run the existing route AND its middleware, never a caller-provided URL. */
final class AssistantEndpoint
{
    public function invoke(User $actor, string $method, string $path, array $input = [], ?string $receipt = null): array
    {
        if (! preg_match('~^[a-z][a-z0-9/_-]*$~D', $path) || str_starts_with($path, 'mobile/assistant')) {
            throw new \LogicException('Invalid internal assistant route.');
        }
        $parent = app('request');
        $request = Request::create('/api/'.$path, $method, $input);
        $request->headers->set('Accept', 'application/json');
        if ($authorization = $parent->header('Authorization')) {
            $request->headers->set('Authorization', $authorization);
        }
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('assistant_action_id', $receipt);
        $router = app(Router::class);
        $route = $router->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        try {
            app()->instance('request', $request);
            $response = app(Pipeline::class)->send($request)->through($router->gatherRouteMiddleware($route))
                ->then(fn ($request) => Router::toResponse($request, $route->run()));
            $data = json_decode($response->getContent(), true) ?? [];
            if ($response->getStatusCode() >= 400) {
                throw new HttpException($response->getStatusCode(), (string) ($data['message'] ?? 'This action could not be completed.'));
            }

            return $data;
        } finally {
            app()->instance('request', $parent);
        }
    }
}
