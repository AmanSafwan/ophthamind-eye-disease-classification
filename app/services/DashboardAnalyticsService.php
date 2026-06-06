<?php

require_once BASE_PATH . '/app/helpers/DiagnosisHelper.php';

/**
 * Personal practice analytics (per doctor_id). Optimized for few round-trips.
 */
class DashboardAnalyticsService
{
    private PDO $db;
    private int $doctorId;
    private ?array $lifetimeCache = null;

    public function __construct(PDO $db, int $doctorId)
    {
        $this->db = $db;
        $this->doctorId = $doctorId;
    }

    public function getSummary(array $filters = []): array
    {
        if ($this->doctorId <= 0) {
            return $this->emptySummary();
        }

        $f = $this->normalizeFilters($filters);
        if ($f['drill'] !== '' && $f['drill_value'] !== '') {
            return $this->buildDrillResponse($f, $f['drill'], $f['drill_value']);
        }

        $base = $this->baseFilter($f);
        $kpis = $this->fetchKpisAndRisk($f, $base);
        $diagnosis = $this->fetchDiagnosisBreakdown($base);
        $recent = $this->fetchRecent($base, 8);

        $total = (int)($kpis['screenings_in_range'] ?? 0);

        return [
            'mode' => 'summary',
            'scope' => 'personal',
            'filters' => $f,
            'breadcrumb' => [['label' => 'Dashboard', 'level' => 'root']],
            'kpis' => $kpis,
            'diagnosis' => $diagnosis,
            'risk' => $kpis['risk_breakdown'] ?? ['Low' => 0, 'Medium' => 0, 'High' => 0],
            'model_agreement_pct' => (float)($kpis['model_agreement_pct'] ?? 0),
            'recent_screenings' => $recent,
            'total_screenings' => $total,
        ];
    }

    public function getCharts(array $filters = []): array
    {
        if ($this->doctorId <= 0) {
            return ['trend' => ['labels' => [], 'values' => []], 'gender' => [], 'age_bands' => [], 'confidence_by_diagnosis' => []];
        }

        $f = $this->normalizeFilters($filters);
        $base = $this->baseFilter($f);

        return [
            'trend' => $this->fetchTrend($f, $base),
            'gender' => $this->fetchGender($base),
            'age_bands' => $this->fetchAgeBands($base),
            'confidence_by_diagnosis' => $this->fetchConfidenceByDiagnosis($base),
        ];
    }

    /** @deprecated Use getSummary + getCharts */
    public function getAnalytics(array $filters = []): array
    {
        $summary = $this->getSummary($filters);
        if (($summary['mode'] ?? '') === 'drill') {
            return $summary;
        }
        $charts = $this->getCharts($filters);
        return array_merge($summary, $charts, ['mode' => 'overview']);
    }

    private function emptySummary(): array
    {
        return [
            'mode' => 'summary',
            'scope' => 'personal',
            'kpis' => [],
            'diagnosis' => array_fill_keys(DiagnosisHelper::all(), 0),
            'risk' => ['Low' => 0, 'Medium' => 0, 'High' => 0],
            'model_agreement_pct' => 0,
            'recent_screenings' => [],
            'total_screenings' => 0,
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        $dateFrom = trim($filters['date_from'] ?? '');
        $dateTo = trim($filters['date_to'] ?? '');
        if ($dateFrom === '') {
            $dateFrom = date('Y-m-d', strtotime('-6 days'));
        }
        if ($dateTo === '') {
            $dateTo = date('Y-m-d');
        }

        $gender = trim($filters['gender'] ?? '');
        $risk = trim($filters['risk'] ?? '');
        $diagnosis = trim($filters['diagnosis'] ?? '');
        if ($diagnosis !== '') {
            $diagnosis = DiagnosisHelper::normalize($diagnosis);
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'gender' => in_array($gender, ['Male', 'Female'], true) ? $gender : '',
            'risk' => in_array($risk, ['Low', 'Medium', 'High'], true) ? $risk : '',
            'diagnosis' => $diagnosis,
            'drill' => trim($filters['drill'] ?? ''),
            'drill_value' => trim($filters['drill_value'] ?? ''),
            'granularity' => in_array($filters['granularity'] ?? '', ['day', 'week', 'month'], true)
                ? $filters['granularity']
                : 'day',
        ];
    }

    /** Index-friendly date range (no DATE(column)). */
    private function baseFilter(array $f): array
    {
        $params = [
            $this->doctorId,
            $f['date_from'] . ' 00:00:00',
            date('Y-m-d', strtotime($f['date_to'] . ' +1 day')) . ' 00:00:00',
        ];
        $where = 'pr.deleted = 0 AND pr.doctor_id = ? AND pr.created_at >= ? AND pr.created_at < ?';
        $join = 'INNER JOIN patients pt ON pt.id = pr.patient_id';

        if ($f['risk'] !== '') {
            $where .= ' AND pr.risk_level = ?';
            $params[] = $f['risk'];
        }
        if ($f['gender'] !== '') {
            $where .= ' AND pt.gender = ?';
            $params[] = $f['gender'];
        }
        if ($f['diagnosis'] !== '') {
            $where .= ' AND (' . $this->diagnosisMatchSql('pr.final_result', $f['diagnosis']) . ')';
        }

        return ['where' => $where, 'params' => $params, 'join' => $join];
    }

    private function diagnosisMatchSql(string $column, string $canonical): string
    {
        $c = strtolower($canonical);
        if ($c === 'normal') {
            return "(LOWER({$column}) LIKE '%normal%' OR LOWER({$column}) LIKE '%healthy%')";
        }
        if ($c === 'cataract') {
            return "LOWER({$column}) LIKE '%cataract%'";
        }
        if ($c === 'glaucoma') {
            return "LOWER({$column}) LIKE '%glauc%'";
        }
        return "(LOWER({$column}) LIKE '%diabet%' OR LOWER({$column}) LIKE '%retinopath%' OR LOWER({$column}) = 'dr')";
    }

    private function getLifetimeStats(): array
    {
        if ($this->lifetimeCache !== null) {
            return $this->lifetimeCache;
        }

        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS all_time_screenings,
                COUNT(DISTINCT patient_id) AS patients_seen,
                SUM(CASE WHEN created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS screenings_today
             FROM predictions
             WHERE deleted = 0 AND doctor_id = ?'
        );
        $stmt->execute([$this->doctorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $this->lifetimeCache = [
            'all_time_screenings' => (int)($row['all_time_screenings'] ?? 0),
            'patients_seen' => (int)($row['patients_seen'] ?? 0),
            'screenings_today' => (int)($row['screenings_today'] ?? 0),
        ];

        return $this->lifetimeCache;
    }

    private function fetchKpisAndRisk(array $f, array $base): array
    {
        $where = $base['where'];
        $params = $base['params'];
        $join = $base['join'];

        $sql = "SELECT
            COUNT(*) AS screenings_in_range,
            COUNT(DISTINCT pr.patient_id) AS unique_patients,
            SUM(CASE WHEN pr.risk_level = 'High' THEN 1 ELSE 0 END) AS high_risk,
            SUM(CASE WHEN pr.risk_level = 'Low' THEN 1 ELSE 0 END) AS risk_low,
            SUM(CASE WHEN pr.risk_level = 'Medium' THEN 1 ELSE 0 END) AS risk_medium,
            COALESCE(AVG(COALESCE(pr.final_confidence, pr.confidence)), 0) AS avg_confidence,
            SUM(CASE
                WHEN LOWER(TRIM(pr.cnn_result)) = LOWER(TRIM(pr.vgg_result))
                 AND LOWER(TRIM(pr.vgg_result)) = LOWER(TRIM(pr.resnet_result))
                THEN 1 ELSE 0 END) AS agreed
            FROM predictions pr {$join} WHERE {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $lifetime = $this->getLifetimeStats();
        $total = (int)($row['screenings_in_range'] ?? 0);
        $high = (int)($row['high_risk'] ?? 0);
        $agreed = (int)($row['agreed'] ?? 0);

        return [
            'patients_seen' => $lifetime['patients_seen'],
            'all_time_screenings' => $lifetime['all_time_screenings'],
            'screenings_in_range' => $total,
            'unique_patients' => (int)($row['unique_patients'] ?? 0),
            'screenings_today' => $lifetime['screenings_today'],
            'high_risk' => $high,
            'high_risk_pct' => $total > 0 ? round(($high / $total) * 100, 1) : 0,
            'avg_confidence' => round((float)($row['avg_confidence'] ?? 0), 1),
            'model_agreement_pct' => $total > 0 ? round(($agreed / $total) * 100, 1) : 0,
            'date_range_label' => date('d M Y', strtotime($f['date_from'])) . ' to ' . date('d M Y', strtotime($f['date_to'])),
            'risk_breakdown' => [
                'Low' => (int)($row['risk_low'] ?? 0),
                'Medium' => (int)($row['risk_medium'] ?? 0),
                'High' => (int)($row['high_risk'] ?? 0),
            ],
        ];
    }

    private function fetchDiagnosisBreakdown(array $base): array
    {
        $sql = "SELECT pr.final_result, COUNT(*) AS total
                FROM predictions pr {$base['join']} WHERE {$base['where']}
                GROUP BY pr.final_result";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($base['params']);
        return DiagnosisHelper::mergeBreakdown($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function fetchTrend(array $f, array $base): array
    {
        if ($f['granularity'] === 'month') {
            $bucket = "DATE_FORMAT(pr.created_at, '%Y-%m')";
        } elseif ($f['granularity'] === 'week') {
            $bucket = "DATE_FORMAT(pr.created_at, '%x-W%v')";
        } else {
            $bucket = 'DATE(pr.created_at)';
        }

        $sql = "SELECT {$bucket} AS bucket, COUNT(*) AS total
                FROM predictions pr {$base['join']} WHERE {$base['where']}
                GROUP BY bucket ORDER BY bucket ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($base['params']);

        $labels = [];
        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $labels[] = (string)$r['bucket'];
            $values[] = (int)$r['total'];
        }
        return ['labels' => $labels, 'values' => $values];
    }

    private function fetchGender(array $base): array
    {
        $sql = "SELECT pt.gender, COUNT(*) AS total
                FROM predictions pr {$base['join']} WHERE {$base['where']}
                GROUP BY pt.gender";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($base['params']);
        $out = ['Male' => 0, 'Female' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $g = $r['gender'] ?? '';
            if (isset($out[$g])) {
                $out[$g] = (int)$r['total'];
            }
        }
        return $out;
    }

    private function fetchAgeBands(array $base): array
    {
        $sql = "SELECT band, COUNT(*) AS total FROM (
            SELECT DISTINCT pt.id,
                CASE
                    WHEN pt.age < 30 THEN 'Under 30'
                    WHEN pt.age BETWEEN 30 AND 49 THEN '30-49'
                    WHEN pt.age BETWEEN 50 AND 64 THEN '50-64'
                    ELSE '65+'
                END AS band
            FROM predictions pr {$base['join']}
            WHERE {$base['where']}
        ) AS cohort GROUP BY band";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($base['params']);

        $bands = ['Under 30' => 0, '30-49' => 0, '50-64' => 0, '65+' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $b = $r['band'] ?? '';
            if (isset($bands[$b])) {
                $bands[$b] = (int)$r['total'];
            }
        }
        return $bands;
    }

    private function fetchConfidenceByDiagnosis(array $base): array
    {
        $sql = "SELECT pr.final_result, AVG(COALESCE(pr.final_confidence, pr.confidence)) AS avg_conf
                FROM predictions pr {$base['join']} WHERE {$base['where']}
                GROUP BY pr.final_result";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($base['params']);

        $sums = [];
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $dx = DiagnosisHelper::normalize($r['final_result'] ?? '');
            $sums[$dx] = ($sums[$dx] ?? 0) + (float)$r['avg_conf'];
            $counts[$dx] = ($counts[$dx] ?? 0) + 1;
        }

        $out = [];
        foreach (DiagnosisHelper::all() as $dx) {
            $out[$dx] = isset($counts[$dx]) ? round($sums[$dx] / $counts[$dx], 1) : 0;
        }
        return $out;
    }

    private function fetchRecent(array $base, int $limit): array
    {
        $sql = "SELECT pr.id, pr.created_at, pr.final_result, pr.risk_level,
                COALESCE(pr.final_confidence, pr.confidence) AS confidence,
                pt.name, pt.ic
                FROM predictions pr {$base['join']}
                WHERE {$base['where']}
                ORDER BY pr.created_at DESC
                LIMIT " . (int)$limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($base['params']);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'created_at' => $r['created_at'],
                'name' => $r['name'],
                'ic' => $r['ic'],
                'final_result' => DiagnosisHelper::normalize($r['final_result'] ?? ''),
                'risk_level' => $r['risk_level'],
                'confidence' => round((float)$r['confidence'], 1),
            ];
        }
        return $out;
    }

    private function buildDrillResponse(array $f, string $drill, string $drillValue): array
    {
        $base = $this->baseFilter($f);
        $where = $base['where'];
        $params = $base['params'];
        $join = $base['join'];

        $breadcrumb = [['label' => 'Dashboard', 'level' => 'root']];
        $title = 'Screening detail';

        if ($drill === 'diagnosis') {
            $dx = DiagnosisHelper::normalize($drillValue);
            $where .= ' AND (' . $this->diagnosisMatchSql('pr.final_result', $dx) . ')';
            $breadcrumb[] = ['label' => $dx, 'level' => 'diagnosis', 'value' => $dx];
            $title = $dx . ': your screenings';
        } elseif ($drill === 'day') {
            $where .= ' AND pr.created_at >= ? AND pr.created_at < ?';
            $params[] = $drillValue . ' 00:00:00';
            $params[] = date('Y-m-d', strtotime($drillValue . ' +1 day')) . ' 00:00:00';
            $breadcrumb[] = ['label' => date('d M Y', strtotime($drillValue)), 'level' => 'day', 'value' => $drillValue];
            $title = 'Screenings on ' . date('d M Y', strtotime($drillValue));
        } elseif ($drill === 'risk') {
            $where .= ' AND pr.risk_level = ?';
            $params[] = $drillValue;
            $breadcrumb[] = ['label' => $drillValue . ' risk', 'level' => 'risk', 'value' => $drillValue];
            $title = $drillValue . ' risk: your screenings';
        }

        $sql = "SELECT pr.id, pr.created_at, pr.final_result, pr.risk_level,
                COALESCE(pr.final_confidence, pr.confidence) AS confidence,
                pr.cnn_result, pr.vgg_result, pr.resnet_result,
                pt.name, pt.ic, pt.age, pt.gender
                FROM predictions pr {$join}
                WHERE {$where}
                ORDER BY pr.created_at DESC
                LIMIT 80";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $table = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $table[] = [
                'id' => (int)$r['id'],
                'created_at' => $r['created_at'],
                'name' => $r['name'],
                'ic' => $r['ic'],
                'age' => (int)$r['age'],
                'gender' => $r['gender'],
                'final_result' => DiagnosisHelper::normalize($r['final_result'] ?? ''),
                'risk_level' => $r['risk_level'],
                'confidence' => round((float)$r['confidence'], 1),
                'cnn' => DiagnosisHelper::normalize($r['cnn_result'] ?? ''),
                'vgg' => DiagnosisHelper::normalize($r['vgg_result'] ?? ''),
                'resnet' => DiagnosisHelper::normalize($r['resnet_result'] ?? ''),
            ];
        }

        return [
            'mode' => 'drill',
            'scope' => 'personal',
            'filters' => $f,
            'breadcrumb' => $breadcrumb,
            'drill_title' => $title,
            'drill_count' => count($table),
            'drill_table' => $table,
            'kpis' => ['screenings_in_range' => count($table)],
        ];
    }
}
