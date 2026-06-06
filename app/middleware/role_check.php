<?php

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../helpers/RoleHelper.php';

class RoleCheck
{
    public static function checkClinicDoctor(): void
    {
        self::checkRole(RoleHelper::CLINIC_DOCTOR);
    }

    public static function checkRole($role)
    {
        self::ensureSession();
        self::enforceSessionTimeout();

        $isAjax = self::isAjaxRequest();

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            self::rejectUnauthenticated($isAjax);
        }

        if (!self::validateSessionUser($role)) {
            self::rejectInvalidSession($isAjax);
        }
    }

    /**
     * True when session maps to an existing user (optionally matching role).
     */
    public static function sessionUserIsActive(?string $expectedRole = null): bool
    {
        self::ensureSession();

        if (empty($_SESSION['user_id'])) {
            return false;
        }

        return self::validateSessionUser($expectedRole);
    }

    public static function redirectByRole()
    {
        self::ensureSession();

        $role = $_SESSION['role'] ?? null;

        switch ($role) {
            case 'admin':
                self::redirect('/admin/dashboard');
                break;

            case RoleHelper::CLINIC_DOCTOR:
                self::redirect('/ophthalmologist/dashboard');
                break;

            default:
                self::redirect('/login');
        }
    }

    public static function destroySession(): void
    {
        self::ensureSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Load user from DB and refresh session fields. Returns false if account removed or role mismatch.
     */
    private static function validateSessionUser(?string $expectedRole = null): bool
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $db = require BASE_PATH . '/config/db.php';
            $stmt = $db->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Auth validation failed: ' . $e->getMessage());
            return false;
        }

        if (!$user) {
            return false;
        }

        if ($expectedRole !== null && !self::roleMatches($user['role'] ?? '', $expectedRole)) {
            return false;
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role'] = (string)$user['role'];
        $_SESSION['name'] = (string)$user['name'];
        $_SESSION['email'] = (string)$user['email'];

        return true;
    }

    private static function roleMatches(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        if ($expected === RoleHelper::CLINIC_DOCTOR && RoleHelper::isClinicDoctor($actual)) {
            return true;
        }

        return false;
    }

    private static function rejectUnauthenticated(bool $isAjax): void
    {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'unauthenticated',
                'message' => 'Please login',
            ]);
            exit;
        }

        $_SESSION['flash_error'] = 'Please login to continue.';
        self::redirect('/login');
    }

    private static function rejectInvalidSession(bool $isAjax): void
    {
        self::destroySession();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'unauthenticated',
                'message' => 'Your session is no longer valid. Please sign in again.',
            ]);
            exit;
        }

        self::redirect('/login?error=session');
    }

    private static function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private static function ensureSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(env('SESSION_NAME', 'eye_system_session'));
            session_start();
        }
    }

    private static function enforceSessionTimeout()
    {
        $timeout = (int) env('SESSION_TIMEOUT', 1800);

        if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
            self::destroySession();
            self::redirect('/login?timeout=1');
        }

        $_SESSION['LAST_ACTIVITY'] = time();
    }

    private static function redirect($path)
    {
        header('Location: ' . BASE_URL . $path);
        exit();
    }
}
