<?php
require_once BASE_PATH . '/app/helpers/NavHelper.php';
require_once BASE_PATH . '/app/helpers/RoleHelper.php';

$role = $_SESSION['role'] ?? RoleHelper::CLINIC_DOCTOR;
$clinicianName = htmlspecialchars($_SESSION['name'] ?? 'Clinician');
$initials = htmlspecialchars(NavHelper::initials($_SESSION['name'] ?? 'DR'));
$roleLabel = htmlspecialchars(NavHelper::roleLabel($role));
$isAdmin = RoleHelper::isAdmin($role);
$homeUrl = BASE_URL . '/' . ($isAdmin ? 'admin/dashboard' : 'ophthalmologist/dashboard');
?>

<aside class="main-sidebar clinical-sidebar elevation-4">

    <a href="<?= $homeUrl ?>" class="brand-link clinical-brand" title="OphthaMind home">
        <img
            src="<?= htmlspecialchars(brand_logo_url()) ?>"
            alt="OphthaMind"
            class="ophthamind-logo"
            width="186"
            height="54"
            decoding="async"
        >
    </a>

    <div class="sidebar clinical-sidebar-inner">


        <nav class="mt-2" aria-label="Main navigation">
            <ul class="nav nav-pills nav-sidebar flex-column clinical-nav-list" data-widget="treeview" role="menu" data-accordion="false">

                <?php if ($isAdmin): ?>

                    <li class="nav-header clinical-nav-header-hide">Admin</li>
                    <li class="nav-item">
                        <a href="<?= BASE_URL ?>/admin/dashboard" class="nav-link<?= NavHelper::navClass('admin/dashboard') ?>" title="Dashboard">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= BASE_URL ?>/admin/user_management" class="nav-link<?= NavHelper::navClass('admin/user_management') ?>" title="Users">
                            <i class="nav-icon fas fa-users-cog"></i>
                            <p>Users</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= BASE_URL ?>/admin/analytics" class="nav-link<?= NavHelper::navClass('admin/analytics') ?>" title="Analytics">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Analytics</p>
                        </a>
                    </li>

                <?php else: ?>

                    <li class="nav-header clinical-nav-header-hide">Workspace</li>
                    <li class="nav-item">
                        <a href="<?= BASE_URL ?>/ophthalmologist/dashboard" class="nav-link<?= NavHelper::navClass('ophthalmologist/dashboard') ?>" title="My dashboard">
                            <i class="nav-icon fas fa-chart-pie"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= BASE_URL ?>/ophthalmologist/predict" class="nav-link<?= NavHelper::navClass('ophthalmologist/predict') ?>" title="AI screening">
                            <i class="nav-icon fas fa-microscope"></i>
                            <p>AI screening</p>
                        </a>
                    </li>

                    <li class="nav-header clinical-nav-header-hide">Patients</li>
                    <li class="nav-item">
                        <a href="<?= BASE_URL ?>/ophthalmologist/patient" class="nav-link<?= NavHelper::navClass('ophthalmologist/patient') ?>" title="Patient registry (shared)">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Registry</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= BASE_URL ?>/ophthalmologist/history" class="nav-link<?= NavHelper::navClass('ophthalmologist/history') ?>" title="Activity log">
                            <i class="nav-icon fas fa-clipboard-list"></i>
                            <p>Activity</p>
                        </a>
                    </li>

                <?php endif; ?>

                <li class="nav-header clinical-nav-header-hide">Account</li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/config/logout.php" class="nav-link" id="logoutLink" title="Sign out">
                        <i class="nav-icon fas fa-sign-out-alt text-danger"></i>
                        <p>Sign out</p>
                    </a>
                </li>
            </ul>
        </nav>

        <?php if (!$isAdmin): ?>
        <p class="clinical-sidebar-footnote">Shared registry · Personal dashboard</p>
        <?php endif; ?>

    </div>
</aside>
