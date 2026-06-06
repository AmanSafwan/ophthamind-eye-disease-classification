<?php

define('BASE_PATH', realpath(__DIR__ . '/..'));

function loadEnv($path)
{
    if (!file_exists($path)) {
        die(".env file not found at $path");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {

        if (strpos(trim($line), '#') === 0) continue;

        $parts = explode('=', $line, 2);

        if (count($parts) == 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);

            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(BASE_PATH . '/.env');

function env($key, $default = null)
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return $value;
}

/**
 * Application base URL (no trailing slash). Uses APP_URL from .env or auto-detects from the request.
 */
function resolve_base_url(): string
{
    $fromEnv = trim((string) env('APP_URL', ''));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = rtrim(dirname($script), '/');
    if ($dir === '/' || $dir === '.') {
        return $scheme . '://' . $host;
    }

    return $scheme . '://' . $host . $dir;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', resolve_base_url());
}

if (!function_exists('asset_url')) {
    function asset_url($path)
    {
        return rtrim(BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', (string) $path), '/');
    }
}

if (!function_exists('app_url')) {
    function app_url($route, array $query = [])
    {
        $url = rtrim(BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', (string) $route), '/');
        if (!empty($query)) {
            $qs = http_build_query($query);
            if ($qs !== '') {
                $url .= '?' . $qs;
            }
        }
        return $url;
    }
}
