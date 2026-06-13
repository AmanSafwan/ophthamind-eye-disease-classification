<?php

class AiServiceManager
{
    public static function aiPort(): int
    {
        $port = (int)env('AI_PORT', 5000);
        return max(1, min(65535, $port));
    }

    public static function healthUrl(): string
    {
        return 'http://127.0.0.1:' . self::aiPort() . '/health';
    }

    public static function predictUrl(): string
    {
        return 'http://127.0.0.1:' . self::aiPort() . '/predict';
    }

    public static function isEnabled(): bool
    {
        if (!function_exists('env_bool')) {
            return true;
        }

        return env_bool('ENABLE_AI', true);
    }

    public static function isHealthy(): bool
    {
        return self::fetchHealth() !== null;
    }

    public static function fetchHealth(): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }

        $response = @file_get_contents(self::healthUrl(), false, stream_context_create([
            'http' => ['timeout' => 3],
        ]));

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) && ($data['status'] ?? '') === 'ok' ? $data : null;
    }

    public static function diskModelManifest(): array
    {
        $manifest = [];
        foreach (['cnn', 'vgg16', 'resnet50'] as $name) {
            $path = BASE_PATH . DIRECTORY_SEPARATOR . 'ai' . DIRECTORY_SEPARATOR . 'models'
                . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . $name . '_final.keras';
            if (!is_file($path)) {
                continue;
            }
            $manifest[$name] = [
                'path' => $path,
                'size_bytes' => (int)filesize($path),
                'modified_unix' => (int)filemtime($path),
                'modified' => date('c', (int)filemtime($path)),
            ];
        }
        return $manifest;
    }

    public static function modelsNeedReload(): bool
    {
        $health = self::fetchHealth();
        if ($health === null) {
            return false;
        }

        $loaded = $health['models'] ?? null;
        if (!is_array($loaded) || $loaded === []) {
            return true;
        }

        $disk = self::diskModelManifest();
        foreach (['cnn', 'vgg16', 'resnet50'] as $name) {
            if (!isset($disk[$name], $loaded[$name])) {
                return true;
            }
            $diskMtime = (int)($disk[$name]['modified_unix'] ?? 0);
            $loadedMtime = (int)($loaded[$name]['modified_unix'] ?? 0);
            $diskSize = (int)($disk[$name]['size_bytes'] ?? 0);
            $loadedSize = (int)($loaded[$name]['size_bytes'] ?? 0);
            if ($diskMtime > $loadedMtime || $diskSize !== $loadedSize) {
                return true;
            }
        }

        return false;
    }

    public static function stopService(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $pid = self::findWindowsPidOnPort(self::aiPort());
            if ($pid !== null) {
                @exec('taskkill /F /PID ' . $pid . ' 2>nul');
                usleep(800000);
            }
            return;
        }

        $pid = self::findWindowsPidOnPort(self::aiPort());
        if ($pid !== null) {
            @exec('kill -9 ' . escapeshellarg((string)$pid));
            usleep(500000);
        }
    }

    public static function restart(int $maxWaitSeconds = 90): void
    {
        self::stopService();
        self::startBackground();
        self::waitUntilHealthy(max(15, $maxWaitSeconds));
    }

    public static function ensureRunning(int $maxWaitSeconds = 90): void
    {
        if (!self::isEnabled()) {
            throw new RuntimeException('AI screening is disabled on this server. Use your local XAMPP environment for predictions.');
        }

        if (self::isHealthy()) {
            if (self::modelsNeedReload()) {
                self::restart($maxWaitSeconds);
            }
            return;
        }

        self::startBackground();

        $deadline = time() + max(15, $maxWaitSeconds);
        while (time() < $deadline) {
            usleep(500000);
            if (self::isHealthy()) {
                return;
            }
        }

        throw new RuntimeException(
            'AI prediction service is unavailable. Start it manually with venv\\Scripts\\python.exe ai_api\\app.py or check storage/logs/ai_service.log'
        );
    }

    private static function waitUntilHealthy(int $maxWaitSeconds): void
    {
        $deadline = time() + max(15, $maxWaitSeconds);
        while (time() < $deadline) {
            usleep(500000);
            if (self::isHealthy()) {
                return;
            }
        }

        throw new RuntimeException(
            'AI prediction service failed to reload models. Check storage/logs/ai_service.log'
        );
    }

    private static function findWindowsPidOnPort(int $port): ?int
    {
        $output = @shell_exec('netstat -ano | findstr :' . (int)$port);
        if (!is_string($output) || $output === '') {
            return null;
        }

        foreach (preg_split('/\R/', $output) as $line) {
            if (stripos($line, 'LISTENING') === false) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            $pid = (int)end($parts);
            if ($pid > 0) {
                return $pid;
            }
        }

        return null;
    }

    public static function startBackground(): void
    {
        $python = self::resolvePythonBinary();
        $appPath = BASE_PATH . DIRECTORY_SEPARATOR . 'ai_api' . DIRECTORY_SEPARATOR . 'app.py';

        if (!file_exists($appPath)) {
            throw new RuntimeException('AI service entry file not found: ai_api/app.py');
        }

        $logDir = BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'ai_service.log';

        $port = (string)self::aiPort();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'cmd /c set AI_PORT=' . $port . ' && start /B "" '
                . escapeshellarg($python) . ' '
                . escapeshellarg($appPath)
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
            @pclose(@popen($cmd, 'r'));
            return;
        }

        $cmd = 'AI_PORT=' . escapeshellarg($port) . ' '
            . escapeshellcmd($python) . ' ' . escapeshellarg($appPath)
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        @exec($cmd);
    }

    private static function resolvePythonBinary(): string
    {
        $candidates = [
            env('PYTHON_PATH', ''),
            BASE_PATH . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',
            BASE_PATH . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python',
            'python',
            'py',
        ];

        foreach ($candidates as $bin) {
            if ($bin === '') {
                continue;
            }
            if ($bin === 'python' || $bin === 'py') {
                return $bin;
            }
            if (file_exists($bin)) {
                return $bin;
            }
        }

        return 'python';
    }
}
