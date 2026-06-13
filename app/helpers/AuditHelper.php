<?php

require_once __DIR__ . '/DiagnosisHelper.php';

class AuditHelper
{
    private $db;

    /** Actions not shown in clinician audit UI (noise / polling) */
    private const HIDDEN_ACTIONS = [
        'AI service health check',
        'DASHBOARD.AI_PING',
    ];

    const MODULE_ORDER = ['Prediction', 'Patient', 'Report', 'Auth', 'Dashboard', 'Audit', 'System'];

    /** Display order for detail fields (most specific first) */
    private const CONTEXT_FIELD_ORDER = [
        'outcome',
        'prediction_id',
        'patient_id',
        'patient_name',
        'ic',
        'age',
        'gender',
        'final_diagnosis',
        'diagnosis',
        'confidence',
        'risk_level',
        'risk',
        'model_agreement',
        'ensemble_breakdown',
        'cnn',
        'vgg',
        'resnet',
        'image_file',
        'doctor_name',
        'performed_by',
        'records',
        'predictions_removed',
        'screenings',
        'results',
        'page',
        'total_pages',
        'search',
        'sort',
        'filter_gender',
        'gender_filter',
        'name',
        'role',
        'email',
        'changes',
        'reason',
        'session',
        'client',
    ];

    private const CONTEXT_LABELS = [
        'outcome' => 'Outcome',
        'prediction_id' => 'Screening ID',
        'patient_id' => 'Patient registry ID',
        'patient_name' => 'Patient name',
        'ic' => 'IC number',
        'age' => 'Age',
        'gender' => 'Gender',
        'final_diagnosis' => 'Final diagnosis',
        'diagnosis' => 'Diagnosis',
        'confidence' => 'Confidence',
        'risk_level' => 'Risk level',
        'risk' => 'Risk level',
        'model_agreement' => 'Model agreement',
        'ensemble_breakdown' => 'Ensemble models',
        'cnn' => 'CNN',
        'vgg' => 'VGG16',
        'resnet' => 'ResNet50',
        'image_file' => 'Image file',
        'doctor_name' => 'Performing clinician',
        'performed_by' => 'Performing clinician',
        'records' => 'Records returned',
        'predictions_removed' => 'Screenings removed',
        'screenings' => 'Screenings in period',
        'results' => 'Registry matches',
        'page' => 'Page',
        'total_pages' => 'Total pages',
        'search' => 'Search query',
        'sort' => 'Sort order',
        'filter_gender' => 'Gender filter',
        'gender_filter' => 'Gender filter',
        'name' => 'Name',
        'role' => 'Role',
        'email' => 'Account',
        'changes' => 'Fields changed',
        'reason' => 'Reason',
        'session' => 'Session',
        'client' => 'Client',
        'ip' => 'IP address',
    ];

    public function __construct($db)
    {
        $this->db = $db;
    }

    /** Real-system style: CODE: human-readable title */
    public static function action(string $code, string $title): string
    {
        return strtoupper(trim($code)) . ': ' . trim($title);
    }

    public static function eventCode(string $summary): string
    {
        if (strpos($summary, ': ') !== false) {
            return trim(explode(': ', $summary, 2)[0]);
        }
        if (strpos($summary, ' — ') !== false) {
            return trim(explode(' — ', $summary, 2)[0]);
        }
        return $summary;
    }

    public static function eventTitle(string $summary): string
    {
        if (strpos($summary, ': ') !== false) {
            return trim(explode(': ', $summary, 2)[1]);
        }
        if (strpos($summary, ' — ') !== false) {
            return trim(explode(' — ', $summary, 2)[1]);
        }
        return '';
    }

    public function logAction($user_id, $action, array $context = [])
    {
        try {
            $ip = self::normalizeIp($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $context = array_merge([
                'session' => self::sessionRef(),
                'client' => self::shortUserAgent($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ], $context);

            $details = self::formatContextForStorage($context, $ip);
            if ($details !== '') {
                $action .= ' | ' . $details;
            }

            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (user_id, action, created_at)
                VALUES (?, ?, NOW())
            ");

            $stmt->execute([$user_id, $action]);

            return true;
        } catch (Throwable $e) {
            error_log('Audit log insert failed: ' . $e->getMessage());
            return false;
        }
    }

    public function logCurrentUser($action, array $context = [])
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return false;
        }
        return $this->logAction($userId, $action, $context);
    }

    /**
     * @return array<string, scalar>
     */
    public function patientContextById(int $patientId): array
    {
        if ($patientId <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, ic, name, age, gender FROM patients WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$patientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['patient_id' => $patientId, 'outcome' => 'Patient not found in registry'];
            }

            return self::patientContextFromRow($row);
        } catch (Throwable $e) {
            return ['patient_id' => $patientId];
        }
    }

    /**
     * @return array<string, scalar>
     */
    public static function patientContextFromRow(array $row): array
    {
        return [
            'patient_id' => (int)($row['id'] ?? 0),
            'patient_name' => trim((string)($row['name'] ?? '')),
            'ic' => preg_replace('/\D/', '', (string)($row['ic'] ?? '')),
            'age' => (int)($row['age'] ?? 0),
            'gender' => trim((string)($row['gender'] ?? '')),
        ];
    }

    /**
     * @return array<string, scalar>
     */
    public function predictionContextById(int $predictionId): array
    {
        if ($predictionId <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT pr.id, pr.patient_id, pr.doctor_id, pr.final_result, pr.final_confidence, pr.confidence,
                       pr.risk_level, pr.model_agreement_score,
                       pr.cnn_result, pr.vgg_result, pr.resnet_result,
                       pr.cnn_confidence, pr.vgg_confidence, pr.resnet_confidence,
                       pr.image_path, pr.created_at,
                       pt.ic, pt.name, pt.age, pt.gender,
                       COALESCE(NULLIF(TRIM(u.name), ''), 'Unassigned clinician') AS doctor_name
                FROM predictions pr
                INNER JOIN patients pt ON pt.id = pr.patient_id
                LEFT JOIN users u ON u.id = pr.doctor_id
                WHERE pr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$predictionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return [
                    'prediction_id' => $predictionId,
                    'outcome' => 'Screening record not found',
                ];
            }

            return array_merge(
                self::patientContextFromRow([
                    'id' => $row['patient_id'],
                    'ic' => $row['ic'],
                    'name' => $row['name'],
                    'age' => $row['age'],
                    'gender' => $row['gender'],
                ]),
                self::predictionContextFromRow($row)
            );
        } catch (Throwable $e) {
            return ['prediction_id' => $predictionId];
        }
    }

    /**
     * @return array<string, scalar>
     */
    public static function predictionContextFromRow(array $row): array
    {
        $conf = (float)($row['final_confidence'] ?? $row['confidence'] ?? 0);
        $cnn = DiagnosisHelper::normalize($row['cnn_result'] ?? '');
        $vgg = DiagnosisHelper::normalize($row['vgg_result'] ?? '');
        $res = DiagnosisHelper::normalize($row['resnet_result'] ?? '');
        $final = DiagnosisHelper::normalize($row['final_result'] ?? '');

        return [
            'prediction_id' => (int)($row['id'] ?? 0),
            'final_diagnosis' => $final,
            'confidence' => round($conf, 1) . '%',
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'model_agreement' => round((float)($row['model_agreement_score'] ?? 0), 1) . '%',
            'ensemble_breakdown' => sprintf(
                'CNN %s (%.1f%%); VGG16 %s (%.1f%%); ResNet50 %s (%.1f%%)',
                $cnn,
                (float)($row['cnn_confidence'] ?? 0),
                $vgg,
                (float)($row['vgg_confidence'] ?? 0),
                $res,
                (float)($row['resnet_confidence'] ?? 0)
            ),
            'image_file' => basename((string)($row['image_path'] ?? '')),
            'doctor_name' => trim((string)($row['doctor_name'] ?? '')),
        ];
    }

    /**
     * @return array<string, scalar>
     */
    public static function screeningResultContext(array $result, int $predictionId = 0): array
    {
        $ctx = [
            'outcome' => 'SUCCESS',
            'final_diagnosis' => DiagnosisHelper::normalize($result['final_result'] ?? ''),
            'confidence' => round((float)($result['final_confidence'] ?? $result['confidence'] ?? 0), 1) . '%',
            'risk_level' => (string)($result['risk_level'] ?? ''),
            'model_agreement' => round((float)($result['model_agreement_score'] ?? 0), 1) . '%',
            'ensemble_breakdown' => sprintf(
                'CNN %s (%.1f%%); VGG16 %s (%.1f%%); ResNet50 %s (%.1f%%)',
                DiagnosisHelper::normalize($result['cnn_result'] ?? ''),
                (float)($result['cnn_confidence'] ?? 0),
                DiagnosisHelper::normalize($result['vgg_result'] ?? ''),
                (float)($result['vgg_confidence'] ?? 0),
                DiagnosisHelper::normalize($result['resnet_result'] ?? ''),
                (float)($result['resnet_confidence'] ?? 0)
            ),
        ];
        if ($predictionId > 0) {
            $ctx['prediction_id'] = $predictionId;
        }
        return $ctx;
    }

    private static function formatContextForStorage(array $context, string $ip): string
    {
        unset($context['method'], $context['uri'], $context['status']);

        $ordered = [];
        foreach (self::CONTEXT_FIELD_ORDER as $key) {
            if (array_key_exists($key, $context)) {
                $ordered[$key] = $context[$key];
            }
        }
        foreach ($context as $key => $value) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        $parts = [];
        foreach ($ordered as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $label = self::CONTEXT_LABELS[$key] ?? ucfirst(str_replace('_', ' ', (string)$key));
            $parts[] = $label . ': ' . $value;
        }

        if ($ip && $ip !== 'unknown') {
            $parts[] = 'IP address: ' . $ip;
        }

        return implode(' · ', $parts);
    }

    public static function parseLogRow(array $log): array
    {
        $action = trim($log['action'] ?? '');
        $summary = $action;
        $details = '';

        if (strpos($action, ' | ') !== false) {
            [$summary, $details] = explode(' | ', $action, 2);
            $summary = trim($summary);
            $details = trim($details);
        }

        if ($details !== '' && $details[0] === '{') {
            $decoded = json_decode($details, true);
            if (is_array($decoded)) {
                $details = self::formatLegacyJson($decoded);
            }
        }

        $module = self::detectModule($summary);
        $ip = '-';
        if (preg_match('/IP(?:\s+address)?:\s*([0-9a-fA-F.:]+)/u', $details, $m)) {
            $ip = $m[1];
        }

        $detailItems = self::parseDetailItems($details);
        $detailItems = array_values(array_filter(
            $detailItems,
            static fn(array $item): bool => stripos($item['label'], 'IP') === false
        ));

        return [
            'module' => $module,
            'summary' => $summary,
            'details' => $details,
            'detail_items' => $detailItems,
            'ip' => $ip,
            'hidden' => self::isHiddenAction($summary),
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function parseDetailItems(string $details): array
    {
        if ($details === '') {
            return [];
        }

        $items = [];
        foreach (explode(' · ', $details) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $colon = strpos($chunk, ':');
            if ($colon === false) {
                $items[] = ['label' => 'Note', 'value' => $chunk];
                continue;
            }
            $items[] = [
                'label' => trim(substr($chunk, 0, $colon)),
                'value' => trim(substr($chunk, $colon + 1)),
            ];
        }

        return $items;
    }

    private static function formatLegacyJson(array $data): string
    {
        $parts = [];
        foreach ($data as $k => $v) {
            if (in_array($k, ['method', 'uri'], true)) {
                continue;
            }
            if (is_scalar($v)) {
                $label = self::CONTEXT_LABELS[$k] ?? ucfirst((string)$k);
                $parts[] = $label . ': ' . $v;
            }
        }
        return implode(' · ', $parts);
    }

    private static function detectModule(string $summary): string
    {
        $code = self::eventCode($summary);

        if (str_starts_with($code, 'AUTH.')) {
            return 'Auth';
        }
        if (str_starts_with($code, 'PREDICT.') || str_starts_with($code, 'SCREENING.')) {
            return 'Prediction';
        }
        if (str_starts_with($code, 'PATIENT.')) {
            return 'Patient';
        }
        if (str_starts_with($code, 'REPORT.')) {
            return 'Report';
        }
        if (str_starts_with($code, 'AUDIT.')) {
            return 'Audit';
        }
        if (str_starts_with($code, 'DASHBOARD.')) {
            return 'Dashboard';
        }

        $s = strtolower($summary);

        if (preg_match('/\b(login|logout|register)\b/', $s)) {
            return 'Auth';
        }
        if (preg_match('/\b(exported|pdf)\b/', $s)) {
            return 'Report';
        }
        if (preg_match('/\b(prediction|screening|predict|diagnosis|fundus|ensemble|reran)\b/', $s)) {
            return 'Prediction';
        }
        if (preg_match('/\b(patient|registry|ic lookup)\b/', $s)) {
            return 'Patient';
        }
        if (preg_match('/\b(audit)\b/', $s)) {
            return 'Audit';
        }
        if (preg_match('/\b(dashboard)\b/', $s)) {
            return 'Dashboard';
        }

        return 'System';
    }

    private static function isHiddenAction(string $summary): bool
    {
        foreach (self::HIDDEN_ACTIONS as $hidden) {
            if (stripos($summary, $hidden) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeIp(string $ip): string
    {
        return $ip === '::1' ? '127.0.0.1' : $ip;
    }

    private static function sessionRef(): string
    {
        $id = session_id();
        if ($id === '') {
            return 'no-session';
        }
        return 'SID-' . strtoupper(substr(hash('crc32b', $id), 0, 8));
    }

    private static function shortUserAgent(string $ua): string
    {
        $ua = trim($ua);
        if ($ua === '') {
            return 'Unknown client';
        }
        if (stripos($ua, 'Edg/') !== false) {
            return 'Microsoft Edge';
        }
        if (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) {
            return 'Google Chrome';
        }
        if (stripos($ua, 'Firefox/') !== false) {
            return 'Mozilla Firefox';
        }
        if (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome') === false) {
            return 'Apple Safari';
        }
        return strlen($ua) > 48 ? substr($ua, 0, 45) . '…' : $ua;
    }

    /**
     * @return array{0: string, 1: array<int, string|int>}
     */
    private function buildLogFilterClause(int $userId, array $filters): array
    {
        $sql = ' WHERE a.user_id = ?';
        $params = [$userId];

        foreach (self::HIDDEN_ACTIONS as $hidden) {
            $sql .= ' AND a.action NOT LIKE ?';
            $params[] = '%' . $hidden . '%';
        }

        if (!empty($filters['q'])) {
            $sql .= ' AND LOWER(a.action) LIKE LOWER(?)';
            $params[] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND DATE(a.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND DATE(a.created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        return [$sql, $params];
    }

    public function countLogsForUser(int $userId, array $filters = []): int
    {
        try {
            [$where, $params] = $this->buildLogFilterClause($userId, $filters);
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM audit_logs a' . $where);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Audit log count failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @return list<array>
     */
    public function fetchLogsPageForUser(int $userId, array $filters, int $offset, int $limit): array
    {
        try {
            [$where, $params] = $this->buildLogFilterClause($userId, $filters);
            $limit = max(1, min(50, $limit));
            $offset = max(0, $offset);

            $sql = "
                SELECT a.id, a.user_id, a.action, a.created_at, u.name AS user_name
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.user_id
                {$where}
                ORDER BY a.created_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Audit log fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getModuleCountsForUser(int $userId, array $filters = []): array
    {
        $modules = array_fill_keys(self::MODULE_ORDER, 0);

        try {
            [$where, $params] = $this->buildLogFilterClause($userId, $filters);
            $stmt = $this->db->prepare('SELECT a.action FROM audit_logs a' . $where);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $parsed = self::parseLogRow($row);
                if ($parsed['hidden']) {
                    continue;
                }
                $module = $parsed['module'];
                if (!isset($modules[$module])) {
                    $modules[$module] = 0;
                }
                $modules[$module]++;
            }
        } catch (Throwable $e) {
            error_log('Audit module counts failed: ' . $e->getMessage());
        }

        return $modules;
    }

    /** @deprecated Use fetchLogsPageForUser */
    public function getLogsByUser($user_id, array $filters = [])
    {
        return $this->fetchLogsPageForUser($user_id, $filters, 0, 500);
    }

    /**
     * @return array{rows: list<array>, modules: array<string, int>}
     */
    public function getVisibleLogsForUser(int $userId, array $filters = []): array
    {
        $rows = [];
        foreach ($this->fetchLogsPageForUser($userId, $filters, 0, 500) as $log) {
            $parsed = self::parseLogRow($log);
            if ($parsed['hidden']) {
                continue;
            }
            $rows[] = [
                'id' => $log['id'],
                'created_at' => $log['created_at'],
                'parsed' => $parsed,
            ];
        }

        return [
            'rows' => $rows,
            'modules' => $this->getModuleCountsForUser($userId, $filters),
        ];
    }

    public function getDoctorClinicalCounts(int $doctorId, array $filters = []): array
    {
        if ($doctorId <= 0) {
            return ['screenings' => 0, 'patients' => 0];
        }

        try {
            $where = 'deleted = 0 AND doctor_id = ?';
            $params = [$doctorId];

            if (!empty($filters['date_from'])) {
                $where .= ' AND created_at >= ?';
                $params[] = $filters['date_from'] . ' 00:00:00';
            }
            if (!empty($filters['date_to'])) {
                $where .= ' AND created_at < ?';
                $params[] = date('Y-m-d', strtotime($filters['date_to'] . ' +1 day')) . ' 00:00:00';
            }

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS screenings, COUNT(DISTINCT patient_id) AS patients
                 FROM predictions WHERE {$where}"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'screenings' => (int)($row['screenings'] ?? 0),
                'patients' => (int)($row['patients'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('Clinical count fetch failed: ' . $e->getMessage());
            return ['screenings' => 0, 'patients' => 0];
        }
    }

    public function getAllLogs()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT a.id, a.user_id, a.action, a.created_at, u.name AS user_name
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.user_id
                ORDER BY a.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}
