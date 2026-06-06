<?php

class AiServiceManager
{
    private const HEALTH_URL = 'http://127.0.0.1:5000/health';
    private const PREDICT_URL = 'http://127.0.0.1:5000/predict';

    public static function healthUrl(): string
    {
        return self::HEALTH_URL;
    }

    public static function predictUrl(): string
    {
        return self::PREDICT_URL;
    }

    public static function isHealthy(): bool
    {
        $response = @file_get_contents(self::HEALTH_URL, false, stream_context_create([
            'http' => ['timeout' => 2],
        ]));

        return $response !== false;
    }

    public static function ensureRunning(int $maxWaitSeconds = 90): void
    {
        if (self::isHealthy()) {
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
            'AI prediction service is unavailable. Start it manually: venv\\Scripts\\python.exe ai_api\\app.py — or check storage/logs/ai_service.log'
        );
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

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'cmd /c start /B "" '
                . escapeshellarg($python) . ' '
                . escapeshellarg($appPath)
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
            @pclose(@popen($cmd, 'r'));
            return;
        }

        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($appPath)
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
