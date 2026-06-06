<?php
require_once BASE_PATH . '/includes/landing_bootstrap.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!empty($_SESSION['error'])) {
    $flash = ['type' => 'error', 'message' => $_SESSION['error']];
    unset($_SESSION['error']);
} elseif (!empty($_SESSION['success'])) {
    $flash = ['type' => 'success', 'message' => $_SESSION['success']];
    unset($_SESSION['success']);
}

$loginErrors = [
    'missing' => 'Please enter your email and password.',
    'invalid' => 'Invalid email or password. Please try again.',
    'role' => 'Your account role is not configured. Contact an administrator.',
    'server' => 'A server error occurred. Please try again shortly.',
    'timeout' => 'Your session expired. Please sign in again.',
    'session' => 'Your account is no longer active or was removed. Please sign in with a valid account.',
];
if (!$flash && !empty($_GET['error']) && isset($loginErrors[$_GET['error']])) {
    $flash = ['type' => 'error', 'message' => $loginErrors[$_GET['error']]];
}

$base = rtrim(BASE_URL, '/');
$showRegister = isset($_GET['register']) || (isset($_GET['tab']) && $_GET['tab'] === 'register');
?>
<!DOCTYPE html>
<html lang="en" class="landing-html">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="app-base" content="<?= htmlspecialchars($base) ?>">
    <title>OphthaMind AI | Clinician Access</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/adminlte/plugins/fontawesome-free/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/pages/auth/landing.css')) ?>">
</head>

<body class="landing-page">

    <div class="landing-shell">

        <section class="landing-story" aria-label="About OphthaMind AI">

            <div class="story-bg" aria-hidden="true">
                <span class="story-orb story-orb-1"></span>
                <span class="story-orb story-orb-2"></span>
                <span class="story-orb story-orb-3"></span>
                <span class="story-grid"></span>
            </div>

            <header class="story-header">
                <a href="<?= htmlspecialchars($base) ?>/" class="story-logo">
                    <span class="story-logo-icon"><i class="fas fa-eye"></i></span>
                    <span>
                        <strong>OphthaMind AI</strong>
                        <small>Clinical eye intelligence</small>
                    </span>
                </a>
            </header>

            <div class="story-body">
                <p class="story-tag">Trusted workflow for ophthalmology clinics</p>
                <h1>Secure AI screening with accountable clinical records</h1>
                <p class="story-lead">
                    Screen fundus images with an ensemble of deep-learning models, manage a shared patient registry,
                    and review your own practice metrics across a multi-clinician eye care team.
                </p>

                <ul class="story-highlights">
                    <li class="story-highlight" data-reveal>
                        <span class="story-highlight-icon"><i class="fas fa-brain"></i></span>
                        <div>
                            <strong>Ensemble AI diagnosis</strong>
                            <span>CNN, VGG16 &amp; ResNet with confidence and risk scoring</span>
                        </div>
                    </li>
                    <li class="story-highlight" data-reveal>
                        <span class="story-highlight-icon"><i class="fas fa-user-injured"></i></span>
                        <div>
                            <strong>Shared patient registry</strong>
                            <span>IC lookup, demographics, and linked screening history</span>
                        </div>
                    </li>
                    <li class="story-highlight" data-reveal>
                        <span class="story-highlight-icon"><i class="fas fa-chart-line"></i></span>
                        <div>
                            <strong>Personal practice dashboard</strong>
                            <span>Your screenings only: trends, KPIs, and drill-down detail</span>
                        </div>
                    </li>
                    <li class="story-highlight" data-reveal>
                        <span class="story-highlight-icon"><i class="fas fa-shield-alt"></i></span>
                        <div>
                            <strong>Governance &amp; audit</strong>
                            <span>Role-based access, session security, full activity log</span>
                        </div>
                    </li>
                </ul>

                <div class="story-dx" data-reveal>
                    <span class="story-dx-label">Supported findings</span>
                    <div class="story-dx-tags">
                        <span>Normal</span>
                        <span>Cataract</span>
                        <span>Glaucoma</span>
                        <span>Diabetic Retinopathy</span>
                    </div>
                </div>
            </div>

            <footer class="story-footer">
                <div class="story-steps">
                    <div class="story-step is-active" data-step="1"><em>1</em> Sign in</div>
                    <div class="story-step" data-step="2"><em>2</em> Patient</div>
                    <div class="story-step" data-step="3"><em>3</em> Screen</div>
                    <div class="story-step" data-step="4"><em>4</em> Report</div>
                </div>
                <p class="story-legal">&copy; <?= date('Y') ?> OphthaMind AI · FYP clinical decision support demo</p>
            </footer>
        </section>

        <aside class="landing-access" aria-label="Clinician access">

            <div class="access-bg" aria-hidden="true"></div>

            <div class="access-panel">
                <div class="access-panel-head">
                    <h2 id="accessTitle"><?= $showRegister ? 'Create account' : 'Clinician sign in' ?></h2>
                    <p id="accessSubtitle"><?= $showRegister ? 'Register to access the clinical workspace.' : 'Enter your credentials to continue.' ?></p>
                </div>

                <?php if ($flash): ?>
                    <div class="access-alert access-alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?>" role="alert">
                        <i class="fas fa-<?= ($flash['type'] ?? '') === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                        <span><?= htmlspecialchars($flash['message'] ?? '') ?></span>
                    </div>
                <?php endif; ?>

                <div class="access-card">
                    <div class="access-tabs" role="tablist">
                        <button type="button" id="loginBtn" class="access-tab<?= $showRegister ? '' : ' is-active' ?>" role="tab" data-tab="login">
                            Sign in
                        </button>
                        <button type="button" id="registerBtn" class="access-tab<?= $showRegister ? ' is-active' : '' ?>" role="tab" data-tab="register">
                            Register
                        </button>
                    </div>

                    <div class="access-forms">
                        <form id="loginForm" class="access-form<?= $showRegister ? '' : ' is-visible' ?>" method="POST" action="<?= htmlspecialchars($base) ?>/login"<?= $showRegister ? ' hidden' : '' ?>>
                            <div class="access-field">
                                <label for="loginEmail">Email</label>
                                <div class="access-input">
                                    <i class="fas fa-envelope"></i>
                                    <input type="email" id="loginEmail" name="email" placeholder="doctor@clinic.my" required autocomplete="email">
                                </div>
                            </div>
                            <div class="access-field">
                                <label for="loginPassword">Password</label>
                                <div class="access-input">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" id="loginPassword" name="password" placeholder="Your password" required autocomplete="current-password">
                                    <button type="button" class="access-pw-toggle" data-target="loginPassword" aria-label="Show password"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                            <button type="submit" class="access-submit">Sign in <i class="fas fa-arrow-right"></i></button>
                        </form>

                        <form id="registerForm" class="access-form<?= $showRegister ? ' is-visible' : '' ?>" method="POST" action="<?= htmlspecialchars($base) ?>/register"<?= $showRegister ? '' : ' hidden' ?>>
                            <div class="access-field">
                                <label for="regName">Full name</label>
                                <div class="access-input">
                                    <i class="fas fa-user-md"></i>
                                    <input type="text" id="regName" name="name" placeholder="Dr. Ahmad Rahman" required autocomplete="name">
                                </div>
                            </div>
                            <div class="access-field">
                                <label for="regEmail">Email</label>
                                <div class="access-input">
                                    <i class="fas fa-envelope"></i>
                                    <input type="email" id="regEmail" name="email" placeholder="doctor@clinic.my" required autocomplete="email">
                                </div>
                            </div>
                            <div class="access-field">
                                <label for="regPassword">Password</label>
                                <div class="access-input">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" id="regPassword" name="password" placeholder="Min. 6 characters" required minlength="6" autocomplete="new-password">
                                    <button type="button" class="access-pw-toggle" data-target="regPassword" aria-label="Show password"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                            <button type="submit" class="access-submit">Create account <i class="fas fa-arrow-right"></i></button>
                        </form>
                    </div>
                </div>

                <p class="access-foot">
                    <i class="fas fa-lock"></i> Encrypted session · HIPAA-style audit logging · AI supports clinical judgment
                </p>
            </div>
        </aside>

    </div>

    <script src="<?= htmlspecialchars(asset_url('assets/js/pages/auth/landing.js')) ?>" defer></script>
</body>

</html>
