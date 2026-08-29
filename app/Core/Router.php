<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,Route> */
    private array $routes = [];

    public function get(string $path, mixed $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, mixed $handler): Route
    {
        $segments = [];
        $pattern = preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            static function (array $m) use (&$segments): string {
                $segments[] = $m[1];

                return '([^/]+)';
            },
            $path
        );

        $route = new Route($method, $path, '#^' . $pattern . '$#', $handler, $segments);
        $this->routes[] = $route;

        return $route;
    }

    /**
     * @return array{route:Route,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route->pattern, $path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route->method !== $method) {
                continue;
            }
            array_shift($matches);
            $params = [];
            foreach ($route->segments as $index => $name) {
                $params[$name] = $matches[$index] ?? '';
            }

            return ['route' => $route, 'params' => $params];
        }

        return $pathMatched ? ['route' => null, 'params' => []] : null;
    }
}
