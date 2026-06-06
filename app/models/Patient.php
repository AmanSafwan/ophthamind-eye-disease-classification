<?php

class Patient
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public static function normalizeIc(string $ic): string
    {
        return preg_replace('/\D/', '', trim($ic));
    }

    public function findByIC($ic)
    {
        return $this->findByICNormalized($ic);
    }

    /**
     * Match patient by digits-only IC (handles dashes/spaces in stored values).
     */
    public function findByICNormalized(string $ic): ?array
    {
        $norm = self::normalizeIc($ic);
        if (strlen($norm) !== 12) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM patients
            WHERE REPLACE(REPLACE(REPLACE(ic, '-', ''), ' ', ''), '.', '') = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$norm]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function create($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO patients (ic, name, age, gender, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $data['ic'],
            $data['name'],
            $data['age'],
            $data['gender'],
        ]);
    }

    /**
     * @return array{0: string, 1: array, 2: string}
     */
    private function buildFilterParts($search = '', $gender = '', $sort = 'latest'): array
    {
        $sql = ' WHERE 1=1';
        $params = [];

        if (!empty($search)) {
            $sql .= ' AND (name LIKE ? OR ic LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if (!empty($gender)) {
            $sql .= ' AND gender = ?';
            $params[] = $gender;
        }

        if ($sort === 'latest') {
            $order = ' ORDER BY created_at DESC';
        } elseif ($sort === 'oldest') {
            $order = ' ORDER BY created_at ASC';
        } else {
            $order = ' ORDER BY name ASC';
        }

        return [$sql, $params, $order];
    }

    public function getFiltered($search = '', $gender = '', $sort = 'latest')
    {
        [$where, $params, $order] = $this->buildFilterParts($search, $gender, $sort);
        $stmt = $this->db->prepare('SELECT * FROM patients' . $where . $order);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFilteredPaginated($search = '', $gender = '', $sort = 'latest', $page = 1, $perPage = 15): array
    {
        require_once BASE_PATH . '/app/helpers/PaginationHelper.php';

        [$where, $params, $order] = $this->buildFilterParts($search, $gender, $sort);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM patients' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $meta = PaginationHelper::resolve((int)$page, $total, (int)$perPage);
        $limit = (int)$meta['per_page'];
        $offset = (int)$meta['offset'];

        $stmt = $this->db->prepare(
            'SELECT * FROM patients' . $where . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'pagination' => $meta,
        ];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare('
            UPDATE patients SET name = ?, age = ?, gender = ? WHERE id = ?
        ');
        return $stmt->execute([
            $data['name'],
            $data['age'],
            $data['gender'],
            $id,
        ]);
    }

    public function countPredictions(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM predictions WHERE patient_id = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Summary counts for the patient registry dashboard (whole table).
     */
    public function getRegistryStats(): array
    {
        $row = $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) AS male,
                SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) AS female,
                SUM(CASE WHEN age >= 55 THEN 1 ELSE 0 END) AS seniors,
                SUM(CASE WHEN age >= 40 AND age < 55 THEN 1 ELSE 0 END) AS middle_age,
                SUM(CASE WHEN age < 40 THEN 1 ELSE 0 END) AS young,
                ROUND(AVG(age)) AS avg_age
            FROM patients
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $screened = (int)$this->db->query(
            'SELECT COUNT(DISTINCT patient_id) FROM predictions'
        )->fetchColumn();

        $new30 = (int)$this->db->query(
            "SELECT COUNT(*) FROM patients WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $total = (int)($row['total'] ?? 0);

        return [
            'total' => $total,
            'male' => (int)($row['male'] ?? 0),
            'female' => (int)($row['female'] ?? 0),
            'seniors' => (int)($row['seniors'] ?? 0),
            'middle_age' => (int)($row['middle_age'] ?? 0),
            'young' => (int)($row['young'] ?? 0),
            'avg_age' => (int)($row['avg_age'] ?? 0),
            'screened' => $screened,
            'not_screened' => max(0, $total - $screened),
            'new_30_days' => $new30,
        ];
    }

    /**
     * Delete patient and ALL related predictions + image files (cascade).
     */
    public function deleteWithPredictions(int $id): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('SELECT image_path FROM predictions WHERE patient_id = ?');
            $stmt->execute([$id]);
            $images = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $uploadBase = BASE_PATH . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR;
            foreach ($images as $imagePath) {
                if (!$imagePath) {
                    continue;
                }
                $full = $uploadBase . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $imagePath);
                if (is_file($full)) {
                    @unlink($full);
                }
            }

            $delPred = $this->db->prepare('DELETE FROM predictions WHERE patient_id = ?');
            $delPred->execute([$id]);
            $predictionsRemoved = $delPred->rowCount();

            $delPatient = $this->db->prepare('DELETE FROM patients WHERE id = ?');
            $delPatient->execute([$id]);

            if ($delPatient->rowCount() < 1) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Patient not found'];
            }

            $this->db->commit();

            return [
                'success' => true,
                'predictions_removed' => $predictionsRemoved,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('Cascade delete failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
        }
    }
}
