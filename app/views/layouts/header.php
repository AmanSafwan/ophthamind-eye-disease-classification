<?php

if (!defined('BASE_PATH')) {
    require_once dirname(__DIR__, 3) . '/config/app.php';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- 🔥 Dynamic Title -->
    <title><?= $page_title ?? 'OphthaMind AI' ?></title>

    <meta name="app-base" content="<?= BASE_URL ?>">

    <!-- 🔥 GLOBAL CSS (your custom) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical-typography.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical-ui.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical-layout.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical-actions.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical-unified.css">

    <!-- ADMINLTE CORE (before shell so shell only tweaks theme, not layout math) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/adminlte/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical-shell.css">

    <!-- 🔥 FONT AWESOME (icons for sidebar) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/adminlte/plugins/fontawesome-free/css/all.min.css">

    <!-- 🔥 OPTIONAL PAGE CSS -->
    <?php if (isset($page_css)) echo $page_css; ?>

    <script src="<?= BASE_URL ?>/assets/js/clinical-diagnosis.js"></script>

</head>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed clinical-app">

<div class="wrapper">