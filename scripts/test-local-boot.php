<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/eye_system/index.php';
$_SERVER['HTTPS'] = 'off';

chdir(dirname(__DIR__));
require dirname(__DIR__) . '/config/app.php';

echo 'BASE_URL=' . BASE_URL . PHP_EOL;
echo 'DB_NAME=' . env('DB_NAME') . PHP_EOL;
echo 'ENABLE_AI=' . (env_bool('ENABLE_AI') ? 'true' : 'false') . PHP_EOL;
echo 'hosting_setup_pending=' . (hosting_setup_pending() ? 'yes' : 'no') . PHP_EOL;

require dirname(__DIR__) . '/config/db.php';
echo "DB connection OK\n";
