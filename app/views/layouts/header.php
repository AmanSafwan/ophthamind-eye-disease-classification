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

    <link rel="icon" type="image/png" href="<?= brand_logo_url() ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/brand-logo.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical-typography.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/clinical.css?v=<?= @filemtime(BASE_PATH . '/assets/css/clinical.css') ?: '1' ?>">
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

    <!-- Shared AI model benchmarks (config/ai_models.php) -->
    <?php
    $__aiModels = is_file(BASE_PATH . '/config/ai_models.php')
        ? require BASE_PATH . '/config/ai_models.php'
        : ['benchmark_accuracy' => [], 'ensemble_weights' => []];
    ?>
    <script>
    window.MODEL_BENCHMARK_ACCURACY = <?= json_encode($__aiModels['benchmark_accuracy'] ?? new stdClass(), JSON_UNESCAPED_SLASHES) ?>;
    window.ENSEMBLE_WEIGHTS = <?= json_encode($__aiModels['ensemble_weights'] ?? new stdClass(), JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script src="<?= BASE_URL ?>/assets/js/clinical-diagnosis.js?v=<?= @filemtime(BASE_PATH . '/assets/js/clinical-diagnosis.js') ?: '1' ?>"></script>

</head>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed clinical-app">

<div class="wrapper">