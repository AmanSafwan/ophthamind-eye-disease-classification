<?php
/**
 * Unified clinical page shell (after header + sidebar).
 */
require_once BASE_PATH . '/app/helpers/NavHelper.php';
require_once BASE_PATH . '/app/helpers/RoleHelper.php';

$page_icon = $page_icon ?? 'fa-hospital';
$page_subtitle = $page_subtitle ?? '';
$role = $_SESSION['role'] ?? '';
$isOphtha = !RoleHelper::isAdmin($role);
$clinicianName = htmlspecialchars($_SESSION['name'] ?? 'Clinician');
$initials = htmlspecialchars(NavHelper::initials($_SESSION['name'] ?? 'DR'));
$roleLabel = htmlspecialchars(NavHelper::roleLabel($role));
?>

<nav class="main-header navbar navbar-expand navbar-white navbar-light clinical-topbar border-0">
    <ul class="navbar-nav align-items-center clinical-topbar-left">
        <li class="nav-item">
            <a class="nav-link clinical-menu-toggle" data-widget="pushmenu" href="#" role="button" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </a>
        </li>
        <li class="nav-item clinical-topbar-page d-lg-none">
            <span class="clinical-topbar-page-title"><?= htmlspecialchars($page_title ?? 'Clinical Workspace') ?></span>
        </li>
    </ul>

    <ul class="navbar-nav ml-auto align-items-center clinical-topbar-right">
        <li class="nav-item dropdown clinical-user-menu">
            <a class="nav-link dropdown-toggle clinical-user-trigger" href="#" data-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                <span class="clinical-user-avatar"><?= $initials ?></span>
                <span class="clinical-user-meta d-none d-sm-flex">
                    <span class="clinical-user-name"><?= $clinicianName ?></span>
                    <span class="clinical-user-role"><?= $roleLabel ?></span>
                </span>
                <i class="fas fa-chevron-down clinical-user-chevron d-none d-sm-inline" aria-hidden="true"></i>
            </a>
            <div class="dropdown-menu dropdown-menu-right clinical-user-dropdown">
                <div class="dropdown-header">
                    <strong><?= $clinicianName ?></strong>
                    <small><?= $roleLabel ?></small>
                </div>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/config/logout.php" id="logoutLinkDropdown">
                    <i class="fas fa-sign-out-alt mr-2"></i> Sign out
                </a>
            </div>
        </li>
    </ul>
</nav>

<div class="content-wrapper clinical-content-wrapper">
    <section class="content clinical-section">
        <div class="container-fluid clinical-main-container">

            <?php if ($isOphtha): ?>
            <div class="clinical-announcement-bar" id="clinicalAnnouncementBar">
                <i class="fas fa-info-circle"></i>
                <span id="clinicalAnnouncementText">Registry is shared with all clinicians · Your dashboard shows only your screenings</span>
                <button type="button" class="clinical-announcement-close" id="clinicalAnnouncementClose" aria-label="Dismiss">&times;</button>
            </div>
            <?php endif; ?>

            <div class="clinical-page-header">
                <div class="clinical-page-header-main">
                    <div class="clinical-page-icon-wrap" aria-hidden="true">
                        <i class="fas <?= htmlspecialchars($page_icon) ?>"></i>
                    </div>
                    <div>
                        <h1><?= htmlspecialchars($page_title ?? 'Clinical Workspace') ?></h1>
                        <?php if ($page_subtitle !== ''): ?>
                            <p><?= htmlspecialchars($page_subtitle) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($page_header_actions)): ?>
                    <div class="clinical-header-actions"><?= $page_header_actions ?></div>
                <?php endif; ?>
            </div>
