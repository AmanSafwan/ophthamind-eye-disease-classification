(function () {
    'use strict';

    var COPY = {
        login: {
            title: 'Clinician sign in',
            subtitle: 'Enter your credentials to continue.',
        },
        register: {
            title: 'Create account',
            subtitle: 'Register to access the clinical workspace.',
        },
    };

    function setCopy(mode) {
        var title = document.getElementById('accessTitle');
        var subtitle = document.getElementById('accessSubtitle');
        var text = COPY[mode];
        if (!text) return;
        if (title) {
            title.style.opacity = '0';
            setTimeout(function () {
                title.textContent = text.title;
                title.style.opacity = '1';
            }, 120);
        }
        if (subtitle) {
            subtitle.textContent = text.subtitle;
        }
    }

    function showForm(mode) {
        var loginForm = document.getElementById('loginForm');
        var registerForm = document.getElementById('registerForm');
        var loginBtn = document.getElementById('loginBtn');
        var registerBtn = document.getElementById('registerBtn');
        if (!loginForm || !registerForm) return;

        var isLogin = mode === 'login';

        loginForm.hidden = !isLogin;
        registerForm.hidden = isLogin;

        loginForm.classList.toggle('is-visible', isLogin);
        registerForm.classList.toggle('is-visible', !isLogin);

        if (loginBtn) {
            loginBtn.classList.toggle('is-active', isLogin);
            loginBtn.setAttribute('aria-selected', isLogin ? 'true' : 'false');
        }
        if (registerBtn) {
            registerBtn.classList.toggle('is-active', !isLogin);
            registerBtn.setAttribute('aria-selected', !isLogin ? 'true' : 'false');
        }

        setCopy(mode);

        var focusEl = isLogin
            ? document.getElementById('loginEmail')
            : document.getElementById('regName');
        if (focusEl) {
            setTimeout(function () { focusEl.focus(); }, 280);
        }
    }

    function showLogin() {
        showForm('login');
    }

    function showRegister() {
        showForm('register');
    }

    function initPasswordToggle() {
        document.querySelectorAll('.access-pw-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-target');
                var input = id ? document.getElementById(id) : null;
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', !show);
                    icon.classList.toggle('fa-eye-slash', show);
                }
            });
        });
    }

    function initSubmitState() {
        document.querySelectorAll('.access-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('.access-submit');
                if (btn && !btn.disabled) {
                    btn.disabled = true;
                    var label = btn.childNodes[0];
                    if (label && label.nodeType === 3) {
                        label.textContent = 'Please wait… ';
                    }
                }
            });
        });
    }

    function initReveal() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.querySelectorAll('[data-reveal]').forEach(function (el) {
                el.classList.add('is-visible');
            });
            return;
        }

        var items = document.querySelectorAll('[data-reveal]');
        items.forEach(function (el, i) {
            setTimeout(function () {
                el.classList.add('is-visible');
            }, 80 + i * 90);
        });
    }

    function initWorkflowSteps() {
        var steps = document.querySelectorAll('.story-step');
        if (!steps.length) return;
        var index = 0;

        setInterval(function () {
            steps.forEach(function (s) { s.classList.remove('is-active'); });
            steps[index].classList.add('is-active');
            index = (index + 1) % steps.length;
        }, 2800);
    }

    function initPanelHeadTransition() {
        var h2 = document.getElementById('accessTitle');
        if (h2) {
            h2.style.transition = 'opacity 0.25s ease';
        }
    }

    window.showLogin = showLogin;
    window.showRegister = showRegister;

    document.addEventListener('DOMContentLoaded', function () {
        var loginBtn = document.getElementById('loginBtn');
        var registerBtn = document.getElementById('registerBtn');
        if (loginBtn) loginBtn.addEventListener('click', showLogin);
        if (registerBtn) registerBtn.addEventListener('click', showRegister);

        initPasswordToggle();
        initSubmitState();
        initReveal();
        initWorkflowSteps();
        initPanelHeadTransition();

        var registerForm = document.getElementById('registerForm');
        if (registerForm && registerForm.classList.contains('is-visible')) {
            setCopy('register');
        }
    });
})();
