<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

class PreserveSubdirectoryRootRoute
{
    public function __construct(private Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routes = $this->router->getRoutes();

        if ($routes instanceof CompiledRouteCollection
            && $request->getBaseUrl() !== ''
            && $request->getPathInfo() === '/') {
            $dashboard = $routes->getByName('dashboard');

            if ($dashboard && $dashboard->uri() === '/') {
                // The compiled matcher trims /public/ to /public and loses its base URL.
                // Its dynamic-route fallback matches the original request and keeps auth middleware.
                $routes->add($dashboard);
            }
        }

        return $next($request);
    }
}
