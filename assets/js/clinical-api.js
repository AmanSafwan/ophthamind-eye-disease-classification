(function () {
    const meta = document.querySelector('meta[name="app-base"]');
    const base = (meta && meta.content) ? meta.content.replace(/\/$/, '') : '';

    window.APP_BASE = base;

    window.apiUrl = function apiUrl(path) {
        const clean = String(path || '').replace(/^\//, '');
        return `${base}/index.php?url=${clean}`;
    };

    /**
     * Pretty route URL for full-page navigation (uses .htaccess + QSA).
     * Example: clinicalPageUrl('ophthalmologist/predict', { ic: '...', view: 'history' })
     */
    window.clinicalPageUrl = function clinicalPageUrl(path, params) {
        const clean = String(path || '').replace(/^\//, '');
        const root = base.replace(/\/$/, '');
        const url = new URL(`${root}/${clean}`, window.location.origin);

        if (params && typeof params === 'object') {
            Object.keys(params).forEach((key) => {
                const val = params[key];
                if (val !== undefined && val !== null && String(val) !== '') {
                    url.searchParams.set(key, val);
                }
            });
        }

        return url.toString();
    };

    window.clinicalFetch = function clinicalFetch(path, options) {
        options = options || {};
        const method = (options.method || 'GET').toUpperCase();

        let routePath = String(path || '').replace(/^\//, '');
        let query = '';
        const qMark = routePath.indexOf('?');
        if (qMark >= 0) {
            query = routePath.slice(qMark + 1);
            routePath = routePath.slice(0, qMark);
        }
        const amp = routePath.indexOf('&');
        if (amp >= 0) {
            query = query ? routePath.slice(amp + 1) + '&' + query : routePath.slice(amp + 1);
            routePath = routePath.slice(0, amp);
        }

        let url = apiUrl(routePath);

        if (method === 'GET') {
            const parts = [];
            if (query) parts.push(query);
            parts.push('_ts=' + Date.now());
            url += (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
        }

        options.cache = 'no-store';
        if (!options.headers) {
            options.headers = {};
        }
        if (!options.headers['X-Requested-With']) {
            options.headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        return fetch(url, options);
    };
})();
