<?php
require_once BASE_PATH . "/includes/header.php";
require_once BASE_PATH . "/includes/sidebar.php";
?>

<!-- PAGE CSS (change ikut page) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages/ophthalmologist/patient.css">

<div class="main-content">

    <!-- =========================
         TOPBAR (GLOBAL - KEEP SAME)
    ========================== -->
    <div class="topbar">
        <h2><?= $page_title ?? 'Page Title' ?></h2>
        <p>
            Welcome back,
            <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?>
        </p>
    </div>

    <!-- =========================
         PAGE CONTENT AREA (CHANGE ONLY THIS)
    ========================== -->

    <div class="panel">

        <?php
        // PAGE CONTENT WILL BE INJECTED HERE
        // Example:
        // include BASE_PATH . "/views/ophthalmologist/patient_content.php";
        ?>

    </div>

</div>

<?php require_once BASE_PATH . "/includes/footer.php"; ?>