<?php
/**
 * Remove user accounts and all related predictions, uploads, and audit history.
 *
 * Usage: php database/delete_users.php --ids=1,2,3 --yes
 */

require_once __DIR__ . '/../config/db.php';

$yes = in_array('--yes', $argv, true);
$idsArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--ids=')) {
        $idsArg = substr($arg, 6);
    }
}

$userIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$idsArg)))));
sort($userIds);

if ($userIds === []) {
    fwrite(STDERR, "Usage: php database/delete_users.php --ids=1,2,3 --yes\n");
    exit(1);
}

$db = require __DIR__ . '/../config/db.php';

$placeholders = implode(',', array_fill(0, count($userIds), '?'));

$users = $db->prepare("SELECT id, name, email, role FROM users WHERE id IN ($placeholders) ORDER BY id");
$users->execute($userIds);
$userRows = $users->fetchAll(PDO::FETCH_ASSOC);

if (!$userRows) {
    echo "No matching users found.\n";
    exit(0);
}

$predStmt = $db->prepare("SELECT id, image_path, doctor_id FROM predictions WHERE doctor_id IN ($placeholders)");
$predStmt->execute($userIds);
$predictions = $predStmt->fetchAll(PDO::FETCH_ASSOC);

$auditCountStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id IN ($placeholders)");
$auditCountStmt->execute($userIds);
$auditCount = (int)$auditCountStmt->fetchColumn();

echo "Users to delete:\n";
foreach ($userRows as $row) {
    echo "  - #{$row['id']} {$row['name']} ({$row['email']}) [{$row['role']}]\n";
}
echo "\nRelated data:\n";
echo '  - Predictions: ' . count($predictions) . "\n";
echo "  - Audit log rows: {$auditCount}\n";

if (!$yes) {
    echo "\nDry run only. Re-run with --yes to apply.\n";
    exit(0);
}

$uploadBase = realpath(__DIR__ . '/../upload') . DIRECTORY_SEPARATOR;
$filesRemoved = 0;

$db->beginTransaction();

try {
    foreach ($predictions as $prediction) {
        $imagePath = trim((string)($prediction['image_path'] ?? ''));
        if ($imagePath !== '') {
            $full = $uploadBase . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $imagePath);
            if (is_file($full) && @unlink($full)) {
                $filesRemoved++;
            }
        }
    }

    $delPred = $db->prepare("DELETE FROM predictions WHERE doctor_id IN ($placeholders)");
    $delPred->execute($userIds);
    $predictionsRemoved = $delPred->rowCount();

    $delAudit = $db->prepare("DELETE FROM audit_logs WHERE user_id IN ($placeholders)");
    $delAudit->execute($userIds);
    $auditRemoved = $delAudit->rowCount();

    $delUsers = $db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
    $delUsers->execute($userIds);
    $usersRemoved = $delUsers->rowCount();

    $db->commit();

    echo "\nDeleted:\n";
    echo "  - Users: {$usersRemoved}\n";
    echo "  - Predictions: {$predictionsRemoved}\n";
    echo "  - Audit logs: {$auditRemoved}\n";
    echo "  - Upload files: {$filesRemoved}\n";

    $remaining = $db->query('SELECT id, name, email, role FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    echo "\nRemaining users:\n";
    foreach ($remaining as $row) {
        echo "  - #{$row['id']} {$row['name']} ({$row['email']}) [{$row['role']}]\n";
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Rollback: ' . $e->getMessage() . "\n");
    exit(1);
}
