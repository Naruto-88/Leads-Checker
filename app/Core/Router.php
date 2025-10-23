<?php
namespace App\Core;

use App\Security\Auth;

class Router
{
    private array $routes = ['GET'=>[], 'POST'=>[]];
    private array $env;

    public function __construct(array $env)
    {
        $this->env = $env;
    }

    public function get(string $path, callable|array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(): void
    {
        $route = $_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($route === null || $route === '') { $route = '/'; }

        // Allow route in either form: '/path' or 'path'
        if ($route[0] !== '/') { $route = '/' . $route; }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $handler = $this->routes[$method][$route] ?? null;

        if (!$handler) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        if (is_array($handler)) {
            [$class, $action] = $handler;
            $controller = new $class($this->env);
            call_user_func([$controller, $action]);
        } else {
            call_user_func($handler);
        }
    }
}

