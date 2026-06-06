<?php

require_once BASE_PATH . '/app/middleware/role_check.php';
require_once BASE_PATH . '/app/services/AiServiceManager.php';
require_once BASE_PATH . '/app/services/DashboardAnalyticsService.php';

class DashboardController extends Controller
{
    private function service(): DashboardAnalyticsService
    {
        return new DashboardAnalyticsService($this->db, (int)$_SESSION['user_id']);
    }

    private function filterParams(): array
    {
        return [
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'gender' => $_GET['gender'] ?? '',
            'risk' => $_GET['risk'] ?? '',
            'diagnosis' => $_GET['diagnosis'] ?? '',
            'granularity' => $_GET['granularity'] ?? 'day',
            'drill' => $_GET['drill'] ?? '',
            'drill_value' => $_GET['drill_value'] ?? '',
        ];
    }

    private function enrichPayload(array $payload): array
    {
        $payload['clinician_name'] = trim($_SESSION['name'] ?? 'Doctor');
        $payload['registry_note'] = 'Patient registry is shared with all clinicians. This dashboard shows only your eye screenings.';
        return $payload;
    }

    public function index()
    {
        RoleCheck::checkClinicDoctor();

        $boot = [
            'mode' => 'shell',
            'scope' => 'personal',
            'clinician_name' => trim($_SESSION['name'] ?? 'Doctor'),
            'doctor_id' => (int)$_SESSION['user_id'],
            'filters' => [
                'date_from' => date('Y-m-d', strtotime('-6 days')),
                'date_to' => date('Y-m-d'),
            ],
            'registry_note' => 'Patient registry is shared with all clinicians. This dashboard shows only your eye screenings.',
            'chart_js' => BASE_URL . '/assets/adminlte/plugins/chart.js/Chart.bundle.min.js',
        ];

        $page_title = 'Dashboard';

        require_once __DIR__ . '/../../views/ophthalmologist/dashboard.php';
    }

    /** Fast: KPIs, risk, diagnosis, recent table only (~3 queries). */
    public function summary()
    {
        RoleCheck::checkClinicDoctor();
        try {
            $this->json($this->enrichPayload($this->service()->getSummary($this->filterParams())));
        } catch (Throwable $e) {
            $this->json(['error' => true, 'message' => 'Could not load dashboard summary.'], 500);
        }
    }

    /** Loaded after summary or when opening chart pages (~4 queries). */
    public function charts()
    {
        RoleCheck::checkClinicDoctor();
        try {
            $this->json($this->enrichPayload($this->service()->getCharts($this->filterParams())));
        } catch (Throwable $e) {
            $this->json(['error' => true, 'message' => 'Could not load dashboard charts.'], 500);
        }
    }

    public function analytics()
    {
        RoleCheck::checkClinicDoctor();
        $this->json($this->enrichPayload($this->service()->getAnalytics($this->filterParams())));
    }

    public function aiPing()
    {
        RoleCheck::checkClinicDoctor();
        $online = @AiServiceManager::isHealthy();
        $this->json([
            'online' => $online,
            'message' => $online ? 'AI ready' : 'AI offline',
        ]);
    }
}
