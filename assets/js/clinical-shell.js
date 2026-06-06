(function () {
    'use strict';

    function initNavActive() {
        const path = window.location.pathname.replace(/\/+$/, '');
        document.querySelectorAll('.clinical-nav-list .nav-link').forEach((link) => {
            const href = link.getAttribute('href') || '';
            if (!href || href.includes('logout')) return;
            try {
                const linkPath = new URL(href, window.location.origin).pathname.replace(/\/+$/, '');
                const active = path === linkPath || (linkPath.length > 1 && path.indexOf(linkPath) !== -1);
                link.classList.toggle('active', active);
            } catch (e) {
                /* ignore */
            }
        });
    }

    function initAnnouncementBar() {
        const bar = document.getElementById('clinicalAnnouncementBar');
        if (!bar) return;
        if (sessionStorage.getItem('clinicalAnnouncementHidden') === '1') {
            bar.classList.add('is-hidden');
            return;
        }
        const close = document.getElementById('clinicalAnnouncementClose');
        if (close) {
            close.addEventListener('click', () => {
                bar.classList.add('is-hidden');
                sessionStorage.setItem('clinicalAnnouncementHidden', '1');
            });
        }
    }

    function initLogout() {
        document.querySelectorAll('#logoutLink, #logoutLinkDropdown').forEach((link) => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                if (confirm('Sign out of OphthaMind?')) {
                    window.location.href = link.getAttribute('href');
                }
            });
        });
    }

    /** Let AdminLTE PushMenu handle sidebar-collapse; refresh charts on toggle */
    function initPushMenuSync() {
        const toggle = document.querySelector('[data-widget="pushmenu"]');
        if (!toggle) return;
        toggle.addEventListener('click', () => {
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
            }, 350);
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.body.classList.add('clinical-app');
        initNavActive();
        initAnnouncementBar();
        initLogout();
        initPushMenuSync();
    });
})();
