<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../landing.php");
    exit();
}

$timeout_duration = 1800;

if (isset($_SESSION['LAST_ACTIVITY'])) {

    if (time() - $_SESSION['LAST_ACTIVITY'] > $timeout_duration) {

        session_unset();
        session_destroy();

        header("Location: ../landing.php?timeout=1");
        exit();
    }
}

$_SESSION['LAST_ACTIVITY'] = time();
?>