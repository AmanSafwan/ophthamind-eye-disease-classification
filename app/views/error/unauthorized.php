<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Access Denied</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages/unauthorized.css">
</head>

<body class="unauthorized-page">

<div class="unauthorized-container">
    <div class="unauthorized-card">
        
        <div class="unauthorized-icon">⛔</div>
        <h1>Access Denied</h1>

        <p>
            <?php 
                echo $_SESSION['flash_error'] ?? "You don’t have permission to access this module.";
            ?>
        </p>

        <p>Redirecting in <span id="countdown">3</span> seconds...</p>

        <a href="<?= BASE_URL ?>/index.php" class="unauthorized-btn">
            Go Back Now
        </a>

    </div>
</div>

<script>
// popup (simple UX feedback)
alert("Access blocked: insufficient permissions");

// countdown redirect
let count = 3;

let timer = setInterval(() => {
    count--;
    document.getElementById("countdown").innerText = count;

    if (count <= 0) {
        clearInterval(timer);
        window.location.href = "<?= BASE_URL ?>/index.php";
    }
}, 1000);
</script>

</body>
</html>