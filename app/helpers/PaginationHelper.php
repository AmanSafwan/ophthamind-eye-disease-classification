<?php

class PaginationHelper
{
    public const PER_PAGE = 15;

    public static function resolve(int $page, int $total, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min(50, $perPage));
        $total = max(0, $total);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        if ($total === 0) {
            return [
                'page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'total_pages' => 1,
                'offset' => 0,
                'from' => 0,
                'to' => 0,
            ];
        }

        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * @return array<int|string> page numbers and 'ellipsis' markers
     */
    public static function windowPages(int $current, int $totalPages): array
    {
        if ($totalPages <= 1) {
            return [1];
        }

        $current = max(1, min($current, $totalPages));
        $pages = [1];

        $rangeStart = max(2, $current - 1);
        $rangeEnd = min($totalPages - 1, $current + 1);

        if ($rangeStart > 2) {
            $pages[] = 'ellipsis';
        }

        for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
            $pages[] = $i;
        }

        if ($rangeEnd < $totalPages - 1) {
            $pages[] = 'ellipsis';
        }

        if ($totalPages > 1) {
            $pages[] = $totalPages;
        }

        return $pages;
    }

    /**
     * @param string $action Form or page base URL (e.g. index.php)
     * @param array<string, scalar|null> $query Base query params (url route, filters); page added per link
     */
    public static function renderNav(string $action, array $query, array $meta): string
    {
        $total = (int)($meta['total'] ?? 0);
        $totalPages = (int)($meta['total_pages'] ?? 1);
        $page = (int)($meta['page'] ?? 1);

        if ($totalPages <= 1 && $total <= $meta['per_page']) {
            if ($total > 0) {
                return '<div class="clinical-pagination"><span class="clinical-pagination-info">Showing '
                    . (int)$meta['from'] . '–' . (int)$meta['to'] . ' of ' . $total . '</span></div>';
            }
            return '';
        }

        $baseQuery = $query;
        unset($baseQuery['page']);

        $href = static function (int $p) use ($action, $baseQuery): string {
            $q = array_merge($baseQuery, ['page' => $p]);
            $qs = http_build_query($q);
            $sep = strpos($action, '?') !== false ? '&' : '?';
            return htmlspecialchars($action . ($qs !== '' ? $sep . $qs : ''), ENT_QUOTES, 'UTF-8');
        };

        $prev = max(1, $page - 1);
        $next = min($totalPages, $page + 1);
        $prevDisabled = $page <= 1 ? ' is-disabled' : '';
        $nextDisabled = $page >= $totalPages ? ' is-disabled' : '';

        $html = '<nav class="clinical-pagination" aria-label="Table pages">';
        $html .= '<span class="clinical-pagination-info">Showing ' . (int)$meta['from'] . '–' . (int)$meta['to']
            . ' of ' . $total . '</span>';
        $html .= '<ul class="clinical-pagination-list">';

        $html .= '<li class="clinical-pagination-item' . $prevDisabled . '">';
        if ($page > 1) {
            $html .= '<a class="clinical-pagination-link" href="' . $href($prev) . '" aria-label="Previous page">&lsaquo;</a>';
        } else {
            $html .= '<span class="clinical-pagination-link" aria-hidden="true">&lsaquo;</span>';
        }
        $html .= '</li>';

        foreach (static::windowPages($page, $totalPages) as $p) {
            if ($p === 'ellipsis') {
                $html .= '<li class="clinical-pagination-item clinical-pagination-ellipsis"><span>…</span></li>';
                continue;
            }
            $p = (int)$p;
            $active = $p === $page ? ' is-active' : '';
            $html .= '<li class="clinical-pagination-item' . $active . '">';
            if ($p === $page) {
                $html .= '<span class="clinical-pagination-link" aria-current="page">' . $p . '</span>';
            } else {
                $html .= '<a class="clinical-pagination-link" href="' . $href($p) . '">' . $p . '</a>';
            }
            $html .= '</li>';
        }

        $html .= '<li class="clinical-pagination-item' . $nextDisabled . '">';
        if ($page < $totalPages) {
            $html .= '<a class="clinical-pagination-link" href="' . $href($next) . '" aria-label="Next page">&rsaquo;</a>';
        } else {
            $html .= '<span class="clinical-pagination-link" aria-hidden="true">&rsaquo;</span>';
        }
        $html .= '</li>';

        $html .= '</ul></nav>';

        return $html;
    }
}
