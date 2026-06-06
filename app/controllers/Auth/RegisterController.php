<?php

require_once BASE_PATH . '/app/helpers/RoleHelper.php';

class RegisterController extends Controller
{
    public function register()
    {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            $_SESSION['error'] = "All fields are required.";
            header("Location: " . BASE_URL);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid email format.";
            header("Location: " . BASE_URL);
            exit;
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['error'] = "Email already registered.";
            header("Location: " . BASE_URL);
            exit;
        }

        // HASH PASSWORD
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // INSERT USER
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, password, role)
            VALUES (?, ?, ?, ?)
        ");

        $success = $stmt->execute([$name, $email, $hashedPassword, RoleHelper::CLINIC_DOCTOR]);

        if ($success) {

            // 🔥 AUDIT LOG (IMPORTANT)
            $userId = $_SESSION['user_id'] ?? 0;
            $action = "Registered new clinic doctor: " . $email;

            $log = $this->db->prepare("
                INSERT INTO audit_logs (user_id, action, created_at)
                VALUES (?, ?, NOW())
            ");

            $log->execute([$userId, $action]);

            $_SESSION['success'] = "Registration successful.";
        } else {
            $_SESSION['error'] = "Something went wrong.";
        }

        header("Location: " . BASE_URL);
        exit;
    }
}