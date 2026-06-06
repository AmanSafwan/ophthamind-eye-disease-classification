<?php

require_once __DIR__ . '/config/app.php';
$sessionName = env('SESSION_NAME', 'eye_system_session');
if (session_status() === PHP_SESSION_NONE) {
    session_name($sessionName);
    session_start();
}

/**
 * 🔥 LOAD DB FIRST (IMPORTANT)
 */
$GLOBALS['conn'] = require __DIR__ . '/config/db.php';

/**
 * 🔥 LOAD CORE SYSTEM
 */
require_once __DIR__ . "/app/core/Controller.php";
require_once __DIR__ . "/app/core/Router.php";
require_once __DIR__ . "/routes/web.php";

/**
 * 🔥 INIT ROUTER
 */
$router = new Router();
routes($router);

/**
 * 🔥 GET URL
 */
$url = $_GET['url'] ?? '';
$url = trim($url, '/');

if ($url === '') {
    $url = 'landing';
}

$method = $_SERVER['REQUEST_METHOD'];

/**
 * 🔥 RESOLVE REQUEST
 */
$router->resolve($url, $method);