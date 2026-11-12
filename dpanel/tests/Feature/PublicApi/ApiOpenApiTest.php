<?php

namespace Tests\Feature\PublicApi;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Yaml\Yaml;

/** Backlog U1: the OpenAPI document is served and matches the routes. */
class ApiOpenApiTest extends PublicApiTestCase
{
    public function test_the_document_is_served_without_a_token(): void
    {
        $response = $this->get('/api/v1/openapi')->assertOk();

        $this->assertStringStartsWith('application/yaml', (string) $response->headers->get('Content-Type'));
        $this->assertSame('3.0.3', Yaml::parse($response->getContent())['openapi']);
    }

    public function test_every_route_is_documented_and_every_documented_path_exists(): void
    {
        $document = Yaml::parseFile(base_path('resources/api/openapi.yaml'));
        $documented = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if ($method !== 'parameters') {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }

        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with((string) $route->getName(), 'api.v1.'))
            ->flatMap(fn (Route $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => $method.' '.substr($route->uri(), strlen('api/v1'))))
            ->values()->all();
        sort($documented);
        sort($routes);

        $this->assertSame($routes, $documented);
    }

    public function test_every_scope_in_the_document_exists(): void
    {
        preg_match_all('/`([a-z]+:(?:read|write))`/', (string) file_get_contents(base_path('resources/api/openapi.yaml')), $matches);

        $this->assertNotEmpty($matches[1]);
        $this->assertSame([], array_values(array_diff(array_unique($matches[1]), \App\Support\ApiScopes::all())));
    }
}
