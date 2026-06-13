<?php
require_once dirname(__DIR__, 2) . '/config/app.php';
$db = require dirname(__DIR__, 2) . '/config/db.php';
foreach (['patients', 'users', 'predictions'] as $t) {
    echo $t . ': ' . $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . PHP_EOL;
}
$r = $db->query("SELECT id, email, role FROM users WHERE email LIKE '%@clinic.my'");
while ($x = $r->fetch()) {
    echo json_encode($x) . PHP_EOL;
}
