<?php

if (!function_exists('env')) {
    require_once __DIR__ . '/app.php';
}

if (!function_exists('app_db')) {
    /**
     * Shared PDO connection for the whole request.
     */
    function app_db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $host = env('DB_HOST', '127.0.0.1');
        $db   = env('DB_NAME', 'eye_system');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');
        $port = (string) env('DB_PORT', '3307');
        $charset = 'utf8mb4';

        $ports = array_values(array_unique(array_filter([$port, '3307', '3306'])));
        $lastError = null;

        foreach ($ports as $tryPort) {
            $dsn = "mysql:host={$host};port={$tryPort};dbname={$db};charset={$charset}";
            try {
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                return $pdo;
            } catch (PDOException $e) {
                $lastError = $e;
            }
        }

        if (function_exists('redirect_to_hosting_setup')) {
            redirect_to_hosting_setup();
        }

        die('DB Connection Failed: ' . ($lastError ? $lastError->getMessage() : 'Unknown error'));
    }
}

return app_db();
