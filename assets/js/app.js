document.addEventListener('DOMContentLoaded', function () {
    const route = new URLSearchParams(window.location.search).get('url')
        || window.location.pathname.split('/').filter(Boolean).pop()
        || '';
    document.querySelectorAll('.nav-sidebar .nav-link').forEach((link) => {
        const href = link.getAttribute('href') || '';
        if (route && (href.includes(route) || href.endsWith('/' + route))) {
            link.classList.add('active');
        }
    });

    const logoutLink = document.getElementById('logoutLink');
    if (logoutLink) {
        logoutLink.addEventListener('click', function (e) {
            e.preventDefault();
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = this.href;
            }
        });
    }
});

window.showToast = function showToast(message, type = 'info') {
    const iconMap = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        info: 'fa-info-circle'
    };

    const toast = document.createElement('div');
    toast.className = `clinical-toast clinical-toast-${type}`;
    toast.innerHTML = `<i class="fas ${iconMap[type] || iconMap.info}"></i><span>${message}</span>`;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3200);
};
