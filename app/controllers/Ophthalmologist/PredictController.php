<?php

require_once BASE_PATH . '/app/middleware/role_check.php';
require_once BASE_PATH . '/app/services/AiServiceManager.php';
require_once BASE_PATH . '/app/services/ClinicalReportService.php';
require_once BASE_PATH . '/app/helpers/DiagnosisHelper.php';
require_once BASE_PATH . '/app/helpers/AuditHelper.php';
require_once BASE_PATH . '/app/models/Patient.php';

class PredictController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // ================= VIEW =================
    public function index()
    {
        $this->checkAuth();
        $this->auditLog(AuditHelper::action('PREDICT.WORKSPACE_OPEN', 'Opened AI retinal screening workspace'));
        $this->view('ophthalmologist/predict', [
            'current_doctor_id' => (int)($_SESSION['user_id'] ?? 0),
            'current_doctor_name' => trim($_SESSION['name'] ?? 'Clinician'),
        ]);
    }

    // ================= CHECK IC =================
    public function checkIC()
    {
        $this->checkAuth();

        try {
            $ic = preg_replace('/[^0-9]/', '', trim($_POST['ic'] ?? ''));

            if (strlen($ic) !== 12) {
                $this->auditLog(AuditHelper::action('PATIENT.IC_LOOKUP_REJECTED', 'Patient IC lookup rejected — invalid format'), [
                    'ic' => $ic,
                    'outcome' => 'FAILED',
                    'reason' => 'IC must be exactly 12 digits',
                ]);
                return $this->json([
                    'status' => 'error',
                    'message' => 'Invalid IC format'
                ]);
            }

            $patientModel = new Patient($this->db);
            $patient = $patientModel->findByICNormalized($ic);

            if ($patient) {
                $patient['ic'] = Patient::normalizeIc((string)$patient['ic']);
                $patient['gender'] = $this->normalizeGender($patient['gender']);
                $this->auditLog(AuditHelper::action('PATIENT.IC_LOOKUP_FOUND', 'Patient registry match — existing record loaded for screening'), array_merge(
                    AuditHelper::patientContextFromRow($patient),
                    ['outcome' => 'FOUND']
                ));

                return $this->json([
                    'status' => 'exist',
                    'patient' => $patient
                ]);
            }

            $info = $this->extractFromIC($ic);
            $this->auditLog(AuditHelper::action('PATIENT.IC_LOOKUP_NEW', 'Patient IC verified — not in registry, registration required'), [
                'ic' => $ic,
                'outcome' => 'NOT_REGISTERED',
                'age' => $info['age'],
                'gender' => $info['gender'],
            ]);

            return $this->json([
                'status' => 'new',
                'age' => $info['age'],
                'gender' => $info['gender']
            ]);

        } catch (Throwable $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    // ================= REGISTER PATIENT =================
    public function register()
    {
        $this->checkAuth();

        try {
            $ic = preg_replace('/[^0-9]/', '', trim($_POST['ic'] ?? ''));
            $name = trim($_POST['name'] ?? '');

            if (!$ic || !$name) {
                return $this->json([
                    'success' => false,
                    'message' => 'Missing IC or Name'
                ]);
            }

            $patientModel = new Patient($this->db);
            if ($patientModel->findByICNormalized($ic)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Patient already exists'
                ]);
            }

            $info = $this->extractFromIC($ic);

            $stmt = $this->db->prepare("
                INSERT INTO patients (ic, name, age, gender, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $ic,
                $name,
                $info['age'],
                $info['gender']
            ]);

            $id = $this->db->lastInsertId();

            $stmt = $this->db->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt->execute([$id]);

            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            $patient['gender'] = $this->normalizeGender($patient['gender']);
            $this->auditLog(AuditHelper::action('PATIENT.REGISTER', 'New patient registered in ophthalmology registry'), array_merge(
                AuditHelper::patientContextFromRow($patient),
                ['outcome' => 'SUCCESS']
            ));

            return $this->json([
                'success' => true,
                'patient' => $patient
            ]);

        } catch (Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    // ================= PREDICT =================
    public function predict()
    {
        $this->checkAuth();

        header('Content-Type: application/json');

        $patientId = null;
        try {
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $image = $_FILES['image'] ?? null;

            if ($patientId <= 0 || !$image) {
                return $this->json([
                    'success' => false,
                    'message' => 'Missing patient or image — search the patient again before scanning'
                ]);
            }

            $patientCheck = $this->db->prepare('SELECT id FROM patients WHERE id = ? LIMIT 1');
            $patientCheck->execute([$patientId]);
            if (!$patientCheck->fetchColumn()) {
                return $this->json([
                    'success' => false,
                    'message' => 'Patient not found — refresh IC search and try again'
                ]);
            }

            if ($image['error'] !== 0) {
                return $this->json([
                    'success' => false,
                    'message' => 'Upload failed'
                ]);
            }

            $allowed = ['image/jpeg', 'image/png', 'image/jpg'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($image['tmp_name']) ?: '';

            if (!in_array($mimeType, $allowed, true)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid file type'
                ]);
            }

            if ($image['size'] > 10 * 1024 * 1024) {
                return $this->json([
                    'success' => false,
                    'message' => 'File too large (max 10MB)'
                ]);
            }

            // ================= SAVE FILE =================
            $uploadDir = BASE_PATH . '/upload/predictions/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $extensionMap = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png'
            ];
            $filename = uniqid('img_', true) . '.' . $extensionMap[$mimeType];
            $filepath = $uploadDir . $filename;

            if (!move_uploaded_file($image['tmp_name'], $filepath)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Unable to store uploaded file'
                ]);
            }

            // ================= AI RESULT =================
            $result = $this->callPredictionService($filepath);

            // ================= SAVE DB =================
            $stmt = $this->db->prepare("
                INSERT INTO predictions
                (
                    patient_id,
                    doctor_id,
                    image_path,

                    cnn_result,
                    vgg_result,
                    resnet_result,

                    cnn_confidence,
                    vgg_confidence,
                    resnet_confidence,

                    final_result,
                    confidence,

                    final_confidence,
                    model_agreement_score,
                    risk_level,

                    created_at,
                    deleted
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)
            ");

            $stmt->execute([
                $patientId,
                $_SESSION['user_id'],
                'predictions/' . $filename,

                $result['cnn_result'],
                $result['vgg_result'],
                $result['resnet_result'],

                $result['cnn_confidence'],
                $result['vgg_confidence'],
                $result['resnet_confidence'],

                $result['final_result'],
                $result['confidence'],

                $result['final_confidence'],
                $result['model_agreement_score'],
                $result['risk_level']
            ]);

            $predictionId = (int)$this->db->lastInsertId();
            $this->auditLog(
                AuditHelper::action('PREDICT.SCREENING_COMPLETED', 'Fundus image analysed — AI screening saved to patient record'),
                array_merge(
                    $this->audit->patientContextById((int)$patientId),
                    AuditHelper::screeningResultContext($result, $predictionId),
                    [
                        'image_file' => $filename,
                        'performed_by' => trim($_SESSION['name'] ?? 'Clinician'),
                    ]
                )
            );

            $screening = $this->fetchPredictionRowForHistory($predictionId);

            return $this->json([
                'success' => true,
                'result' => $result,
                'prediction_id' => $predictionId,
                'patient_id' => $patientId,
                'screening' => $screening,
            ]);

        } catch (Throwable $e) {
            $failCtx = ['outcome' => 'FAILED', 'reason' => $e->getMessage()];
            if (!empty($patientId)) {
                $failCtx = array_merge($failCtx, $this->audit->patientContextById((int)$patientId));
            }
            $this->auditLog(
                AuditHelper::action('PREDICT.SCREENING_FAILED', 'Fundus AI screening failed — no record saved'),
                $failCtx
            );
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    // ================= HISTORY =================
    public function getPredictions()
    {
        $this->checkAuth();

        try {
            $patientId = (int)($_GET['patient_id'] ?? 0);

            if ($patientId <= 0) {
                return $this->json([
                    'success' => false,
                    'message' => 'Missing patient_id',
                    'items' => [],
                ]);
            }

            $patientStmt = $this->db->prepare('SELECT id, ic, name, age, gender FROM patients WHERE id = ? LIMIT 1');
            $patientStmt->execute([$patientId]);
            $patientRow = $patientStmt->fetch(PDO::FETCH_ASSOC);
            if (!$patientRow) {
                return $this->json([
                    'success' => false,
                    'message' => 'Patient not found',
                    'items' => [],
                ]);
            }

            $stmt = $this->db->prepare("
                SELECT
                    pr.id,
                    pr.patient_id,
                    pr.doctor_id,
                    pr.image_path,
                    pr.cnn_result,
                    pr.vgg_result,
                    pr.resnet_result,
                    pr.final_result,
                    pr.confidence,
                    pr.final_confidence,
                    pr.model_agreement_score,
                    pr.risk_level,
                    pr.created_at,
                    COALESCE(NULLIF(TRIM(u.name), ''), 'Unassigned clinician') AS doctor_name
                FROM predictions pr
                LEFT JOIN users u ON u.id = pr.doctor_id
                WHERE pr.patient_id = ? AND pr.deleted = 0
                ORDER BY pr.created_at DESC
            ");

            $stmt->execute([$patientId]);
            $currentDoctorId = (int)($_SESSION['user_id'] ?? 0);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $normalized = $this->normalizePredictionRow($row);
                $normalized['doctor_name'] = trim((string)($normalized['doctor_name'] ?? 'Unassigned clinician'));
                $normalized['is_mine'] = $currentDoctorId > 0
                    && (int)($normalized['doctor_id'] ?? 0) === $currentDoctorId;
                $rows[] = $normalized;
            }
            $this->auditLog(AuditHelper::action('PREDICT.HISTORY_LIST', 'Retrieved AI screening history for patient'), array_merge(
                AuditHelper::patientContextFromRow($patientRow),
                [
                    'outcome' => 'SUCCESS',
                    'records' => count($rows) . ' screening(s)',
                ]
            ));
            return $this->json([
                'success' => true,
                'patient_id' => $patientId,
                'items' => $rows,
            ]);

        } catch (Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Could not load screening history',
                'items' => [],
            ]);
        }
    }

    private function fetchPredictionRowForHistory(int $predictionId): ?array
    {
        if ($predictionId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT
                pr.id,
                pr.patient_id,
                pr.doctor_id,
                pr.image_path,
                pr.cnn_result,
                pr.vgg_result,
                pr.resnet_result,
                pr.final_result,
                pr.confidence,
                pr.final_confidence,
                pr.model_agreement_score,
                pr.risk_level,
                pr.created_at,
                COALESCE(NULLIF(TRIM(u.name), ''), 'Unassigned clinician') AS doctor_name
            FROM predictions pr
            LEFT JOIN users u ON u.id = pr.doctor_id
            WHERE pr.id = ? AND pr.deleted = 0
            LIMIT 1
        ");
        $stmt->execute([$predictionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $normalized = $this->normalizePredictionRow($row);
        $currentDoctorId = (int)($_SESSION['user_id'] ?? 0);
        $normalized['doctor_name'] = trim((string)($normalized['doctor_name'] ?? 'Unassigned clinician'));
        $normalized['is_mine'] = $currentDoctorId > 0
            && (int)($normalized['doctor_id'] ?? 0) === $currentDoctorId;

        return $normalized;
    }

    public function getPredictionDetail()
    {
        $this->checkAuth();

        try {
            $id = $_GET['id'] ?? null;

            if (!$id) {
                return $this->json(null);
            }

            $stmt = $this->db->prepare("
                SELECT pr.*,
                       COALESCE(NULLIF(TRIM(u.name), ''), 'Unassigned clinician') AS doctor_name
                FROM predictions pr
                LEFT JOIN users u ON u.id = pr.doctor_id
                WHERE pr.id = ? AND pr.deleted = 0
                LIMIT 1
            ");
            $stmt->execute([$id]);

            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                $data = $this->normalizePredictionRow($data);
                $data['is_mine'] = (int)($_SESSION['user_id'] ?? 0) === (int)($data['doctor_id'] ?? 0);
                $this->auditLog(AuditHelper::action('PREDICT.SCREENING_VIEW', 'Opened AI screening result detail'), array_merge(
                    $this->audit->predictionContextById((int)$id),
                    ['outcome' => 'SUCCESS']
                ));
                $data['image_url'] = BASE_URL . '/upload/' . ltrim($data['image_path'], '/');
            }

            return $this->json($data ?: null);

        } catch (Throwable $e) {
            return $this->json(null);
        }
    }

    // ================= IC LOGIC =================
    private function extractFromIC($ic)
    {
        $yearPrefix = (int)substr($ic, 0, 2);
        $currentYear = (int)date('y');

        $birthYear = ($yearPrefix > $currentYear)
            ? 1900 + $yearPrefix
            : 2000 + $yearPrefix;

        $age = (int)date('Y') - $birthYear;

        $lastDigit = (int)substr($ic, -1);
        $gender = ($lastDigit % 2 === 1) ? 'Male' : 'Female';

        return [
            'age' => $age,
            'gender' => $gender
        ];
    }

    private function normalizeGender($gender)
    {
        $g = strtolower(trim($gender));

        return match ($g) {
            'm', 'male' => 'Male',
            'f', 'female' => 'Female',
            default => ucfirst($g)
        };
    }

    // =====================================================
    // CALL FLASK AI SERVICE
    // BUG FIX: Previously hardcoded cnn/vgg/resnet confidence
    //          to 0 and tried to map string labels as integers.
    //          Now we correctly read all values from Flask response.
    // =====================================================
    private function callPredictionService($imagePath)
    {
        AiServiceManager::ensureRunning();
        $url = AiServiceManager::predictUrl();

        $ch = curl_init();
        $cfile = new CURLFile($imagePath);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => ['image' => $cfile],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);

        // =====================
        // CURL ERROR HANDLING
        // =====================
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("CURL Error: " . $err);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // =====================
        // HTTP CHECK
        // =====================
        if ($httpCode !== 200) {
            throw new Exception("AI HTTP Error: " . $response);
        }

        // =====================
        // JSON VALIDATION
        // =====================
        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !$result) {
            throw new Exception("Invalid JSON Response: " . $response);
        }

        // =====================
        // MODEL RESULTS
        // =====================
        $cnnResult    = DiagnosisHelper::normalize($result['cnn_result'] ?? '');
        $vggResult    = DiagnosisHelper::normalize($result['vgg_result'] ?? '');
        $resnetResult = DiagnosisHelper::normalize($result['resnet_result'] ?? '');

        // =====================
        // MODEL CONFIDENCE (normalize)
        // =====================
        $cnnConfidence    = (float)($result['cnn_confidence'] ?? 0);
        $vggConfidence    = (float)($result['vgg_confidence'] ?? 0);
        $resnetConfidence = (float)($result['resnet_confidence'] ?? 0);

        // normalize if 0–1
        if ($cnnConfidence <= 1)    $cnnConfidence *= 100;
        if ($vggConfidence <= 1)    $vggConfidence *= 100;
        if ($resnetConfidence <= 1) $resnetConfidence *= 100;

        $cnnConfidence    = round($cnnConfidence, 2);
        $vggConfidence    = round($vggConfidence, 2);
        $resnetConfidence = round($resnetConfidence, 2);

        // =====================
        // FINAL RESULT
        // =====================
        $finalResult = DiagnosisHelper::normalize($result['final_result'] ?? '');

        // =====================
        // FINAL CONFIDENCE
        // =====================
        $confidence = (float)($result['final_confidence'] ?? $result['confidence'] ?? 0);

        if ($confidence <= 1.0) {
            $confidence *= 100;
        }

        $confidence = round($confidence, 2);

        // =====================
        // MODEL AGREEMENT SCORE
        // =====================
        $agreementScore = isset($result['model_agreement_score'])
            ? (float)$result['model_agreement_score']
            : (count(array_filter(
                [$cnnResult, $vggResult, $resnetResult],
                fn($v) => $v === $finalResult
            )) / 3) * 100;

        $agreementScore = round($agreementScore, 2);

        // =====================
        // 🔥 HYBRID RISK ENGINE (REAL WORLD LOGIC)
        // =====================

        // 1. Base medical severity
        $baseRiskMap = [
            'Normal'               => 1,
            'Cataract'             => 2,
            'Diabetic Retinopathy' => 3,
            'Glaucoma'             => 3
        ];

        $baseRisk = $baseRiskMap[$finalResult] ?? $baseRiskMap[DiagnosisHelper::normalize($finalResult)] ?? 0;

        // 2. Confidence weight
        if ($confidence >= 85) {
            $confidenceScore = 1.0;
        } elseif ($confidence >= 60) {
            $confidenceScore = 0.7;
        } else {
            $confidenceScore = 0.4;
        }

        // 3. Agreement normalization (0–1)
        $agreementNormalized = $agreementScore / 100;

        // 4. Final weighted score
        $finalRiskScore =
            ($baseRisk * 0.5) +
            ($confidenceScore * 2 * 0.3) +
            ($agreementNormalized * 2 * 0.2);

        // 5. Convert to label
        if ($finalRiskScore >= 2.5) {
            $risk = 'High';
        } elseif ($finalRiskScore >= 1.5) {
            $risk = 'Medium';
        } else {
            $risk = 'Low';
        }

        // =====================
        // RETURN FINAL DATA
        // =====================
        return [
            // MODEL OUTPUT
            'cnn_result'    => $cnnResult,
            'vgg_result'    => $vggResult,
            'resnet_result' => $resnetResult,

            // MODEL CONFIDENCE
            'cnn_confidence'    => $cnnConfidence,
            'vgg_confidence'    => $vggConfidence,
            'resnet_confidence' => $resnetConfidence,

            // FINAL AI RESULT
            'class_id'         => (int)($result['class'] ?? 0),
            'final_result'     => $finalResult,
            'confidence'       => $confidence,
            'final_confidence' => $confidence,

            // CLINICAL INTELLIGENCE
            'risk_level'            => $risk,
            'model_agreement_score'=> $agreementScore,

            // 🔥 BONUS (for explainability / panel impress)
            'risk_score' => round($finalRiskScore, 2)
        ];
    }

  /**
     * Ensure only the four canonical diagnosis labels are stored/returned.
     */
    private function normalizePredictionRow(array $row): array
    {
        $row['final_result'] = DiagnosisHelper::normalize($row['final_result'] ?? '');
        $row['cnn_result'] = DiagnosisHelper::normalize($row['cnn_result'] ?? '');
        $row['vgg_result'] = DiagnosisHelper::normalize($row['vgg_result'] ?? '');
        $row['resnet_result'] = DiagnosisHelper::normalize($row['resnet_result'] ?? '');
        return $row;
    }

    // ================= AUTH =================
    private function checkAuth()
    {
        RoleCheck::checkClinicDoctor();
    }

    // ================= EXPORT PDF =================
    public function exportPDF()
    {
        $this->checkAuth();

        try {
            $id = $_GET['id'] ?? null;

            if (!$id) {
                exit("Missing ID");
            }

            $stmt = $this->db->prepare("
                SELECT p.*, pt.name, pt.ic, pt.age, pt.gender,
                       COALESCE(NULLIF(TRIM(u.name), ''), 'Unassigned clinician') AS doctor_name
                FROM predictions p
                JOIN patients pt ON pt.id = p.patient_id
                LEFT JOIN users u ON u.id = p.doctor_id
                WHERE p.id = ? AND p.deleted = 0
            ");

            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                http_response_code(404);
                exit('Screening record not found or has been removed.');
            }

            $data = $this->normalizePredictionRow($data);
            $this->auditLog(AuditHelper::action('REPORT.PDF_EXPORT', 'Exported retinal diagnostic report (PDF)'), array_merge(
                $this->audit->predictionContextById((int)$id),
                ['outcome' => 'SUCCESS', 'format' => 'application/pdf']
            ));
            ClinicalReportService::streamPdf($data, (int)$id);
            exit;

        } catch (RuntimeException $e) {
            http_response_code(503);
            exit($e->getMessage());
        } catch (Throwable $e) {
            http_response_code(500);
            exit('Unable to generate PDF report.');
        }
    }

    // ================= DELETE =================
    public function deletePrediction()
    {
        $this->checkAuth();

        try {
            $id = $_POST['id'] ?? null;

            if (!$id) {
                return $this->json(['success' => false, 'message' => 'Missing ID']);
            }

            $ctx = $this->audit->predictionContextById((int)$id);
            $stmt = $this->db->prepare("UPDATE predictions SET deleted = 1 WHERE id = ?");
            $stmt->execute([$id]);
            $this->auditLog(AuditHelper::action('PREDICT.SCREENING_DELETE', 'Soft-deleted AI screening record (hidden from history)'), array_merge(
                $ctx,
                ['outcome' => 'SUCCESS']
            ));

            return $this->json(['success' => true]);

        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ================= RERUN =================
    public function rerunPrediction()
    {
        $this->checkAuth();

        try {
            $id = $_POST['id'] ?? null;

            if (!$id) {
                return $this->json(['success' => false, 'message' => 'Missing ID']);
            }

            $stmt = $this->db->prepare("SELECT * FROM predictions WHERE id = ?");
            $stmt->execute([$id]);
            $prediction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prediction) {
                return $this->json(['success' => false, 'message' => 'Prediction not found']);
            }

            $imagePath = BASE_PATH . '/upload/' . $prediction['image_path'];

            if (!file_exists($imagePath)) {
                return $this->json(['success' => false, 'message' => 'Original image not found']);
            }

            $result = $this->callPredictionService($imagePath);

            $stmt = $this->db->prepare("
                UPDATE predictions SET
                    cnn_result             = ?,
                    vgg_result             = ?,
                    resnet_result          = ?,
                    cnn_confidence         = ?,
                    vgg_confidence         = ?,
                    resnet_confidence      = ?,
                    final_result           = ?,
                    confidence             = ?,
                    final_confidence       = ?,
                    model_agreement_score  = ?,
                    risk_level             = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $result['cnn_result'],
                $result['vgg_result'],
                $result['resnet_result'],
                $result['cnn_confidence'],
                $result['vgg_confidence'],
                $result['resnet_confidence'],
                $result['final_result'],
                $result['confidence'],
                $result['final_confidence'],
                $result['model_agreement_score'],
                $result['risk_level'],
                $id
            ]);
            $this->auditLog(AuditHelper::action('PREDICT.SCREENING_RERUN', 'Re-ran ensemble AI on stored fundus image'), array_merge(
                $this->audit->predictionContextById((int)$id),
                AuditHelper::screeningResultContext($result, (int)$id),
                ['outcome' => 'SUCCESS']
            ));

            return $this->json(['success' => true, 'result' => $result]);

        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function exportPredictionPDF()
    {
        $this->exportPDF();
    }

    public function aiStatus()
    {
        $this->checkAuth();

        try {
            if (!AiServiceManager::isHealthy()) {
                try {
                    AiServiceManager::ensureRunning(45);
                } catch (Throwable $startErr) {
                    return $this->json([
                        'online' => false,
                        'message' => $startErr->getMessage(),
                    ], 503);
                }
            }

            $online = AiServiceManager::isHealthy();

            return $this->json([
                'online' => $online,
                'message' => $online ? 'AI engine ready' : 'Starting AI engine…',
            ]);
        } catch (Throwable $e) {
            return $this->json([
                'online' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }
}