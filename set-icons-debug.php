<?php
/**
 * set-icons.php — ใส่ Blocksy menu icon ให้เมนูที่ยัง "ว่าง" (ข้ามตัวที่มีอยู่แล้ว)
 *
 * รันตรงจาก GitHub (ไม่ต้องเซฟไฟล์):
 *   URL='https://raw.githubusercontent.com/ufavisionseoteam19/Set-Icons/main/set-icons.php'
 *   curl -s "$URL?v=$(date +%s)" | php -- naka888s.org                      # dry-run
 *   curl -s "$URL?v=$(date +%s)" | php -- naka888s.org --commit --purge     # เขียนจริง (จะถามยืนยันก่อน)
 *   curl -s "$URL?v=$(date +%s)" | php -- d1 d2 d3 --commit --purge --yes   # เขียนจริง ข้ามการยืนยัน
 *
 * Flags:
 *   --commit   เขียนจริง (ค่าเริ่มต้น = dry-run)
 *   --purge    ล้าง LiteSpeed cache หลังเขียน
 *   --yes/-y   ข้ามการถามยืนยัน (สำหรับ cron/อัตโนมัติ)
 *   --csv=...  อ่านรายชื่อโดเมนจากไฟล์/URL (คอลัมน์แรก = domain)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

// ====== URL ของสคริปต์ตัวเองบน GitHub (แก้ให้ตรง repo คุณ) ======
const SELF_URL = 'https://raw.githubusercontent.com/ufavisionseoteam19/Set-Icons/main/set-icons-debug.php';

global $argv;

// ============================================================
// CHILD detection: ถ้ามี --docroot= = รันในนาม user แล้ว -> Phase 2
// ============================================================
$childDocroot = null;
$dumpId = null;
foreach ($argv as $a) {
    if (strpos($a, '--docroot=') === 0) { $childDocroot = substr($a, 10); }
    if (strpos($a, '--dump=') === 0)    { $dumpId = (int)substr($a, 7); }
}
if ($childDocroot !== null) {
    if ($dumpId !== null) { dump_item($childDocroot, $dumpId); exit(0); }
    run_phase2($childDocroot);
    exit(0);
}

// ============================================================
// PHASE 1 (root): parse args
// ============================================================
$argvRest = array_slice($argv, 1);
$commit   = in_array('--commit', $argvRest, true);
$purge    = in_array('--purge', $argvRest, true);
$yes      = in_array('--yes', $argvRest, true) || in_array('-y', $argvRest, true);

$domains = [];
$csvPath = null;

foreach ($argvRest as $a) {
    if (in_array($a, ['--commit','--purge','--yes','-y','--','-'], true) || strpos($a,'--dump=')===0) {
        continue;
    } elseif ($a === '--csv') {
        $csvPath = 'domains-config.csv';
    } elseif (strpos($a, '--csv=') === 0) {
        $csvPath = substr($a, 6);
    } elseif ($a !== '' && $a[0] === '-') {
        fwrite(STDERR, "ไม่รู้จัก option: $a\n"); exit(1);
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
    fwrite(STDERR, "  ... | php -- <domain> [--commit] [--purge] [--yes]\n");
    fwrite(STDERR, "  ... | php -- d1 d2 ... [--commit] [--purge] [--yes]\n");
    fwrite(STDERR, "  ... | php -- --csv=<file|url> [--commit] [--purge] [--yes]\n");
    exit(1);
}

// ต้องเป็น root
$map = '/etc/userdatadomains';
if (!is_readable($map)) {
    fwrite(STDERR, "ERROR: อ่าน $map ไม่ได้ (ต้องรันด้วย root)\n");
    exit(1);
}

$self        = __FILE__;
$runFromFile = is_file($self);

// ----- กล่องสรุป -----
echo str_repeat('=', 56) . "\n";
echo " set-icons.php — Blocksy menu icons\n";
echo " จำนวนโดเมน : " . count($domains) . "\n";
echo " โหมด       : " . ($commit ? "เขียนจริง" : "DRY-RUN (ดูอย่างเดียว)") . "\n";
echo " แหล่งโค้ด  : " . ($runFromFile ? "ไฟล์ ($self)" : "GitHub (pipe)") . "\n";
if ($purge) echo " ล้าง cache : ใช่\n";
echo str_repeat('=', 56) . "\n";

// ----- ยืนยันก่อนเขียนจริง (เฉพาะ --commit และยังไม่ใส่ --yes) -----
if ($commit && !$yes) {
    confirm_or_abort($domains, $purge);
}

// ----- loop ทีละโดเมน -----
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
//  ยืนยันก่อนเขียนจริง — อ่านจาก /dev/tty (ใช้ได้แม้รันแบบ pipe)
// ============================================================
function confirm_or_abort(array $domains, bool $purge): void {
    echo "\n";
    echo " \e[33m*** กำลังจะเขียนจริง ลงโดเมนต่อไปนี้ ***\e[0m\n";
    foreach ($domains as $i => $d) {
        printf("   %2d. %s\n", $i + 1, $d);
    }
    echo " ล้าง cache : " . ($purge ? "ใช่" : "ไม่") . "\n\n";
    echo " \e[36m> ยืนยันการเขียนจริง? [y/N] : \e[0m";

    // อ่านจาก /dev/tty เพราะ STDIN ถูกใช้ส่งโค้ด PHP (pipe) ไปแล้ว
    $tty = @fopen('/dev/tty', 'r');
    if ($tty === false) {
        echo "\n \e[31mERROR:\e[0m ไม่มี terminal ให้ยืนยัน — ใส่ --yes เพื่อรันอัตโนมัติ\n";
        exit(1);
    }
    $answer = strtolower(trim((string)fgets($tty)));
    fclose($tty);

    if ($answer !== 'y' && $answer !== 'yes') {
        echo " \e[33mยกเลิก\e[0m — ไม่มีการเขียนใดๆ\n";
        exit(0);
    }
    echo " \e[32mยืนยันแล้ว เริ่มเขียน...\e[0m\n";
}


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
            $owner = $parts[0]; $docroot = $parts[4]; break;
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

    $childArgs = [escapeshellarg($domain), '--docroot=' . escapeshellarg($docroot)];
    if ($commit) $childArgs[] = '--commit';
    if ($purge)  $childArgs[] = '--purge';
    foreach ($GLOBALS['argv'] as $ga) { if (strpos($ga, '--dump=') === 0) { $childArgs[] = escapeshellarg($ga); } }
    $argStr = implode(' ', $childArgs);

    if ($runFromFile) {
        $cmd = sprintf('sudo -u %s %s %s %s',
            escapeshellarg($owner), escapeshellarg($php),
            escapeshellarg($self), $argStr);
    } else {
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
        $content = @file_get_contents($path);
        if ($content === false) $content = shell_exec('curl -s ' . escapeshellarg($path));
        if (!$content) { fwrite(STDERR, "ERROR: ดึง CSV จาก URL ไม่ได้ '$path'\n"); exit(1); }
    } else {
        foreach ([$path, getcwd()."/$path", __DIR__."/$path", "/tmp/".basename($path)] as $c) {
            if (is_file($c) && is_readable($c)) { $src = $c; $content = file_get_contents($c); break; }
        }
        if ($content === null) { fwrite(STDERR, "ERROR: อ่าน CSV ไม่ได้ '$path'\n"); exit(1); }
    }

    $domains = []; $rowNo = 0;
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
//  DEBUG: dump โครงสร้าง post meta ของ menu item (เทียบ working vs new)
// ============================================================
function dump_item(string $docroot, int $id): void {
    if (!$docroot || !file_exists("$docroot/wp-load.php")) {
        fwrite(STDERR, "  ERROR: docroot ไม่ถูกต้อง ($docroot)\n"); exit(1);
    }
    define('WP_USE_THEMES', false);
    require_once "$docroot/wp-load.php";

    echo "=== DUMP menu item ID $id ===\n";
    $post = get_post($id);
    echo "title: " . ($post ? $post->post_title : '(ไม่พบ)') . "\n\n";

    echo "--- blocksy_post_meta_options ---\n";
    var_export(get_post_meta($id, 'blocksy_post_meta_options', true));
    echo "\n\n--- ALL post meta keys ---\n";
    $all = get_post_meta($id);
    foreach ($all as $k => $v) {
        echo "[$k] => ";
        var_export(array_map(fn($x)=>maybe_unserialize($x), $v));
        echo "\n";
    }
    echo "\n=== END DUMP ===\n";
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
    if (empty($locs)) { echo "  ไม่พบ menu location\n"; exit(0); }

    $changed = 0; $skipped = 0; $nomatch = 0;

    foreach ($locs as $loc => $menu_id) {
        $size  = in_array($loc, $MOBILE_LOCATIONS, true) ? '18' : '22';
        $items = wp_get_nav_menu_items($menu_id);
        if (!$items) continue;

        echo "  === $loc (size $size) ===\n";
        foreach ($items as $it) {
            $title = html_entity_decode($it->title, ENT_QUOTES);
            $opt   = get_post_meta($it->ID, 'blocksy_post_meta_options', true);
            $has   = (is_array($opt) && isset($opt['menu_item_icon']['icon'])
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
