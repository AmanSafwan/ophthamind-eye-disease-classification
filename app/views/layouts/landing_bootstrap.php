<?php

/**
 * Landing / login page bootstrap (CSS, JS, session, BASE_URL).
 */
if (!defined('BASE_PATH')) {
    require_once dirname(__DIR__, 3) . '/config/app.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_name(env('SESSION_NAME', 'eye_system_session'));
    session_start();
}
