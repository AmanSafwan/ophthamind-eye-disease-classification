<?php
/**
 * Fix duplicate patient names in the full registry (max 3 per exact name).
 * Preserves original demo patients from data/original_patients.php
 *
 * Usage: php database/fix_duplicate_patient_names.php --yes
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from command line only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once __DIR__ . '/MalaysianNameGenerator.php';

/** @var PDO $db */
$db = require dirname(__DIR__) . '/config/db.php';

$skipConfirm = in_array('--yes', $argv ?? [], true);
$originals = require __DIR__ . '/data/original_patients.php';
$preserveIc = [];
$preserveNames = [];
foreach ($originals as $row) {
    $ic = preg_replace('/\D/', '', $row['ic']);
    $preserveIc[$ic] = strtoupper(trim($row['name']));
}

if (!$skipConfirm) {
    $total = (int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    echo "This will rename patients so no name appears more than "
        . MalaysianNameGenerator::MAX_DUPLICATES . " times.\n";
    echo "Original demo ICs (" . count($preserveIc) . ") keep their names.\n";
    echo "Patients to process: about " . ($total - count($preserveIc)) . " of {$total}.\n";
    echo "Type YES to continue: ";
    if (strtoupper(trim((string)fgets(STDIN))) !== 'YES') {
        echo "Aborted.\n";
        exit(0);
    }
}

$rows = $db->query('SELECT id, ic, gender FROM patients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$generator = new MalaysianNameGenerator();

foreach ($preserveIc as $name) {
    $generator->reservePublic($name);
}

$update = $db->prepare('UPDATE patients SET name = ? WHERE id = ?');

$updated = 0;
$preserved = 0;
$batch = 0;

$db->beginTransaction();
try {
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $ic = preg_replace('/\D/', '', (string)$row['ic']);
        $male = strtolower((string)$row['gender']) === 'male'
            || ((int)substr($ic, -1) % 2 === 1);

        if (isset($preserveIc[$ic])) {
            $update->execute([$preserveIc[$ic], $id]);
            $preserved++;
            continue;
        }

        $cohort = MalaysianNameGenerator::inferCohortForFix($id, $ic);
        $name = $generator->assign($cohort, $male, $id);
        $update->execute([$name, $id]);
        $updated++;

        $batch++;
        if ($batch % 2000 === 0) {
            echo "  … {$batch} / " . count($rows) . "\n";
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$worst = $db->query('
    SELECT name, COUNT(*) c FROM patients GROUP BY name ORDER BY c DESC LIMIT 5
')->fetchAll(PDO::FETCH_ASSOC);

echo "Done.\n";
echo "  Preserved original demo names: {$preserved}\n";
echo "  Renamed: {$updated}\n";
echo "  Generator max duplicate count: " . $generator->maxUsageCount() . "\n";
echo "  Top names in DB now:\n";
foreach ($worst as $w) {
    echo "    {$w['c']} × {$w['name']}\n";
}

$over = (int)$db->query('
    SELECT COUNT(*) FROM (
        SELECT name FROM patients GROUP BY name HAVING COUNT(*) > ' . MalaysianNameGenerator::MAX_DUPLICATES . '
    ) t
')->fetchColumn();
$withDigits = (int)$db->query("SELECT COUNT(*) FROM patients WHERE name REGEXP '[0-9]'")->fetchColumn();
$mixed = (int)$db->query("
    SELECT COUNT(*) FROM patients WHERE
        (name REGEXP 'BIN|BINTI' AND name REGEXP 'A/L|A/P')
        OR (name REGEXP 'BIN|BINTI' AND name REGEXP 'ANAK|@')
        OR (name REGEXP 'A/L|A/P' AND name REGEXP 'ANAK|@')
")->fetchColumn();
echo $over === 0
    ? "  OK: no name exceeds " . MalaysianNameGenerator::MAX_DUPLICATES . " patients.\n"
    : "  WARNING: {$over} names still exceed limit.\n";
echo $withDigits === 0
    ? "  OK: no digits in any patient name.\n"
    : "  WARNING: {$withDigits} names still contain digits.\n";
echo $mixed === 0
    ? "  OK: no mixed ethnic naming conventions.\n"
    : "  WARNING: {$mixed} names still mix BIN/A/L/ANAK styles.\n";
