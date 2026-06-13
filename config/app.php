<?php

define('BASE_PATH', realpath(__DIR__ . '/..'));

function loadEnvFile(string $path, bool $required = true): void
{
    if (!file_exists($path)) {
        if ($required) {
            die('.env file not found at ' . $path);
        }
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ($key === '') {
            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}

$envPath = BASE_PATH . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    $fallback = BASE_PATH . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . '.env.hosting';
    if (file_exists($fallback)) {
        loadEnvFile($fallback, false);
    } elseif (!is_file(BASE_PATH . DIRECTORY_SEPARATOR . 'hosting_setup.php')) {
        die('.env file not found at ' . $envPath);
    }
} else {
    loadEnvFile($envPath, true);
}

if (file_exists(BASE_PATH . DIRECTORY_SEPARATOR . '.env.local')) {
    loadEnvFile(BASE_PATH . DIRECTORY_SEPARATOR . '.env.local', false);
}

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

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key, null);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function hosting_setup_pending(): bool
{
    if (!is_file(BASE_PATH . DIRECTORY_SEPARATOR . 'hosting_setup.php')) {
        return false;
    }
    if (is_file(BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'hosting.lock')) {
        return false;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (str_contains($host, 'unisza.work') || str_contains($host, 'onceamonth.work')) {
        return true;
    }

    return is_file(BASE_PATH . DIRECTORY_SEPARATOR . '.hosting-deploy');
}

function redirect_to_hosting_setup(): void
{
    if (!hosting_setup_pending()) {
        return;
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    if ($script === 'hosting_setup.php') {
        return;
    }

    header('Location: hosting_setup.php');
    exit;
}

/**
 * Application base URL (no trailing slash). Uses APP_URL from .env or auto-detects from the request.
 */
function resolve_base_url(): string
{
    $fromEnv = trim((string) env('APP_URL', ''));
    if ($fromEnv !== '' && !hosting_setup_pending()) {
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

if (!function_exists('brand_logo_url')) {
    function brand_logo_url(): string
    {
        static $url = null;
        if ($url !== null) {
            return $url;
        }
        $relative = 'assets/images/ophthamind-logo.png';
        $full = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $v = is_file($full) ? (string) filemtime($full) : (string) time();
        $url = asset_url($relative) . '?v=' . $v;
        return $url;
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
