<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
/** @var PDO $db */
$db = require dirname(__DIR__, 2) . '/config/db.php';

$uploadCount = count(glob(dirname(__DIR__, 2) . '/upload/predictions/*') ?: []);
echo "Upload files: {$uploadCount}\n";

$r = $db->query('SELECT MIN(created_at) AS mn, MAX(created_at) AS mx FROM predictions');
echo 'Date range: ' . json_encode($r->fetch(PDO::FETCH_ASSOC)) . "\n";

$r = $db->query('SELECT MIN(c) AS min_c, MAX(c) AS max_c, AVG(c) AS avg_c FROM (SELECT doctor_id, COUNT(*) c FROM predictions GROUP BY doctor_id) t');
echo 'Doctor screening load: ' . json_encode($r->fetch(PDO::FETCH_ASSOC)) . "\n";

$sql = <<<'SQL'
SELECT CASE
    WHEN p.age >= 60 THEN '60+'
    WHEN p.age >= 45 THEN '45-59'
    WHEN p.age >= 30 THEN '30-44'
    ELSE 'under30'
END AS band,
SUM(pr.final_result = 'Normal') AS normal_c,
COUNT(*) AS total
FROM predictions pr
JOIN patients p ON p.id = pr.patient_id
GROUP BY band
ORDER BY band
SQL;
$r = $db->query($sql);
echo "Age vs Normal rate:\n";
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $pct = round((float)$row['normal_c'] / (float)$row['total'] * 100, 1);
    echo "  {$row['band']}: {$row['total']} screenings, Normal {$pct}%\n";
}

$r = $db->query('SELECT COUNT(*) FROM (SELECT patient_id FROM predictions GROUP BY patient_id HAVING COUNT(*) = 2) t');
echo 'Patients with 2 screenings: ' . $r->fetchColumn() . "\n";
$r = $db->query('SELECT COUNT(*) FROM (SELECT patient_id FROM predictions GROUP BY patient_id HAVING COUNT(*) = 3) t');
echo 'Patients with 3 screenings: ' . $r->fetchColumn() . "\n";
