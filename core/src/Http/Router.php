<?php

declare(strict_types=1);

namespace Emp\Core\Http;

/**
 * Router delle API del Core. Ogni rotta appartiene al modulo che l'ha
 * registrata; due moduli non possono registrare la stessa rotta.
 * I percorsi sono tutti sotto /api/v1/.
 */
final class Router
{
    /** @var array<string, array<string, array{string, \Closure(Request): Response}>> */
    private array $routes = [];

    /** @param \Closure(Request): Response $handler */
    public function add(string $method, string $path, string $moduleId, \Closure $handler): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException('Metodo HTTP non supportato');
        }
        if (!preg_match('#^/api/v1(/[a-z0-9-]+|/\{[a-z_]+\})+$#', $path)) {
            throw new \InvalidArgumentException(sprintf('Percorso API non valido: %s', $path));
        }
        if (isset($this->routes[$method][$path])) {
            throw new \LogicException(sprintf('Rotta %s %s già registrata da "%s"', $method, $path, $this->routes[$method][$path][0]));
        }
        $this->routes[$method][$path] = [$moduleId, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $path = rtrim($request->path, '/') ?: '/';
        $methodAllowed = false;
        foreach ($this->routes as $m => $routes) {
            foreach ($routes as $pattern => [, $handler]) {
                $params = self::match($pattern, $path);
                if ($params === null) {
                    continue;
                }
                if ($m === $request->method) {
                    return $handler($request->withParams($params));
                }
                $methodAllowed = true;
            }
        }

        return $methodAllowed ? Response::error(405, 'Metodo non consentito') : Response::error(404, 'Risorsa non trovata');
    }

    /** @return array<string, string>|null */
    private static function match(string $pattern, string $path): ?array
    {
        $regex = '#^' . preg_replace('#\\\\\{([a-z_]+)\\\\\}#', '(?P<$1>[A-Za-z0-9_-]{1,128})', preg_quote($pattern, '#')) . '$#';
        if (!preg_match($regex, $path, $m)) {
            return null;
        }

        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
