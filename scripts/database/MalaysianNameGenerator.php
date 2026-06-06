<?php

declare(strict_types=1);

/**
 * Realistic Malaysian patient names — one ethnicity per name, no mixed conventions.
 * References: JPN/MyKad practice, Cultural Atlas, common Malaysian naming patterns.
 */
class MalaysianNameGenerator
{
    public const MAX_DUPLICATES = 3;

    /** @var array<string, int> */
    private array $usage = [];

    /** Malay / Muslim peninsular — [optional prefix] + given + BIN/BINTI + father's given name only */
    private array $malayMaleGiven = [
        'AHMAD', 'FAIZAL', 'ZULKIFLI', 'KHAIRUL', 'HAFIZ', 'AMIRUL', 'HAZIQ', 'SYAFIQ', 'RIZAL', 'SHUKRI',
        'MAHADI', 'SAMSUDIN', 'MUSA', 'HARUN', 'ZAINAL', 'KAMARUDIN', 'SULAIMAN', 'HAMZAH', 'IDRIS', 'AZMAN',
        'DANISH', 'AMIR', 'IKMAL', 'ARIF', 'HAKIM', 'NAZRI', 'FARID', 'AZRUL', 'BAKRI', 'DAUD',
        'SHAMSUDIN', 'GHANI', 'KAMAL', 'RASHID', 'YUSRI', 'AZMI', 'RAFI', 'HILMI', 'FADZLI', 'ZAMRI',
        'ASHRAF', 'IZZUDIN', 'LUQMAN', 'HAFIZI', 'SYAHMI', 'AMRI', 'FIRDAUS', 'HAIRUL', 'JAMAL', 'KAMARUL',
    ];

    private array $malayFemaleGiven = [
        'SITI', 'NURUL', 'FATIMAH', 'AISYAH', 'ROSNAH', 'SALMAH', 'ROKIAH', 'ZAINAB', 'AMINAH', 'KALSOM',
        'NORAINI', 'ZARINA', 'ROHANI', 'MASITAH', 'HASLINA', 'AZLINA', 'SOFEA', 'NURIN', 'ARINA', 'ILYANA',
        'HALIMAH', 'MARIAM', 'SAIMAH', 'NORMAH', 'RUSILAWATI', 'NORHAYATI', 'MASARAH', 'SYAFIQAH', 'NURULHUDA', 'WAN MAISARAH',
        'NURAINI', 'SURIANI', 'ZURAINI', 'ROHAYU', 'MASITAH', 'SAADIAH', 'RAHIMAH', 'SALWANA', 'MAZNI', 'HASNITA',
        'NORAZLINA', 'SITI MARIAM', 'NUR AIN', 'SITI SARAH', 'WAN NUR', 'CHE MINAH', 'PAUZIAH', 'RUBIAH', 'ASIAH', 'MAIMAH',
    ];

    private array $malayFather = [
        'ISMAIL', 'HASSAN', 'ABDULLAH', 'RAHMAN', 'YUSOF', 'IBRAHIM', 'OMAR', 'ALI', 'SAID', 'AHMAD',
        'MAT', 'SENIK', 'TAMBI', 'MOKHTAR', 'SULAIMAN', 'KASSIM', 'ZAINUDDIN', 'KAMAL', 'SAMAD', 'IDRIS',
        'HARUN', 'GHANI', 'OMAR', 'DAUD', 'YATIM', 'LAH', 'DAH', 'SARIP', 'HASSIM', 'BASIR',
        'TALIB', 'GANI', 'SALLEH', 'HAMID', 'NORDIN', 'SAARI', 'MAJID', 'RASHID', 'ZAKI', 'BAKAR',
    ];

    private array $malayMalePrefix = ['', '', '', 'MUHAMMAD', 'ABDUL', 'MOHD', 'WAN', 'CHE', 'ABU'];
    private array $malayFemalePrefix = ['', '', '', 'SITI', 'NUR', 'NOOR', 'WAN', 'CHE', 'DAYANG'];

    /** Chinese — surname + given only (no BIN/BINTI); pools sized for 7k+ registry */
    private array $chineseSurname = [
        'TAN', 'LIM', 'LEE', 'WONG', 'NG', 'CHONG', 'GOH', 'TEH', 'CHEW', 'YAP', 'LAU', 'KHOO', 'ONG', 'CHUA', 'TEO',
        'LOW', 'SIM', 'KOH', 'CHAN', 'HENG', 'YEAP', 'LIEW', 'TAY', 'CHAI', 'FOO', 'SOO', 'MAH', 'TOH', 'OOI', 'GAN',
        'BEH', 'HOR', 'KHOR', 'KHAW', 'KUEH', 'LEONG', 'LOH', 'MOK', 'SEAH', 'SIA', 'SIOW', 'THAM', 'YEOH', 'CHEONG', 'PHUA',
    ];

    private array $chineseMaleGiven = [
        'WEI MING', 'JUN HAO', 'KOK WAI', 'CHUN KIT', 'YONG KANG', 'JIA WEI', 'ZHEN HAO', 'GUAN HENG', 'BOON HENG', 'CHEE KEONG',
        'KIM SENG', 'SOON HOCK', 'CHIN HOE', 'WENG FATT', 'JIN HAO', 'KAI XUAN', 'ZHI YANG', 'HOCK LYE', 'SHIN CHENG', 'WEI JIE',
        'AH KOW', 'BOON CHYE', 'CHEE MENG', 'CHIN TONG', 'GUAN CHYE', 'HOCK CHYE', 'KIM HOCK', 'LAI MENG', 'SOON YEW', 'TAI CHONG',
        'WENG FOOK', 'YEW MENG', 'CHIN HIN', 'HOCK KEE', 'KOK LEONG', 'YEW HOCK', 'BOON HOCK', 'CHIN KEAT', 'HOCK SENG', 'KIM HOE',
        'ALVIN', 'BILLY', 'CALVIN', 'DESMOND', 'EDMUND', 'JASON', 'KELVIN', 'RAYMOND', 'STEVEN', 'VINCENT',
        'HOCK CHUAN', 'KIM BENG', 'LAI HOCK', 'SOON KEAT', 'TEH HOCK', 'WENG KEONG', 'YONG KEAT', 'CHUN WEI', 'GUAN YEW', 'YONG SENG',
    ];

    private array $chineseFemaleGiven = [
        'MEI LING', 'SU CHIN', 'PEI YING', 'XIN YI', 'JIA HUI', 'YI XUAN', 'POH LING', 'AI LING', 'SIEW MEI', 'SIEW LAN',
        'MEI FONG', 'CHUI PENG', 'AI CHOO', 'SOOK YIN', 'HUI MIN', 'YAN NI', 'LI LING', 'PEI CHI', 'XUE TING', 'QI HUA',
        'CHUI YIN', 'FOONG LING', 'GEK LING', 'HOCK LENG', 'KIM LAN', 'LAI FONG', 'MEE LENG', 'POH YEE', 'SIEW FONG', 'SOO CHIN',
        'TAI MUI', 'WAI LING', 'YOK LAN', 'CHIN LAN', 'HOCK LAN', 'KOK LAN', 'LIM LAN', 'NG LAN', 'ONG LAN', 'TEH LAN',
        'AI PING', 'BOON YING', 'CHIN YING', 'HOCK YING', 'KIM HUA', 'LAI YING', 'MEI HUA', 'POH HUA', 'SIEW HUA', 'SOO YING',
        'WAI PING', 'YEW LING', 'CHUI LAN', 'FOONG HUA', 'GEK HUA', 'HOCK PING', 'KIM YING', 'LAI HUA', 'MEI YING', 'POH LAN',
    ];

    /** Indian (Tamil/Malaysian Indian) — given + A/L or A/P + father's given name only */
    private array $indianMaleGiven = [
        'RAJESH', 'SURESH', 'MURUGAN', 'PRAKASH', 'VIJAY', 'GANESH', 'DEEPAK', 'KARTHICK', 'RAMESH', 'SELVAM',
        'LOGANATHAN', 'NATARAJAN', 'SHANKAR', 'ARVIND', 'THANESH', 'SUNIL', 'PRABHU', 'GUNALAN', 'MUNIANDY', 'SUBRAMANIAM',
        'NAGARATNAM', 'MANOGARAN', 'KUMARAN', 'SIVAKUMAR', 'ANBALAGAN', 'THAMILSELVAN', 'BALAKUMAR', 'CHANDRASEKAR', 'JEYAKUMAR', 'SASIKUMAR',
        'VIKNESWARAN', 'KANAGARATNAM', 'MAHENDRAN', 'RAVINDRAN', 'SATHIAH', 'PARAMASIVAM', 'KANDIAH', 'SUPPIAH', 'RAMACHANDRAN', 'GOVINDARAJ',
    ];

    private array $indianFemaleGiven = [
        'PRIYA', 'LAKSHMI', 'KAVITHA', 'MEENA', 'SHANTI', 'DEVI', 'ANITHA', 'MALAR', 'REVATHI', 'VASANTHI',
        'KAMALA', 'SAROJA', 'INDIRA', 'VIJAYA', 'GEETHA', 'MALATHI', 'RANI', 'NIRMALA', 'KALYANI', 'SHARMILA',
        'SUMATHI', 'JAYANTHI', 'USHARANI', 'VANITHA', 'PREMA', 'KOGILA', 'MAHESWARI', 'LATHA', 'KOMALA', 'SARASWATHI',
        'MALLIKA', 'RENUGA', 'JOTHI', 'VIJAYALAKSHMI', 'SARASWATHY', 'NAGESWARI', 'RATHA', 'KANAGAM', 'POONGODI', 'SIVAGAMI',
    ];

    private array $indianFather = [
        'KUMAR', 'SUBRAMANIAM', 'RAMASAMY', 'KRISHNAN', 'PERUMAL', 'SELVAM', 'GOVINDASAMY', 'NADARAJAN', 'PILLAI', 'VELAYUTHAM',
        'ARUMUGAM', 'CHANDRAN', 'GOPAL', 'SUPPIAH', 'THAMBY', 'MUNIANDY', 'RATNASAMY', 'SINNATHAMBY', 'MURUGIAH', 'SEGAR',
        'ANBUSSELVAN', 'KANNAN', 'PALANIAPPAN', 'RAVICHANDRAN', 'SIVASUBRAMANIAM', 'THANABAL', 'VEERAPPAN', 'MANICKAM', 'KUPPUSAMY', 'NAGAPPAN',
        'RAMALINGAM', 'SUNDARAM', 'BALAKRISHNAN', 'CHINNIAH', 'DORAISAMY', 'GANAPATHY', 'JEYARAM', 'KANDASAMY', 'MUNUSAMY', 'PERIASAMY',
    ];

    /** Sabah — Kadazan/Dusun (ANAK / @) or Bajau/Malay Muslim (BIN/BINTI) */
    private array $sabahIndigenousMale = [
        'JOHNNY', 'RICHARD', 'JEFFREY', 'PETER', 'MICHAEL', 'ANDY', 'RONNY', 'BENSON', 'JOSEPH', 'FRANCIS',
        'CHARLES', 'ANTHONY', 'GEORGE', 'HENRY', 'JAMES', 'ROBERT', 'THOMAS', 'WILLIAM', 'DANIEL', 'MARK',
        'PAUL', 'SIMON', 'VINCENT', 'MARTIN', 'ALBERT',
    ];

    private array $sabahIndigenousFemale = [
        'MARLINA', 'JENNY', 'ROSELINE', 'CHRISTINA', 'LINDA', 'GRACE', 'ELIZABETH', 'MARY', 'TERESA', 'CECILIA',
        'ANGELINE', 'BERNADETTE', 'CATHERINE', 'DOROTHY', 'HELEN', 'IRENE', 'JUDITH', 'KAREN', 'LAURA', 'NANCY',
        'PATRICIA', 'REBECCA', 'SUSAN', 'VICTORIA', 'YVONNE',
    ];

    private array $sabahIndigenousFather = [
        'GILING', 'JUNING', 'MADING', 'SIPANG', 'KADING', 'LIMBANG', 'SAGU', 'MOKUAT', 'TANGAH', 'BUNDU',
        'DULLAH', 'GUNGGUS', 'KASSIM', 'LUMIN', 'MATUSIN', 'MONGIN', 'SADI', 'SALUDIN', 'TALIB', 'UDIN',
        'AMAT', 'BASIR', 'GURA', 'KULING', 'SAMPIL',
    ];

    private array $sabahMuslimMale = ['MUHAMMAD', 'ABDUL', 'ISMAIL', 'HASSAN', 'RAHMAN'];
    private array $sabahMuslimFemale = ['SITI', 'NUR', 'DAYANG', 'ROHANI', 'HALIMAH'];

    /** Sarawak — Iban/Melanau indigenous (ANAK/BINTI) or Malay-style BIN */
    private array $sarawakIndigenousMale = [
        'ANDREW', 'JONATHAN', 'MICHAEL', 'PATRICK', 'CHRISTOPHER', 'DAVID', 'PAUL', 'KEVIN', 'BENJAMIN', 'SAMUEL',
        'DANIEL', 'MATTHEW', 'MARK', 'LUKE', 'JOHN', 'PETER', 'JAMES', 'ROBERT', 'THOMAS', 'GEORGE',
        'HENRY', 'CHARLES', 'ANTHONY', 'FRANCIS', 'JOSEPH',
    ];

    private array $sarawakIndigenousFemale = [
        'MARY', 'CATHERINE', 'RUTH', 'HANNAH', 'SARAH', 'RACHEL', 'DEBORAH', 'JOYCE', 'AGNES', 'MONICA',
        'ELIZABETH', 'GRACE', 'HELEN', 'IRENE', 'JANE', 'JUDITH', 'KAREN', 'LAURA', 'MARGARET', 'NANCY',
        'PATRICIA', 'REBECCA', 'SUSAN', 'VICTORIA', 'YVONNE',
    ];

    private array $sarawakIndigenousFather = [
        'JAMIN', 'BADAU', 'SAGA', 'BAYANG', 'MUNAN', 'SULAU', 'ENTALAI', 'BALAN', 'SARIB', 'GAMAN',
        'BADA', 'BELARE', 'DUNGA', 'EMANG', 'GARING', 'JABU', 'KULAI', 'LAKI', 'MUNA', 'NYALANG',
        'PUN', 'SAGI', 'TARAT', 'UKUM', 'UTONG',
    ];

    private array $sarawakMelanauMale = ['ABANG', 'AWANG', 'MAWAN', 'BENJAMIN'];
    private array $sarawakMelanauFemale = ['DAYANG', 'ROSE', 'AGNES', 'CECILIA'];

    public function reservePublic(string $name): void
    {
        $this->usage[strtoupper(trim($name))] = self::MAX_DUPLICATES;
    }

    public function assign(string $cohort, bool $male, int $sequence): string
    {
        $space = $this->combinationSpace($cohort, $male);
        $maxTry = max(12000, $space * 2);

        for ($try = 0; $try < $maxTry; $try++) {
            $idx = $sequence + $try * 4999 + intdiv($try * $sequence, 17);
            if ($space > 0) {
                $idx = ($sequence * 7919 + $try * 104729) % $space;
            }
            $name = $this->composeStrict($cohort, $male, $idx);
            if ($this->canUse($name)) {
                $this->markUsed($name);
                return $name;
            }
        }

        throw new RuntimeException('Could not assign unique name for sequence ' . $sequence . ' cohort ' . $cohort);
    }

    private function combinationSpace(string $cohort, bool $male): int
    {
        switch ($cohort) {
            case 'chinese':
                $g = $male ? count($this->chineseMaleGiven) : count($this->chineseFemaleGiven);
                return count($this->chineseSurname) * $g;
            case 'indian':
                $g = $male ? count($this->indianMaleGiven) : count($this->indianFemaleGiven);
                return $g * count($this->indianFather);
            case 'sabah':
                return 2500;
            case 'sarawak':
                return 2800;
            case 'malay_old':
            case 'malay_modern':
            case 'malay_genz':
            case 'malay':
                $g = $male ? count($this->malayMaleGiven) : count($this->malayFemaleGiven);
                return $g * count($this->malayFather) * count($male ? $this->malayMalePrefix : $this->malayFemalePrefix);
            default:
                return 0;
        }
    }

    private function canUse(string $name): bool
    {
        $name = strtoupper(preg_replace('/\s+/', ' ', trim($name)) ?? '');
        if ($name === '' || preg_match('/\d/u', $name) === 1) {
            return false;
        }
        if ($this->isMixedConvention($name)) {
            return false;
        }

        return ($this->usage[$name] ?? 0) < self::MAX_DUPLICATES;
    }

    /** Reject names that combine markers from different ethnic systems */
    private function isMixedConvention(string $name): bool
    {
        $hasBin = (bool)preg_match('/\b(BIN|BINTI)\b/u', $name);
        $hasAl = (bool)preg_match('/\b(A\/L|A\/P)\b/u', $name);
        $hasAnak = (bool)preg_match('/\bANAK\b/u', $name);
        $hasAt = str_contains($name, ' @ ');

        $markers = (int)$hasBin + (int)$hasAl + (int)$hasAnak + (int)$hasAt;
        if ($markers > 1) {
            return true;
        }

        if ($hasAl && ($hasBin || $hasAnak || $hasAt)) {
            return true;
        }

        if ($hasBin && ($hasAnak || $hasAt)) {
            return true;
        }

        return false;
    }

    private function markUsed(string $name): void
    {
        $name = strtoupper(preg_replace('/\s+/', ' ', trim($name)) ?? '');
        $this->usage[$name] = ($this->usage[$name] ?? 0) + 1;
    }

    private function composeStrict(string $cohort, bool $male, int $idx): string
    {
        switch ($cohort) {
            case 'malay_old':
            case 'malay_modern':
            case 'malay_genz':
            case 'malay':
                return $this->malayName($male, $idx, $cohort);
            case 'chinese':
                return $this->chineseName($male, $idx);
            case 'indian':
                return $this->indianName($male, $idx);
            case 'sabah':
                return $this->sabahName($male, $idx);
            case 'sarawak':
                return $this->sarawakName($male, $idx);
            default:
                return $this->malayName($male, $idx, 'malay');
        }
    }

    private function malayName(bool $male, int $idx, string $cohort): string
    {
        $givenPool = $male ? $this->malayMaleGiven : $this->malayFemaleGiven;
        $prefixPool = $male ? $this->malayMalePrefix : $this->malayFemalePrefix;
        $link = $male ? 'BIN' : 'BINTI';

        $g = $givenPool[$idx % count($givenPool)];
        $f = $this->malayFather[intdiv($idx, count($givenPool)) % count($this->malayFather)];
        $prefix = $prefixPool[intdiv($idx, count($givenPool) * count($this->malayFather)) % count($prefixPool)];

        if ($cohort === 'malay_genz' && $prefix === '') {
            $prefix = $male ? 'MOHD' : 'NUR';
        }

        if ($prefix !== '') {
            return $prefix . ' ' . $g . ' ' . $link . ' ' . $f;
        }

        return $g . ' ' . $link . ' ' . $f;
    }

    private function chineseName(bool $male, int $idx): string
    {
        $surnames = count($this->chineseSurname);
        $givenPool = $male ? $this->chineseMaleGiven : $this->chineseFemaleGiven;
        $givens = count($givenPool);
        $surname = $this->chineseSurname[$idx % $surnames];
        $given = $givenPool[intdiv($idx, $surnames) % $givens];

        return $surname . ' ' . $given;
    }

    private function indianName(bool $male, int $idx): string
    {
        $givenPool = $male ? $this->indianMaleGiven : $this->indianFemaleGiven;
        $given = $givenPool[$idx % count($givenPool)];
        $father = $this->indianFather[intdiv($idx, count($givenPool)) % count($this->indianFather)];
        $link = $male ? 'A/L' : 'A/P';

        return $given . ' ' . $link . ' ' . $father;
    }

    private function sabahName(bool $male, int $idx): string
    {
        $useMuslimStyle = ($idx % 5) === 4;

        if ($useMuslimStyle) {
            $givenPool = $male ? $this->sabahMuslimMale : $this->sabahMuslimFemale;
            $given = $givenPool[$idx % count($givenPool)];
            $link = $male ? 'BIN' : 'BINTI';
            $father = $this->malayFather[intdiv($idx, count($givenPool)) % count($this->malayFather)];

            return $given . ' ' . $link . ' ' . $father;
        }

        $givenPool = $male ? $this->sabahIndigenousMale : $this->sabahIndigenousFemale;
        $given = $givenPool[$idx % count($givenPool)];
        $father = $this->sabahIndigenousFather[intdiv($idx, count($givenPool)) % count($this->sabahIndigenousFather)];

        if (($idx % 3) === 1) {
            return $given . ' @ ' . $father;
        }

        return $given . ' ANAK ' . $father;
    }

    private function sarawakName(bool $male, int $idx): string
    {
        $style = $idx % 6;

        if ($style === 5) {
            $givenPool = $male ? $this->sarawakMelanauMale : $this->sarawakMelanauFemale;
            $given = $givenPool[$idx % count($givenPool)];
            $link = $male ? 'BIN' : 'BINTI';
            $father = $this->malayFather[intdiv($idx, count($givenPool)) % count($this->malayFather)];

            return $given . ' ' . $link . ' ' . $father;
        }

        if ($style === 4) {
            $pool = $male ? $this->malayMaleGiven : $this->malayFemaleGiven;
            $given = $pool[$idx % count($pool)];
            $link = $male ? 'BIN' : 'BINTI';
            $father = $this->malayFather[intdiv($idx, 11) % count($this->malayFather)];

            return $given . ' ' . $link . ' ' . $father;
        }

        $givenPool = $male ? $this->sarawakIndigenousMale : $this->sarawakIndigenousFemale;
        $given = $givenPool[$idx % count($givenPool)];
        $father = $this->sarawakIndigenousFather[intdiv($idx, count($givenPool)) % count($this->sarawakIndigenousFather)];
        $link = $male ? 'ANAK' : 'BINTI';

        return $given . ' ' . $link . ' ' . $father;
    }

    public static function inferCohortFromIc(string $ic): string
    {
        return self::inferCohortForFix(0, $ic);
    }

    public static function inferCohortForFix(int $id, string $ic): string
    {
        $pb = substr($ic, 6, 2);
        if ($pb === '12') {
            return 'sabah';
        }
        if ($pb === '13') {
            return 'sarawak';
        }

        $chinesePlaces = ['14', '54', '55', '56', '57', '07', '34', '35', '36', '37', '38', '39', '08'];
        if (in_array($pb, $chinesePlaces, true)) {
            return 'chinese';
        }

        $indianPlaces = ['10', '42', '43', '44', '59'];
        if (in_array($pb, $indianPlaces, true)) {
            return 'indian';
        }

        $bucket = $id % 100;
        if ($bucket < 50) {
            return 'malay_old';
        }
        if ($bucket < 62) {
            return 'malay_modern';
        }
        if ($bucket < 68) {
            return 'malay_genz';
        }
        if ($bucket < 82) {
            return 'chinese';
        }

        return 'indian';
    }

    public function maxUsageCount(): int
    {
        return $this->usage === [] ? 0 : max($this->usage);
    }
}
