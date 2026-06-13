<?php

require_once BASE_PATH . '/app/middleware/role_check.php';
require_once BASE_PATH . '/app/models/Patient.php';
require_once BASE_PATH . '/app/helpers/AuditHelper.php';

class PatientController extends Controller
{
    public function index()
    {
        RoleCheck::checkClinicDoctor();

        $model = new Patient($this->db);

        $search = $_GET['search'] ?? '';
        $gender = $_GET['gender'] ?? '';
        $sort   = $_GET['sort'] ?? 'latest';

        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = $model->getFilteredPaginated($search, $gender, $sort, $page);
        $patients = $result['items'];
        $pagination = $result['pagination'];
        $totalPatients = (int)$pagination['total'];
        $registryStats = $model->getRegistryStats();

        $this->auditLog(AuditHelper::action('PATIENT.LIST_VIEW', 'Opened patient registry list'), [
            'outcome' => 'SUCCESS',
            'search' => $search !== '' ? $search : '(none)',
            'filter_gender' => $gender !== '' ? $gender : 'All',
            'sort' => $sort,
            'results' => $totalPatients . ' patient(s)',
            'page' => $pagination['page'] . ' of ' . $pagination['total_pages'],
        ]);

        $page_title = 'Patient Registry';

        require_once __DIR__ . '/../../views/ophthalmologist/patient.php';
    }

    public function data()
    {
        RoleCheck::checkClinicDoctor();

        $model = new Patient($this->db);

        $search = $_POST['search'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $sort   = $_POST['sort'] ?? 'latest';

        $page = max(1, (int)($_POST['page'] ?? 1));
        $result = $model->getFilteredPaginated($search, $gender, $sort, $page);

        $this->auditLog(AuditHelper::action('PATIENT.LIST_FILTER', 'Applied filters on patient registry (AJAX)'), [
            'outcome' => 'SUCCESS',
            'search' => $search !== '' ? $search : '(none)',
            'filter_gender' => $gender !== '' ? $gender : 'All',
            'sort' => $sort,
            'results' => (int)($result['pagination']['total'] ?? 0) . ' patient(s)',
            'page' => (int)($result['pagination']['page'] ?? 1),
        ]);

        return $this->json($result);
    }

    public function get()
    {
        RoleCheck::checkClinicDoctor();

        $id = (int)($_GET['id'] ?? 0);
        $model = new Patient($this->db);
        $patient = $model->findById($id);

        if (!$patient) {
            return $this->json(['success' => false, 'message' => 'Patient not found'], 404);
        }

        $patient = $this->normalizePatientRow($patient);

        $this->auditLog(AuditHelper::action('PATIENT.RECORD_VIEW', 'Viewed patient demographic record'), array_merge(
            AuditHelper::patientContextFromRow($patient),
            ['outcome' => 'SUCCESS']
        ));

        return $this->json(['success' => true, 'patient' => $patient]);
    }

    public function update()
    {
        RoleCheck::checkClinicDoctor();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $age = (int)($_POST['age'] ?? 0);
        $gender = trim($_POST['gender'] ?? '');

        $gender = $this->normalizePatientGender($gender);

        if ($id <= 0 || $name === '' || $age < 0 || $age > 120 || !in_array($gender, ['Male', 'Female'], true)) {
            return $this->json(['success' => false, 'message' => 'Invalid patient data. Check name, age (0 to 120), and gender.']);
        }

        $model = new Patient($this->db);
        $existing = $model->findById($id);
        if (!$existing) {
            return $this->json(['success' => false, 'message' => 'Patient not found'], 404);
        }

        $ok = $model->update($id, [
            'name' => $name,
            'age' => $age,
            'gender' => $gender,
        ]);

        if ($ok) {
            $changes = [];
            if (($existing['name'] ?? '') !== $name) {
                $changes[] = 'name';
            }
            if ((int)($existing['age'] ?? 0) !== $age) {
                $changes[] = 'age';
            }
            if (($existing['gender'] ?? '') !== $gender) {
                $changes[] = 'gender';
            }
            $this->auditLog(AuditHelper::action('PATIENT.RECORD_UPDATE', 'Updated patient demographic record'), array_merge(
                AuditHelper::patientContextFromRow(array_merge($existing, ['name' => $name, 'age' => $age, 'gender' => $gender])),
                [
                    'outcome' => 'SUCCESS',
                    'changes' => $changes !== [] ? implode(', ', $changes) : 'no field change',
                ]
            ));
            $updated = $model->findById($id);
            return $this->json([
                'success' => true,
                'patient' => $updated ? $this->normalizePatientRow($updated) : null,
            ]);
        }

        return $this->json(['success' => false, 'message' => 'Update failed. Database error.']);
    }

    private function normalizePatientGender(string $gender): string
    {
        $g = strtolower(trim($gender));
        if ($g === 'female' || $g === 'f') {
            return 'Female';
        }
        if ($g === 'male' || $g === 'm') {
            return 'Male';
        }
        return trim($gender);
    }

    private function normalizePatientRow(array $row): array
    {
        $row['ic'] = Patient::normalizeIc((string)($row['ic'] ?? ''));
        $row['gender'] = $this->normalizePatientGender((string)($row['gender'] ?? ''));
        if (!in_array($row['gender'], ['Male', 'Female'], true)) {
            $row['gender'] = 'Male';
        }
        $row['age'] = (int)($row['age'] ?? 0);

        return $row;
    }

    public function delete()
    {
        RoleCheck::checkClinicDoctor();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            return $this->json(['success' => false, 'message' => 'Missing patient ID']);
        }

        $model = new Patient($this->db);
        $existing = $model->findById($id);
        if (!$existing) {
            return $this->json(['success' => false, 'message' => 'Patient not found'], 404);
        }

        // Cascade: predictions + uploads removed, then patient row
        $result = $model->deleteWithPredictions($id);

        if (!empty($result['success'])) {
            $this->auditLog(AuditHelper::action('PATIENT.RECORD_DELETE', 'Permanently removed patient and all linked AI screenings'), array_merge(
                AuditHelper::patientContextFromRow($existing),
                [
                    'outcome' => 'SUCCESS',
                    'predictions_removed' => (int)($result['predictions_removed'] ?? 0) . ' screening(s)',
                    'reason' => 'Clinician-initiated cascade delete',
                ]
            ));

            return $this->json([
                'success' => true,
                'predictions_removed' => (int)($result['predictions_removed'] ?? 0),
                'message' => 'Patient and screening records removed.',
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => $result['message'] ?? 'Delete failed',
        ]);
    }
}
