<?php

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../helpers/AuditHelper.php';
require_once __DIR__ . '/../../helpers/RoleHelper.php';
require_once BASE_PATH . '/app/middleware/role_check.php';

class LoginController
{
    private $conn;

    public function __construct()
    {
        $this->conn = require __DIR__ . '/../../../config/db.php';
    }

    /**
     * Show login/register page (GET /login).
     */
    public function index()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!empty($_SESSION['user_id']) && RoleCheck::sessionUserIsActive()) {
            RoleCheck::redirectByRole();
        }

        if (!empty($_SESSION['user_id'])) {
            RoleCheck::destroySession();
        }

        try {
            $log = $this->conn->prepare('INSERT INTO audit_logs (user_id, action, created_at) VALUES (0, ?, NOW())');
            $log->execute(['Opened login page']);
        } catch (Throwable $e) {
            error_log('Audit: ' . $e->getMessage());
        }

        require __DIR__ . '/../../views/auth/landing.php';
    }

    public function login()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            try {
                $audit = new AuditHelper($this->conn);
                $audit->logAction(0, AuditHelper::action('AUTH.LOGIN_FAILED', 'Sign-in attempt rejected, missing credentials'), [
                    'outcome' => 'FAILED',
                    'email' => $email !== '' ? $email : '(empty)',
                    'reason' => 'Email or password not supplied',
                ]);
            } catch (Throwable $e) {
                error_log('Audit: ' . $e->getMessage());
            }
            header("Location: " . BASE_URL . "/login?error=missing");
            exit;
        }

        try {
            // =========================
            // GET USER (PDO STYLE)
            // =========================
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // =========================
            // VERIFY PASSWORD
            // =========================
            if ($user && password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                // =========================
                // SESSION SETUP
                // =========================
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];

                $audit = new AuditHelper($this->conn);
                $audit->logAction($user['id'], AuditHelper::action('AUTH.LOGIN_SUCCESS', 'Clinician signed in to OphthaMind'), [
                    'outcome' => 'SUCCESS',
                    'email' => $user['email'] ?? $email,
                    'role' => $user['role'] ?? '',
                    'name' => $user['name'] ?? '',
                ]);

                // =========================
                // ROLE REDIRECT
                // =========================
                if ($user['role'] === 'admin') {
                    header("Location: " . BASE_URL . "/admin/dashboard");
                    exit;
                }

                if (RoleHelper::isClinicDoctor($user['role'])) {
                    header("Location: " . BASE_URL . "/ophthalmologist/dashboard");
                    exit;
                }

                // fallback
                header("Location: " . BASE_URL . "/login?error=role");
                exit;
            }

            try {
                $audit = new AuditHelper($this->conn);
                $audit->logAction(0, AuditHelper::action('AUTH.LOGIN_FAILED', 'Sign-in attempt rejected, invalid credentials'), [
                    'outcome' => 'FAILED',
                    'email' => $email,
                    'reason' => $user ? 'Incorrect password' : 'Unknown email address',
                ]);
            } catch (Throwable $e) {
                error_log('Audit: ' . $e->getMessage());
            }

            header("Location: " . BASE_URL . "/login?error=invalid");
            exit;

        } catch (Throwable $e) {
            error_log("Login error: " . $e->getMessage());
            header("Location: " . BASE_URL . "/login?error=server");
            exit;
        }
    }
}