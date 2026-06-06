<?php
/**
 * Restore original FYP demo patients (keeps existing bulk registry).
 *
 * Usage: php database/restore_original_patients.php --yes
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from command line only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/app/models/Patient.php';

/** @var PDO $db */
$db = require dirname(__DIR__) . '/config/db.php';

$skipConfirm = in_array('--yes', $argv ?? [], true);
$originals = require __DIR__ . '/data/original_patients.php';

if (!$skipConfirm) {
    echo 'This will insert or update ' . count($originals) . " original demo patients (ICs from your first registry).\n";
    echo "Existing patients with other ICs are not removed.\n";
    echo "Type YES to continue: ";
    if (strtoupper(trim((string)fgets(STDIN))) !== 'YES') {
        echo "Aborted.\n";
        exit(0);
    }
}

function extractFromIC(string $ic): array
{
    $yearPrefix = (int)substr($ic, 0, 2);
    $currentYear = (int)date('y');
    $birthYear = ($yearPrefix > $currentYear) ? 1900 + $yearPrefix : 2000 + $yearPrefix;
    $age = (int)date('Y') - $birthYear;
    $lastDigit = (int)substr($ic, -1);
    $gender = ($lastDigit % 2 === 1) ? 'Male' : 'Female';

    return ['age' => max(0, $age), 'gender' => $gender];
}

$insert = $db->prepare('
    INSERT INTO patients (ic, name, age, gender, created_at)
    VALUES (?, ?, ?, ?, ?)
');
$update = $db->prepare('
    UPDATE patients SET name = ?, age = ?, gender = ?, created_at = ? WHERE ic = ?
');

$inserted = 0;
$updated = 0;

$db->beginTransaction();
try {
    foreach ($originals as $row) {
        $ic = preg_replace('/\D/', '', $row['ic']);
        if (strlen($ic) !== 12) {
            continue;
        }
        $info = extractFromIC($ic);
        $name = strtoupper(trim($row['name']));
        $createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');

        $patientModel = new Patient($db);
        $existing = $patientModel->findByICNormalized($ic);
        if ($existing) {
            $updateId = $db->prepare('
                UPDATE patients SET ic = ?, name = ?, age = ?, gender = ?, created_at = ? WHERE id = ?
            ');
            $updateId->execute([$ic, $name, $info['age'], $info['gender'], $createdAt, (int)$existing['id']]);
            $updated++;
        } else {
            $insert->execute([$ic, $name, $info['age'], $info['gender'], $createdAt]);
            $inserted++;
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$total = (int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$irfan = $db->prepare('SELECT ic, name, age, gender FROM patients WHERE ic = ?');
$irfan->execute(['030217030161']);
$you = $irfan->fetch(PDO::FETCH_ASSOC);

echo "Done.\n";
echo "  Inserted: {$inserted}\n";
echo "  Updated (IC already existed): {$updated}\n";
echo "  Registry total: {$total}\n";
if ($you) {
    echo "  Your demo patient: {$you['ic']} · {$you['name']} · {$you['gender']} age {$you['age']}\n";
}
