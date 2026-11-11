<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The API and its contract must not drift apart in either direction: every route the application
 * registers is documented in openapi/openapi.yaml, and every documented operation is routed.
 */
final class OpenApiCoverageTest extends ApiTestCase
{
    private const HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function test_every_registered_route_is_documented_and_vice_versa(): void
    {
        $registered = $this->registeredEndpoints();
        $documented = $this->documentedEndpoints();

        // Neither side may silently be empty.
        self::assertNotSame([], $registered, 'No route is registered.');
        self::assertNotSame([], $documented, 'openapi.yaml documents no operation.');

        $undocumented = array_values(array_diff($registered, $documented));
        $unrouted = array_values(array_diff($documented, $registered));

        $problems = [];
        if ($undocumented !== []) {
            $problems[] = "Registered in config/routes.php but missing from openapi.yaml:\n  - " . implode("\n  - ", $undocumented);
        }
        if ($unrouted !== []) {
            $problems[] = "Documented in openapi.yaml but not registered in config/routes.php:\n  - " . implode("\n  - ", $unrouted);
        }

        if ($problems !== []) {
            self::fail(implode("\n", $problems));
        }

        self::assertSame($registered, $documented);
    }

    /**
     * "GET /api/tasks/{id}" for every method of every route; "{id:[0-9]+}" is normalised to "{id}".
     * HEAD is ignored: Slim does not register it, and OpenAPI documents it only when it is a
     * separate operation.
     *
     * @return list<string>
     */
    private function registeredEndpoints(): array
    {
        $endpoints = [];

        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $path = preg_replace('/\{\s*([a-zA-Z_][a-zA-Z0-9_-]*)\s*(?::(?:[^{}]|\{[^{}]*\})*)?\}/', '{$1}', $route->getPattern());
            self::assertIsString($path);

            foreach ($route->getMethods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $endpoints[] = $method . ' ' . $path;
            }
        }

        sort($endpoints);

        return array_values(array_unique($endpoints));
    }

    /** @return list<string> */
    private function documentedEndpoints(): array
    {
        $document = Yaml::parse((string) file_get_contents(__DIR__ . '/../../openapi/openapi.yaml'));
        self::assertIsArray($document);
        self::assertIsArray($document['paths'] ?? null, 'openapi.yaml has no paths.');

        $endpoints = [];
        foreach ($document['paths'] as $path => $item) {
            self::assertIsArray($item);
            foreach (array_keys($item) as $key) {
                $method = strtoupper((string) $key);
                // Path-level keys such as "parameters" or "summary" are not operations.
                if (in_array($method, self::HTTP_METHODS, true)) {
                    $endpoints[] = $method . ' ' . $path;
                }
            }
        }

        sort($endpoints);

        return array_values(array_unique($endpoints));
    }
}
