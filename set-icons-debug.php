<?php
/**
 * set-icons.php — ใส่ Blocksy menu icon ให้เมนูที่ยัง "ว่าง" (ข้ามตัวที่มีอยู่แล้ว)
 *
 * รันตรงจาก GitHub ได้ (ไม่ต้องเซฟไฟล์):
 *   URL='https://raw.githubusercontent.com/visionteamseo1/Set-Icons/main/set-icons.php'
 *   curl -s "$URL?v=$(date +%s)" | php -- naka888s.org
 *   curl -s "$URL?v=$(date +%s)" | php -- naka888s.org --commit --purge
 *   curl -s "$URL?v=$(date +%s)" | php -- d1.com d2.com d3.com --commit --purge
 *   curl -s "$URL?v=$(date +%s)" | php -- --csv=https://.../domains-config.csv --commit --purge
 *
 * หรือเซฟไฟล์แล้วรันก็ได้ (โหมดเดิม):
 *   curl -s "$URL?v=$(date +%s)" -o /tmp/set-icons.php
 *   php /tmp/set-icons.php naka888s.org --commit --purge
 *
 * วิธีทำงาน:
 *   - หา docroot/owner จาก /etc/userdatadomains (ครอบคลุมทุก cPanel)
 *   - รัน 2 เฟส: root หา path -> re-exec ในนาม user เจ้าของ
 *   - ถ้ารันแบบ pipe (ไม่มีไฟล์) จะ "ดึงตัวเองจาก GitHub ซ้ำ" ตอน re-exec
 *   - default = dry-run (ต้องใส่ --commit ถึงเขียน)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

// ====== ตั้งค่า URL ของสคริปต์ตัวเองบน GitHub (แก้ให้ตรง repo คุณ) ======
const SELF_URL = 'https://raw.githubusercontent.com/visionteamseo1/Set-Icons/main/set-icons.php';

global $argv;

// ============================================================
// CHILD detection: ถ้ามี --docroot= = รันในนาม user แล้ว -> Phase 2
// ============================================================
$childDocroot = null;
foreach ($argv as $a) {
    if (strpos($a, '--docroot=') === 0) {
        $childDocroot = substr($a, 10);
        break;
    }
}
if ($childDocroot !== null) {
    run_phase2($childDocroot);
    exit(0);
}

// ============================================================
// PHASE 1 (root): parse args แล้ว loop ทีละโดเมน
// ============================================================
$argvRest = array_slice($argv, 1);
$commit   = in_array('--commit', $argvRest, true);
$purge    = in_array('--purge', $argvRest, true);

$domains = [];
$csvPath = null;

foreach ($argvRest as $a) {
    if ($a === '--commit' || $a === '--purge' || $a === '--' || $a === '-') {
        continue;
    } elseif ($a === '--csv') {
        $csvPath = 'domains-config.csv';
    } elseif (strpos($a, '--csv=') === 0) {
        $csvPath = substr($a, 6);
    } elseif ($a !== '' && $a[0] === '-') {
        fwrite(STDERR, "ไม่รู้จัก option: $a\n");
        exit(1);
    } elseif ($a !== '') {
        $domains[] = $a;
    }
}

if ($csvPath !== null) {
    $domains = array_merge($domains, read_domains_from_csv($csvPath));
}

$domains = array_values(array_unique(array_filter($domains, fn($d) => $d !== '')));

if (empty($domains)) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  ... | php -- <domain> [--commit] [--purge]\n");
    fwrite(STDERR, "  ... | php -- d1.com d2.com ... [--commit] [--purge]\n");
    fwrite(STDERR, "  ... | php -- --csv=<file|url> [--commit] [--purge]\n");
    exit(1);
}

// ต้องเป็น root
$map = '/etc/userdatadomains';
if (!is_readable($map)) {
    fwrite(STDERR, "ERROR: อ่าน $map ไม่ได้ (ต้องรันด้วย root)\n");
    exit(1);
}

// ตรวจว่ารันจากไฟล์ หรือจาก stdin (pipe)
$self        = __FILE__;
$runFromFile = is_file($self);

echo str_repeat('=', 56) . "\n";
echo " set-icons.php — Blocksy menu icons\n";
echo " จำนวนโดเมน : " . count($domains) . "\n";
echo " โหมด       : " . ($commit ? "เขียนจริง" : "DRY-RUN (ดูอย่างเดียว)") . "\n";
echo " แหล่งโค้ด  : " . ($runFromFile ? "ไฟล์ ($self)" : "GitHub (pipe)") . "\n";
if ($purge) echo " ล้าง cache : ใช่\n";
echo str_repeat('=', 56) . "\n";

$ok = 0; $fail = 0;
foreach ($domains as $i => $domain) {
    $n = $i + 1;
    echo "\n[$n/" . count($domains) . "] > $domain\n";
    echo str_repeat('-', 56) . "\n";
    $code = process_domain($domain, $commit, $purge, $map, $runFromFile, $self);
    if ($code === 0) $ok++; else $fail++;
}

echo "\n" . str_repeat('=', 56) . "\n";
echo " เสร็จสิ้น — สำเร็จ: $ok | ล้มเหลว/ข้าม: $fail | รวม: " . count($domains) . "\n";
echo str_repeat('=', 56) . "\n";
exit($fail > 0 ? 1 : 0);


// ============================================================
//  PHASE 1 ต่อโดเมน: หา path+user แล้ว re-exec เป็น user
// ============================================================
function process_domain(string $domain, bool $commit, bool $purge,
                        string $map, bool $runFromFile, string $self): int {
    $docroot = ''; $owner = '';
    foreach (file($map, FILE_IGNORE_NEW_LINES) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $key = substr($line, 0, $pos);
        if (strpos($key, $domain . '.') !== 0 && $key !== $domain) continue;

        $rhs   = trim(substr($line, $pos + 1));
        $parts = explode('==', $rhs);
        if (count($parts) >= 5 && strpos($parts[4], "/$domain") !== false) {
            $owner   = $parts[0];
            $docroot = $parts[4];
            break;
        }
    }

    if ($docroot === '') {
        fwrite(STDERR, "  ERROR: ไม่พบโดเมน '$domain' ใน $map (ข้าม)\n");
        return 1;
    }
    if (!file_exists("$docroot/wp-load.php")) {
        fwrite(STDERR, "  ERROR: ไม่พบ wp-load.php ที่ $docroot (ไม่ใช่ WordPress? ข้าม)\n");
        return 1;
    }

    // หา php-cli
    $php = PHP_BINARY;
    if (strpos($php, 'php-cgi') !== false || !is_executable($php)) {
        foreach (['/opt/cpanel/ea-php84/root/usr/bin/php',
                  '/opt/cpanel/ea-php83/root/usr/bin/php',
                  '/opt/cpanel/ea-php82/root/usr/bin/php',
                  '/usr/bin/php'] as $cand) {
            if (is_executable($cand)) { $php = $cand; break; }
        }
    }

    echo "  เจ้าของ: $owner\n";
    echo "  Path  : $docroot\n";

    // สร้าง args สำหรับ child
    $childArgs = [
        escapeshellarg($domain),
        '--docroot=' . escapeshellarg($docroot),
    ];
    if ($commit) $childArgs[] = '--commit';
    if ($purge)  $childArgs[] = '--purge';
    $argStr = implode(' ', $childArgs);

    if ($runFromFile) {
        // โหมดไฟล์: re-exec ไฟล์เดิมในนาม user
        $cmd = sprintf('sudo -u %s %s %s %s',
            escapeshellarg($owner), escapeshellarg($php),
            escapeshellarg($self), $argStr);
    } else {
        // โหมด pipe: ดึงตัวเองจาก GitHub ซ้ำ แล้ว pipe ให้ user รัน (ไม่เซฟไฟล์)
        $url = SELF_URL . '?v=' . time();
        $cmd = sprintf('curl -s %s | sudo -u %s %s -- %s',
            escapeshellarg($url), escapeshellarg($owner),
            escapeshellarg($php), $argStr);
    }

    passthru($cmd, $code);
    return $code;
}


// ============================================================
//  อ่านโดเมนจาก CSV — รองรับทั้งไฟล์ในเครื่อง และ URL
// ============================================================
function read_domains_from_csv(string $path): array {
    $content = null; $src = $path;

    if (preg_match('#^https?://#i', $path)) {
        // CSV จาก URL
        $content = @file_get_contents($path);
        if ($content === false) {
            $content = shell_exec('curl -s ' . escapeshellarg($path));
        }
        if (!$content) { fwrite(STDERR, "ERROR: ดึง CSV จาก URL ไม่ได้ '$path'\n"); exit(1); }
    } else {
        // CSV ไฟล์ในเครื่อง
        foreach ([$path, getcwd()."/$path", __DIR__."/$path", "/tmp/".basename($path)] as $c) {
            if (is_file($c) && is_readable($c)) { $src = $c; $content = file_get_contents($c); break; }
        }
        if ($content === null) { fwrite(STDERR, "ERROR: อ่าน CSV ไม่ได้ '$path'\n"); exit(1); }
    }

    $domains = [];
    $rowNo   = 0;
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $rowNo++;
        $cells = str_getcsv($line);
        $val   = trim((string)($cells[0] ?? ''));
        if ($val === '' || $val[0] === '#') continue;
        if ($rowNo === 1 && strcasecmp($val, 'domain') === 0) continue;
        $val = preg_replace('#^https?://#i', '', $val);
        $val = rtrim(explode('/', $val)[0]);
        if ($val !== '') $domains[] = $val;
    }

    echo "อ่าน CSV: $src (พบ " . count($domains) . " โดเมน)\n";
    return $domains;
}


// ============================================================
//  PHASE 2 (รันในนาม user): bootstrap WP และใส่ icon จริง
// ============================================================
function run_phase2(string $docroot): void {
    if (!$docroot || !file_exists("$docroot/wp-load.php")) {
        fwrite(STDERR, "  ERROR: docroot ไม่ถูกต้อง ($docroot)\n");
        exit(1);
    }

    global $argv;
    $commit = in_array('--commit', $argv, true);
    $purge  = in_array('--purge', $argv, true);

    define('WP_USE_THEMES', false);
    require_once "$docroot/wp-load.php";

    // ----- ตารางแม่: ชื่อเมนู => icon class -----
    $ICON_MAP = [
        'หน้าหลัก'           => 'blc blc-home',
        'เข้าสู่ระบบ'         => 'fas fa-sign-in-alt',
        'สมัครสมาชิก'        => 'fas fa-user-plus',
        'บทความ'            => 'fas fa-book',
        'เกี่ยวกับเรา'        => 'fas fa-users',
        'สล็อตออนไลน์'      => 'far fa-gem',
        'โปรโมชั่น'          => 'fas fa-gift',
        'ติดต่อเรา'          => 'fab fa-line',
        'บริการของเรา'       => 'fas fa-dice',
        'คำถามที่พบบ่อย'     => 'fas fa-question-circle',
        'Term & Condition'  => 'fas fa-clipboard-check',
        'Privacy Policy'    => 'fas fa-shield-alt',
    ];
    $MOBILE_LOCATIONS = ['menu_mobile'];

    $locs = get_nav_menu_locations();
    if (empty($locs)) {
        echo "  ไม่พบ menu location (เว็บนี้อาจยังไม่ได้ตั้งเมนู)\n";
        exit(0);
    }

    $changed = 0; $skipped = 0; $nomatch = 0;

    foreach ($locs as $loc => $menu_id) {
        $size  = in_array($loc, $MOBILE_LOCATIONS, true) ? '18' : '22';
        $items = wp_get_nav_menu_items($menu_id);
        if (!$items) continue;

        echo "  === $loc (size $size) ===\n";
        foreach ($items as $it) {
            $title = html_entity_decode($it->title, ENT_QUOTES);
            $opt   = get_post_meta($it->ID, 'blocksy_post_meta_options', true);
            $has   = (is_array($opt)
                      && isset($opt['menu_item_icon']['icon'])
                      && $opt['menu_item_icon']['icon'] !== '');

            if ($has) { echo "    SKIP  [{$it->ID}] {$title}\n"; $skipped++; continue; }
            if (!isset($ICON_MAP[$title])) {
                echo "    ????  [{$it->ID}] {$title} (ชื่อไม่ตรงตาราง - ข้าม)\n";
                $nomatch++; continue;
            }

            $icon = $ICON_MAP[$title];
            echo "    SET   [{$it->ID}] {$title} => {$icon} (size {$size})\n";
            $changed++;

            if ($commit) {
                if (!is_array($opt)) $opt = [];
                $opt['menu_item_icon'] = ['icon' => $icon];
                $opt['menu_item_icon_size'] = $size;
                update_post_meta($it->ID, 'blocksy_post_meta_options', $opt);
            }
        }
    }

    if ($commit && $purge) {
        do_action('litespeed_purge_all');
        echo "  [ล้าง LiteSpeed cache แล้ว]\n";
    }

    echo "  ----- สรุปโดเมนนี้ -----\n";
    echo "  เติม: $changed | ข้าม(มีแล้ว): $skipped | ชื่อไม่ตรง: $nomatch\n";
    echo $commit ? "  ** เขียนจริงแล้ว **\n" : "  ** DRY-RUN ยังไม่เขียน **\n";
}
