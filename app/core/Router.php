<?php

class Router
{
    private $routes = [];

    public function get($uri, $action)
    {
        $this->routes['GET'][$uri] = $action;
    }

    public function post($uri, $action)
    {
        $this->routes['POST'][$uri] = $action;
    }

    public function resolve($uri, $httpMethod)
    {
        $uri = trim($uri, '/');
        if (($q = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $q);
        }
        if (($amp = strpos($uri, '&')) !== false) {
            $uri = substr($uri, 0, $amp);
        }

        $action = $this->routes[$httpMethod][$uri] ?? null;

        if (!$action) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        if (is_string($action)) {
            [$controller, $methodName] = explode('@', $action, 2);
            $controllerPath = $this->controllerPath($controller);
            $controllerClass = $this->controllerClass($controller);

            if (!is_file($controllerPath)) {
                die('Controller not found: ' . $controllerPath);
            }

            require_once $controllerPath;

            if (!class_exists($controllerClass)) {
                die('Class not found: ' . $controllerClass);
            }

            $controllerObj = new $controllerClass();

            if (!method_exists($controllerObj, $methodName)) {
                die('Method not found: ' . $methodName);
            }

            $controllerObj->$methodName();
            return;
        }

        call_user_func($action);
    }

    private function controllerPath(string $controller): string
    {
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $controller) . '.php';
        $base = defined('BASE_PATH')
            ? BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers'
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers';

        return $base . DIRECTORY_SEPARATOR . $relative;
    }

    private function controllerClass(string $controller): string
    {
        $parts = explode('\\', $controller);
        return end($parts);
    }
}
