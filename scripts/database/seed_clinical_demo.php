<?php
/**
 * CLI: Seed 100 ophthalmologists + realistic screening history for every patient.
 *
 * Usage:
 *   php scripts/database/seed_clinical_demo.php --yes
 *   php scripts/database/seed_clinical_demo.php --yes --sample=500   (test subset)
 *
 * Requires: venv Python with tensorflow, pymysql, bcrypt (pip install pymysql bcrypt)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from command line only.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$python = $root . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
$script = __DIR__ . DIRECTORY_SEPARATOR . 'seed_clinical_screenings.py';

if (!is_file($python)) {
    fwrite(STDERR, "Python venv not found at {$python}\n");
    exit(1);
}

if (!is_file($script)) {
    fwrite(STDERR, "Seed script not found at {$script}\n");
    exit(1);
}

$argvList = $argv ?? [];
$skipConfirm = in_array('--yes', $argvList, true);
$extraArgs = [];
foreach ($argvList as $arg) {
    if ($arg === '--yes') {
        continue;
    }
    if (str_contains($arg, 'seed_clinical_demo.php') || str_contains($arg, 'seed_clinical_screenings.py')) {
        continue;
    }
    $extraArgs[] = $arg;
}

if (!$skipConfirm) {
    echo "This will:\n";
    echo "  - Create 99 ophthalmologist accounts (@clinic.my) if missing\n";
    echo "  - DELETE all existing predictions and prediction images\n";
    echo "  - Run real AI on dataset images and seed 2-3 screenings per patient\n";
    echo "Type YES to continue: ";
    $answer = trim((string)fgets(STDIN));
    if (strtoupper($answer) !== 'YES') {
        echo "Aborted.\n";
        exit(0);
    }
}

$cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --yes';
foreach ($extraArgs as $arg) {
    $cmd .= ' ' . escapeshellarg($arg);
}

echo "Running: {$cmd}\n\n";
passthru($cmd, $exitCode);
exit($exitCode);
