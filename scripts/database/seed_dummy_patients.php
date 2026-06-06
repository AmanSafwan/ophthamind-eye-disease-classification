<?php
/**
 * CLI: Seed Malaysian ophthalmology patient registry.
 *
 * Usage:
 *   php database/seed_dummy_patients.php --yes                    (reset + 1500)
 *   php database/seed_dummy_patients.php --add=499 --yes          (append exact count)
 *   php database/seed_dummy_patients.php --add-random --yes       (append 1000–5000 random)
 *   php database/seed_dummy_patients.php --add-random=2000,8000 --yes
 *   php database/restore_original_patients.php --yes   (FYP demo patients e.g. Irfan Adli)
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

const SEED_TOTAL = 1500;
const RANDOM_ADD_MIN = 1000;
const RANDOM_ADD_MAX = 5000;

$argvList = $argv ?? [];
$skipConfirm = in_array('--yes', $argvList, true);
$addCount = 0;
$addRandom = false;
$randomMin = RANDOM_ADD_MIN;
$randomMax = RANDOM_ADD_MAX;

foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--add=')) {
        $addCount = max(0, (int)substr($arg, 6));
    }
    if ($arg === '--add-random' || str_starts_with($arg, '--add-random=')) {
        $addRandom = true;
        if (str_starts_with($arg, '--add-random=')) {
            $range = substr($arg, 13);
            $parts = array_map('intval', explode(',', $range, 2));
            if (count($parts) === 2 && $parts[0] > 0 && $parts[1] > 0) {
                $randomMin = max(1000, min($parts[0], $parts[1]));
                $randomMax = max($randomMin, max($parts[0], $parts[1]));
            }
        }
    }
}

if ($addRandom) {
    $addCount = random_int($randomMin, $randomMax);
    echo "Random batch selected: {$addCount} patients (range {$randomMin}–{$randomMax}).\n";
}

$appendMode = $addCount > 0;
$insertCount = $appendMode ? $addCount : SEED_TOTAL;

if (!$skipConfirm) {
    if ($appendMode) {
        $existing = (int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
        echo "This will ADD {$addCount} patients (current registry: {$existing}). Existing records stay.\n";
    } else {
        echo 'This will DELETE all patients and predictions, then insert ' . SEED_TOTAL . " realistic dummy patients.\n";
    }
    echo "Type YES to continue: ";
    $answer = trim((string)fgets(STDIN));
    if (strtoupper($answer) !== 'YES') {
        echo "Aborted.\n";
        exit(0);
    }
}

/** Matches PredictController::extractFromIC() */
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

/** Ganjil (1,3,5,7,9) male · Genap (0,2,4,6,8) female */
function genderDigitFromIcRule(bool $male, int $seed): int
{
    $odd = [1, 3, 5, 7, 9];
    $even = [0, 2, 4, 6, 8];
    $pool = $male ? $odd : $even;

    return $pool[abs($seed) % count($pool)];
}

function buildMalaysianIc(DateTime $dob, string $placeCode, int $serial, bool $male, int $uniquenessSeed): string
{
    $yy = $dob->format('y');
    $mm = $dob->format('m');
    $dd = $dob->format('d');
    $pb = str_pad($placeCode, 2, '0', STR_PAD_LEFT);
    $serial = max(0, min(999, $serial));
    $sss = str_pad((string)$serial, 3, '0', STR_PAD_LEFT);
    $g = genderDigitFromIcRule($male, $uniquenessSeed);

    return $yy . $mm . $dd . $pb . $sss . (string)$g;
}

function randomDobBetween(int $minAge, int $maxAge): DateTime
{
    $today = new DateTime('today');
    $maxBirth = (clone $today)->modify('-' . $minAge . ' years');
    $minBirth = (clone $today)->modify('-' . ($maxAge + 1) . ' years')->modify('+1 day');

    return (new DateTime())->setTimestamp(random_int($minBirth->getTimestamp(), $maxBirth->getTimestamp()));
}

/** Eye-clinic weighted ages: mostly older adults */
function randomDobClinical(): DateTime
{
    $roll = random_int(1, 100);
    if ($roll <= 50) {
        return randomDobBetween(60, 89);
    }
    if ($roll <= 72) {
        return randomDobBetween(48, 59);
    }
    if ($roll <= 84) {
        return randomDobBetween(38, 47);
    }
    if ($roll <= 93) {
        return randomDobBetween(25, 37);
    }
    if ($roll <= 98) {
        return randomDobBetween(14, 24);
    }

    return randomDobBetween(1, 13);
}

function randomGender(): bool
{
    return random_int(1, 100) <= 48;
}

class MalaysianPatientGenerator
{
    /** @var array<string, int> */
    private array $quotas = [
        'malay_old' => 400,
        'malay_modern' => 150,
        'malay_genz' => 150,
        'chinese' => 220,
        'indian' => 200,
        'sabah' => 190,
        'sarawak' => 190,
    ];

    private array $malayOldMale = [
        'MAHADI', 'SAMSUDIN', 'SHAMSUDIN', 'MUSA', 'HARUN', 'ZAINAL', 'GHANI', 'YUSOF', 'OMAR', 'RASHID',
        'KAMARUDIN', 'ISMAIL', 'HASSAN', 'ABDULLAH', 'RAHMAN', 'SULAIMAN', 'IDRIS', 'HAMZAH', 'BAKRI', 'DAUD',
    ];
    private array $malayOldFemale = [
        'ROSNAH', 'SALMAH', 'ROKIAH', 'ZAINAB', 'AMINAH', 'KALSOM', 'FATIMAH', 'MARIAM', 'SAIMAH', 'NORMAH',
        'PAUZIAH', 'HALIMAH', 'RUBIAH', 'ASIAH', 'SARIPAH', 'CHE MINAH', 'WAN NIK', 'WAN LAH', 'MAIMAH', 'SITI AMINAH',
    ];
    private array $malayOldFather = [
        'MAT', 'SENIK', 'LAH', 'DAH', 'SARIP', 'TAMBI', 'HASSIM', 'MOKHTAR', 'YATIM', 'SULAIMAN',
        'ABDULLAH', 'MOHAMAD', 'AHMAD', 'ISMAIL', 'HASSAN', 'OMAR', 'YUSOF', 'ALI', 'SAID', 'KASSIM',
    ];

    private array $malayModernMale = [
        'FAIZAL', 'ZULKIFLI', 'AZMAN', 'KHAIRUL', 'RIZAL', 'HAFIZ', 'SYAHIR', 'FARID', 'AZRUL', 'SHUKRI',
    ];
    private array $malayModernFemale = [
        'NORAINI', 'ZARINA', 'ROHANI', 'MASITAH', 'SURIANI', 'HASLINA', 'MARINA', 'AZLINA', 'RUSILAWATI', 'NORHAYATI',
    ];
    private array $malayModernFather = [
        'ISMAIL', 'HASSAN', 'RAHMAN', 'YUSOF', 'IBRAHIM', 'KAMAL', 'RASHID', 'ZAINUDDIN', 'MAJID', 'SAMAD',
    ];

    private array $malayGenzMale = [
        'DANISH', 'AMIR', 'AQIL', 'IKMAL', 'HAZIQ', 'IRFAN', 'SYAFIQ', 'ARIF', 'IMAN', 'HAKIM', 'ADLI', 'FIQRI',
    ];
    private array $malayGenzFemale = [
        'SOFEA', 'ALYA', 'QISYA', 'NURIN', 'ARINA', 'DAMIA', 'ADIBA', 'BATRISYA', 'ILYANA', 'SYAZANA', 'NAJLA', 'AISY',
    ];
    private array $malayGenzFather = [
        'ZAMRI', 'FAIZ', 'HAKIM', 'RIZAL', 'AZMAN', 'KHAIRUL', 'SHUKRI', 'HAFIZ', 'SYAHIR', 'AMIR',
    ];

    private array $chineseSurname = [
        'TAN', 'LIM', 'LEE', 'WONG', 'NG', 'CHONG', 'GOH', 'TEH', 'CHEW', 'YAP', 'LAU', 'KHOO', 'FOO', 'ONG', 'CHUA',
        'TEO', 'LOW', 'SIM', 'KOH', 'CHAN',
    ];
    private array $chineseMaleGiven = [
        'WEI MING', 'JUN HAO', 'KOK WAI', 'ZHI YANG', 'CHUN KIT', 'YONG KANG', 'JIA WEI', 'ZHEN HAO', 'KAI XUAN', 'JIN HAO',
        'AH CHAI', 'KIM SENG', 'HOCK LYE', 'SOON HOCK', 'CHIN HOE', 'AH KOW', 'BOON HENG', 'CHEE KEONG', 'GUAN HENG', 'WENG FATT',
    ];
    private array $chineseFemaleGiven = [
        'MEI LING', 'SU CHIN', 'PEI YING', 'XIN YI', 'JIA HUI', 'YI XUAN', 'LI LING', 'SIEW MEI', 'POH LING', 'AI LING',
        'CHUI PENG', 'SIEW LAN', 'MEI FONG', 'AI CHOO', 'SOOK YIN', 'PEI CHI', 'HUI MIN', 'YAN NI', 'QI HUA', 'XUE TING',
    ];

    private array $indianMale = [
        'RAJESH', 'SURESH', 'MURUGAN', 'ARVIND', 'PRAKASH', 'VIJAY', 'GANESH', 'THANESH', 'DEEPAK', 'KARTHICK',
        'RAMESH', 'SELVAM', 'LOGANATHAN', 'MUNIANDY', 'SUBRAMANIAM', 'KRISHNAN', 'GOVIND', 'SHANKAR', 'BALAKRISHNAN', 'NATARAJAN',
    ];
    private array $indianFemale = [
        'PRIYA', 'LAKSHMI', 'KAVITHA', 'MEENA', 'SHANTI', 'DEVI', 'ANITHA', 'SUMATHI', 'MALAR', 'REVATHI',
        'VASANTHI', 'JAYANTHI', 'KAMALA', 'SAROJA', 'INDIRA', 'VIJAYA', 'GEETHA', 'MALATHI', 'SUMATHI', 'RANI',
    ];
    private array $indianFather = [
        'KUMAR', 'SUBRAMANIAM', 'RAMASAMY', 'KRISHNAN', 'MUNIANDY', 'PERUMAL', 'SELVAM', 'GOVINDASAMY', 'NADARAJAN', 'PILLAI',
    ];

    private array $sabahKadazanMale = ['JOHNNY', 'RICHARD', 'JEFFREY', 'PETER', 'MICHAEL', 'ANDY', 'RONNY', 'BENSON'];
    private array $sabahKadazanFemale = ['MARLINA', 'JENNY', 'ROSELINE', 'CHRISTINA', 'LINDA', 'GRACE', 'ELIZABETH'];
    private array $sabahDusunFather = ['GILING', 'JUNING', 'MADING', 'SIPANG', 'KADING', 'LIMBANG', 'SAGU', 'MOKUAT'];
    private array $sabahBajauMale = ['MUHAMMAD', 'ABDUL', 'MOHD', 'ISMAIL', 'HASSAN'];
    private array $sabahBajauFemale = ['SITI', 'NUR', 'DAYANG', 'ROHANI', 'HALIMAH'];

    private array $sarawakIbanMale = ['ANDREW', 'JONATHAN', 'MICHAEL', 'PATRICK', 'CHRISTOPHER', 'DAVID', 'PAUL', 'KEVIN'];
    private array $sarawakIbanFemale = ['MARY', 'CATHERINE', 'RUTH', 'HANNAH', 'SARAH', 'RACHEL', 'DEBORAH', 'JOYCE'];
    private array $sarawakIbanFather = ['JAMIN', 'BADAU', 'SAGA', 'BAYANG', 'MUNAN', 'SULAU', 'ENTALAI', 'BALAN'];
    private array $sarawakMelanauMale = ['ABANG', 'AWANG', 'MAWAN', 'BENJAMIN', 'SAMUEL'];
    private array $sarawakMelanauFemale = ['DAYANG', 'ROSE', 'AGNES', 'CECILIA', 'MONICA'];

    private array $peninsularCodes = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '14', '16'];
    private array $chinesePlaceCodes = ['14', '10', '07', '08', '05', '04', '06'];
    private array $indianPlaceCodes = ['10', '07', '14', '08', '04', '05', '09'];

    /** @return array<string, int> */
    private function quotasForTarget(int $target): array
    {
        $baseSum = array_sum($this->quotas);
        $scaled = [];
        $assigned = 0;
        $keys = array_keys($this->quotas);
        foreach ($keys as $n => $cohort) {
            if ($n === count($keys) - 1) {
                $scaled[$cohort] = max(0, $target - $assigned);
                break;
            }
            $q = (int)round($this->quotas[$cohort] * $target / $baseSum);
            $scaled[$cohort] = $q;
            $assigned += $q;
        }

        return $scaled;
    }

    /**
     * @param array<string, true> $usedIc
     */
    public function generateCount(int $target, array $usedIc = [], int $indexOffset = 0): array
    {
        $rows = [];
        $quotas = $this->quotasForTarget($target);
        $index = $indexOffset;
        $salt = $indexOffset + (int)(microtime(true) * 1000) % 10000;
        $nameGen = new MalaysianNameGenerator();

        foreach ($quotas as $cohort => $count) {
            for ($i = 0; $i < $count; $i++) {
                $male = randomGender();
                $dob = $this->dobForCohort($cohort);
                $place = $this->placeForCohort($cohort, $i + $salt);
                $serial = ($index * 19 + $i * 7 + $salt + random_int(1, 999)) % 1000;

                $ic = null;
                for ($attempt = 0; $attempt < 120; $attempt++) {
                    $tryDob = $attempt > 40 ? randomDobClinical() : $dob;
                    $tryPlace = $attempt > 20
                        ? $this->placeForCohort($cohort, $i + $salt + $attempt)
                        : $place;
                    $candidate = buildMalaysianIc(
                        $tryDob,
                        $tryPlace,
                        ($serial + $attempt * 13) % 1000,
                        $male,
                        $index + $attempt * 17 + $salt
                    );
                    if (!isset($usedIc[$candidate])) {
                        $ic = $candidate;
                        $usedIc[$candidate] = true;
                        break;
                    }
                }
                if ($ic === null) {
                    continue;
                }

                $info = extractFromIC($ic);
                $name = $nameGen->assign($cohort, $male, $index);

                $age = (int)$info['age'];
                $registeredDaysAgo = $this->registrationLagDays($age);

                $rows[] = [
                    'ic' => $ic,
                    'name' => $name,
                    'age' => $age,
                    'gender' => $info['gender'],
                    'cohort' => $cohort,
                    'created_at' => (new DateTime())->modify('-' . $registeredDaysAgo . ' days')->format('Y-m-d H:i:s'),
                ];
                $index++;
            }
        }

        return $rows;
    }

    public function generateAll(): array
    {
        return $this->generateCount(SEED_TOTAL, [], 0);
    }

    private function dobForCohort(string $cohort): DateTime
    {
        if ($cohort === 'malay_genz') {
            $roll = random_int(1, 100);
            if ($roll <= 70) {
                return randomDobBetween(18, 28);
            }
            return randomDobBetween(12, 21);
        }
        if ($cohort === 'malay_old') {
            $roll = random_int(1, 100);
            if ($roll <= 75) {
                return randomDobBetween(55, 88);
            }
            return randomDobBetween(45, 54);
        }

        return randomDobClinical();
    }

    private function placeForCohort(string $cohort, int $i): string
    {
        switch ($cohort) {
            case 'malay_old':
            case 'malay_modern':
            case 'malay_genz':
                return $this->peninsularCodes[$i % count($this->peninsularCodes)];
            case 'chinese':
                return $this->chinesePlaceCodes[$i % count($this->chinesePlaceCodes)];
            case 'indian':
                return $this->indianPlaceCodes[$i % count($this->indianPlaceCodes)];
            case 'sabah':
                return '12';
            case 'sarawak':
                return '13';
            default:
                return '14';
        }
    }

    private function registrationLagDays(int $age): int
    {
        if ($age >= 65) {
            return random_int(180, 2200);
        }
        if ($age >= 45) {
            return random_int(60, 1600);
        }

        return random_int(7, 900);
    }

    private function pick(array $list, int $i, int $salt = 0): string
    {
        return $list[($i + $salt) % count($list)];
    }

    public function nameForCohort(string $cohort, bool $male, int $i, int $age): string
    {
        switch ($cohort) {
            case 'malay_old':
                $given = $male
                    ? $this->pick($this->malayOldMale, $i)
                    : $this->pick($this->malayOldFemale, $i, 2);
                $father = $this->pick($this->malayOldFather, $i, 5);
                return $given . ($male ? ' BIN ' : ' BINTI ') . $father;

            case 'malay_modern':
                $given = $male
                    ? $this->pick($this->malayModernMale, $i)
                    : $this->pick($this->malayModernFemale, $i, 1);
                $father = $this->pick($this->malayModernFather, $i, 3);
                return $given . ($male ? ' BIN ' : ' BINTI ') . $father;

            case 'malay_genz':
                $given = $male
                    ? $this->pick($this->malayGenzMale, $i)
                    : $this->pick($this->malayGenzFemale, $i, 4);
                $father = $this->pick($this->malayGenzFather, $i, 6);
                return $given . ($male ? ' BIN ' : ' BINTI ') . $father;

            case 'chinese':
                $surname = $this->pick($this->chineseSurname, $i);
                $given = $male
                    ? $this->pick($this->chineseMaleGiven, $i)
                    : $this->pick($this->chineseFemaleGiven, $i, 7);
                return $surname . ' ' . $given;

            case 'indian':
                $given = $male
                    ? $this->pick($this->indianMale, $i)
                    : $this->pick($this->indianFemale, $i, 2);
                $father = $this->pick($this->indianFather, $i, 4);
                return $given . ($male ? ' A/L ' : ' A/P ') . $father;

            case 'sabah':
                return $this->sabahName($male, $i);

            case 'sarawak':
                return $this->sarawakName($male, $i);

            default:
                return 'PATIENT UNKNOWN';
        }
    }

    private function sabahName(bool $male, int $i): string
    {
        $variant = $i % 5;
        if ($variant <= 2) {
            $given = $male
                ? $this->pick($this->sabahKadazanMale, $i)
                : $this->pick($this->sabahKadazanFemale, $i);
            $father = $this->pick($this->sabahDusunFather, $i, 3);
            if ($i % 2 === 0) {
                return $given . ' ANAK ' . $father;
            }
            return $given . ' @ ' . $father;
        }
        $given = $male
            ? $this->pick($this->sabahBajauMale, $i)
            : $this->pick($this->sabahBajauFemale, $i);
        $father = $this->pick($this->malayModernFather, $i, 8);
        return $given . ($male ? ' BIN ' : ' BINTI ') . $father;
    }

    private function sarawakName(bool $male, int $i): string
    {
        if ($i % 4 === 3) {
            $given = $male
                ? $this->pick($this->sarawakMelanauMale, $i)
                : $this->pick($this->sarawakMelanauFemale, $i);
            if ($male) {
                return $given . ' BIN ' . $this->pick($this->malayModernFather, $i, 2);
            }
            return $given . ' BINTI ' . $this->pick($this->malayModernFather, $i, 5);
        }
        $given = $male
            ? $this->pick($this->sarawakIbanMale, $i)
            : $this->pick($this->sarawakIbanFemale, $i);
        $father = $this->pick($this->sarawakIbanFather, $i, 1);
        return $given . ($male ? ' ANAK ' : ' BINTI ') . $father;
    }
}

function purgePatients(PDO $db): void
{
    $uploadBase = BASE_PATH . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR;
    $paths = $db->query('SELECT image_path FROM predictions WHERE image_path IS NOT NULL AND image_path != ""')
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($paths as $imagePath) {
        $full = $uploadBase . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$imagePath);
        if (is_file($full)) {
            @unlink($full);
        }
    }

    $db->exec('DELETE FROM predictions');
    $db->exec('DELETE FROM patients');
    $db->exec('ALTER TABLE patients AUTO_INCREMENT = 1');
}

/** @return array<string, true> */
function loadExistingIcMap(PDO $db): array
{
    $used = [];
    foreach ($db->query('SELECT ic FROM patients') as $row) {
        $used[(string)$row['ic']] = true;
    }

    return $used;
}

function insertPatients(PDO $db, array $rows): void
{
    $stmt = $db->prepare('INSERT INTO patients (ic, name, age, gender, created_at) VALUES (?, ?, ?, ?, ?)');
    $chunks = array_chunk($rows, 300);

    foreach ($chunks as $chunkIndex => $chunk) {
        $db->beginTransaction();
        try {
            foreach ($chunk as $row) {
                $stmt->execute([
                    $row['ic'],
                    $row['name'],
                    $row['age'],
                    $row['gender'],
                    $row['created_at'],
                ]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw new RuntimeException(
                'Insert failed at chunk ' . ($chunkIndex + 1) . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
        if (count($chunks) > 3 && ($chunkIndex + 1) % 5 === 0) {
            echo '  … inserted ' . min(($chunkIndex + 1) * 300, count($rows)) . ' / ' . count($rows) . "\n";
        }
    }
}

function printStats(PDO $db, array $rows): void
{
    $total = (int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    $male = (int)$db->query("SELECT COUNT(*) FROM patients WHERE gender = 'Male'")->fetchColumn();
    $female = (int)$db->query("SELECT COUNT(*) FROM patients WHERE gender = 'Female'")->fetchColumn();
    $minAge = (int)$db->query('SELECT MIN(age) FROM patients')->fetchColumn();
    $maxAge = (int)$db->query('SELECT MAX(age) FROM patients')->fetchColumn();
    $senior = (int)$db->query('SELECT COUNT(*) FROM patients WHERE age >= 55')->fetchColumn();
    $mid = (int)$db->query('SELECT COUNT(*) FROM patients WHERE age >= 40 AND age < 55')->fetchColumn();
    $young = (int)$db->query('SELECT COUNT(*) FROM patients WHERE age < 40')->fetchColumn();
    $chineseBin = (int)$db->query("SELECT COUNT(*) FROM patients WHERE name LIKE '% BIN %' AND name REGEXP '^(TAN|LIM|LEE|WONG|NG|CHONG|GOH|TEH|CHEW|YAP|LAU|KHOO|FOO|ONG|CHUA|TEO|LOW|SIM|KOH|CHAN) '")->fetchColumn();
    $icEnds01 = (int)$db->query("SELECT COUNT(*) FROM patients WHERE RIGHT(ic,1) IN ('0','1')")->fetchColumn();
    $chineseSample = $db->query("SELECT name FROM patients WHERE name REGEXP '^(TAN|LIM|LEE|WONG|NG|CHONG|GOH|TEH|CHEW|YAP) ' ORDER BY RAND() LIMIT 1")->fetchColumn();
    $dup = (int)$db->query('SELECT COUNT(*) FROM (SELECT ic FROM patients GROUP BY ic HAVING COUNT(*)>1) x')->fetchColumn();

    $lastDigits = $db->query('SELECT RIGHT(ic,1) d, COUNT(*) c FROM patients GROUP BY d ORDER BY d')->fetchAll();

    echo "Done.\n";
    echo "  Total patients: {$total}\n";
    echo "  Male: {$male}, Female: {$female}\n";
    echo "  Age range: {$minAge} – {$maxAge}\n";
    echo "  Age 55+: {$senior} (" . round($senior / max(1, $total) * 100, 1) . "%) · 40–54: {$mid} · under 40: {$young}\n";
    echo "  Chinese with BIN (should be 0): {$chineseBin}\n";
    echo "  IC ending 0 or 1 (expected ~20% of all digits): {$icEnds01}\n";
    echo "  Sample Chinese name: {$chineseSample}\n";
    echo "  Duplicate ICs: {$dup}\n";
    echo "  Last-digit distribution: ";
    foreach ($lastDigits as $ld) {
        echo $ld['d'] . '=' . $ld['c'] . ' ';
    }
    echo "\n";

    $samples = array_slice($rows, 0, 4);
    shuffle($samples);
    foreach (array_slice($samples, 0, 4) as $s) {
        $g = substr($s['ic'], -1);
        echo "  · {$s['ic']} (…{$g}) {$s['name']} · {$s['gender']} age {$s['age']}\n";
    }
}

$generator = new MalaysianPatientGenerator();

if ($appendMode) {
    $before = (int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    $usedIc = loadExistingIcMap($db);
    echo "Generating {$addCount} patients (current registry: {$before})...\n";
    $rows = $generator->generateCount($addCount, $usedIc, $before + random_int(10000, 99999));
} else {
    echo "Purging existing patients and predictions...\n";
    purgePatients($db);
    echo 'Generating ' . SEED_TOTAL . " realistic patients...\n";
    $rows = $generator->generateAll();
}

if (count($rows) < $insertCount) {
    echo 'Warning: generated ' . count($rows) . ' rows (target ' . $insertCount . ").\n";
}

echo 'Inserting ' . count($rows) . " records...\n";
insertPatients($db, $rows);

if ($appendMode) {
    $after = (int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    echo "Registry grew by " . ($after - ($before ?? 0)) . " to {$after} patients.\n";
}

printStats($db, $rows);
