        </div>
    </section>
</div>

<footer class="main-footer clinical-footer">
    <div class="clinical-footer-grid">
        <div class="clinical-footer-brand">
            <div class="clinical-footer-logo">
                <img
                    src="<?= htmlspecialchars(brand_logo_url()) ?>"
                    alt="OphthaMind"
                    class="ophthamind-logo"
                    width="160"
                    height="44"
                    decoding="async"
                >
            </div>
            <p>Clinical decision support for ophthalmology. Ensemble deep-learning screening with secure patient workflows.</p>
            <span class="clinical-footer-version">Platform v1.0 · FYP <?= date('Y') ?></span>
        </div>
        <div class="clinical-footer-links">
            <h6>Quick links</h6>
            <ul>
                <?php if (($_SESSION['role'] ?? '') !== 'admin'): ?>
                <li><a href="<?= BASE_URL ?>/ophthalmologist/dashboard"><i class="fas fa-chevron-right"></i> My dashboard</a></li>
                <li><a href="<?= BASE_URL ?>/ophthalmologist/predict"><i class="fas fa-chevron-right"></i> AI screening</a></li>
                <li><a href="<?= BASE_URL ?>/ophthalmologist/patient"><i class="fas fa-chevron-right"></i> Patient registry</a></li>
                <li><a href="<?= BASE_URL ?>/ophthalmologist/history"><i class="fas fa-chevron-right"></i> Activity log</a></li>
                <?php else: ?>
                <li><a href="<?= BASE_URL ?>/admin/dashboard"><i class="fas fa-chevron-right"></i> Admin dashboard</a></li>
                <li><a href="<?= BASE_URL ?>/admin/user_management"><i class="fas fa-chevron-right"></i> Users</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="clinical-footer-meta">
            <h6>Clinical notice</h6>
            <p>For education and FYP demonstration. AI output supports clinical judgment and does not replace clinician review.</p>
            <div class="clinical-footer-badges">
                <span><i class="fas fa-lock"></i> Session secured</span>
                <span><i class="fas fa-user-md"></i> Role-based access</span>
            </div>
        </div>
    </div>
    <div class="clinical-footer-bottom">
        <small>&copy; <?= date('Y') ?> OphthaMind AI · All rights reserved</small>
        <small class="clinical-footer-powered">Powered by CNN · VGG · ResNet ensemble</small>
    </div>
</footer>
