/**
 * Client-side pagination UI for AJAX tables.
 */
(function (global) {
    'use strict';

    function windowPages(current, totalPages) {
        if (totalPages <= 1) {
            return [1];
        }
        current = Math.max(1, Math.min(current, totalPages));
        const pages = [1];
        const start = Math.max(2, current - 1);
        const end = Math.min(totalPages - 1, current + 1);

        if (start > 2) {
            pages.push('ellipsis');
        }
        for (let i = start; i <= end; i++) {
            pages.push(i);
        }
        if (end < totalPages - 1) {
            pages.push('ellipsis');
        }
        if (totalPages > 1) {
            pages.push(totalPages);
        }
        return pages;
    }

    function render(containerId, pagination, onPage) {
        const host = document.getElementById(containerId);
        if (!host) {
            return;
        }

        const total = parseInt(pagination.total, 10) || 0;
        const page = parseInt(pagination.page, 10) || 1;
        const totalPages = parseInt(pagination.total_pages, 10) || 1;
        const from = parseInt(pagination.from, 10) || 0;
        const to = parseInt(pagination.to, 10) || 0;

        if (total === 0) {
            host.innerHTML = '';
            return;
        }

        const prev = Math.max(1, page - 1);
        const next = Math.min(totalPages, page + 1);
        const pages = windowPages(page, totalPages);

        let html = '<div class="clinical-pagination">';
        html += '<span class="clinical-pagination-info">Showing ' + from + '–' + to + ' of ' + total + '</span>';
        html += '<ul class="clinical-pagination-list">';

        html += '<li class="clinical-pagination-item' + (page <= 1 ? ' is-disabled' : '') + '">';
        html += '<a href="#" class="clinical-pagination-link" data-page="' + prev + '" aria-label="Previous page">&lsaquo;</a></li>';

        pages.forEach(function (p) {
            if (p === 'ellipsis') {
                html += '<li class="clinical-pagination-item clinical-pagination-ellipsis"><span>…</span></li>';
                return;
            }
            const active = p === page ? ' is-active' : '';
            if (p === page) {
                html += '<li class="clinical-pagination-item' + active + '"><span class="clinical-pagination-link" aria-current="page">' + p + '</span></li>';
            } else {
                html += '<li class="clinical-pagination-item"><a href="#" class="clinical-pagination-link" data-page="' + p + '">' + p + '</a></li>';
            }
        });

        html += '<li class="clinical-pagination-item' + (page >= totalPages ? ' is-disabled' : '') + '">';
        html += '<a href="#" class="clinical-pagination-link" data-page="' + next + '" aria-label="Next page">&rsaquo;</a></li>';

        html += '</ul></div>';
        host.innerHTML = html;

        host.querySelectorAll('[data-page]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const target = parseInt(link.getAttribute('data-page'), 10);
                if (!target || target === page) {
                    return;
                }
                if (target < 1 || target > totalPages) {
                    return;
                }
                onPage(target);
            });
        });
    }

    global.renderClinicalPagination = render;
})(typeof window !== 'undefined' ? window : globalThis);
