<?php

require_once BASE_PATH . '/app/middleware/role_check.php';
require_once BASE_PATH . '/app/helpers/PaginationHelper.php';
require_once BASE_PATH . '/app/helpers/AuditHelper.php';

class HistoryController extends Controller
{
    public function index()
    {
        RoleCheck::checkClinicDoctor();

        $userId = (int)$_SESSION['user_id'];
        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'date_from' => trim($_GET['date_from'] ?? ''),
            'date_to' => trim($_GET['date_to'] ?? ''),
        ];

        $page = max(1, (int)($_GET['page'] ?? 1));
        $totalLogs = $this->audit->countLogsForUser($userId, $filters);
        $pagination = PaginationHelper::resolve($page, $totalLogs, PaginationHelper::PER_PAGE);

        $clinical = $this->audit->getDoctorClinicalCounts($userId, $filters);
        $moduleCounts = $this->audit->getModuleCountsForUser($userId, $filters);

        $rows = [];
        foreach ($this->audit->fetchLogsPageForUser(
            $userId,
            $filters,
            $pagination['offset'],
            $pagination['per_page']
        ) as $log) {
            $parsed = AuditHelper::parseLogRow($log);
            if ($parsed['hidden']) {
                continue;
            }
            $rows[] = [
                'id' => $log['id'],
                'created_at' => $log['created_at'],
                'parsed' => $parsed,
            ];
        }

        $hasFilters = $filters['q'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== '';
        $filterLabel = $this->buildFilterLabel($filters, $hasFilters);

        $this->auditLog(AuditHelper::action('AUDIT.TRAIL_VIEW', 'Opened clinical audit trail — activity log'), [
            'outcome' => 'SUCCESS',
            'records' => $totalLogs . ' log entry(ies) in scope',
            'screenings' => $clinical['screenings'] . ' AI screening(s) in period',
            'page' => 'Page ' . $pagination['page'] . ' of ' . $pagination['total_pages'],
            'search' => $filters['q'] !== '' ? $filters['q'] : '(none)',
        ]);

        $page_title = 'Clinical Audit Trail';
        $moduleOrder = AuditHelper::MODULE_ORDER;
        $historyFormAction = rtrim(BASE_URL, '/') . '/index.php';
        $paginationQuery = [
            'url' => 'ophthalmologist/history',
            'q' => $filters['q'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
        ];

        require_once __DIR__ . '/../../views/ophthalmologist/history.php';
    }

    private function buildFilterLabel(array $filters, bool $hasFilters): string
    {
        if (!$hasFilters) {
            return 'All time · your account only';
        }

        $parts = [];
        if ($filters['date_from'] !== '' && $filters['date_to'] !== '') {
            $parts[] = date('d M Y', strtotime($filters['date_from']))
                . ' to ' . date('d M Y', strtotime($filters['date_to']));
        } elseif ($filters['date_from'] !== '') {
            $parts[] = 'From ' . date('d M Y', strtotime($filters['date_from']));
        } elseif ($filters['date_to'] !== '') {
            $parts[] = 'Until ' . date('d M Y', strtotime($filters['date_to']));
        }
        if ($filters['q'] !== '') {
            $parts[] = 'Search: "' . $filters['q'] . '"';
        }

        return implode(' · ', $parts) . ' · your account only';
    }
}
