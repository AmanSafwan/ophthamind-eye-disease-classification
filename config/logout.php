<?php

session_start();

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../app/helpers/AuditHelper.php';

$conn = require __DIR__ . '/db.php';
$audit = new AuditHelper($conn);

// ambil user dulu sebelum session hilang
$userId = $_SESSION['user_id'] ?? null;

// 🔥 LETAK DI SINI (BEFORE DESTROY SESSION)
if ($userId) {

    $ip = $_SERVER['REMOTE_ADDR'] == '::1'
        ? '127.0.0.1'
        : $_SERVER['REMOTE_ADDR'];

    $agent = $_SERVER['HTTP_USER_AGENT'];

    $audit->logAction(
        $userId,
        AuditHelper::action('AUTH.LOGOUT', 'Clinician signed out of OphthaMind'),
        [
            'outcome' => 'SUCCESS',
            'email' => $_SESSION['email'] ?? ($_SESSION['name'] ?? ''),
            'role' => $_SESSION['role'] ?? '',
        ]
    );
}

// destroy session
session_unset();
session_destroy();

// redirect
header("Location: http://localhost/eye_system/");
exit();