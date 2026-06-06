<?php

class NavHelper
{
    public static function currentPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#/index\.php\?url=([^&]+)#', $uri, $m)) {
            return trim($m[1], '/');
        }
        $path = parse_url($uri, PHP_URL_PATH) ?: '';
        $path = trim(str_replace('\\', '/', $path), '/');
        $base = trim(parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_PATH) ?: '', '/');
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = trim(substr($path, strlen($base)), '/');
        }
        return $path;
    }

    public static function isActive(string $segment): bool
    {
        $path = self::currentPath();
        return $path === $segment || strpos($path, $segment . '/') === 0;
    }

    public static function navClass(string $segment): string
    {
        return self::isActive($segment) ? ' active' : '';
    }

    public static function initials(?string $name): string
    {
        $name = trim($name ?? 'DR');
        if ($name === '') {
            return 'DR';
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }

    public static function roleLabel(?string $role): string
    {
        require_once __DIR__ . '/RoleHelper.php';
        return RoleHelper::label($role);
    }
}
