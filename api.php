<?php
// =============================================================
// موارد سيستم الموحّد — API كامل
// الإصدار النهائي v3.2:
//   ✅ استيراد كل أعمدة الشيت (45+ عمود)
//   ✅ Smart Auto-Fix لتصحيح إزاحة بيانات الضامن/العنوان
//   ✅ فلتر Coll في get_team_clients
//   ✅ عرض كامل للبيانات في شاشة المحصل والمدخل
//   ✅ عزل تام بين الموظفين
// =============================================================

// المنطقة الزمنية: توقيت القاهرة (بدونها كانت الأوقات والـ "اليوم" بتتحسب بتوقيت السيرفر)
date_default_timezone_set('Africa/Cairo');
@ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$requestErrorId = bin2hex(random_bytes(8));
$exceptionHandled = false;
$debugSqlEnabled = static function () {
    return getenv('MAWARED_DEBUG_SQL') === '1'
        || ($_ENV['MAWARED_DEBUG_SQL'] ?? $_SERVER['MAWARED_DEBUG_SQL'] ?? null) === '1'
        || ($GLOBALS['fileEnvironment']['MAWARED_DEBUG_SQL'] ?? null) === '1'
        || ($GLOBALS['CONFIG']['debug_sql'] ?? false) === true;
};

// أي خطأ غير متوقع يرجع رسالة JSON مفهومة بدل صفحة فاضية
set_exception_handler(function ($e) use ($requestErrorId, &$exceptionHandled, $debugSqlEnabled) {
    $exceptionHandled = true;
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    if ($cycleId) {
        $cycleClientCount = (int)(DB::one(
            "SELECT COUNT(*) AS n FROM clients WHERE cycle_id = ? AND assigned_to = ?",
            [$cycleId, $ownerId]
        )['n'] ?? 0);
        $assignment = DB::one("SELECT id FROM collection_assignments WHERE cycle_id = ? AND user_id = ?", [$cycleId, $ownerId]);
        if (!$assignment) {
            DB::insert('collection_assignments', [
                'cycle_id' => $cycleId,
                'user_id' => $ownerId,
                'assigned_count' => $cycleClientCount,
                'uploaded_count' => $cycleClientCount,
                'status' => 'working',
            ]);
        } else {
            DB::q(
                "UPDATE collection_assignments SET assigned_count = ?, uploaded_count = ?, status = 'working', updated_at = NOW() WHERE id = ?",
                [$cycleClientCount, $cycleClientCount, $assignment['id']]
            );
        }
    }
    $msg = 'حصل خطأ في السيرفر — حاول تاني، ولو استمر بلّغ المسؤول';
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        if (!headers_sent()) http_response_code(409);
        $msg = 'البيانات دي موجودة قبل كده (رقم تليفون مكرر)';
    }
    error_log('[mawared][' . $requestErrorId . '] ' . get_class($e) . ': ' . $e->getMessage());
    $response = [
        'success' => false,
        'message' => $msg,
        'error_id' => $requestErrorId,
    ];
    if ($debugSqlEnabled() && ($_GET['action'] ?? '') === 'login') {
        $sqlError = $e instanceof PDOException
            ? ($e->errorInfo[2] ?? $e->getMessage())
            : get_class($e) . ': ' . $e->getMessage();
        $config = $GLOBALS['CONFIG']['db'] ?? [];
        foreach (['host', 'user', 'pass', 'name'] as $key) {
            $secret = (string)($config[$key] ?? '');
            if ($secret !== '') $sqlError = str_replace($secret, '[hidden]', (string)$sqlError);
        }
        $response['debug_error'] = (string)$sqlError;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
});
register_shutdown_function(function () use ($requestErrorId, &$exceptionHandled, $debugSqlEnabled) {
    if ($exceptionHandled) return;
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
        }
        error_log('[mawared][' . $requestErrorId . '] fatal: ' . $e['message']);
        $response = [
            'success' => false,
            'message' => 'خطأ داخلي في السيرفر',
            'error_id' => $requestErrorId,
        ];
        if ($debugSqlEnabled() && ($_GET['action'] ?? '') === 'login') {
            $sqlError = preg_replace('/^Uncaught\s+/', '', $e['message']);
            $sqlError = preg_split('/\r?\nStack trace:/', (string)$sqlError, 2)[0];
            $sqlError = preg_replace('/\s+thrown$/', '', (string)$sqlError);
            $sqlError = preg_replace('/\s+in\s+.+\s+on line\s+\d+$/', '', $sqlError);
            $config = $GLOBALS['CONFIG']['db'] ?? [];
            foreach (['host', 'user', 'pass', 'name'] as $key) {
                $secret = (string)($config[$key] ?? '');
                if ($secret !== '') $sqlError = str_replace($secret, '[hidden]', (string)$sqlError);
            }
            $response['debug_error'] = (string)$sqlError;
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/collection_schema.php';
$CONFIG = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';

// =============================================================
// Helpers
// =============================================================
function input() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    return $_POST;
}
function out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $c = 400) { http_response_code($c); out(['success' => false, 'message' => $m]); }
function ok($d = []) { out(array_merge(['success' => true], $d)); }

function clean_phone($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    if (strpos($d, '201') === 0 && strlen($d) === 12) $d = '0' . substr($d, 2);
    elseif (strpos($d, '1') === 0 && strlen($d) === 10) $d = '0' . $d;
    return $d;
}

function save_uploaded_image($file, $subdir = 'cards', &$why = null) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { $why = 'فشل رفع الصورة (كود ' . $file['error'] . ')'; return null; }
    if ($file['size'] > 8 * 1024 * 1024) { $why = 'الصورة أكبر من 8 ميجا'; return null; }

    // نتأكد إن الملف صورة فعلاً (مش بس الامتداد)
    $info = @getimagesize($file['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $map[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($map[$info[2]])) { $why = 'الملف ده مش صورة مدعومة (JPG / PNG / WebP)'; return null; }

    $dir = __DIR__ . '/uploads/' . $subdir . '/' . date('Ym');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $why = 'تعذر إنشاء مجلد الصور'; return null; }

    $name = bin2hex(random_bytes(12)) . '.' . $map[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) { $why = 'تعذر حفظ الصورة'; return null; }

    return 'uploads/' . $subdir . '/' . date('Ym') . '/' . $name;
}

function current_user() {
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_GET['token'] ?? null);
    if (!$t) {
        $in = input();
        $t = $in['token'] ?? null;
    }
    if (!$t) return null;
    return DB::one(
        "SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token = ? AND s.expires_at > NOW() AND u.is_active = 1",
        [$t]
    ) ?: null;
}

function require_auth($roles = []) {
    $u = current_user();
    if (!$u) fail('غير مصرح — سجل دخول الأول', 401);
    if ($roles && !in_array($u['role'], $roles)) fail('ليس لديك صلاحية لهذا الإجراء', 403);
    return $u;
}

// =============================================================
// صلاحيات ونطاقات الرؤية (كل دور يشوف نطاقه بس)
// =============================================================
const VISIT_DONE = ['done', 'paid', 'partial'];
const VISIT_STATUSES = ['pending', 'done', 'paid', 'partial', 'promise', 'absent', 'refused', 'wrong'];
const SQL_VISIT_PENDING = "(v.status = 'pending' OR v.status IS NULL OR v.status = '')";

function valid_date($s, $default) {
    $s = trim((string)$s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false) return $s;
    return $default;
}

function in_list($ids) { return implode(',', array_fill(0, count($ids), '?')); }

// الأدمن + أعضاء فريقه. سوبر أدمن = null (كل الفرق)
function team_ids($u) {
    if ($u['role'] === 'super_admin') return null;
    $ids = [(int)$u['id']];
    if ($u['role'] === 'admin') {
        foreach (DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]) as $r) $ids[] = (int)$r['id'];
    }
    return array_values(array_unique($ids));
}

// شرط SQL لجدول visits (alias = v)
function visit_scope($u) {
    if ($u['role'] === 'super_admin') return ['1=1', []];
    if ($u['role'] === 'admin') {
        $ids = team_ids($u);
        $ph = in_list($ids);
        return ["(v.collector_id IN ($ph) OR v.created_by IN ($ph))", array_merge($ids, $ids)];
    }
    if ($u['role'] === 'data_entry') return ['(v.collector_id = ? OR v.created_by = ?)', [(int)$u['id'], (int)$u['id']]];
    return ['v.collector_id = ?', [(int)$u['id']]];
}

// شرط SQL لجدول clients (alias = c)
function employee_active_collection_cycle($userId) {
    ensure_collection_schema();
    $cycle = DB::one(
        "SELECT cc.id
         FROM collection_assignments ca
         JOIN collection_cycles cc ON cc.id = ca.cycle_id
         WHERE ca.user_id = ? AND cc.status IN ('draft','active','review')
         ORDER BY cc.month_key DESC, cc.id DESC
         LIMIT 1",
        [(int)$userId]
    );
    return $cycle ? (int)$cycle['id'] : null;
}

function client_scope($u) {
    $manualClientScope = "c.cycle_id IS NULL AND c.sheet_name = 'manual'";
    if ($u['role'] === 'super_admin') {
        ensure_collection_schema();
        return [
            "(c.cycle_id IN (SELECT id FROM collection_cycles WHERE status IN ('draft','active','review')) OR $manualClientScope)",
            [],
        ];
    }
    if ($u['role'] === 'admin') {
        ensure_collection_schema();
        $ids = team_ids($u);
        $placeholders = in_list($ids);
        $cycle = DB::one(
            "SELECT id FROM collection_cycles
             WHERE created_by = ? AND status IN ('draft','active','review')
             ORDER BY month_key DESC, id DESC LIMIT 1",
            [(int)$u['id']]
        );
        $teamScope = "(c.owner_user_id IN ($placeholders) OR c.assigned_to IN ($placeholders))";
        if ($cycle) {
            return ["$teamScope AND c.cycle_id = ?", array_merge($ids, $ids, [(int)$cycle['id']])];
        }
        return ["$teamScope AND $manualClientScope", array_merge($ids, $ids)];
    }
    $cycleId = employee_active_collection_cycle((int)$u['id']);
    if ($cycleId) {
        return ['c.assigned_to = ? AND c.cycle_id = ?', [(int)$u['id'], $cycleId]];
    }
    return ["c.assigned_to = ? AND $manualClientScope", [(int)$u['id']]];
}

function client_ip() { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

function collection_coll_column_index($header) {
    foreach ($header as $index => $value) {
        if (strtolower(trim((string)$value)) === 'coll') return (int)$index;
    }
    return null;
}

function collection_cycle_source_path($cycleId) {
    global $CONFIG;
    $privateRoot = $CONFIG['storage']['private'] ?? null;
    if ($privateRoot === null || trim((string)$privateRoot) === '') {
        $privateRoot = getenv('MAWARED_PRIVATE_STORAGE');
    }
    if ($privateRoot === false || trim($privateRoot) === '') {
        $privateRoot = $_ENV['MAWARED_PRIVATE_STORAGE'] ?? $_SERVER['MAWARED_PRIVATE_STORAGE'] ?? null;
    }
    if ($privateRoot === null || trim((string)$privateRoot) === '') {
        $privateRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mawared_private';
    }
    return rtrim((string)$privateRoot, "\\/") . DIRECTORY_SEPARATOR . 'collection_cycles'
        . DIRECTORY_SEPARATOR . (int)$cycleId . DIRECTORY_SEPARATOR . 'source.xlsx';
}

function ensure_private_storage_protection() {
    global $CONFIG;
    $privateRoot = $CONFIG['storage']['private'] ?? '';
    if (empty($CONFIG['storage']['private_inside_web_root'])) return true;
    if (!is_dir($privateRoot) && !@mkdir($privateRoot, 0755, true) && !is_dir($privateRoot)) {
        return false;
    }
    $denyFile = rtrim($privateRoot, "\\/") . DIRECTORY_SEPARATOR . '.htaccess';
    return file_put_contents($denyFile, "Options -Indexes\nRequire all denied\n", LOCK_EX) !== false;
}

// حماية من تخمين كلمات المرور: 6 محاولات غلط لنفس الاسم من نفس الجهاز = انتظار 15 دقيقة
function login_throttled($username) {
    try {
        DB::q("CREATE TABLE IF NOT EXISTS login_attempts (
                 id INT AUTO_INCREMENT PRIMARY KEY,
                 username VARCHAR(100) NOT NULL,
                 ip VARCHAR(45) NOT NULL,
                 created_at DATETIME NOT NULL,
                 KEY idx_lookup (username, ip, created_at)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::q("DELETE FROM login_attempts WHERE created_at < ?", [date('Y-m-d H:i:s', time() - 86400)]);
        $r = DB::one(
            "SELECT COUNT(*) AS n FROM login_attempts WHERE username = ? AND ip = ? AND created_at > ?",
            [$username, client_ip(), date('Y-m-d H:i:s', time() - 900)]
        );
        return (int)($r['n'] ?? 0) >= 6;
    } catch (Throwable $e) { return false; }
}
function login_failed($username) {
    try { DB::insert('login_attempts', ['username' => $username, 'ip' => client_ip(), 'created_at' => date('Y-m-d H:i:s')]); } catch (Throwable $e) {}
}
function login_ok($username) {
    try { DB::q("DELETE FROM login_attempts WHERE username = ? AND ip = ?", [$username, client_ip()]); } catch (Throwable $e) {}
}

// =============================================================
// 1) Auth
// =============================================================
if ($action === 'login') {
    $in = input();
    $username = trim($in['username'] ?? '');
    $password = $in['password'] ?? '';
    if (!$username || !$password) fail('اسم المستخدم وكلمة المرور مطلوبان');

    $username = substr($username, 0, 100);
    if (login_throttled($username)) fail('محاولات دخول غلط كتير — استنى 15 دقيقة وجرّب تاني', 429);

    $u = DB::one("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1", [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        login_failed($username);
        fail('بيانات الدخول غير صحيحة', 401);
    }
    login_ok($username);
    DB::q("DELETE FROM sessions WHERE expires_at < NOW()");

    $token = bin2hex(random_bytes(32));
    $exp = date('Y-m-d H:i:s', strtotime('+' . $CONFIG['security']['session_days'] . ' days'));
    DB::insert('sessions', ['user_id' => $u['id'], 'token' => $token, 'expires_at' => $exp]);
    DB::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $u['id']]);

    log_activity($u, 'login', 'تسجيل دخول', $u['full_name'] . ' (' . $u['role'] . ')');

    ok([
        'token' => $token,
        'user' => [
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'full_name' => $u['full_name'],
            'role' => $u['role'],
            'phone' => $u['phone'],
            'admin_id' => $u['admin_id'],
        ]
    ]);
}

if ($action === 'logout') {
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? null;
    if ($t) DB::q("DELETE FROM sessions WHERE token = ?", [$t]);
    ok();
}

if ($action === 'me') {
    $u = require_auth();
    unset($u['password_hash']);
    ok(['user' => $u]);
}

// =============================================================
// إعدادات الاتصال (Webhook من MacroDroid) — شخصية لكل مستخدم،
// لأن كل موظف بيستخدم تليفونه هو في الاتصال
// =============================================================
function ensure_webhooks_table() {
    static $ok = null;
    if ($ok === true) return;
    DB::q("CREATE TABLE IF NOT EXISTS user_webhooks (
             user_id INT PRIMARY KEY,
             method VARCHAR(10) NOT NULL DEFAULT 'android',
             call_url VARCHAR(500) DEFAULT NULL,
             hangup_url VARCHAR(500) DEFAULT NULL,
             shortcut_name VARCHAR(100) DEFAULT NULL,
             updated_at DATETIME NULL,
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // إصلاح الجدول لو كان موجود من قبل بدون عمودي الآيفون (method / shortcut_name)
    try {
        $cols = array_map(function ($c) { return strtolower($c['Field']); }, DB::all("SHOW COLUMNS FROM user_webhooks"));
        if (!in_array('method', $cols, true)) DB::q("ALTER TABLE user_webhooks ADD `method` VARCHAR(10) NOT NULL DEFAULT 'android'");
        if (!in_array('shortcut_name', $cols, true)) DB::q("ALTER TABLE user_webhooks ADD `shortcut_name` VARCHAR(100) DEFAULT NULL");
    } catch (Throwable $e) { error_log('[mawared] webhooks migrate: ' . $e->getMessage()); }
    $ok = true;
}


function ensure_activity_tables() {
    static $ok = null;
    if ($ok === true) return true;
    try {
        DB::q("CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            user_name VARCHAR(120) NULL,
            role VARCHAR(30) NULL,
            event_type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_created (created_at),
            KEY idx_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::q("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT NOT NULL,
            from_name VARCHAR(120) NULL,
            to_user_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            body_text TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_to_unread (to_user_id, is_read),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // إصلاح أعمدة notifications لو الجدول موجود بشكل ناقص/قديم
        try {
            $cols = DB::all("SHOW COLUMNS FROM notifications");
            $names = array_map(function ($c) { return strtolower($c['Field']); }, $cols ?: []);
            $need = [
                'from_user_id' => "ADD `from_user_id` INT NOT NULL DEFAULT 0",
                'from_name'    => "ADD `from_name` VARCHAR(120) NULL",
                'to_user_id'   => "ADD `to_user_id` INT NOT NULL DEFAULT 0",
                'title'        => "ADD `title` VARCHAR(200) NOT NULL DEFAULT ''",
                'body_text'    => "ADD `body_text` TEXT NULL",
                'is_read'      => "ADD `is_read` TINYINT(1) NOT NULL DEFAULT 0",
                'read_at'      => "ADD `read_at` DATETIME NULL",
                'created_at'   => "ADD `created_at` DATETIME NULL",
            ];
            foreach ($need as $col => $ddl) {
                if (!in_array($col, $names, true)) {
                    // body قديم → body_text
                    if ($col === 'body_text' && in_array('body', $names, true)) {
                        DB::q("ALTER TABLE notifications CHANGE `body` `body_text` TEXT NULL");
                    } else {
                        DB::q("ALTER TABLE notifications $ddl");
                    }
                }
            }
        } catch (Throwable $e2) {
            error_log('[mawared] notif migrate: ' . $e2->getMessage());
        }

        DB::one("SELECT id FROM activity_log LIMIT 1");
        DB::one("SELECT id, from_user_id, to_user_id, body_text FROM notifications LIMIT 1");
        $ok = true;
        return true;
    } catch (Throwable $e) {
        $ok = false;
        error_log('[mawared] activity tables: ' . $e->getMessage());
        return false;
    }
}

function log_activity($user, $event_type, $title, $detail = null) {
    try {
        if (!ensure_activity_tables()) return;
        DB::q(
            "INSERT INTO activity_log (user_id, user_name, role, event_type, title, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $user ? (int)$user['id'] : null,
                $user['full_name'] ?? ($user['username'] ?? null),
                $user['role'] ?? null,
                mb_substr((string)$event_type, 0, 50),
                mb_substr((string)$title, 0, 255),
                $detail !== null ? mb_substr((string)$detail, 0, 2000) : null,
                date('Y-m-d H:i:s'),
            ]
        );
    } catch (Throwable $e) {
        error_log('[mawared] log_activity: ' . $e->getMessage());
    }
}

if ($action === 'get_call_settings') {
    $u = require_auth();
    ensure_webhooks_table();
    $r = DB::one("SELECT method, call_url, hangup_url, shortcut_name FROM user_webhooks WHERE user_id = ?", [$u['id']]);
    ok([
        'method' => $r['method'] ?? 'android',
        'call_url' => $r['call_url'] ?? '',
        'hangup_url' => $r['hangup_url'] ?? '',
        'shortcut_name' => $r['shortcut_name'] ?? '',
    ]);
}

if ($action === 'save_call_settings') {
    $u = require_auth();
    $in = input();
    $method = trim((string)($in['method'] ?? 'android'));
    if (!in_array($method, ['android', 'iphone'], true)) $method = 'android';
    $callUrl = trim((string)($in['call_url'] ?? ''));
    $hangupUrl = trim((string)($in['hangup_url'] ?? ''));
    $shortcutName = trim((string)($in['shortcut_name'] ?? ''));
    foreach ([$callUrl, $hangupUrl] as $url) {
        if ($url !== '' && !preg_match('#^https://#i', $url)) fail('الرابط لازم يبدأ بـ https://');
        if (mb_strlen($url) > 500) fail('الرابط طويل جداً');
    }
    if (mb_strlen($shortcutName) > 100) fail('اسم الأمر السريع طويل جداً');
    ensure_webhooks_table();
    $exists = DB::one("SELECT user_id FROM user_webhooks WHERE user_id = ?", [$u['id']]);
    $data = [
        'method' => $method,
        'call_url' => $callUrl !== '' ? $callUrl : null,
        'hangup_url' => $hangupUrl !== '' ? $hangupUrl : null,
        'shortcut_name' => $shortcutName !== '' ? $shortcutName : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($exists) DB::update('user_webhooks', $data, 'user_id = :uid', ['uid' => $u['id']]);
    else { $data['user_id'] = $u['id']; DB::insert('user_webhooks', $data); }
    ok(['message' => 'تم حفظ إعدادات الاتصال']);
}

// =============================================================
// 2) الشيتات
// =============================================================
if ($action === 'my_sheet_info') {
    $u = require_auth();
    [$clientScope, $clientParams] = client_scope($u);

    $sheet = DB::one(
        "SELECT id, filename, client_count, uploaded_at, last_download_at, file_size
         FROM user_sheets WHERE user_id = ? AND is_active = 1
         ORDER BY id DESC LIMIT 1",
        [$u['id']]
    );

    // إحصائيات المكالمات (من غير سجلات الزيارات) — الإجمالي + اليوم بس
    $ts = date('Y-m-d 00:00:00');
    $calls = DB::one(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'تم التواصل بنجاح' THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 'تعهد بالسداد' THEN 1 ELSE 0 END) AS ptp,
                SUM(CASE WHEN called_at >= ? THEN 1 ELSE 0 END) AS today_total,
                SUM(CASE WHEN called_at >= ? AND status = 'تم التواصل بنجاح' THEN 1 ELSE 0 END) AS today_success,
                SUM(CASE WHEN called_at >= ? AND status = 'تعهد بالسداد' THEN 1 ELSE 0 END) AS today_ptp
         FROM calls WHERE user_id = ? AND (status IS NULL OR status NOT LIKE 'زيارة%')",
        [$ts, $ts, $ts, $u['id']]
    );

    $pending = DB::one(
        "SELECT COUNT(*) AS n FROM clients c
         WHERE $clientScope
           AND NOT EXISTS (SELECT 1 FROM calls k WHERE k.client_id = c.id AND k.user_id = ? AND k.called_at >= ?)",
        array_merge($clientParams, [$u['id'], $ts])
    );
    if ($sheet && in_array($u['role'], ['collector', 'data_entry'], true)) {
        $sheet['client_count'] = (int)(DB::one(
            "SELECT COUNT(*) AS n FROM clients c WHERE $clientScope",
            $clientParams
        )['n'] ?? 0);
    }

    ok([
        'has_sheet' => (bool)$sheet,
        'sheet' => $sheet,
        'stats' => [
            'total_calls' => (int)($calls['total'] ?? 0),
            'success' => (int)($calls['success'] ?? 0),
            'ptp' => (int)($calls['ptp'] ?? 0),
            'today_total' => (int)($calls['today_total'] ?? 0),
            'today_success' => (int)($calls['today_success'] ?? 0),
            'today_ptp' => (int)($calls['today_ptp'] ?? 0),
            'pending_today' => (int)($pending['n'] ?? 0),
        ]
    ]);
}

// =============================================================
// UPLOAD SHEET — v3.2 مع Smart Auto-Fix
// =============================================================
if ($action === 'upload_sheet') {
    while (ob_get_level()) ob_end_clean();
    ob_start();

    @set_time_limit(300);
    @ini_set('memory_limit', '256M');
    @ini_set('max_execution_time', '300');
    @ini_set('display_errors', '0');

    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $cycleId = (int)($_POST['cycle_id'] ?? 0);
    $collectorFilter = trim((string)($_POST['collector_filter'] ?? ''));
    $ownerId = (int)$u['id'];
    if ($cycleId) {
        if (!in_array($u['role'], ['admin', 'super_admin', 'collector', 'data_entry'], true)) {
            ob_end_clean();
            fail('ليس لديك صلاحية رفع ملفات الدورات', 403);
        }
        if ($collectorFilter === '') {
            ob_end_clean();
            fail('اختار قيمة من عمود coll قبل رفع الشيت');
        }
        $cycle = DB::one("SELECT * FROM collection_cycles WHERE id = ?", [$cycleId]);
        if (!$cycle || !in_array($cycle['status'], ['draft', 'active'], true)) {
            ob_end_clean();
            fail('الدورة غير موجودة أو لا تقبل ملفات جديدة', 409);
        }
        if ($u['role'] === 'super_admin') {
            $ownerId = (int)($_POST['assign_to'] ?? 0);
            if (!$ownerId) {
                ob_end_clean();
                fail('اختر موظفًا لإسناد الملف إليه');
            }
        } elseif ($u['role'] === 'admin') {
            $ownerId = (int)($_POST['assign_to'] ?? 0);
            $team = team_ids($u);
            if (!$ownerId || !in_array($ownerId, $team, true)) {
                ob_end_clean();
                fail('اختر موظفًا من فريقك لإسناد الملف إليه');
            }
            if ((int)$cycle['created_by'] !== (int)$u['id']) {
                ob_end_clean();
                fail('لا يمكنك رفع ملفات لدورة أنشأها مسؤول آخر', 403);
            }
        } else {
            $ownerId = (int)$u['id'];
        }
        if (in_array($u['role'], ['admin', 'super_admin'], true)) {
            $assignment = DB::one(
                "SELECT id, status FROM collection_assignments WHERE cycle_id = ? AND user_id = ?",
                [$cycleId, $ownerId]
            );
            if (!$assignment) {
                ob_end_clean();
                fail('يجب إسناد الدورة إلى الموظف قبل رفع الشيت');
            }
            if (in_array($assignment['status'], ['submitted', 'approved'], true)) {
                ob_end_clean();
                fail('تم تسليم ملف هذا الموظف أو اعتماده بالفعل؛ لا يمكن استبداله');
            }
        } else {
            $ownerId = (int)$u['id'];
            $assignment = DB::one(
                "SELECT id, status FROM collection_assignments WHERE cycle_id = ? AND user_id = ?",
                [$cycleId, $ownerId]
            );
            if (!$assignment || in_array($assignment['status'], ['submitted', 'approved'], true)) {
                ob_end_clean();
                fail('لا يوجد إسناد مفتوح لك في هذه الدورة', 403);
            }
        }
        $owner = DB::one("SELECT id, role, admin_id, is_active FROM users WHERE id = ?", [$ownerId]);
        if (!$owner || !$owner['is_active'] || !in_array($owner['role'], ['collector', 'data_entry'], true)) {
            ob_end_clean();
            fail('الموظف المختار غير نشط أو لا يقبل إسناد ملفات');
        }
        $groupAssignedElsewhere = DB::one(
            "SELECT id FROM clients WHERE cycle_id = ? AND coll = ? AND assigned_to <> ? LIMIT 1",
            [$cycleId, $collectorFilter, $ownerId]
        );
        if ($groupAssignedElsewhere) {
            ob_end_clean();
            fail('مجموعة coll دي متوزعة بالفعل على موظف آخر في نفس الدورة');
        }
        if (!empty($_POST['delete_old']) && (string)$_POST['delete_old'] !== '0') {
            ob_end_clean();
            fail('لا يمكن حذف العملاء عند رفع ملف دورة؛ ارفع الملف دون حذف السجلات السابقة');
        }
    }

    if (empty($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['sheet']['error'] ?? 'no file';
        ob_end_clean();
        fail('لم يتم رفع الملف (كود الخطأ: ' . $err . ')');
    }

    $f = $_FILES['sheet'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') { ob_end_clean(); fail('الملف لازم يكون بصيغة .xlsx'); }
    if ($f['size'] > 20 * 1024 * 1024) { ob_end_clean(); fail('حجم الملف كبير'); }

    $baseDir = __DIR__ . '/uploads/sheets/' . $ownerId;
    $historyDir = $baseDir . '/history';
    if (!is_dir($historyDir)) @mkdir($historyDir, 0755, true);

    // مجلد الشيتات ممنوع الوصول له من الإنترنت (فيه بيانات العملاء)
    $sheetsRoot = __DIR__ . '/uploads/sheets';
    if (!file_exists($sheetsRoot . '/.htaccess')) {
        @file_put_contents($sheetsRoot . '/.htaccess', "Order Allow,Deny\nDeny from all\n");
    }

    if ($cycleId) {
        if (!ensure_private_storage_protection()) {
            ob_end_clean();
            fail('تعذر تأمين مجلد ملفات الدورة؛ راجع صلاحيات الكتابة في htdocs');
        }
        $cycleSourcePath = collection_cycle_source_path($cycleId);
        $cycleSourceDir = dirname($cycleSourcePath);
        if (!is_dir($cycleSourceDir) && !@mkdir($cycleSourceDir, 0755, true)) {
            ob_end_clean();
            fail('تعذر إنشاء مساحة حفظ الشيت الأصلي للدورة');
        }
        if (file_exists($cycleSourcePath)) {
            $storedHash = hash_file('sha256', $cycleSourcePath);
            $uploadHash = hash_file('sha256', $f['tmp_name']);
            if ($storedHash === false || $uploadHash === false || !hash_equals($storedHash, $uploadHash)) {
                ob_end_clean();
                fail('لازم تستخدم نفس نسخة الشيت المجمع في كل عمليات التوزيع داخل الدورة');
            }
        } elseif (!@copy($f['tmp_name'], $cycleSourcePath)) {
            ob_end_clean();
            fail('تعذر حفظ نسخة الشيت المجمع الأصلية للدورة');
        }
    }

    $currentPath = $baseDir . '/current.xlsx';
    if (file_exists($currentPath)) {
        $stamp = date('Ymd_His');
        @copy($currentPath, $historyDir . '/' . $stamp . '_' . preg_replace('/[^\w\.\-]/', '_', $f['name']));
    }

    if (!move_uploaded_file($f['tmp_name'], $currentPath)) {
        ob_end_clean();
        fail('تعذر حفظ الملف');
    }

    // استبدال مع حذف العملاء القدامى (اختياري من الواجهة)
    $deleteOld = !empty($_POST['delete_old']) && (string)$_POST['delete_old'] !== '0';
    $deletedCount = 0;
    if ($deleteOld) {
        try {
            $before = DB::one("SELECT COUNT(*) AS c FROM clients WHERE owner_user_id = ?", [(int)$u['id']]);
            DB::q("DELETE FROM clients WHERE owner_user_id = ?", [(int)$u['id']]);
            $deletedCount = (int)($before['c'] ?? 0);
        } catch (Throwable $e) {
            error_log('[mawared] delete old clients: ' . $e->getMessage());
            ob_end_clean();
            fail('تعذر حذف العملاء القدامى قبل رفع الشيت الجديد');
        }
    }

    // نحتفظ بآخر 5 نسخ بس في الـ history عشان مساحة السيرفر
    $old = glob($historyDir . '/*.xlsx') ?: [];
    if (count($old) > 5) {
        usort($old, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($old, 5) as $oldFile) @unlink($oldFile);
    }

    // ===== تحديد مسار مكتبة SimpleXLSX =====
    $xlsxLib = __DIR__ . '/simple_xlsx/SimpleXLSX.php';
    if (!file_exists($xlsxLib)) $xlsxLib = __DIR__ . '/SimpleXLSX.php';
    if (!file_exists($xlsxLib)) {
        ob_end_clean();
        fail('مكتبة SimpleXLSX.php مش موجودة');
    }
    require_once $xlsxLib;

    try {
        $xlsx = Shuchkin\SimpleXLSX::parse($currentPath);
        if (!$xlsx) { ob_end_clean(); fail('تعذر قراءة الملف'); }
    } catch (Throwable $e) {
        ob_end_clean();
        fail('خطأ في قراءة الإكسيل: ' . $e->getMessage(), 500);
    }

    $rowsToImport = [];
    $seenPhones = [];
    $totalSkipped = 0;
    $autoFixesCount = 0;
    $collectorRowsMatched = 0;
    $collectorRowsSkipped = 0;
    $collectorColumnSeen = false;

    foreach ($xlsx->sheetNames() as $sheetIdx => $sheetName) {
        $rows = $xlsx->rows($sheetIdx);
        if (empty($rows)) continue;

        $header = array_map('trim', $rows[0]);
        $colMap = [];
        foreach ($header as $i => $name) $colMap[$name] = $i;

        // ===== تحقق من الأعمدة المهمة + بدائل =====
        $alternatives = [
            'بطاقة العميل'         => ['الرقم القومي', 'الرقم القومى', 'بطاقه العميل', 'بطاقة العميل '],
            'هاتف العميل'          => ['تليفون العميل', 'رقم التليفون', 'الهاتف', 'رقم الهاتف'],
            'أسم العميل'           => ['اسم العميل', 'الاسم', 'أسم العميل '],
            'عنوان العميل'         => ['العنوان', 'عنوان العميل '],
            'علامة مميزة للعميل'   => ['علامة مميزة', 'علامه مميزه للعميل', 'علامة مميزه للعميل'],
            'Coll'                  => ['coll'],
            'أسم الضامن'           => ['اسم الضامن'],
            'هاتف الضامن'          => ['رقم الضامن'],
            'عنوان الضامن'         => ['عنوان الضامن '],
            'علامة مميزة للضامن'   => ['علامة مميزة للضامن '],
            'عنوان النشاط'         => ['عنوان النشاط '],
            'علامة مميزة للنشاط'   => ['علامة مميزة للنشاط '],
        ];
        foreach ($alternatives as $canonical => $alts) {
            if (!isset($colMap[$canonical])) {
                foreach ($alts as $alt) {
                    if (isset($colMap[$alt])) { $colMap[$canonical] = $colMap[$alt]; break; }
                }
            }
        }
        $normalizedColMap = [];
        foreach ($colMap as $columnName => $columnIndex) {
            $normalizedColMap[mb_strtolower(trim((string)$columnName), 'UTF-8')] = $columnIndex;
        }

        if (!isset($colMap['هاتف العميل'])) continue;
        $collectorColumnIndex = collection_coll_column_index($header);
        if ($cycleId && $collectorColumnIndex === null) {
            ob_end_clean();
            fail('الشيت لا يحتوي على عمود coll المطلوب لتصفية عملاء الموظف');
        }
        if ($cycleId) $collectorColumnSeen = true;

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            if ($cycleId) {
                $rowCollector = trim((string)($row[$collectorColumnIndex] ?? ''));
                if ($rowCollector !== $collectorFilter) {
                    $collectorRowsSkipped++;
                    continue;
                }
                $collectorRowsMatched++;
            }
            $get = function($key) use ($colMap, $normalizedColMap, $row) {
                $index = $colMap[$key] ?? ($normalizedColMap[mb_strtolower(trim((string)$key), 'UTF-8')] ?? null);
                return $index !== null ? trim((string)($row[$index] ?? '')) : '';
            };

            // =====================================================
            // ✨ Smart Auto-Fix
            // =====================================================
            $rawGuarPhone   = $get('هاتف الضامن');
            $rawGuarContact = $get('اتصال بالضامن');
            $rawGuarRel     = $get('علاقة العميل بالضامن');
            $rawAddress     = $get('عنوان العميل');
            $rawLandmark    = $get('علامة مميزة للعميل');

            $isPhone = function($v) {
                $d = preg_replace('/\D/', '', (string)$v);
                return strlen($d) >= 8 && strlen($d) <= 15;
            };
            $isRelation = function($v) {
                $v = trim((string)$v);
                if ($v === '') return false;
                $relations = ['أب','اب','أخ','اخ','زوج','زوجة','أم','ام','إبن','ابن','إبنة','ابنة','صديق','أخت','اخت','عم','خال','قريب','جدة','جد','شقيق','شقيقه','والد','والده'];
                if (in_array($v, $relations)) return true;
                if (mb_strlen($v) <= 8 && !preg_match('/[0-9]/', $v)
                    && !preg_match('/(ش|شارع|منزل|عمارة|دور|بجوار|أمام|امام|خلف|منطقة|حي|عزبة|عزبه|طريق|ميدان)/u', $v)) {
                    return true;
                }
                return false;
            };
            $isAddress = function($v) {
                $v = trim((string)$v);
                if (mb_strlen($v) < 5) return false;
                return preg_match('/(شارع|ش\s|منزل|عمارة|عماره|دور|بجوار|أمام|امام|خلف|بعد|قبل|منطقة|حي|عزبة|عزبه|طريق|ميدان|مك|بندر|مركز|قريه|قرية|كفر|نجع)/u', $v) > 0;
            };

            $fixApplied = false;

            if (!$isPhone($rawGuarPhone) && $isPhone($rawGuarContact)) {
                $rawGuarPhone = $rawGuarContact;
                $rawGuarContact = '';
                $fixApplied = true;
            }
            if ($isRelation($rawGuarContact) && !$isRelation($rawGuarRel)) {
                if ($isAddress($rawGuarRel) && !$rawAddress) {
                    $rawAddress = $rawGuarRel;
                }
                $rawGuarRel = $rawGuarContact;
                $rawGuarContact = '';
                $fixApplied = true;
            }
            if ($isAddress($rawGuarRel)) {
                if (!$rawAddress) $rawAddress = $rawGuarRel;
                elseif (!$rawLandmark) $rawLandmark = $rawGuarRel;
                $rawGuarRel = '';
                $fixApplied = true;
            }
            if ($rawLandmark && $rawAddress && $rawLandmark === $rawAddress) {
                $rawLandmark = '';
                $fixApplied = true;
            }
            if (!$rawAddress && $rawLandmark && $isAddress($rawLandmark)) {
                $rawAddress = $rawLandmark;
                $rawLandmark = '';
                $fixApplied = true;
            }

            if ($fixApplied) $autoFixesCount++;
            // =====================================================

            $phone = clean_phone($get('هاتف العميل'));
            if (strlen($phone) < 5) { $totalSkipped++; continue; }
            if (isset($seenPhones[$phone])) { $totalSkipped++; continue; }
            $seenPhones[$phone] = true;

            $rowsToImport[] = [
                'sheet_name'   => $sheetName,
                'excel_row'    => $r + 1,
                'phone'        => $phone,
                'name'         => $get('أسم العميل'),
                'nid'          => $get('بطاقة العميل'),
                'loan_number'  => $get('رقم القرض'),
                'client_number'=> $get('رقم العميل'),
                'coll'         => $get('Coll'),
                's_v'          => $get('S.V'),
                'keep_field'   => $get('keep'),
                'execution_date' => $get('تاريخ الاعدام'),
                'branch'       => $get('الفرع'),
                'governorate'  => $get('المحافظة'),
                'visit_flag'   => $get('زياره'),
                'feedback'     => $get('الفيدباك'),
                'client_contact' => $get('اتصال بالعميل'),
                'paid'         => $get('paied'),
                'paid_date'    => $get('paied date'),
                'broken'       => $get('broken'),
                'comment'      => $get('comment'),
                'data_field'   => $get('data'),
                'installment'  => floatval(preg_replace('/[^\d.]/', '', $get('القسط'))),
                'grant_amount' => floatval(preg_replace('/[^\d.]/', '', $get('مبلغ المنح'))),
                'loan_balance' => floatval(preg_replace('/[^\d.]/', '', $get('رصيد القرض'))),
                'interest_fine_balance' => floatval(preg_replace('/[^\d.]/', '', $get('رصيد الفائدة والغرامة'))),
                'interest'     => floatval(preg_replace('/[^\d.]/', '', $get('الفائدة'))),
                'fine'         => floatval(preg_replace('/[^\d.]/', '', $get('الغرامة'))),
                'guarantor_card' => $get('بطاقة الضامن'),
                'guarantor_name' => $get('أسم الضامن'),
                'guarantor_phone' => clean_phone($rawGuarPhone),
                'guarantor_contact' => $rawGuarContact,
                'guarantor_relation' => $rawGuarRel,
                'address'      => $rawAddress,
                'landmark'     => $rawLandmark,
                'guarantor_address' => $get('عنوان الضامن'),
                'guarantor_landmark' => $get('علامة مميزة للضامن'),
                'activity_address' => $get('عنوان النشاط'),
                'activity_landmark' => $get('علامة مميزة للنشاط'),
                'delay_days'   => intval(preg_replace('/[^\d]/', '', $get('عدد ايام التأخير'))),
                'grant_date'   => $get('تاريخ المنح'),
                'late_installment_date' => $get('تاريخ القسط المتأخر'),
                'portfolio'    => $get('Portfolio'),
                'finance_officer' => $get('مسؤول التمويل'),
                'sep_owner'    => $get('Sep Owner'),
                'legal_num'    => $get('Legal Num'),
                'legal_status' => $get('Legal Action Status'),
                'status'       => $get('الحاله'),
                'ptp'          => $get('ptp'),
                'ptp_date'     => $get('ptp date'),
                'dcr'          => $get('DCR'),
            ];
        }
    }

    if ($cycleId && !$collectorColumnSeen) {
        ob_end_clean();
        fail('لم يتم العثور على ورقة عمل تحتوي على عمود هاتف العميل وعمود coll');
    }
    if ($cycleId && !$collectorRowsMatched) {
        ob_end_clean();
        fail('لا توجد صفوف مطابقة للقيمة المختارة في عمود coll');
    }

    $totalImported = 0;
    $failedRows = 0;
    $protectedDuplicates = 0;

    if (!empty($rowsToImport)) {
        $chunks = array_chunk($rowsToImport, 250);

        foreach ($chunks as $chunk) {
            if ($cycleId) {
                $phones = array_values(array_unique(array_column($chunk, 'phone')));
                $existing = DB::all(
                    "SELECT phone, cycle_id FROM clients WHERE owner_user_id = ? AND phone IN (" . in_list($phones) . ")",
                    array_merge([$ownerId], $phones)
                );
                $existingCycles = [];
                foreach ($existing as $row) $existingCycles[(string)$row['phone']] = $row['cycle_id'] === null ? null : (int)$row['cycle_id'];
                $chunk = array_values(array_filter($chunk, function ($row) use ($existingCycles, $cycleId, &$protectedDuplicates) {
                    $phone = (string)$row['phone'];
                    if (array_key_exists($phone, $existingCycles) && $existingCycles[$phone] !== null && $existingCycles[$phone] !== $cycleId) {
                        $protectedDuplicates++;
                        return false;
                    }
                    return true;
                }));
            }
            if (!$chunk) continue;
            $values = [];
            $params = [];

            foreach ($chunk as $row) {
                $values[] = $cycleId
                    ? "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    : "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params[] = $ownerId;
                $params[] = $ownerId;
                if ($cycleId) $params[] = $cycleId;
                $params[] = $row['sheet_name'];
                $params[] = $row['excel_row'];
                $params[] = $row['phone'];
                $params[] = $row['name'];
                $params[] = $row['nid'];
                $params[] = $row['loan_number'];
                $params[] = $row['client_number'];
                $params[] = $row['coll'];
                $params[] = $row['s_v'];
                $params[] = $row['keep_field'];
                $params[] = $row['execution_date'];
                $params[] = $row['branch'];
                $params[] = $row['governorate'];
                $params[] = $row['visit_flag'];
                $params[] = $row['feedback'];
                $params[] = $row['client_contact'];
                $params[] = $row['paid'];
                $params[] = $row['paid_date'];
                $params[] = $row['broken'];
                $params[] = $row['comment'];
                $params[] = $row['data_field'];
                $params[] = $row['installment'];
                $params[] = $row['grant_amount'];
                $params[] = $row['loan_balance'];
                $params[] = $row['interest_fine_balance'];
                $params[] = $row['interest'];
                $params[] = $row['fine'];
                $params[] = $row['guarantor_card'];
                $params[] = $row['guarantor_name'];
                $params[] = $row['guarantor_phone'];
                $params[] = $row['guarantor_contact'];
                $params[] = $row['guarantor_relation'];
                $params[] = $row['address'];
                $params[] = $row['landmark'];
                $params[] = $row['guarantor_address'];
                $params[] = $row['guarantor_landmark'];
                $params[] = $row['activity_address'];
                $params[] = $row['activity_landmark'];
                $params[] = $row['delay_days'];
                $params[] = $row['grant_date'];
                $params[] = $row['late_installment_date'];
                $params[] = $row['portfolio'];
                $params[] = $row['finance_officer'];
                $params[] = $row['sep_owner'];
                $params[] = $row['legal_num'];
                $params[] = $row['legal_status'];
                $params[] = $row['status'];
                $params[] = $row['ptp'];
                $params[] = $row['ptp_date'];
                $params[] = $row['dcr'];
            }

            $sql = "INSERT INTO clients
                    (owner_user_id, assigned_to, " . ($cycleId ? "cycle_id, " : "") . "sheet_name, excel_row, phone, name, nid,
                     loan_number, client_number, coll, s_v, keep_field, execution_date, branch, governorate,
                     visit_flag, feedback, client_contact, paid, paid_date, broken, comment, data_field,
                     installment, grant_amount, loan_balance, interest_fine_balance, interest, fine,
                     guarantor_card, guarantor_name, guarantor_phone, guarantor_contact, guarantor_relation,
                     address, landmark, guarantor_address, guarantor_landmark,
                     activity_address, activity_landmark, delay_days, grant_date, late_installment_date,
                     portfolio, finance_officer, sep_owner, legal_num, legal_status,
                     status, ptp, ptp_date, dcr)
                    VALUES " . implode(',', $values) . "
                    ON DUPLICATE KEY UPDATE
                        " . ($cycleId ? "cycle_id = IF(cycle_id IS NULL OR cycle_id = VALUES(cycle_id), VALUES(cycle_id), cycle_id), " : "") . "
                        assigned_to = VALUES(assigned_to),
                        excel_row = VALUES(excel_row),
                        name = VALUES(name),
                        nid = VALUES(nid),
                        loan_number = VALUES(loan_number),
                        client_number = VALUES(client_number),
                        coll = VALUES(coll),
                        s_v = VALUES(s_v),
                        keep_field = VALUES(keep_field),
                        execution_date = VALUES(execution_date),
                        branch = VALUES(branch),
                        governorate = VALUES(governorate),
                        visit_flag = VALUES(visit_flag),
                        feedback = VALUES(feedback),
                        client_contact = VALUES(client_contact),
                        paid = VALUES(paid),
                        paid_date = VALUES(paid_date),
                        broken = VALUES(broken),
                        comment = VALUES(comment),
                        data_field = VALUES(data_field),
                        installment = VALUES(installment),
                        grant_amount = VALUES(grant_amount),
                        loan_balance = VALUES(loan_balance),
                        interest_fine_balance = VALUES(interest_fine_balance),
                        interest = VALUES(interest),
                        fine = VALUES(fine),
                        guarantor_card = VALUES(guarantor_card),
                        guarantor_name = VALUES(guarantor_name),
                        guarantor_phone = VALUES(guarantor_phone),
                        guarantor_contact = VALUES(guarantor_contact),
                        guarantor_relation = VALUES(guarantor_relation),
                        address = VALUES(address),
                        landmark = VALUES(landmark),
                        guarantor_address = VALUES(guarantor_address),
                        guarantor_landmark = VALUES(guarantor_landmark),
                        activity_address = VALUES(activity_address),
                        activity_landmark = VALUES(activity_landmark),
                        delay_days = VALUES(delay_days),
                        grant_date = VALUES(grant_date),
                        late_installment_date = VALUES(late_installment_date),
                        portfolio = VALUES(portfolio),
                        finance_officer = VALUES(finance_officer),
                        sep_owner = VALUES(sep_owner),
                        legal_num = VALUES(legal_num),
                        legal_status = VALUES(legal_status)";

            try {
                DB::q($sql, $params);
                $totalImported += count($chunk);
            } catch (Throwable $e) {
                // بنكمّل باقي المجموعات، بس بنعدّ الصفوف اللي فشلت ونبلّغ بيها
                $failedRows += count($chunk);
                error_log('[mawared] import chunk failed: ' . $e->getMessage());
            }
        }
    }

    if ($cycleId && $totalImported === 0) {
        ob_end_clean();
        fail($failedRows > 0
            ? 'تعذر استيراد أي عميل من المجموعة؛ راجع بيانات الهاتف ثم أعد المحاولة'
            : 'لا توجد عملاء صالحون للاستيراد في مجموعة coll المختارة');
    }

    if ($cycleId) {
        $cycleClientCount = (int)(DB::one(
            "SELECT COUNT(*) AS n FROM clients WHERE cycle_id = ? AND assigned_to = ?",
            [$cycleId, $ownerId]
        )['n'] ?? 0);
        DB::q(
            "UPDATE collection_assignments
             SET assigned_count = ?, uploaded_count = ?, status = 'working', updated_at = NOW()
             WHERE cycle_id = ? AND user_id = ?",
            [$cycleClientCount, $cycleClientCount, $cycleId, $ownerId]
        );
    }

    try {
        DB::conn()->beginTransaction();
        DB::q("UPDATE user_sheets SET is_active = 0 WHERE user_id = ?", [$ownerId]);
        DB::insert('user_sheets', [
            'user_id' => $ownerId,
            'filename' => $f['name'],
            'file_path' => $currentPath,
            'file_size' => $f['size'],
            'client_count' => $totalImported,
            'is_active' => 1,
        ]);
        DB::insert('sheet_log', [
            'user_id' => $u['id'],
            'cycle_id' => $cycleId ?: null,
            'action' => 'upload',
            'filename' => $f['name'],
            'client_count' => $totalImported,
        ]);
        DB::conn()->commit();
    } catch (Throwable $e) {
        if (DB::conn()->inTransaction()) DB::conn()->rollBack();
        error_log('[mawared] sheet metadata save failed: ' . $e->getMessage());
        ob_end_clean();
        fail('تم استيراد العملاء، لكن تعذر تسجيل ملف الشيت في حساب الموظف. أبلغ مسؤول النظام قبل إعادة الرفع.', 500);
    }

    log_activity($u, 'sheet', 'رفع شيت', ($f['name'] ?? '') . ' — ' . $totalImported . ' عميل');

    ob_end_clean();
    $delMsg = ($deleteOld && $deletedCount > 0) ? " (اتمسح $deletedCount عميل قديم)" : ($deleteOld ? " (اتمسحت قائمة العملاء القديمة)" : "");
    ok([
        'message' => "✅ تم رفع الشيت واستيراد $totalImported عميل" . $delMsg .
                     ($autoFixesCount > 0 ? " (تم تصحيح $autoFixesCount صف تلقائيًا)" : "") .
                     (($totalSkipped + $protectedDuplicates) > 0 ? " (تم تجاهل " . ($totalSkipped + $protectedDuplicates) . " صف — رقم فاضي أو مكرر أو محفوظ في دورة سابقة)" : "") .
                     ($failedRows > 0 ? " ⚠️ تعذر استيراد $failedRows صف — ارفع الشيت تاني أو راجع الصفوف" : ""),
        'client_count' => $totalImported,
        'failed' => $failedRows,
        'auto_fixes' => $autoFixesCount,
        'skipped' => $totalSkipped,
        'protected_duplicates' => $protectedDuplicates,
        'collector_rows_matched' => $collectorRowsMatched,
        'collector_rows_skipped' => $collectorRowsSkipped,
        'deleted_old' => $deletedCount,
    ]);
}

// =============================================================
// DOWNLOAD SHEET
// =============================================================
if ($action === 'download_sheet') {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');
    $u = require_auth();

    $s = DB::one(
        "SELECT * FROM user_sheets WHERE user_id = ? AND is_active = 1
         ORDER BY id DESC LIMIT 1",
        [$u['id']]
    );
    if (!$s) fail('مفيش شيت مرفوع — ارفع الأول');
    $cycleId = in_array($u['role'], ['collector', 'data_entry'], true)
        ? employee_active_collection_cycle((int)$u['id'])
        : null;
    $sourcePath = $cycleId && is_file(collection_cycle_source_path($cycleId))
        ? collection_cycle_source_path($cycleId)
        : $s['file_path'];
    if (!file_exists($sourcePath)) fail('الملف الأصلي مفقود');

    $xlsxLib = __DIR__ . '/simple_xlsx/SimpleXLSX.php';
    if (!file_exists($xlsxLib)) $xlsxLib = __DIR__ . '/SimpleXLSX.php';
    require_once $xlsxLib;

    $genLib = __DIR__ . '/simple_xlsx/SimpleXLSXGen.php';
    if (!file_exists($genLib)) $genLib = __DIR__ . '/SimpleXLSXGen.php';
    require_once $genLib;

    $xlsx = Shuchkin\SimpleXLSX::parse($sourcePath);
    if (!$xlsx) fail('تعذر قراءة الملف');

    if ($cycleId) {
        $cs = DB::all(
            "SELECT sheet_name, excel_row, status, ptp, ptp_date, dcr
             FROM clients WHERE cycle_id = ? AND assigned_to = ?",
            [$cycleId, $u['id']]
        );
    } elseif (in_array($u['role'], ['collector', 'data_entry'], true)) {
        $cs = DB::all(
            "SELECT sheet_name, excel_row, phone, status, ptp, ptp_date, dcr
             FROM clients WHERE cycle_id IS NULL AND assigned_to = ?",
            [$u['id']]
        );
    } else {
        $cs = DB::all(
            "SELECT sheet_name, excel_row, phone, status, ptp, ptp_date, dcr
             FROM clients WHERE owner_user_id = ?",
            [$u['id']]
        );
    }

    $byKey = [];
    $byRow = [];
    foreach ($cs as $c) {
        if ($cycleId) {
            $byRow[(string)$c['sheet_name']][(int)$c['excel_row']] = $c;
        } else {
            $byKey[$c['sheet_name'] . '|' . $c['phone']] = $c;
        }
    }

    $allData = [];
    foreach ($xlsx->sheetNames() as $idx => $sheetName) {
        $rows = $xlsx->rows($idx);
        if (empty($rows)) { $allData[$sheetName] = [['']]; continue; }

        $header = array_map(fn($h) => trim((string)$h), $rows[0]);
        $width = count($header);
        $cols = [
            'phone' => array_search('هاتف العميل', $header),
            'status' => array_search('الحاله', $header),
            'ptp' => array_search('ptp', $header),
            'ptpDate' => array_search('ptp date', $header),
            'dcr' => array_search('DCR', $header),
        ];
        foreach ($cols as $k => $ci) { if ($ci !== false && $ci >= $width) $width = $ci + 1; }

        $cleanRows = [];
        foreach ($rows as $r => $row) {
            // نضمن إن كل الصفوف بنفس عدد الأعمدة وبنفس الترتيب
            $row = array_pad(array_values($row), $width, '');
            if ($cycleId && $r > 0) {
                $c = $byRow[$sheetName][$r + 1] ?? null;
                if (!$c) continue;
                if ($cols['status'] !== false)  $row[$cols['status']]  = $c['status'] ?? '';
                if ($cols['ptp'] !== false)     $row[$cols['ptp']]     = $c['ptp'] ?? '';
                if ($cols['ptpDate'] !== false) $row[$cols['ptpDate']] = $c['ptp_date'] ?? '';
                if ($cols['dcr'] !== false)     $row[$cols['dcr']]     = $c['dcr'] ?? '';
            } elseif ($r > 0 && $cols['phone'] !== false) {
                $key = $sheetName . '|' . clean_phone($row[$cols['phone']] ?? '');
                if (isset($byKey[$key])) {
                    $c = $byKey[$key];
                    if ($cols['status'] !== false)  $row[$cols['status']]  = $c['status'] ?? '';
                    if ($cols['ptp'] !== false)     $row[$cols['ptp']]     = $c['ptp'] ?? '';
                    if ($cols['ptpDate'] !== false) $row[$cols['ptpDate']] = $c['ptp_date'] ?? '';
                    if ($cols['dcr'] !== false)     $row[$cols['dcr']]     = $c['dcr'] ?? '';
                }
            }
            $cleanRows[] = array_map(fn($cell) => is_null($cell) ? '' : (string)$cell, $row);
        }
        $allData[$sheetName] = $cleanRows;
    }

    $gen = new Shuchkin\SimpleXLSXGen();
    foreach ($allData as $name => $rows) {
        $gen->addSheet($rows, $name);
    }

    $outDir = __DIR__ . '/uploads/sheets/' . (int)$u['id'];
    if (!is_dir($outDir)) mkdir($outDir, 0755, true);
    $outFile = $outDir . '/export_' . date('Ymd_His') . '.xlsx';
    $gen->saveAs($outFile);

    DB::update('user_sheets', ['last_download_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $s['id']]);
    DB::insert('sheet_log', [
        'user_id' => $u['id'],
        'action' => 'download',
        'filename' => basename($outFile),
        'client_count' => count($byKey),
    ]);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($outFile) . '"');
    header('Content-Length: ' . filesize($outFile));
    header('Cache-Control: no-store');
    readfile($outFile);
    @unlink($outFile); // الملف المؤقت بيتمسح بعد التحميل (كان بيتراكم على السيرفر)
    exit;
}

if ($action === 'team_sheets') {
    $u = require_auth(['super_admin', 'admin']);

    if ($u['role'] === 'admin') {
        $rows = DB::all(
            "SELECT us.*, u.full_name, u.role
             FROM user_sheets us
             JOIN users u ON u.id = us.user_id
             WHERE us.is_active = 1 AND (u.admin_id = ? OR u.id = ?)
             ORDER BY us.uploaded_at DESC",
            [$u['id'], $u['id']]
        );
    } else {
        $rows = DB::all(
            "SELECT us.*, u.full_name, u.role
             FROM user_sheets us
             JOIN users u ON u.id = us.user_id
             WHERE us.is_active = 1
             ORDER BY us.uploaded_at DESC"
        );
    }
    ok(['sheets' => $rows]);
}

// =============================================================
// 3) العملاء
// =============================================================
if ($action === 'get_customer') {
    $u = require_auth();
    $ph = clean_phone($_GET['phone'] ?? '');
    if (!$ph) fail('رقم غير صحيح');

    [$cs, $cp] = client_scope($u);
    $c = DB::one("SELECT c.* FROM clients c WHERE c.phone = ? AND $cs LIMIT 1", array_merge([$ph], $cp));

    // لو الرقم مش لعميل، ندور عليه كرقم ضامن (ونبلّغ الشاشة عشان تنبّه المستخدم)
    $viaGuarantor = false;
    if (!$c) {
        $c = DB::one("SELECT c.* FROM clients c WHERE c.guarantor_phone = ? AND $cs LIMIT 1", array_merge([$ph], $cp));
        $viaGuarantor = (bool)$c;
    }

    if (!$c) fail('العميل مش موجود في شيتك — تأكد إنك رفعت الشيت', 404);

    $todayCall = DB::one(
        "SELECT called_at FROM calls
         WHERE client_id = ? AND user_id = ? AND called_at >= ?
         ORDER BY called_at DESC LIMIT 1",
        [$c['id'], $u['id'], date('Y-m-d 00:00:00')]
    );

    $c['already_contacted_today'] = (bool)$todayCall;
    $c['last_contact_time'] = $todayCall['called_at'] ?? null;
    $c['matched_guarantor'] = $viaGuarantor;
    $c['total_debt'] = (float)($c['interest_fine_balance'] ?? 0) > 0 ? (float)$c['interest_fine_balance'] : (float)($c['loan_balance'] ?? 0);

    ok(['data' => $c]);
}

if ($action === 'search_customer') {
    $u = require_auth();
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) ok(['results' => []]);

    [$cs, $cp] = client_scope($u);
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $rows = DB::all(
        "SELECT c.id, c.phone, c.name, c.sheet_name, c.delay_days, c.status,
                c.guarantor_name, c.guarantor_phone,
                (CASE WHEN c.name LIKE ? OR c.phone LIKE ? OR c.nid LIKE ? THEN 0 ELSE 1 END) AS via_guarantor
         FROM clients c
         WHERE $cs
           AND (c.name LIKE ? OR c.phone LIKE ? OR c.nid LIKE ? OR c.guarantor_name LIKE ? OR c.guarantor_phone LIKE ?)
         ORDER BY via_guarantor ASC, c.delay_days DESC
         LIMIT 15",
        array_merge([$like, $like, $like], $cp, [$like, $like, $like, $like, $like])
    );
    ok(['results' => $rows]);
}

if ($action === 'next_customer') {
    $u = require_auth();
    $from = date('Y-m-d 00:00:00');
    $notCalledToday = "NOT EXISTS (SELECT 1 FROM calls k WHERE k.client_id = c.id AND k.user_id = ? AND k.called_at >= ?)";
    [$clientScope, $clientParams] = client_scope($u);

    $c = DB::one(
        "SELECT c.* FROM clients c
         WHERE $clientScope AND $notCalledToday
         ORDER BY c.delay_days DESC, c.interest_fine_balance DESC
         LIMIT 1",
        array_merge($clientParams, [$u['id'], $from])
    );
    if (!$c) fail('تم الانتهاء من كل عملائك المستهدفين اليوم 🎉', 404);

    $remaining = (int)(DB::one(
        "SELECT COUNT(*) AS n FROM clients c WHERE $clientScope AND $notCalledToday",
        array_merge($clientParams, [$u['id'], $from])
    )['n'] ?? 0);

    $c['already_contacted_today'] = false;
    $c['matched_guarantor'] = false;
    $c['total_debt'] = (float)($c['interest_fine_balance'] ?? 0) > 0 ? (float)$c['interest_fine_balance'] : (float)($c['loan_balance'] ?? 0);
    ok(['data' => $c, 'remaining' => $remaining]);
}

if ($action === 'update_customer') {
    $u = require_auth();
    $in = input();

    $cid = (int)($in['client_id'] ?? 0);
    if (!$cid) fail('client_id مطلوب');

    [$cs, $cp] = client_scope($u);
    $c = DB::one("SELECT c.* FROM clients c WHERE c.id = ? AND $cs", array_merge([$cid], $cp));
    if (!$c) fail('مش عندك صلاحية على العميل ده', 403);

    $status = mb_substr(trim($in['status'] ?? ''), 0, 100);
    $ptp = mb_substr(trim((string)($in['ptp'] ?? '')), 0, 100);
    $ptpDate = mb_substr(trim((string)($in['ptp_date'] ?? '')), 0, 50);
    $dcrNew = trim((string)($in['dcr'] ?? ''));

    if ($status === '' && $dcrNew === '') fail('اختر حالة المكالمة أو اكتب ملاحظة');

    $dcr = $c['dcr'] ?? '';
    if ($dcrNew) $dcr = $dcr ? ($dcr . ' / ' . $dcrNew) : $dcrNew;

    $update = ['needs_sync' => 1];
    if ($status)  $update['status'] = $status;
    if ($ptp)     $update['ptp'] = $ptp;
    if ($ptpDate) $update['ptp_date'] = $ptpDate;
    if ($dcrNew)  $update['dcr'] = $dcr;

    DB::update('clients', $update, 'id = :id', ['id' => $cid]);

    DB::insert('calls', [
        'user_id' => $u['id'],
        'client_id' => $cid,
        'phone' => $c['phone'],
        'customer_name' => $c['name'],
        'sheet_name' => $c['sheet_name'],
        'status' => $status,
        'ptp' => $ptp,
        'ptp_date' => $ptpDate,
        'dcr' => $dcrNew,
    ]);

    $stageMap = [
        'تم التواصل بنجاح' => 'contacted',
        'لم يتم الرد' => 'contacted',
        'الخط مشغول' => 'contacted',
        'تعهد بالسداد' => 'promise',
    ];
    $newStage = $stageMap[$status] ?? null;
    $clientUpdate = ['last_activity_at' => date('Y-m-d H:i:s')];
    if ($newStage !== null) $clientUpdate['collection_state'] = $newStage;
    DB::update('clients', $clientUpdate, 'id = :id', ['id' => $cid]);
    collection_log_event(
        $c['cycle_id'] ?? null,
        $cid,
        (int)$u['id'],
        'call',
        $c['collection_state'] ?? 'new',
        $newStage ?? ($c['collection_state'] ?? 'new'),
        trim(($status !== '' ? $status : 'ملاحظة اتصال') . ($dcrNew !== '' ? ' — ' . mb_substr($dcrNew, 0, 1000) : ''))
    );

    if ($status === 'تعهد بالسداد' && $ptp !== '' && is_numeric($ptp) && (float)$ptp > 0 && $ptpDate !== '') {
        $promiseDate = DateTime::createFromFormat('!Y-m-d', $ptpDate);
        $dateErrors = DateTime::getLastErrors();
        if ($promiseDate && (!$dateErrors || (!$dateErrors['warning_count'] && !$dateErrors['error_count'])) && $promiseDate->format('Y-m-d') === $ptpDate) {
            $existingPromise = DB::one(
                "SELECT id FROM collection_promises
                 WHERE client_id = ? AND created_by = ? AND amount = ? AND due_date = ? AND status = 'open'
                 LIMIT 1",
                [$cid, (int)$u['id'], (float)$ptp, $ptpDate]
            );
            if (!$existingPromise) {
                DB::insert('collection_promises', [
                    'client_id' => $cid,
                    'created_by' => (int)$u['id'],
                    'amount' => (float)$ptp,
                    'due_date' => $ptpDate,
                    'status' => 'open',
                    'notes' => $dcrNew !== '' ? mb_substr($dcrNew, 0, 2000) : null,
                ]);
                DB::insert('collection_tasks', [
                    'client_id' => $cid,
                    'assigned_to' => (int)$u['id'],
                    'created_by' => (int)$u['id'],
                    'task_type' => 'promise_follow_up',
                    'priority' => 'high',
                    'due_at' => $ptpDate . ' 10:00:00',
                    'status' => 'open',
                    'title' => 'متابعة تعهد بالسداد',
                    'notes' => 'مبلغ التعهد: ' . number_format((float)$ptp, 2, '.', '') . ' ج.م',
                ]);
            }
        }
    }

    log_activity($u, 'call', 'مكالمة: ' . ($c['name'] ?? ''), ($status ?: 'بدون حالة') . ($dcrNew ? ' — ' . mb_substr($dcrNew, 0, 80) : ''));

    ok(['message' => 'تم الحفظ في شيتك']);
}

// =============================================================
// ADD CLIENT
// =============================================================
if ($action === 'add_client') {
    $u = require_auth(['admin', 'super_admin']);
    $in = input();

    $name = trim($in['name'] ?? '');
    $phone = clean_phone($in['phone'] ?? '');
    if (!$name) fail('الاسم مطلوب');

    if ($phone) {
        $ex = DB::one("SELECT id FROM clients WHERE owner_user_id = ? AND phone = ?", [$u['id'], $phone]);
        if ($ex) fail('العميل موجود مسبقاً بنفس الرقم في شيتك');
    }

    $id = DB::insert('clients', [
        'sheet_name' => 'manual',
        'owner_user_id' => $u['id'],
        'assigned_to' => $u['id'],
        'name' => $name,
        'phone' => $phone !== '' ? $phone : null,
        'nid' => trim($in['nid'] ?? ''),
        'address' => trim($in['address'] ?? ''),
        'landmark' => trim($in['landmark'] ?? ''),
        'loan_balance' => floatval($in['loan_balance'] ?? 0),
        'interest_fine_balance' => floatval($in['interest_fine_balance'] ?? 0),
        'installment' => floatval($in['installment'] ?? 0),
        'delay_days' => (int)($in['delay_days'] ?? 0),
        'legal_num' => trim($in['case_number'] ?? ''),
        'legal_status' => trim($in['verdict'] ?? ''),
        'guarantor_name' => trim($in['guarantor_name'] ?? ''),
        'guarantor_phone' => clean_phone($in['guarantor_phone'] ?? ''),
        'guarantor_relation' => trim($in['guarantor_relation'] ?? ''),
        'loan_number' => trim($in['loan_number'] ?? ''),
        'client_number' => trim($in['client_number'] ?? ''),
        'grant_amount' => floatval($in['grant_amount'] ?? 0),
        'interest' => floatval($in['interest'] ?? 0),
        'fine' => floatval($in['fine'] ?? 0),
        'guarantor_card' => trim($in['guarantor_card'] ?? ''),
        'guarantor_address' => trim($in['guarantor_address'] ?? ''),
        'guarantor_landmark' => trim($in['guarantor_landmark'] ?? ''),
        'activity_address' => trim($in['activity_address'] ?? ''),
        'activity_landmark' => trim($in['activity_landmark'] ?? ''),
        'grant_date' => trim($in['grant_date'] ?? ''),
        'late_installment_date' => trim($in['late_installment_date'] ?? ''),
        'branch' => trim($in['branch'] ?? ''),
        'governorate' => trim($in['governorate'] ?? ''),
        'portfolio' => trim($in['portfolio'] ?? ''),
        'finance_officer' => trim($in['finance_officer'] ?? ''),
        'sep_owner' => trim($in['sep_owner'] ?? ''),
    ]);

    ok(['id' => $id, 'message' => 'تم إضافة العميل']);
}

// =============================================================
// 4) الزيارات
// =============================================================
if ($action === 'get_my_visits') {
    $u = require_auth();
    $filter = $_GET['filter'] ?? 'today';
    $today = date('Y-m-d');
    [$scopeSql, $scopeP] = visit_scope($u);

    $w = [$scopeSql];
    $p = $scopeP;

    if ($filter === 'today') {
        $w[] = 'v.visit_date = ?';
        $p[] = $today;
    } elseif ($filter === 'done') {
        $w[] = "v.status IN ('done','paid','partial')";
    } elseif ($filter === 'pending') {
        $w[] = SQL_VISIT_PENDING;
    } elseif ($filter === 'overdue') {
        $w[] = SQL_VISIT_PENDING;
        $w[] = 'v.visit_date < ?';
        $p[] = $today;
    }

    // زيارات النهارده: الباقي الأول، والمنتهي في الآخر
    $order = ($filter === 'today')
        ? "(CASE WHEN v.status IN ('done','paid','partial') THEN 1 ELSE 0 END) ASC, v.id ASC"
        : "v.visit_date DESC, v.id DESC";

    $sql = "SELECT v.*, c.name, c.phone, c.address, c.landmark, c.nid,
                   c.id_front_image, c.id_back_image,
                   c.loan_balance, c.interest_fine_balance, c.interest, c.fine,
                   IF(c.interest_fine_balance > 0, c.interest_fine_balance, c.loan_balance) AS total_debt,
                   c.installment, c.delay_days, c.legal_num, c.legal_status,
                   c.guarantor_name, c.guarantor_phone, c.guarantor_relation, c.guarantor_address,
                   c.activity_address, c.activity_landmark, c.loan_number, c.governorate,
                   u.full_name AS collector_name
            FROM visits v
            JOIN clients c ON c.id = v.client_id
            LEFT JOIN users u ON u.id = v.collector_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY $order
            LIMIT 300";

    $st = DB::one(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN v.status IN ('done','paid','partial') THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN " . SQL_VISIT_PENDING . " THEN 1 ELSE 0 END) AS pending
         FROM visits v WHERE $scopeSql AND v.visit_date = ?",
        array_merge($scopeP, [$today])
    );
    $od = DB::one(
        "SELECT COUNT(*) AS n FROM visits v WHERE $scopeSql AND " . SQL_VISIT_PENDING . " AND v.visit_date < ?",
        array_merge($scopeP, [$today])
    );

    ok([
        'visits' => DB::all($sql, $p),
        'today' => $today,
        'stats' => [
            'today_total' => (int)($st['total'] ?? 0),
            'today_done' => (int)($st['done'] ?? 0),
            'today_pending' => (int)($st['pending'] ?? 0),
            'overdue' => (int)($od['n'] ?? 0),
        ],
    ]);
}

// تسجيل نتيجة زيارة (المحصل ميقدرش يعدّل غير زياراته)
if ($action === 'mark_visit') {
    $u = require_auth();
    $in = input();
    $vid = (int)($in['visit_id'] ?? 0);
    $status = trim((string)($in['status'] ?? ''));
    if (!$vid || !in_array($status, VISIT_STATUSES, true)) fail('بيانات الزيارة غير صحيحة');

    [$scopeSql, $scopeP] = visit_scope($u);
    $v = DB::one("SELECT v.* FROM visits v WHERE v.id = ? AND $scopeSql", array_merge([$vid], $scopeP));
    if (!$v) fail('الزيارة دي مش متاحة ليك', 403);

    $upd = ['status' => $status];
    $upd['visited_at'] = ($status === 'pending') ? null : ($v['visited_at'] ?: date('Y-m-d H:i:s'));
    if (array_key_exists('collected_amount', $in)) $upd['collected_amount'] = max(0, floatval($in['collected_amount']));
    if (array_key_exists('notes', $in)) $upd['collector_notes'] = mb_substr(trim((string)$in['notes']), 0, 2000);
    DB::update('visits', $upd, 'id = :id', ['id' => $vid]);

    $client = DB::one(
        "SELECT id, cycle_id, name, phone, sheet_name, collection_state FROM clients WHERE id = ?",
        [(int)$v['client_id']]
    );
    if ($status !== 'pending' && $client) {
        $stageMap = [
            'done' => 'visited',
            'paid' => 'paid',
            'partial' => 'partial',
            'promise' => 'promise',
            'absent' => 'visited',
            'refused' => 'visited',
            'wrong' => 'visited',
        ];
        $newStage = $stageMap[$status] ?? ($client['collection_state'] ?: 'visited');
        DB::update('clients', [
            'collection_state' => $newStage,
            'last_activity_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int)$v['client_id']]);
        $visitNote = 'نتيجة الزيارة: ' . $status;
        if (!empty($upd['collected_amount'])) $visitNote .= ' — محصّل: ' . number_format((float)$upd['collected_amount'], 2, '.', '') . ' ج.م';
        if (!empty($upd['collector_notes'])) $visitNote .= ' — ' . mb_substr($upd['collector_notes'], 0, 1000);
        collection_log_event(
            $client['cycle_id'] ?? null,
            (int)$v['client_id'],
            (int)$u['id'],
            'visit',
            $client['collection_state'] ?? 'new',
            $newStage,
            $visitNote
        );

        $collectedAmount = (float)($upd['collected_amount'] ?? $v['collected_amount'] ?? 0);
        if (in_array($status, ['paid', 'partial'], true) && $collectedAmount > 0) {
            $existingPayment = DB::one(
                "SELECT id FROM collection_payments
                 WHERE visit_id = ? AND status IN ('pending','pending_review','approved')
                 ORDER BY id DESC LIMIT 1",
                [(int)$vid]
            );
            if (!$existingPayment) {
                $paymentId = (int)DB::insert('collection_payments', [
                    'client_id' => (int)$v['client_id'],
                    'visit_id' => (int)$vid,
                    'recorded_by' => (int)$u['id'],
                    'amount' => $collectedAmount,
                    'payment_date' => date('Y-m-d'),
                    'method' => 'cash',
                    'status' => 'pending_review',
                    'notes' => $upd['collector_notes'] ?? null,
                ]);
                collection_log_event(
                    $client['cycle_id'] ?? null,
                    (int)$v['client_id'],
                    (int)$u['id'],
                    'payment_submitted',
                    null,
                    ['payment_id' => $paymentId, 'amount' => $collectedAmount],
                    'تحصيل ميداني بانتظار مراجعة الإدارة'
                );
            }
        }
    }

    if ($status !== 'pending' && $status !== ($v['status'] ?? '')) {
        DB::insert('calls', [
            'user_id' => $u['id'],
            'client_id' => $v['client_id'],
            'phone' => $client['phone'] ?? null,
            'customer_name' => $client['name'] ?? null,
            'sheet_name' => $client['sheet_name'] ?? null,
            'status' => 'زيارة: ' . $status,
            'dcr' => trim((string)($in['notes'] ?? '')),
        ]);
    }

    if ($status !== 'pending') {
        log_activity($u, 'visit', 'زيارة: ' . ($client['name'] ?? ''), $status . (isset($upd['collected_amount']) ? ' — محصّل: ' . $upd['collected_amount'] : ''));
    }

    ok([
        'visit_id' => $vid,
        'status' => $status,
        'visited_at' => $upd['visited_at'],
        'collected_amount' => $upd['collected_amount'] ?? (float)$v['collected_amount'],
    ]);
}

if ($action === 'delete_visit') {
    $u = require_auth(['super_admin', 'admin', 'data_entry']);
    $in = input();
    $vid = (int)($in['visit_id'] ?? 0);
    if (!$vid) fail('visit_id مطلوب');

    [$scopeSql, $scopeP] = visit_scope($u);
    $v = DB::one("SELECT v.id FROM visits v WHERE v.id = ? AND $scopeSql", array_merge([$vid], $scopeP));
    if (!$v) fail('الزيارة مش موجودة أو مش من صلاحياتك', 404);

    DB::q("DELETE FROM visits WHERE id = ?", [$vid]);
    ok(['message' => 'تم حذف الزيارة']);
}

// زيارة + بيانات عميلها (لشاشة التعديل)
if ($action === 'get_visit') {
    $u = require_auth(['super_admin', 'admin', 'data_entry']);
    $vid = (int)($_GET['id'] ?? 0);
    if (!$vid) fail('id مطلوب');

    [$scopeSql, $scopeP] = visit_scope($u);
    $v = DB::one(
        "SELECT v.*, u.full_name AS collector_name
         FROM visits v LEFT JOIN users u ON u.id = v.collector_id
         WHERE v.id = ? AND $scopeSql",
        array_merge([$vid], $scopeP)
    );
    if (!$v) fail('الزيارة مش موجودة أو مش من صلاحياتك', 404);

    $c = DB::one("SELECT * FROM clients WHERE id = ?", [$v['client_id']]);
    ok(['visit' => $v, 'client' => $c]);
}

// =============================================================
// SAVE VISIT (إضافة / تعديل زيارة — مدخل بيانات وأدمن وسوبر أدمن)
// =============================================================
if ($action === 'save_visit') {
    $u = require_auth(['data_entry', 'admin', 'super_admin']);

    $clientId = (int)($_POST['client_id'] ?? 0);
    $status = trim($_POST['status'] ?? 'pending');
    if (!in_array($status, VISIT_STATUSES, true)) $status = 'pending';
    $amount = max(0, floatval($_POST['collected_amount'] ?? 0));
    $collectorId = (int)($_POST['collector_id'] ?? 0);
    $visitDate = valid_date($_POST['visit_date'] ?? '', date('Y-m-d'));
    $isNewClient = (int)($_POST['is_new_client'] ?? 0);
    $editVisitId = (int)($_POST['visit_id'] ?? 0);

    // ---- المحصل لازم يكون من نطاق المستخدم ----
    if (!$collectorId) $collectorId = (int)$u['id'];
    if ($u['role'] !== 'super_admin' && $collectorId !== (int)$u['id']) {
        $myAdmin = $u['role'] === 'admin' ? $u['id'] : ($u['admin_id'] ?? 0);
        if ($myAdmin) {
            $check = DB::one(
                "SELECT id FROM users WHERE id = ? AND (admin_id = ? OR id = ?)",
                [$collectorId, $myAdmin, $myAdmin]
            );
            if (!$check) fail('المحصل المختار ليس من فريقك', 403);
        } else {
            $check = DB::one("SELECT id FROM users WHERE id = ? AND is_active = 1", [$collectorId]);
            if (!$check) fail('المحصل غير موجود', 404);
        }
    }

    // ---- تعديل زيارة موجودة: لازم تكون في نطاقه ----
    $existing = null;
    if ($editVisitId > 0) {
        [$vs, $vp] = visit_scope($u);
        $existing = DB::one("SELECT v.* FROM visits v WHERE v.id = ? AND $vs", array_merge([$editVisitId], $vp));
        if (!$existing) fail('الزيارة مش موجودة أو مش من صلاحياتك', 404);
        $clientId = (int)$existing['client_id'];
        $isNewClient = 0;
    }

    $clientWasCreated = false;
    $pdo = DB::conn();
    $pdo->beginTransaction();

    if ($isNewClient && !$clientId) {
        $cName = trim($_POST['client_name'] ?? '');
        if (!$cName) { $pdo->rollBack(); fail('اسم العميل مطلوب'); }

        $cPhone = clean_phone($_POST['client_phone'] ?? '');
        if ($cPhone) {
            $ex = DB::one("SELECT id FROM clients WHERE owner_user_id = ? AND phone = ?", [$u['id'], $cPhone]);
            if ($ex) { $clientId = (int)$ex['id']; $isNewClient = 0; }
        }

        if ($isNewClient) {
            $clientId = (int)DB::insert('clients', [
                'sheet_name' => 'manual',
                'owner_user_id' => $u['id'],
                'assigned_to' => $collectorId,
                'name' => $cName,
                'phone' => $cPhone !== '' ? $cPhone : null,
                'nid' => trim($_POST['client_nid'] ?? ''),
                'address' => trim($_POST['client_address'] ?? ''),
                'landmark' => trim($_POST['client_landmark'] ?? ''),
                'loan_balance' => floatval($_POST['client_loan'] ?? 0),
                'interest_fine_balance' => floatval($_POST['client_fine'] ?? 0),
                'installment' => floatval($_POST['client_installment'] ?? 0),
                'delay_days' => (int)($_POST['client_delay'] ?? 0),
                'legal_num' => trim($_POST['client_case'] ?? ''),
                'legal_status' => trim($_POST['client_verdict'] ?? ''),
                'guarantor_name' => trim($_POST['client_guarantor_name'] ?? ''),
                'guarantor_phone' => clean_phone($_POST['client_guarantor_phone'] ?? ''),
                'guarantor_relation' => trim($_POST['client_guarantor_relation'] ?? ''),
            ]);
            $clientWasCreated = true;
        }
    }

    if (!$clientId) { $pdo->rollBack(); fail('اختر عميل أو املأ بيانات عميل جديد'); }

    $c = DB::one("SELECT * FROM clients WHERE id = ?", [$clientId]);
    if (!$c) { $pdo->rollBack(); fail('العميل غير موجود', 404); }

    // ---- تحديث بيانات العميل: بس لو العميل في نطاق المستخدم ----
    if (!$clientWasCreated) {
        [$cs, $cp] = client_scope($u);
        $canEditClient = (bool)DB::one("SELECT c.id FROM clients c WHERE c.id = ? AND $cs", array_merge([$clientId], $cp));

        if ($canEditClient) {
            $fieldMap = [
                'client_name'    => 'name',
                'client_nid'     => 'nid',
                'client_phone'   => 'phone',
                'client_address' => 'address',
                'client_landmark'=> 'landmark',
                'client_loan'    => 'loan_balance',
                'client_fine'    => 'interest_fine_balance',
                'client_installment' => 'installment',
                'client_delay'   => 'delay_days',
                'client_case'    => 'legal_num',
                'client_verdict' => 'legal_status',
                'client_guarantor_name' => 'guarantor_name',
                'client_guarantor_phone'=> 'guarantor_phone',
                'client_guarantor_relation' => 'guarantor_relation',
                'client_loan_number'     => 'loan_number',
                'client_client_number'   => 'client_number',
                'client_grant_amount'    => 'grant_amount',
                'client_interest'        => 'interest',
                'client_fine_only'       => 'fine',
                'client_guarantor_card'  => 'guarantor_card',
                'client_guarantor_address'=> 'guarantor_address',
                'client_guarantor_landmark'=> 'guarantor_landmark',
                'client_activity_address'=> 'activity_address',
                'client_activity_landmark'=> 'activity_landmark',
                'client_grant_date'      => 'grant_date',
                'client_late_installment_date' => 'late_installment_date',
                'client_branch'          => 'branch',
                'client_governorate'     => 'governorate',
                'client_portfolio'       => 'portfolio',
                'client_finance_officer' => 'finance_officer',
                'client_sep_owner'       => 'sep_owner',
            ];

            $clientUpdates = [];
            foreach ($fieldMap as $postKey => $dbField) {
                if (!isset($_POST[$postKey])) continue;
                $val = $_POST[$postKey];
                if (in_array($dbField, ['loan_balance', 'interest_fine_balance', 'installment', 'grant_amount', 'interest', 'fine'], true)) {
                    $val = floatval($val);
                } elseif ($dbField === 'delay_days') {
                    $val = intval($val);
                } elseif ($dbField === 'phone' || $dbField === 'guarantor_phone') {
                    $val = clean_phone($val);
                    // رقم فاضي = NULL عشان قيد عدم التكرار ميمنعش أكتر من عميل بدون رقم
                    if ($dbField === 'phone' && $val === '') $val = null;
                } else {
                    $val = trim((string)$val);
                }
                $clientUpdates[$dbField] = $val;
            }
            if (!empty($clientUpdates)) {
                DB::update('clients', $clientUpdates, 'id = :id', ['id' => $clientId]);
            }
        }
    }

    // ---- صور البطاقة ----
    $warnings = [];
    $front = $back = null;
    if (!empty($_FILES['id_front_image'])) {
        $why = null;
        $front = save_uploaded_image($_FILES['id_front_image'], 'cards', $why);
        if ($why) $warnings[] = 'وجه البطاقة: ' . $why;
    }
    if (!empty($_FILES['id_back_image'])) {
        $why = null;
        $back = save_uploaded_image($_FILES['id_back_image'], 'cards', $why);
        if ($why) $warnings[] = 'ظهر البطاقة: ' . $why;
    }
    $cUpd = [];
    if ($front) $cUpd['id_front_image'] = $front;
    if ($back)  $cUpd['id_back_image']  = $back;
    if ($cUpd) DB::update('clients', $cUpd, 'id = :id', ['id' => $clientId]);

    // ---- الزيارة نفسها ----
    if (!$existing) {
        $existing = DB::one(
            "SELECT * FROM visits WHERE collector_id = ? AND client_id = ? AND visit_date = ?",
            [$collectorId, $clientId, $visitDate]
        ) ?: null;
    }

    $visitData = [
        'collector_id' => $collectorId,
        'client_id' => $clientId,
        'visit_date' => $visitDate,
        'status' => $status,
        'collected_amount' => $amount,
        'visited_at' => ($status === 'pending') ? null : (($existing['visited_at'] ?? null) ?: date('Y-m-d H:i:s')),
    ];
    // توجيهات الأدمن وملاحظات المحصل: بنعدّلهم بس لو اتبعتوا (عشان التعديل ميمسحش ملاحظات المحصل)
    if (array_key_exists('admin_notes', $_POST)) $visitData['admin_notes'] = mb_substr(trim((string)$_POST['admin_notes']), 0, 2000);
    if (array_key_exists('notes', $_POST))       $visitData['collector_notes'] = mb_substr(trim((string)$_POST['notes']), 0, 2000);

    if ($existing) {
        DB::update('visits', $visitData, 'id = :id', ['id' => $existing['id']]);
        $visitId = (int)$existing['id'];
    } else {
        $visitData['created_by'] = $u['id'];
        $visitId = (int)DB::insert('visits', $visitData);
    }

    // سجل في المكالمات (للتاريخ) — بس لما تتغير الحالة لحاجة غير "قيد الانتظار"
    if ($status !== 'pending' && (!$existing || ($existing['status'] ?? '') !== $status)) {
        DB::insert('calls', [
            'user_id' => $u['id'],
            'client_id' => $clientId,
            'phone' => $c['phone'],
            'customer_name' => $c['name'],
            'sheet_name' => $c['sheet_name'],
            'status' => 'زيارة: ' . $status,
            'dcr' => trim((string)($_POST['notes'] ?? '')),
        ]);
    }

    $pdo->commit();

    ok([
        'message' => $clientWasCreated ? 'تم إضافة العميل والزيارة بنجاح' : ($editVisitId ? 'تم تعديل الزيارة' : 'تم حفظ الزيارة'),
        'visit_id' => $visitId,
        'client_id' => $clientId,
        'collector_id' => $collectorId,
        'visit_date' => $visitDate,
        'is_new_client' => $clientWasCreated ? 1 : 0,
        'warnings' => $warnings,
    ]);
}

// =============================================================
// 5) سجل المكالمات + Dashboard
// =============================================================
if ($action === 'get_calls') {
    $u = require_auth();
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d'));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $userId = (int)($_GET['user_id'] ?? 0);

    $w = ["DATE(c.called_at) BETWEEN ? AND ?"];
    $p = [$from, $to];

    if (!in_array($u['role'], ['super_admin', 'admin'])) {
        $w[] = "c.user_id = ?";
        $p[] = $u['id'];
    } elseif ($u['role'] === 'admin') {
        $w[] = "(c.user_id = ? OR c.user_id IN (SELECT id FROM users WHERE admin_id = ?))";
        $p[] = $u['id'];
        $p[] = $u['id'];
    } elseif ($userId) {
        $w[] = "c.user_id = ?";
        $p[] = $userId;
    }

    $sql = "SELECT c.*, u.full_name AS user_name
            FROM calls c
            JOIN users u ON u.id = c.user_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY c.called_at DESC
            LIMIT 500";
    ok(['calls' => DB::all($sql, $p)]);
}

if ($action === 'dashboard') {
    $u = require_auth();
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d'));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $adminFilter = (int)($_GET['admin_id'] ?? 0);

    if (in_array($u['role'], ['collector', 'data_entry'])) {
        $userIds = [$u['id']];
        $scopeLabel = 'مكالماتي';
    } elseif ($u['role'] === 'admin') {
        $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]);
        $userIds = array_merge([$u['id']], array_column($team, 'id'));
        $scopeLabel = 'فريقي';
    } else {
        if ($adminFilter) {
            $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$adminFilter]);
            $userIds = array_merge([$adminFilter], array_column($team, 'id'));
            $scopeLabel = "فريق الأدمن #$adminFilter";
        } else {
            $userIds = null;
            $scopeLabel = 'كل الفرق';
        }
    }

    $ph = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : null;
    $w = "DATE(called_at) BETWEEN ? AND ? AND (status IS NULL OR status NOT LIKE 'زيارة%')" . ($ph ? " AND user_id IN ($ph)" : "");
    $p = array_merge([$from, $to], $userIds ?: []);

    $total = DB::one("SELECT COUNT(*) AS total FROM calls WHERE $w", $p);
    $byStatus = DB::all("SELECT status, COUNT(*) AS cnt FROM calls WHERE $w GROUP BY status", $p);
    $byUser = DB::all(
        "SELECT c.user_id, u.full_name AS user_name, COUNT(*) AS total,
                SUM(CASE WHEN c.status = 'تم التواصل بنجاح' THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN c.status = 'تعهد بالسداد' THEN 1 ELSE 0 END) AS ptp
         FROM calls c
         JOIN users u ON u.id = c.user_id
         WHERE $w
         GROUP BY c.user_id
         ORDER BY total DESC",
        $p
    );

    ok([
        'scope' => $scopeLabel,
        'total' => (int)$total['total'],
        'by_status' => array_column($byStatus, 'cnt', 'status'),
        'by_user' => $byUser,
    ]);
}

// =============================================================
// 6) إحصائيات الزيارات
// =============================================================
if ($action === 'visits_dashboard') {
    $u = require_auth();
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d'));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $adminFilter = (int)($_GET['admin_id'] ?? 0);

    if (in_array($u['role'], ['collector', 'data_entry'])) {
        $userIds = [$u['id']];
        $scopeLabel = 'زياراتي';
    } elseif ($u['role'] === 'admin') {
        $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]);
        $userIds = array_merge([$u['id']], array_column($team, 'id'));
        $scopeLabel = 'زيارات فريقي';
    } else {
        if ($adminFilter) {
            $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$adminFilter]);
            $userIds = array_merge([$adminFilter], array_column($team, 'id'));
            $scopeLabel = "زيارات فريق الأدمن #$adminFilter";
        } else {
            $userIds = null;
            $scopeLabel = 'زيارات كل الفرق';
        }
    }

    $ph = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : null;
    $w = "DATE(v.visit_date) BETWEEN ? AND ?" . ($ph ? " AND v.collector_id IN ($ph)" : "");
    $p = array_merge([$from, $to], $userIds ?: []);

    $total = DB::one("SELECT COUNT(*) AS total FROM visits v WHERE $w", $p);
    $completed = DB::one("SELECT COUNT(*) AS c FROM visits v WHERE $w AND v.status IN ('done','paid','partial')", $p);
    $pending = DB::one("SELECT COUNT(*) AS c FROM visits v WHERE $w AND (v.status = 'pending' OR v.status IS NULL OR v.status = '')", $p);
    $today = DB::one("SELECT COUNT(*) AS c FROM visits v WHERE $w AND v.visit_date = CURDATE()", $p);
    $collected = DB::one("SELECT COALESCE(SUM(v.collected_amount), 0) AS s FROM visits v WHERE $w", $p);

    $byStatus = DB::all("SELECT v.status, COUNT(*) AS cnt FROM visits v WHERE $w GROUP BY v.status", $p);

    $byCollector = DB::all(
        "SELECT v.collector_id, u.full_name AS collector_name,
                COUNT(*) AS total,
                SUM(CASE WHEN v.status IN ('done','paid','partial') THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN v.status = 'pending' OR v.status IS NULL OR v.status = '' THEN 1 ELSE 0 END) AS pending,
                COALESCE(SUM(v.collected_amount), 0) AS collected
         FROM visits v
         JOIN users u ON u.id = v.collector_id
         WHERE $w
         GROUP BY v.collector_id
         ORDER BY completed DESC, collected DESC",
        $p
    );

    ok([
        'scope' => $scopeLabel,
        'total' => (int)$total['total'],
        'completed' => (int)$completed['c'],
        'pending' => (int)$pending['c'],
        'today' => (int)$today['c'],
        'collected' => (float)$collected['s'],
        'by_status' => array_column($byStatus, 'cnt', 'status'),
        'by_collector' => $byCollector,
    ]);
}

if ($action === 'get_team_visits') {
    $u = require_auth(['super_admin', 'admin']);
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d'));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $status = trim($_GET['status'] ?? '');
    $adminFilter = (int)($_GET['admin_id'] ?? 0);

    if ($u['role'] === 'admin') {
        $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]);
        $userIds = array_merge([$u['id']], array_column($team, 'id'));
    } else {
        if ($adminFilter) {
            $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$adminFilter]);
            $userIds = array_merge([$adminFilter], array_column($team, 'id'));
        } else {
            $userIds = null;
        }
    }

    $w = ["DATE(v.visit_date) BETWEEN ? AND ?"];
    $p = [$from, $to];

    if ($userIds) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $w[] = "v.collector_id IN ($ph)";
        $p = array_merge($p, $userIds);
    }
    if ($status) {
        $w[] = "v.status = ?";
        $p[] = $status;
    }
    $collectorFilter = (int)($_GET['collector_id'] ?? 0);
    if ($collectorFilter) {
        $w[] = "v.collector_id = ?";
        $p[] = $collectorFilter;
    }

    $sql = "SELECT v.*,
                   c.name AS client_name, c.phone AS client_phone, c.nid,
                   c.address, c.landmark, c.loan_balance, c.interest_fine_balance,
                   IF(c.interest_fine_balance > 0, c.interest_fine_balance, c.loan_balance) AS total_debt,
                   u.full_name AS collector_name
            FROM visits v
            JOIN clients c ON c.id = v.client_id
            LEFT JOIN users u ON u.id = v.collector_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY v.visit_date DESC, v.id DESC
            LIMIT 300";
    ok(['visits' => DB::all($sql, $p)]);
}

// =============================================================
// 7) الزيارات حسب المنطقة
// =============================================================
if ($action === 'visits_by_region') {
    $u = require_auth();
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d'));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $adminFilter = (int)($_GET['admin_id'] ?? 0);

    if (in_array($u['role'], ['collector', 'data_entry'])) {
        $userIds = [$u['id']];
    } elseif ($u['role'] === 'admin') {
        $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]);
        $userIds = array_merge([$u['id']], array_column($team, 'id'));
    } else {
        if ($adminFilter) {
            $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$adminFilter]);
            $userIds = array_merge([$adminFilter], array_column($team, 'id'));
        } else {
            $userIds = null;
        }
    }

    $ph = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : null;
    $w = "DATE(v.visit_date) BETWEEN ? AND ?" . ($ph ? " AND v.collector_id IN ($ph)" : "");
    $p = array_merge([$from, $to], $userIds ?: []);

    $rows = DB::all(
        "SELECT c.nid, COUNT(*) AS cnt,
                SUM(CASE WHEN v.status IN ('done','paid','partial') THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(v.collected_amount), 0) AS collected
         FROM visits v
         JOIN clients c ON c.id = v.client_id
         WHERE $w
         GROUP BY c.nid",
        $p
    );

    $GOV = [
        '01' => 'القاهرة', '02' => 'الإسكندرية', '03' => 'بورسعيد', '04' => 'السويس',
        '11' => 'دمياط', '12' => 'الدقهلية', '13' => 'الشرقية', '14' => 'القليوبية',
        '15' => 'كفر الشيخ', '16' => 'الغربية', '17' => 'المنوفية', '18' => 'البحيرة',
        '19' => 'الإسماعيلية', '21' => 'الجيزة', '22' => 'بني سويف', '23' => 'الفيوم',
        '24' => 'المنيا', '25' => 'أسيوط', '26' => 'سوهاج', '27' => 'قنا',
        '28' => 'أسوان', '29' => 'الأقصر', '31' => 'البحر الأحمر', '32' => 'الوادي الجديد',
        '33' => 'مطروح', '34' => 'شمال سيناء', '35' => 'جنوب سيناء', '88' => 'خارج الجمهورية'
    ];

    $regions = [];
    foreach ($rows as $r) {
        $nid = preg_replace('/\D/', '', $r['nid'] ?? '');
        $key = 'غير محدد';
        if (strlen($nid) === 14) {
            $code = substr($nid, 7, 2);
            $key = $GOV[$code] ?? 'أخرى';
        }
        if (!isset($regions[$key])) $regions[$key] = ['name' => $key, 'total' => 0, 'completed' => 0, 'collected' => 0];
        $regions[$key]['total'] += (int)$r['cnt'];
        $regions[$key]['completed'] += (int)$r['completed'];
        $regions[$key]['collected'] += (float)$r['collected'];
    }
    usort($regions, fn($a, $b) => $b['total'] - $a['total']);
    ok(['regions' => array_values($regions)]);
}

// =============================================================
// 8) مخطط الزيارات
// =============================================================
if ($action === 'visits_chart') {
    $u = require_auth();
    $days = min((int)($_GET['days'] ?? 7), 30);
    $adminFilter = (int)($_GET['admin_id'] ?? 0);

    if (in_array($u['role'], ['collector', 'data_entry'])) {
        $userIds = [$u['id']];
    } elseif ($u['role'] === 'admin') {
        $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]);
        $userIds = array_merge([$u['id']], array_column($team, 'id'));
    } else {
        if ($adminFilter) {
            $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$adminFilter]);
            $userIds = array_merge([$adminFilter], array_column($team, 'id'));
        } else {
            $userIds = null;
        }
    }

    $from = date('Y-m-d', strtotime("-$days days"));
    $to = date('Y-m-d');
    $ph = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : null;
    $w = "v.visit_date BETWEEN ? AND ?" . ($ph ? " AND v.collector_id IN ($ph)" : "");
    $p = array_merge([$from, $to], $userIds ?: []);

    $rows = DB::all(
        "SELECT v.visit_date, COUNT(*) AS total,
                SUM(CASE WHEN v.status IN ('done','paid','partial') THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(v.collected_amount), 0) AS collected
         FROM visits v
         WHERE $w
         GROUP BY v.visit_date
         ORDER BY v.visit_date ASC",
        $p
    );

    $result = [];
    $map = [];
    foreach ($rows as $r) $map[$r['visit_date']] = $r;
    for ($i = $days; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $result[] = [
            'date' => $d,
            'label' => date('d/m', strtotime($d)),
            'total' => (int)($map[$d]['total'] ?? 0),
            'completed' => (int)($map[$d]['completed'] ?? 0),
            'collected' => (float)($map[$d]['collected'] ?? 0),
        ];
    }
    ok(['chart' => $result]);
}

// =============================================================
// 9) تصدير الزيارات CSV
// =============================================================
if ($action === 'export_visits') {
    $u = require_auth(['super_admin', 'admin']);
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days')));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $status = trim($_GET['status'] ?? '');
    $adminFilter = (int)($_GET['admin_id'] ?? 0);

    if ($u['role'] === 'admin') {
        $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]);
        $userIds = array_merge([$u['id']], array_column($team, 'id'));
    } else {
        if ($adminFilter) {
            $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$adminFilter]);
            $userIds = array_merge([$adminFilter], array_column($team, 'id'));
        } else {
            $userIds = null;
        }
    }

    $w = ["DATE(v.visit_date) BETWEEN ? AND ?"];
    $p = [$from, $to];

    if ($userIds) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $w[] = "v.collector_id IN ($ph)";
        $p = array_merge($p, $userIds);
    }
    if ($status) {
        $w[] = "v.status = ?";
        $p[] = $status;
    }

    $sql = "SELECT v.visit_date, v.status, v.collected_amount, v.visited_at,
                   v.collector_notes, v.admin_notes,
                   c.name AS client_name, c.phone AS client_phone, c.nid,
                   c.address, c.landmark, c.loan_balance, c.interest_fine_balance, c.interest, c.fine,
                   IF(c.interest_fine_balance > 0, c.interest_fine_balance, c.loan_balance) AS total_debt,
                   u.full_name AS collector_name
            FROM visits v
            JOIN clients c ON c.id = v.client_id
            LEFT JOIN users u ON u.id = v.collector_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY v.visit_date DESC, v.id DESC";

    $rows = DB::all($sql, $p);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="visits_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'التاريخ', 'المحصل', 'العميل', 'الهاتف', 'الرقم القومي', 'العنوان', 'علامة مميزة',
        'رصيد القرض', 'الفوائد والغرامات', 'إجمالي المديونية', 'الحالة',
        'المبلغ المحصل', 'وقت الزيارة', 'ملاحظات المحصل', 'توجيهات الأدمن'
    ]);

    $labels = [
        'done' => 'تمت الزيارة', 'paid' => 'سدد بالكامل', 'partial' => 'دفع جزء',
        'promise' => 'وعد بالسداد', 'absent' => 'غير موجود', 'refused' => 'رفض السداد',
        'wrong' => 'عنوان غلط', 'pending' => 'قيد الانتظار'
    ];

    foreach ($rows as $r) {
        $loan = (float)($r['loan_balance'] ?? 0);
        $total = (float)($r['total_debt'] ?? 0);
        $partsSum = (float)($r['interest'] ?? 0) + (float)($r['fine'] ?? 0);
        $fees = $partsSum > 0 ? $partsSum : max(0, $total - $loan);
        fputcsv($out, [
            $r['visit_date'],
            $r['collector_name'] ?? '—',
            $r['client_name'] ?? '—',
            $r['client_phone'] ?? '',
            $r['nid'] ?? '',
            $r['address'] ?? '',
            $r['landmark'] ?? '',
            number_format($loan, 2, '.', ''),
            number_format($fees, 2, '.', ''),
            number_format($total, 2, '.', ''),
            $labels[$r['status']] ?? $r['status'],
            number_format((float)($r['collected_amount'] ?? 0), 2, '.', ''),
            $r['visited_at'] ? date('d/m/Y H:i', strtotime($r['visited_at'])) : '',
            $r['collector_notes'] ?? '',
            $r['admin_notes'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// =============================================================
// 10) تصدير المكالمات CSV
// =============================================================
if ($action === 'export_calls') {
    $u = require_auth(['super_admin', 'admin']);
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days')));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $adminFilter = (int)($_GET['admin_id'] ?? 0);

    if ($u['role'] === 'admin') {
        $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$u['id']]);
        $userIds = array_merge([$u['id']], array_column($team, 'id'));
    } else {
        if ($adminFilter) {
            $team = DB::all("SELECT id FROM users WHERE admin_id = ?", [$adminFilter]);
            $userIds = array_merge([$adminFilter], array_column($team, 'id'));
        } else {
            $userIds = null;
        }
    }

    $w = ["DATE(c.called_at) BETWEEN ? AND ?"];
    $p = [$from, $to];
    if ($userIds) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $w[] = "c.user_id IN ($ph)";
        $p = array_merge($p, $userIds);
    }

    $sql = "SELECT c.called_at, c.phone, c.customer_name, c.status, c.ptp, c.ptp_date, c.dcr,
                   u.full_name AS user_name
            FROM calls c
            JOIN users u ON u.id = c.user_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY c.called_at DESC";

    $rows = DB::all($sql, $p);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="calls_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['التاريخ والوقت', 'الموظف', 'العميل', 'الرقم', 'الحالة', 'مبلغ التعهد', 'تاريخ التعهد', 'الملاحظات']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['called_at'], $r['user_name'], $r['customer_name'], $r['phone'],
            $r['status'] ?? '', $r['ptp'] ?? '', $r['ptp_date'] ?? '', $r['dcr'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// =============================================================
// 11) تقرير أداء كامل
// =============================================================
if ($action === 'full_performance') {
    $u = require_auth(['super_admin', 'admin']);
    $from = valid_date($_GET['from'] ?? '', date('Y-m-d'));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
    $adminFilter = (int)($_GET['admin_id'] ?? 0);
    $userFilter = (int)($_GET['user_id'] ?? 0);
    $roleFilter = trim($_GET['role'] ?? '');

    $wUsers = ["u.role != 'super_admin'"];
    $pUsers = [];

    if ($u['role'] === 'admin') {
        $wUsers[] = "(u.admin_id = ? OR u.id = ?)";
        $pUsers[] = $u['id'];
        $pUsers[] = $u['id'];
    } else {
        if ($adminFilter) {
            $wUsers[] = "(u.admin_id = ? OR u.id = ?)";
            $pUsers[] = $adminFilter;
            $pUsers[] = $adminFilter;
        }
        if ($userFilter) {
            $wUsers[] = "u.id = ?";
            $pUsers[] = $userFilter;
        }
        if ($roleFilter) {
            $wUsers[] = "u.role = ?";
            $pUsers[] = $roleFilter;
        }
    }

    $users = DB::all(
        "SELECT u.id, u.full_name, u.role, u.admin_id, u.is_active,
                adm.full_name AS admin_name
         FROM users u
         LEFT JOIN users adm ON adm.id = u.admin_id
         WHERE " . implode(' AND ', $wUsers) . "
         ORDER BY u.admin_id IS NULL, u.admin_id, u.full_name",
        $pUsers
    );

    $pmap = products_map_for_users();
    foreach ($users as &$uu) {
        $names = $pmap[(int)$uu['id']] ?? [];
        $uu['product_names'] = $names;
        $uu['product_label'] = $names ? implode(' · ', $names) : '';
    }
    unset($uu);

    if (empty($users)) {
        ok(['users' => [], 'totals' => [
            'calls' => 0, 'success' => 0, 'ptp' => 0,
            'visits' => 0, 'completed' => 0, 'collected' => 0
        ]]);
    }

    $userIds = array_column($users, 'id');
    $ph = implode(',', array_fill(0, count($userIds), '?'));

    $callsRows = DB::all(
        "SELECT user_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'تم التواصل بنجاح' THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 'تعهد بالسداد' THEN 1 ELSE 0 END) AS ptp,
                SUM(CASE WHEN status = 'لم يتم الرد' THEN 1 ELSE 0 END) AS no_answer,
                SUM(CASE WHEN status = 'الخط مشغول' THEN 1 ELSE 0 END) AS busy
         FROM calls
         WHERE user_id IN ($ph) AND DATE(called_at) BETWEEN ? AND ?
           AND (status IS NULL OR status NOT LIKE 'زيارة%')
         GROUP BY user_id",
        array_merge($userIds, [$from, $to])
    );
    $callsMap = [];
    foreach ($callsRows as $r) $callsMap[$r['user_id']] = $r;

    $visitsRows = DB::all(
        "SELECT collector_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('done','paid','partial') THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'pending' OR status IS NULL OR status = '' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'promise' THEN 1 ELSE 0 END) AS promise,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) AS refused,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) AS partial,
                COALESCE(SUM(collected_amount), 0) AS collected
         FROM visits
         WHERE collector_id IN ($ph) AND visit_date BETWEEN ? AND ?
         GROUP BY collector_id",
        array_merge($userIds, [$from, $to])
    );
    $visitsMap = [];
    foreach ($visitsRows as $r) $visitsMap[$r['collector_id']] = $r;

    $result = [];
    $totals = [
        'calls' => 0, 'success' => 0, 'ptp' => 0, 'no_answer' => 0,
        'visits' => 0, 'completed' => 0, 'pending' => 0, 'collected' => 0
    ];

    foreach ($users as $usr) {
        $c = $callsMap[$usr['id']] ?? [];
        $v = $visitsMap[$usr['id']] ?? [];

        $row = [
            'id' => (int)$usr['id'],
            'full_name' => $usr['full_name'],
            'role' => $usr['role'],
            'admin_id' => $usr['admin_id'] ? (int)$usr['admin_id'] : null,
            'admin_name' => $usr['admin_name'] ?: '—',
            'is_active' => (int)$usr['is_active'],
            'product_names' => $usr['product_names'] ?? [],
            'product_label' => $usr['product_label'] ?? '',
            'calls' => [
                'total' => (int)($c['total'] ?? 0),
                'success' => (int)($c['success'] ?? 0),
                'ptp' => (int)($c['ptp'] ?? 0),
                'no_answer' => (int)($c['no_answer'] ?? 0),
                'busy' => (int)($c['busy'] ?? 0),
            ],
            'visits' => [
                'total' => (int)($v['total'] ?? 0),
                'completed' => (int)($v['completed'] ?? 0),
                'pending' => (int)($v['pending'] ?? 0),
                'promise' => (int)($v['promise'] ?? 0),
                'absent' => (int)($v['absent'] ?? 0),
                'refused' => (int)($v['refused'] ?? 0),
                'paid' => (int)($v['paid'] ?? 0),
                'partial' => (int)($v['partial'] ?? 0),
                'collected' => (float)($v['collected'] ?? 0),
            ],
        ];

        $result[] = $row;

        $totals['calls']     += $row['calls']['total'];
        $totals['success']   += $row['calls']['success'];
        $totals['ptp']       += $row['calls']['ptp'];
        $totals['no_answer'] += $row['calls']['no_answer'];
        $totals['visits']    += $row['visits']['total'];
        $totals['completed'] += $row['visits']['completed'];
        $totals['pending']   += $row['visits']['pending'];
        $totals['collected'] += $row['visits']['collected'];
    }

    ok([
        'users' => $result,
        'totals' => $totals,
        'from' => $from,
        'to' => $to,
    ]);
}

// =============================================================
// 12) إدارة المستخدمين
// =============================================================
if ($action === 'get_users') {
    $u = require_auth(['super_admin', 'admin']);

    if ($u['role'] === 'super_admin') {
        $rows = DB::all(
            "SELECT u.id, u.username, u.full_name, u.role, u.phone, u.admin_id, u.is_active, u.last_login,
                    (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = u.id) AS client_count
             FROM users u ORDER BY u.role, u.full_name"
        );
    } else {
        $rows = DB::all(
            "SELECT u.id, u.username, u.full_name, u.role, u.phone, u.admin_id, u.is_active, u.last_login,
                    (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = u.id) AS client_count
             FROM users u WHERE u.admin_id = ? OR u.id = ? ORDER BY u.full_name",
            [$u['id'], $u['id']]
        );
    }
    $pmap = products_map_for_users();
    foreach ($rows as &$r) {
        $names = $pmap[(int)$r['id']] ?? [];
        $r['product_names'] = $names;
        $r['product_label'] = $names ? implode(' · ', $names) : '';
    }
    unset($r);
    ok(['users' => $rows]);
}

if ($action === 'save_user') {
    $u = require_auth(['super_admin', 'admin']);
    $in = input();

    $id = (int)($in['id'] ?? 0);
    $target = null;
    if ($id) {
        $target = DB::one("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$target) fail('المستخدم غير موجود', 404);
        if ($u['role'] === 'admin' && (int)$target['admin_id'] !== (int)$u['id']) fail('ليس من فريقك', 403);
    }

    // لو الدور مابعتش (تعديل بيانات بس) بنحافظ على الدور الحالي بدل ما نرجّعه "محصل"
    $role = $in['role'] ?? ($target['role'] ?? 'collector');
    if ($u['role'] === 'admin' && !in_array($role, ['collector', 'data_entry'], true)) {
        fail('الأدمن يقدر يضيف محصلين ومدخلي بيانات بس', 403);
    }
    if (!in_array($role, ['collector', 'data_entry', 'admin', 'super_admin'], true)) {
        fail('دور غير صالح', 400);
    }

    $fullName = trim($in['full_name'] ?? '');
    if ($fullName === '') fail('الاسم مطلوب');

    $password = (string)($in['password'] ?? '');
    if ($password !== '' && mb_strlen($password) < 6) fail('كلمة المرور لازم تكون 6 حروف أو أرقام على الأقل');

    $data = [
        'full_name' => $fullName,
        'phone' => trim($in['phone'] ?? ''),
        'is_active' => isset($in['is_active']) ? ((int)$in['is_active'] ? 1 : 0) : (int)($target['is_active'] ?? 1),
    ];

    if ($id) {
        // مينفعش المستخدم يعطّل نفسه أو يغيّر دوره
        if ($id === (int)$u['id']) {
            $data['is_active'] = 1;
            $role = $u['role'];
        }
        if ($password !== '') $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);

        if ($u['role'] === 'super_admin') {
            $data['role'] = $role;
            if (array_key_exists('admin_id', $in)) {
                $aid = (int)$in['admin_id'] ?: null;
                if (in_array($role, ['admin', 'super_admin'], true) || $aid === $id) $aid = null;
                $data['admin_id'] = $aid;
            }
        }
        DB::update('users', $data, 'id = :id', ['id' => $id]);

        // تغيير الباسورد أو التعطيل = تسجيل خروج من كل الأجهزة (ما عدا الجلسة الحالية)
        if ($password !== '' || !$data['is_active']) {
            $curTok = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_GET['token'] ?? '');
            DB::q("DELETE FROM sessions WHERE user_id = ? AND token <> ?", [$id, $curTok]);
        }
        ok(['id' => $id]);
    }

    $username = trim($in['username'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
        fail('اسم المستخدم لازم يكون إنجليزي أو أرقام (3 حروف على الأقل) وبدون مسافات');
    }
    if ($password === '') fail('كلمة المرور مطلوبة');
    if (DB::one("SELECT id FROM users WHERE username = ?", [$username])) {
        fail('اسم المستخدم موجود مسبقاً');
    }

    $data['username'] = $username;
    $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $data['role'] = $role;
    if ($u['role'] === 'admin') {
        $data['admin_id'] = $u['id'];
    } else {
        $data['admin_id'] = in_array($role, ['admin', 'super_admin'], true) ? null : ((int)($in['admin_id'] ?? 0) ?: null);
    }

    $newId = DB::insert('users', $data);
    ok(['id' => $newId]);
}

if ($action === 'delete_user') {
    $u = require_auth(['super_admin', 'admin']);
    $in = input();
    $id = (int)($in['id'] ?? 0);

    if ($id === (int)$u['id']) fail('مش هتقدر تحذف نفسك');
    $target = DB::one("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$target) fail('المستخدم غير موجود', 404);
    if ($u['role'] === 'admin' && (int)$target['admin_id'] !== (int)$u['id']) fail('ليس من فريقك', 403);

    $team = DB::one("SELECT COUNT(*) AS n FROM users WHERE admin_id = ?", [$id]);
    if ((int)($team['n'] ?? 0) > 0) fail('المستخدم ده عنده فريق — انقل أعضاءه لأدمن تاني الأول');

    // لو عنده سجل شغل (مكالمات/زيارات/عملاء) بنعطّله بدل ما نحذفه عشان السجلات متتيتّمش
    $used = DB::one(
        "SELECT (SELECT COUNT(*) FROM calls WHERE user_id = ?)
              + (SELECT COUNT(*) FROM visits WHERE collector_id = ? OR created_by = ?)
              + (SELECT COUNT(*) FROM clients WHERE owner_user_id = ? OR assigned_to = ?) AS n",
        [$id, $id, $id, $id, $id]
    );
    if ((int)($used['n'] ?? 0) > 0) {
        DB::update('users', ['is_active' => 0], 'id = :id', ['id' => $id]);
        DB::q("DELETE FROM sessions WHERE user_id = ?", [$id]);
        ok(['deactivated' => true, 'message' => 'الموظف عنده سجل شغل، فاتعطّل بدل ما يتحذف عشان السجلات متضيعش']);
    }

    DB::q("DELETE FROM users WHERE id = ?", [$id]);
    ok(['deleted' => true]);
}

// =============================================================
// 13) قائمة المحصلين
// =============================================================
if ($action === 'get_team_collectors') {
    $u = require_auth(['data_entry', 'admin', 'super_admin']);

    if ($u['role'] === 'super_admin') {
        $rows = DB::all(
            "SELECT u.id, u.full_name, u.role, adm.full_name AS admin_name
             FROM users u
             LEFT JOIN users adm ON adm.id = u.admin_id
             WHERE u.role = 'collector' AND u.is_active = 1
             ORDER BY adm.full_name, u.full_name"
        );
    } else {
        $myAdmin = $u['role'] === 'admin' ? $u['id'] : ($u['admin_id'] ?? 0);
        if ($myAdmin) {
            $rows = DB::all(
                "SELECT u.id, u.full_name, u.role, adm.full_name AS admin_name
                 FROM users u
                 LEFT JOIN users adm ON adm.id = u.admin_id
                 WHERE u.role = 'collector' AND u.is_active = 1
                   AND (u.admin_id = ? OR u.id = ?)
                 ORDER BY u.full_name",
                [$myAdmin, $myAdmin]
            );
        } else {
            $rows = DB::all(
                "SELECT u.id, u.full_name, u.role, NULL AS admin_name
                 FROM users u
                 WHERE u.role = 'collector' AND u.is_active = 1
                 ORDER BY u.full_name"
            );
        }
    }
    ok(['collectors' => $rows]);
}

// =============================================================
// 14) قائمة العملاء (بحث + فلتر Coll + صفحات)
// =============================================================
if ($action === 'get_team_clients') {
    $u = require_auth(['collector', 'data_entry', 'admin', 'super_admin']);
    $q = trim($_GET['q'] ?? '');
    $coll = trim($_GET['coll'] ?? '');
    $filter = trim($_GET['filter'] ?? 'all');
    $limit = max(1, min((int)($_GET['limit'] ?? 50), 200));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    [$cs, $cp] = client_scope($u);
    $w = [$cs];
    $p = $cp;

    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $w[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.nid LIKE ? OR c.guarantor_name LIKE ? OR c.guarantor_phone LIKE ?)";
        array_push($p, $like, $like, $like, $like, $like);
    }
    if ($coll !== '') {
        $w[] = "c.coll = ?";
        $p[] = $coll;
    }
    if ($filter === 'high-delay')        $w[] = "c.delay_days >= 30";
    elseif ($filter === 'not-visited')   $w[] = "COALESCE(vc.cnt, 0) = 0";
    elseif ($filter === 'has-visit')     $w[] = "COALESCE(vc.cnt, 0) > 0";

    $from = "FROM clients c
             LEFT JOIN users u ON u.id = c.assigned_to
             LEFT JOIN (SELECT client_id, COUNT(*) AS cnt FROM visits GROUP BY client_id) vc ON vc.client_id = c.id
             LEFT JOIN (
               SELECT client_id, MAX(called_at) AS last_call_at
               FROM calls
               WHERE status IS NULL OR status NOT LIKE 'زيارة%'
               GROUP BY client_id
             ) lc ON lc.client_id = c.id
             LEFT JOIN (
               SELECT client_id, MAX(COALESCE(visited_at, CONCAT(visit_date, ' 00:00:00'))) AS last_visit_at
               FROM visits
               WHERE status IS NOT NULL AND status <> '' AND status <> 'pending'
               GROUP BY client_id
             ) lv ON lv.client_id = c.id
             WHERE " . implode(' AND ', $w);

    $total = (int)(DB::one("SELECT COUNT(*) AS n $from", $p)['n'] ?? 0);

    $rows = DB::all(
        "SELECT c.id, c.name, c.phone, c.nid, c.address, c.landmark,
                c.coll, c.delay_days, c.loan_balance, c.interest_fine_balance,
                c.legal_num, c.legal_status, c.owner_user_id, c.sheet_name,
                (c.id_front_image IS NOT NULL AND c.id_front_image <> '') AS has_front,
                COALESCE(vc.cnt, 0) AS visits_count,
                u.full_name AS owner_name,
                lc.last_call_at,
                lv.last_visit_at
         $from
         ORDER BY c.delay_days DESC, c.name ASC
         LIMIT $limit OFFSET $offset",
        $p
    );

    $out = ['clients' => $rows, 'total' => $total, 'has_more' => ($offset + count($rows)) < $total];

    // قيم Coll المتاحة (بس في أول صفحة عشان منحمّلهاش كل مرة)
    if ($offset === 0) {
        $collsRows = DB::all(
            "SELECT DISTINCT c.coll FROM clients c WHERE $cs AND c.coll IS NOT NULL AND c.coll <> '' ORDER BY c.coll ASC",
            $cp
        );
        $out['colls'] = array_column($collsRows, 'coll');
    }

    ok($out);
}

// =============================================================
// 15) جلب عميل بالمعرف
// =============================================================
if ($action === 'get_client_by_id') {
    $u = require_auth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('client_id مطلوب');

    [$scope, $params] = client_scope($u);
    $c = DB::one("SELECT * FROM clients c WHERE c.id = ? AND $scope", array_merge([$id], $params));

    if (!$c) fail('العميل غير موجود', 404);

    $c['total_debt'] = (float)($c['interest_fine_balance'] ?? 0) > 0 ? (float)$c['interest_fine_balance'] : (float)($c['loan_balance'] ?? 0);

    if (!empty($c['owner_user_id'])) {
        $ownerName = DB::one("SELECT full_name FROM users WHERE id = ?", [$c['owner_user_id']]);
        $c['owner_name'] = $ownerName['full_name'] ?? '—';
    }

    ok(['client' => $c]);
}

// =============================================================
// 16) تقرير أداء محصل
// =============================================================
if ($action === 'get_collector_visits_summary') {
    $u = require_auth(['data_entry', 'admin', 'super_admin']);
    $colId = (int)($_GET['collector_id'] ?? 0);
    if (!$colId) fail('collector_id مطلوب');

    $from = valid_date($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days')));
    $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));

    $rows = DB::all(
        "SELECT v.status, COUNT(*) AS cnt,
                SUM(CASE WHEN v.status IN ('done','paid','partial') THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(v.collected_amount), 0) AS collected
         FROM visits v
         WHERE v.collector_id = ? AND v.visit_date BETWEEN ? AND ?
         GROUP BY v.status",
        [$colId, $from, $to]
    );

    $col = DB::one("SELECT full_name FROM users WHERE id = ?", [$colId]);

    ok([
        'collector_name' => $col['full_name'] ?? '—',
        'by_status' => $rows,
    ]);
}

// =============================================================
// 17) توزيع العملاء
// =============================================================
if ($action === 'assign_clients') {
    $u = require_auth(['super_admin', 'admin']);
    $in = input();

    $clientIds = $in['client_ids'] ?? [];
    $assignTo = (int)($in['user_id'] ?? 0);
    if (!$assignTo) fail('حدد الموظف');
    $assignee = DB::one("SELECT id, role, is_active FROM users WHERE id = ?", [$assignTo]);
    if (!$assignee || !$assignee['is_active'] || !in_array($assignee['role'], ['collector', 'data_entry'], true)) {
        fail('الموظف المحدد غير نشط أو لا يقبل إسناد عملاء');
    }
    if ($u['role'] === 'admin' && !in_array($assignTo, team_ids($u), true)) {
        fail('لا يمكنك إسناد العملاء إلى موظف خارج فريقك', 403);
    }

    $clientIds = array_values(array_filter(array_map('intval', (array)$clientIds)));
    $updated = 0;
    if (!empty($clientIds)) {
        [$cs, $cp] = client_scope($u);
        $ph = in_list($clientIds);
        $clients = DB::all(
            "SELECT c.id, c.cycle_id, c.assigned_to, cc.status AS cycle_status, current_assignment.status AS assignment_status
             FROM clients c
             LEFT JOIN collection_cycles cc ON cc.id = c.cycle_id
             LEFT JOIN collection_assignments current_assignment
               ON current_assignment.cycle_id = c.cycle_id AND current_assignment.user_id = c.assigned_to
             WHERE c.id IN ($ph) AND $cs",
            array_merge($clientIds, $cp)
        );
        $affected = [];
        foreach ($clients as $client) {
            if (!$client['cycle_id']) continue;
            if (!in_array($client['cycle_status'], ['draft', 'active'], true)
                || in_array($client['assignment_status'], ['submitted', 'approved'], true)) {
                fail('لا يمكن تغيير إسناد عميل بعد تسليمه أو اعتماد الدورة', 409);
            }
            if (!DB::one(
                "SELECT id FROM collection_assignments WHERE cycle_id = ? AND user_id = ?",
                [$client['cycle_id'], $assignTo]
            )) {
                fail('يجب إسناد الدورة إلى الموظف المستهدف قبل نقل العملاء إليه', 409);
            }
            $affected[(int)$client['cycle_id']][(int)$client['assigned_to']] = true;
            $affected[(int)$client['cycle_id']][$assignTo] = true;
        }
        $st = DB::q(
            "UPDATE clients c SET c.assigned_to = ? WHERE c.id IN ($ph) AND $cs",
            array_merge([$assignTo], $clientIds, $cp)
        );
        $updated = $st->rowCount();
        foreach ($affected as $cycleId => $employeeIds) {
            foreach (array_keys($employeeIds) as $employeeId) {
                $count = (int)(DB::one(
                    "SELECT COUNT(*) AS n FROM clients WHERE cycle_id = ? AND assigned_to = ?",
                    [$cycleId, $employeeId]
                )['n'] ?? 0);
                DB::q(
                    "UPDATE collection_assignments
                     SET assigned_count = ?, uploaded_count = ?,
                         status = CASE WHEN ? > 0 THEN 'working' WHEN status = 'working' THEN 'pending' ELSE status END,
                         updated_at = NOW()
                     WHERE cycle_id = ? AND user_id = ?",
                    [$count, $count, $count, $cycleId, $employeeId]
                );
            }
        }
    }
    ok(['updated' => $updated]);
}

// =============================================================
// 18) الذكاء الاصطناعي
// =============================================================
if ($action === 'ai_negotiate') {
    require_auth();
    $in = input();

    if (empty($CONFIG['gemini_api_key'])) {
        $hasV = !empty($in['legal_status']) && !in_array($in['legal_status'], ['لا يوجد', '0', '']);
        $s = "📌 **استراتيجية مقترحة:**\n";
        $s .= "• **النبرة:** " . ($hasV ? 'حازمة لوجود حكم قضائي' : 'تحذيرية جادة') . "\n";
        $s .= "• **نقطة الضغط:** إجمالي المديونية " . ($in['interest_fine_balance'] ?? $in['loan_balance'] ?? 0) . " ج.م\n";
        $s .= "• **نص المكالمة:** أهلاً أستاذ " . ($in['name'] ?? '') . "، بخصوص القسط المتأخر بقيمة " . ($in['installment'] ?? 0) . " ج.م، برجاء التنسيق للسداد الفوري لتجنب تصعيد الملف.\n";
        ok(['strategy' => $s]);
    }

    $prompt = "أنت محصل ديون محترف وخبير مفاوضات لشركة تمويل.\n"
        . "حلل بيانات العميل وأعطني خطة تفاوض مختصرة:\n"
        . json_encode(array_intersect_key($in, array_flip(['name', 'installment', 'delay_days', 'loan_balance', 'interest_fine_balance', 'legal_num', 'legal_status', 'status', 'ptp', 'ptp_date'])), JSON_UNESCAPED_UNICODE)
        . "\n\nالمطلوب: 1) النبرة الموصى بها، 2) مدخل الحوار، 3) نص مفتاحي للمكالمة. اجعلها مختصرة.";

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$CONFIG['gemini_api_key']}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($resp, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? 'تعذر توليد استراتيجية';
    ok(['strategy' => $text]);
}

// =============================================================
// 19) تشخيص
// =============================================================
if ($action === 'debug_clients') {
    $u = require_auth(['super_admin']);

    $total = DB::one("SELECT COUNT(*) AS c FROM clients");
    $mine = DB::one("SELECT COUNT(*) AS c FROM clients WHERE owner_user_id = ?", [$u['id']]);
    $nullOwners = DB::one("SELECT COUNT(*) AS c FROM clients WHERE owner_user_id IS NULL");

    $sample = DB::all(
        "SELECT id, name, phone, nid, address, coll, owner_user_id, sheet_name, created_at
         FROM clients WHERE owner_user_id = ? LIMIT 5",
        [$u['id']]
    );

    ok([
        'user' => [
            'id' => $u['id'],
            'username' => $u['username'],
            'role' => $u['role'],
            'admin_id' => $u['admin_id'],
        ],
        'counts' => [
            'total_clients_in_db' => (int)$total['c'],
            'my_clients' => (int)$mine['c'],
            'null_owner_clients' => (int)$nullOwners['c'],
        ],
        'my_sample' => $sample,
        'php_limits' => [
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ]
    ]);
}


// =============================================================
// Live feed + Notifications (سوبر أدمن)
// =============================================================
if ($action === 'live_feed') {
    $u = require_auth(['super_admin']);
    if (!ensure_activity_tables()) {
        ok(['events' => [], 'stats' => ['calls_today'=>0,'visits_today'=>0,'logins_today'=>0,'online'=>0,'unread_notifs'=>0], 'online_users' => [], 'server_time' => date('Y-m-d H:i:s'), 'warn' => 'جداول النشاط مش جاهزة']);
    }
    $sinceId = (int)($_GET['since_id'] ?? 0);
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 40)));

    if ($sinceId > 0) {
        $rows = DB::all(
            "SELECT id, user_id, user_name, role, event_type, title, detail, created_at
             FROM activity_log WHERE id > ? ORDER BY id ASC LIMIT $limit",
            [$sinceId]
        );
    } else {
        $rows = DB::all(
            "SELECT id, user_id, user_name, role, event_type, title, detail, created_at
             FROM activity_log ORDER BY id DESC LIMIT $limit"
        );
        $rows = array_reverse($rows);
    }

    $today = date('Y-m-d');
    $stats = [
        'calls_today' => (int)(DB::one("SELECT COUNT(*) AS c FROM calls WHERE DATE(called_at) = ? AND (status IS NULL OR status NOT LIKE 'زيارة%')", [$today])['c'] ?? 0),
        'visits_today' => (int)(DB::one("SELECT COUNT(*) AS c FROM visits WHERE (DATE(visited_at) = ? OR (visited_at IS NULL AND visit_date = ?)) AND status != 'pending' AND status IS NOT NULL AND status != ''", [$today, $today])['c'] ?? 0),
        'logins_today' => (int)(DB::one("SELECT COUNT(*) AS c FROM activity_log WHERE event_type = 'login' AND DATE(created_at) = ?", [$today])['c'] ?? 0),
        'online' => (int)(DB::one("SELECT COUNT(DISTINCT user_id) AS c FROM sessions WHERE expires_at > NOW()")['c'] ?? 0),
        'unread_notifs' => (int)(DB::one("SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0")['c'] ?? 0),
    ];

    $onlineUsers = DB::all(
        "SELECT u.id, u.full_name, u.role, MAX(s.expires_at) AS expires_at
         FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.expires_at > NOW()
         GROUP BY u.id, u.full_name, u.role
         ORDER BY u.full_name ASC
         LIMIT 50"
    );

    ok([
        'events' => $rows,
        'stats' => $stats,
        'online_users' => $onlineUsers,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'send_notification') {
    $u = require_auth(['super_admin']);
    if (!ensure_activity_tables()) {
        fail('تعذر تجهيز جداول الإشعارات — تأكد إن حساب قاعدة البيانات عنده صلاحية CREATE TABLE، أو نفّذ SQL من phpMyAdmin');
    }
    $in = input();
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
    $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 2000);
    $toId = (int)($in['to_user_id'] ?? 0);
    $toAll = !empty($in['to_all']);

    if ($title === '' || $body === '') fail('العنوان والرسالة مطلوبان');

    $targets = [];
    if ($toAll) {
        $targets = DB::all("SELECT id FROM users WHERE is_active = 1 AND id != ?", [(int)$u['id']]);
    } elseif ($toId > 0) {
        $t = DB::one("SELECT id FROM users WHERE id = ? AND is_active = 1", [$toId]);
        if (!$t) fail('المستخدم غير موجود أو غير نشط');
        $targets = [$t];
    } else {
        fail('اختر مستلم أو أرسل للجميع');
    }
    if (!$targets) fail('مفيش مستلمين متاحين');

    $n = 0;
    $now = date('Y-m-d H:i:s');
    try {
        foreach ($targets as $t) {
            DB::q(
                "INSERT INTO notifications (from_user_id, from_name, to_user_id, title, body_text, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?)",
                [(int)$u['id'], (string)($u['full_name'] ?? ''), (int)$t['id'], $title, $body, $now]
            );
            $n++;
        }
    } catch (Throwable $e) {
        error_log('[mawared] send_notification: ' . $e->getMessage());
        fail('فشل حفظ الإشعار: ' . $e->getMessage());
    }

    log_activity($u, 'notif', 'إشعار: ' . $title, $toAll ? "للجميع ($n)" : "لمستخدم #$toId");

    ok(['message' => "تم إرسال الإشعار لـ $n مستخدم", 'sent' => $n]);
}

if ($action === 'my_notifications') {
    $u = require_auth();
    if (!ensure_activity_tables()) {
        ok(['notifications' => [], 'count' => 0]);
    }
    $rows = DB::all(
        "SELECT id, from_user_id, from_name, title, body_text AS body, is_read, read_at, created_at
         FROM notifications
         WHERE to_user_id = ? AND is_read = 0
         ORDER BY id ASC
         LIMIT 20",
        [(int)$u['id']]
    );
    ok(['notifications' => $rows ?: [], 'count' => count($rows ?: [])]);
}

if ($action === 'ack_notification') {
    $u = require_auth();
    ensure_activity_tables();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    if (!$id) fail('id مطلوب');
    $n = DB::one("SELECT id FROM notifications WHERE id = ? AND to_user_id = ?", [$id, (int)$u['id']]);
    if (!$n) fail('الإشعار غير موجود', 404);
    DB::q("UPDATE notifications SET is_read = 1, read_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
    ok(['message' => 'تم']);
}

if ($action === 'notif_users') {
    $u = require_auth(['super_admin']);
    $rows = DB::all(
        "SELECT id, full_name, role, is_active FROM users WHERE is_active = 1 ORDER BY full_name ASC"
    );
    ok(['users' => $rows]);
}



// =============================================================
// منتجات + مهام + أهداف يومية + تذكيرات + تايملاين
// =============================================================
function ensure_ops_tables() {
    static $ok = null;
    if ($ok === true) return true;
    try {
        DB::q("CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(40) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::q("CREATE TABLE IF NOT EXISTS product_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            admin_id INT NOT NULL,
            team_name VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_prod_admin (product_id, admin_id),
            KEY idx_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::q("CREATE TABLE IF NOT EXISTS daily_targets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            target_date DATE NOT NULL,
            calls_target INT NOT NULL DEFAULT 0,
            visits_target INT NOT NULL DEFAULT 0,
            set_by INT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_user_day (user_id, target_date),
            KEY idx_date (target_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::q("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT NOT NULL,
            to_user_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT NULL,
            due_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL,
            done_at DATETIME NULL,
            KEY idx_to (to_user_id, status),
            KEY idx_from (from_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::q("CREATE TABLE IF NOT EXISTS client_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            client_id INT NOT NULL,
            remind_at DATETIME NOT NULL,
            note VARCHAR(500) NULL,
            is_done TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_user_due (user_id, is_done, remind_at),
            KEY idx_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // منتجات افتراضية
        $defaults = ['تنمية', 'فاليو', 'مايلو', 'CIB', 'أمان'];
        foreach ($defaults as $n) {
            $ex = DB::one("SELECT id FROM products WHERE name = ?", [$n]);
            if (!$ex) {
                DB::q("INSERT INTO products (name, code, is_active, created_at) VALUES (?, ?, 1, ?)",
                    [$n, strtoupper(substr(preg_replace('/\s+/', '', $n), 0, 20)), date('Y-m-d H:i:s')]);
            }
        }
        $ok = true;
        return true;
    } catch (Throwable $e) {
        error_log('[mawared] ensure_ops_tables: ' . $e->getMessage());
        $ok = false;
        return false;
    }
}


/** خريطة user_id => أسماء المنتجات (المشرف من product_teams، والموظف من مشرفه) */
function products_map_for_users() {
    try {
        ensure_ops_tables();
        $teams = DB::all(
            "SELECT pt.admin_id, p.name
             FROM product_teams pt
             JOIN products p ON p.id = pt.product_id
             WHERE p.is_active = 1
             ORDER BY p.name"
        );
        $adminProducts = [];
        foreach ($teams ?: [] as $row) {
            $aid = (int)$row['admin_id'];
            if (!isset($adminProducts[$aid])) $adminProducts[$aid] = [];
            if (!in_array($row['name'], $adminProducts[$aid], true)) {
                $adminProducts[$aid][] = $row['name'];
            }
        }
        $users = DB::all("SELECT id, role, admin_id FROM users");
        $map = [];
        foreach ($users ?: [] as $u) {
            $uid = (int)$u['id'];
            if (in_array($u['role'], ['admin', 'super_admin'], true)) {
                $map[$uid] = $adminProducts[$uid] ?? [];
            } else {
                $aid = (int)($u['admin_id'] ?? 0);
                $map[$uid] = $aid ? ($adminProducts[$aid] ?? []) : [];
            }
        }
        return $map;
    } catch (Throwable $e) {
        error_log('[mawared] products_map: ' . $e->getMessage());
        return [];
    }
}

if ($action === 'list_products') {
    $u = require_auth(['super_admin', 'admin']);
    ensure_ops_tables();
    $rows = DB::all("SELECT * FROM products ORDER BY is_active DESC, name ASC");
    $teams = DB::all(
        "SELECT pt.*, u.full_name AS admin_name, p.name AS product_name,
                (SELECT COUNT(*) FROM users m WHERE m.is_active = 1 AND (m.id = pt.admin_id OR m.admin_id = pt.admin_id)) AS members_count
         FROM product_teams pt
         JOIN users u ON u.id = pt.admin_id
         JOIN products p ON p.id = pt.product_id
         ORDER BY p.name, u.full_name"
    );
    ok(['products' => $rows ?: [], 'teams' => $teams ?: []]);
}

if ($action === 'save_product') {
    $u = require_auth(['super_admin']);
    ensure_ops_tables();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
    $code = mb_substr(trim((string)($in['code'] ?? '')), 0, 40);
    $active = !empty($in['is_active']) ? 1 : 0;
    if ($name === '') fail('اسم المنتج مطلوب');
    if ($id) {
        DB::q("UPDATE products SET name=?, code=?, is_active=? WHERE id=?", [$name, $code ?: null, $active, $id]);
    } else {
        DB::q("INSERT INTO products (name, code, is_active, created_at) VALUES (?,?,1,?)",
            [$name, $code ?: null, date('Y-m-d H:i:s')]);
        $id = (int)DB::conn()->lastInsertId();
    }
    ok(['message' => 'تم حفظ المنتج', 'id' => $id]);
}

if ($action === 'assign_product_team') {
    $u = require_auth(['super_admin']);
    ensure_ops_tables();
    $in = input();
    $productId = (int)($in['product_id'] ?? 0);
    $adminId = (int)($in['admin_id'] ?? 0);
    $teamName = mb_substr(trim((string)($in['team_name'] ?? '')), 0, 120);
    if (!$productId || !$adminId) fail('المنتج والأدمن مطلوبان');
    $p = DB::one("SELECT id FROM products WHERE id = ?", [$productId]);
    $a = DB::one("SELECT id, role FROM users WHERE id = ? AND is_active = 1", [$adminId]);
    if (!$p || !$a) fail('بيانات غير صحيحة');
    $ex = DB::one("SELECT id FROM product_teams WHERE product_id = ? AND admin_id = ?", [$productId, $adminId]);
    if ($ex) {
        DB::q("UPDATE product_teams SET team_name = ? WHERE id = ?", [$teamName ?: null, $ex['id']]);
    } else {
        DB::q("INSERT INTO product_teams (product_id, admin_id, team_name, created_at) VALUES (?,?,?,?)",
            [$productId, $adminId, $teamName ?: null, date('Y-m-d H:i:s')]);
    }
    ok(['message' => 'تم ربط الفريق بالمنتج']);
}

if ($action === 'remove_product_team') {
    $u = require_auth(['super_admin']);
    ensure_ops_tables();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    if (!$id) fail('id مطلوب');
    DB::q("DELETE FROM product_teams WHERE id = ?", [$id]);
    ok(['message' => 'تم']);
}

if ($action === 'set_daily_target') {
    $u = require_auth(['super_admin', 'admin']);
    ensure_ops_tables();
    $in = input();
    $userId = (int)($in['user_id'] ?? 0);
    $date = valid_date($in['target_date'] ?? '', date('Y-m-d'));
    $calls = max(0, (int)($in['calls_target'] ?? 0));
    $visits = max(0, (int)($in['visits_target'] ?? 0));
    if (!$userId) fail('اختر الموظف');
    if ($calls === 0 && $visits === 0) fail('حط هدف مكالمات أو زيارات على الأقل');

    // صلاحية النطاق
    if ($u['role'] === 'admin') {
        $okUser = DB::one("SELECT id FROM users WHERE id = ? AND (id = ? OR admin_id = ?)", [$userId, $u['id'], $u['id']]);
        if (!$okUser) fail('الموظف مش من فريقك', 403);
    }

    $ex = DB::one("SELECT id FROM daily_targets WHERE user_id = ? AND target_date = ?", [$userId, $date]);
    if ($ex) {
        DB::q("UPDATE daily_targets SET calls_target=?, visits_target=?, set_by=? WHERE id=?",
            [$calls, $visits, (int)$u['id'], $ex['id']]);
    } else {
        DB::q("INSERT INTO daily_targets (user_id, target_date, calls_target, visits_target, set_by, created_at)
               VALUES (?,?,?,?,?,?)",
            [$userId, $date, $calls, $visits, (int)$u['id'], date('Y-m-d H:i:s')]);
    }
    ok(['message' => 'تم حفظ الهدف اليومي']);
}

if ($action === 'delete_daily_target') {
    $u = require_auth(['super_admin', 'admin']);
    ensure_ops_tables();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    $userId = (int)($in['user_id'] ?? 0);
    $date = valid_date($in['target_date'] ?? '', '');
    $row = null;
    if ($id) {
        $row = DB::one("SELECT * FROM daily_targets WHERE id = ?", [$id]);
    } elseif ($userId && $date) {
        $row = DB::one("SELECT * FROM daily_targets WHERE user_id = ? AND target_date = ?", [$userId, $date]);
    }
    if (!$row) fail('الهدف غير موجود', 404);
    if ($u['role'] === 'admin') {
        $okUser = DB::one(
            "SELECT id FROM users WHERE id = ? AND (id = ? OR admin_id = ?)",
            [(int)$row['user_id'], (int)$u['id'], (int)$u['id']]
        );
        if (!$okUser) fail('الهدف ده مش من فريقك', 403);
    }
    DB::q("DELETE FROM daily_targets WHERE id = ?", [(int)$row['id']]);
    ok(['message' => 'تم حذف الهدف']);
}

if ($action === 'my_daily_target') {
    $u = require_auth();
    if (!ensure_ops_tables()) {
        ok(['target' => null, 'progress' => ['calls_done'=>0,'visits_done'=>0,'calls_target'=>0,'visits_target'=>0], 'date' => date('Y-m-d')]);
    }
    $date = valid_date($_GET['date'] ?? '', date('Y-m-d'));
    $t = DB::one("SELECT * FROM daily_targets WHERE user_id = ? AND target_date = ?", [(int)$u['id'], $date]);
    $ts = $date . ' 00:00:00';
    $te = $date . ' 23:59:59';
    $callsDone = (int)(DB::one(
        "SELECT COUNT(*) AS c FROM calls WHERE user_id = ? AND called_at BETWEEN ? AND ? AND (status IS NULL OR status NOT LIKE 'زيارة%')",
        [(int)$u['id'], $ts, $te]
    )['c'] ?? 0);
    $visitsDone = (int)(DB::one(
        "SELECT COUNT(*) AS c FROM visits WHERE collector_id = ? AND (
            (visited_at BETWEEN ? AND ?) OR (visited_at IS NULL AND visit_date = ? AND status <> 'pending' AND status IS NOT NULL AND status <> '')
         ) AND status <> 'pending'",
        [(int)$u['id'], $ts, $te, $date]
    )['c'] ?? 0);
    ok([
        'target' => $t,
        'progress' => [
            'calls_done' => $callsDone,
            'visits_done' => $visitsDone,
            'calls_target' => (int)($t['calls_target'] ?? 0),
            'visits_target' => (int)($t['visits_target'] ?? 0),
        ],
        'date' => $date,
    ]);
}

if ($action === 'team_daily_targets') {
    $u = require_auth(['super_admin', 'admin']);
    ensure_ops_tables();
    $date = valid_date($_GET['date'] ?? '', date('Y-m-d'));
    if ($u['role'] === 'super_admin') {
        $users = DB::all("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role != 'super_admin' ORDER BY full_name");
    } else {
        $users = DB::all(
            "SELECT id, full_name, role FROM users WHERE is_active = 1 AND (id = ? OR admin_id = ?) ORDER BY full_name",
            [(int)$u['id'], (int)$u['id']]
        );
    }
    $targets = DB::all("SELECT * FROM daily_targets WHERE target_date = ?", [$date]);
    $byUser = [];
    foreach ($targets as $t) $byUser[(int)$t['user_id']] = $t;
    $ts = $date . ' 00:00:00';
    $te = $date . ' 23:59:59';
    $out = [];
    foreach ($users as $usr) {
        $uid = (int)$usr['id'];
        $t = $byUser[$uid] ?? null;
        $callsDone = (int)(DB::one(
            "SELECT COUNT(*) AS c FROM calls WHERE user_id = ? AND called_at BETWEEN ? AND ? AND (status IS NULL OR status NOT LIKE 'زيارة%')",
            [$uid, $ts, $te]
        )['c'] ?? 0);
        $visitsDone = (int)(DB::one(
            "SELECT COUNT(*) AS c FROM visits WHERE collector_id = ? AND (
                (visited_at BETWEEN ? AND ?) OR (visited_at IS NULL AND visit_date = ? AND status IS NOT NULL AND status <> '' AND status <> 'pending')
             )",
            [$uid, $ts, $te, $date]
        )['c'] ?? 0);
        $out[] = [
            'user' => $usr,
            'target' => $t,
            'calls_done' => $callsDone,
            'visits_done' => $visitsDone,
        ];
    }
    $pmap = products_map_for_users();
    foreach ($out as &$row) {
        $names = $pmap[(int)$row['user']['id']] ?? [];
        $row['user']['product_names'] = $names;
        $row['user']['product_label'] = $names ? implode(' · ', $names) : '';
    }
    unset($row);
    ok(['date' => $date, 'rows' => $out]);
}

if ($action === 'create_task') {
    $u = require_auth(['super_admin', 'admin']);
    ensure_ops_tables();
    $in = input();
    $toId = (int)($in['to_user_id'] ?? 0);
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
    $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 2000);
    $due = trim((string)($in['due_date'] ?? ''));
    $due = $due !== '' ? valid_date($due, date('Y-m-d')) : null;
    if (!$toId || $title === '') fail('المستلم والعنوان مطلوبان');
    if ($u['role'] === 'admin') {
        $okUser = DB::one("SELECT id FROM users WHERE id = ? AND (id = ? OR admin_id = ?)", [$toId, $u['id'], $u['id']]);
        if (!$okUser) fail('الموظف مش من فريقك', 403);
    }
    try {
        DB::q("INSERT INTO tasks (from_user_id, to_user_id, title, body, due_date, status, created_at)
               VALUES (?,?,?,?,?,'open',?)",
            [(int)$u['id'], $toId, $title, $body !== '' ? $body : null, $due, date('Y-m-d H:i:s')]);
        $taskId = (int)DB::conn()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[mawared] create_task: ' . $e->getMessage());
        fail('فشل حفظ المهمة: ' . $e->getMessage());
    }
    // إشعار للمستلم
    try {
        ensure_activity_tables();
        DB::q("INSERT INTO notifications (from_user_id, from_name, to_user_id, title, body_text, is_read, created_at)
               VALUES (?,?,?,?,?,0,?)",
            [(int)$u['id'], $u['full_name'], $toId, 'مهمة جديدة: ' . $title, $body !== '' ? $body : $title, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {}
    ok(['message' => 'تم إنشاء المهمة — هتظهر عند الموظف فورًا', 'task_id' => $taskId, 'to_user_id' => $toId]);
}

if ($action === 'my_tasks') {
    $u = require_auth();
    if (!ensure_ops_tables()) {
        ok(['tasks' => [], 'warn' => 'جداول المهام مش جاهزة']);
    }
    $status = trim($_GET['status'] ?? 'open');
    $w = "t.to_user_id = ?";
    $p = [(int)$u['id']];
    if ($status === 'open' || $status === 'done') {
        $w .= " AND t.status = ?";
        $p[] = $status;
    }
    try {
        $rows = DB::all(
            "SELECT t.*, u.full_name AS from_name
             FROM tasks t LEFT JOIN users u ON u.id = t.from_user_id
             WHERE $w
             ORDER BY (t.status = 'open') DESC, t.due_date IS NULL, t.due_date ASC, t.id DESC
             LIMIT 100",
            $p
        );
    } catch (Throwable $e) {
        error_log('[mawared] my_tasks: ' . $e->getMessage());
        ok(['tasks' => [], 'warn' => $e->getMessage()]);
    }
    ok(['tasks' => $rows ?: [], 'user_id' => (int)$u['id']]);
}

if ($action === 'tasks_sent') {
    $u = require_auth(['super_admin', 'admin']);
    ensure_ops_tables();
    $rows = DB::all(
        "SELECT t.*, u.full_name AS to_name
         FROM tasks t LEFT JOIN users u ON u.id = t.to_user_id
         WHERE t.from_user_id = ?
         ORDER BY t.id DESC LIMIT 100",
        [(int)$u['id']]
    );
    ok(['tasks' => $rows ?: []]);
}

if ($action === 'complete_task') {
    $u = require_auth();
    ensure_ops_tables();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    if (!$id) fail('id مطلوب');
    $t = DB::one("SELECT * FROM tasks WHERE id = ? AND to_user_id = ?", [$id, (int)$u['id']]);
    if (!$t) fail('المهمة غير موجودة', 404);
    DB::q("UPDATE tasks SET status = 'done', done_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
    ok(['message' => 'تم إنهاء المهمة']);
}

if ($action === 'client_timeline') {
    $u = require_auth();
    $cid = (int)($_GET['client_id'] ?? 0);
    if (!$cid) fail('client_id مطلوب');
    [$cs, $cp] = client_scope($u);
    $c = DB::one("SELECT c.id, c.name FROM clients c WHERE c.id = ? AND $cs", array_merge([$cid], $cp));
    if (!$c) {
        // زيارات في نطاقه
        [$vs, $vp] = visit_scope($u);
        $viaVisit = DB::one("SELECT v.client_id FROM visits v WHERE v.client_id = ? AND $vs LIMIT 1", array_merge([$cid], $vp));
        if (!$viaVisit && $u['role'] !== 'super_admin') fail('العميل مش متاح ليك', 403);
        $c = DB::one("SELECT id, name FROM clients WHERE id = ?", [$cid]);
    }
    $calls = DB::all(
        "SELECT k.id, k.status, k.dcr, k.ptp, k.ptp_date, k.called_at, u.full_name AS user_name
         FROM calls k LEFT JOIN users u ON u.id = k.user_id
         WHERE k.client_id = ?
         ORDER BY k.called_at DESC LIMIT 100",
        [$cid]
    );
    $visits = DB::all(
        "SELECT v.id, v.status, v.visit_date, v.visited_at, v.collected_amount, v.collector_notes, v.admin_notes,
                u.full_name AS collector_name
         FROM visits v LEFT JOIN users u ON u.id = v.collector_id
         WHERE v.client_id = ?
         ORDER BY COALESCE(v.visited_at, v.visit_date) DESC LIMIT 100",
        [$cid]
    );
    $events = [];
    foreach ($calls as $k) {
        $events[] = [
            'type' => 'call',
            'at' => $k['called_at'],
            'title' => $k['status'] ?: 'مكالمة',
            'detail' => $k['dcr'],
            'user' => $k['user_name'],
            'meta' => $k,
        ];
    }
    foreach ($visits as $v) {
        $events[] = [
            'type' => 'visit',
            'at' => $v['visited_at'] ?: ($v['visit_date'] . ' 00:00:00'),
            'title' => 'زيارة: ' . ($v['status'] ?: 'pending'),
            'detail' => trim(($v['collector_notes'] ?: '') . ' ' . ($v['admin_notes'] ?: '')),
            'user' => $v['collector_name'],
            'meta' => $v,
        ];
    }
    usort($events, function ($a, $b) {
        return strcmp($b['at'] ?? '', $a['at'] ?? '');
    });
    ok(['client' => $c, 'events' => $events]);
}

if ($action === 'save_reminder') {
    $u = require_auth();
    ensure_ops_tables();
    $in = input();
    $cid = (int)($in['client_id'] ?? 0);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500);
    $at = trim((string)($in['remind_at'] ?? ''));
    if (!$cid || $at === '') fail('العميل ووقت التذكير مطلوبان');
    // accept "YYYY-MM-DDTHH:MM" or "YYYY-MM-DD HH:MM"
    $at = str_replace('T', ' ', $at);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $at)) $at .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at)) fail('صيغة الوقت غير صحيحة');
    [$cs, $cp] = client_scope($u);
    $c = DB::one("SELECT c.id, c.name FROM clients c WHERE c.id = ? AND $cs", array_merge([$cid], $cp));
    if (!$c && $u['role'] !== 'super_admin') fail('العميل مش متاح ليك', 403);
    if (!$c) $c = DB::one("SELECT id, name FROM clients WHERE id = ?", [$cid]);
    DB::q("INSERT INTO client_reminders (user_id, client_id, remind_at, note, is_done, created_at)
           VALUES (?,?,?,?,0,?)",
        [(int)$u['id'], $cid, $at, $note ?: null, date('Y-m-d H:i:s')]);
    ok(['message' => 'تم ضبط التذكير', 'client_name' => $c['name'] ?? '']);
}

if ($action === 'my_reminders') {
    $u = require_auth();
    ensure_ops_tables();
    $dueOnly = !empty($_GET['due']);
    $w = "r.user_id = ? AND r.is_done = 0";
    $p = [(int)$u['id']];
    if ($dueOnly) {
        $w .= " AND r.remind_at <= ?";
        $p[] = date('Y-m-d H:i:s');
    }
    $rows = DB::all(
        "SELECT r.*, c.name AS client_name, c.phone AS client_phone
         FROM client_reminders r
         LEFT JOIN clients c ON c.id = r.client_id
         WHERE $w
         ORDER BY r.remind_at ASC
         LIMIT 50",
        $p
    );
    ok(['reminders' => $rows ?: []]);
}

if ($action === 'ack_reminder') {
    $u = require_auth();
    ensure_ops_tables();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    if (!$id) fail('id مطلوب');
    DB::q("UPDATE client_reminders SET is_done = 1 WHERE id = ? AND user_id = ?", [$id, (int)$u['id']]);
    ok(['message' => 'تم']);
}

// =============================================================
// Calendar
// =============================================================
function ensure_calendar_events_table() {
    DB::q("CREATE TABLE IF NOT EXISTS calendar_events (
             id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
             assigned_to INT NOT NULL,
             created_by INT NOT NULL,
             event_type VARCHAR(20) NOT NULL DEFAULT 'other',
             title VARCHAR(200) NOT NULL,
             note VARCHAR(1000) DEFAULT NULL,
             client_id INT DEFAULT NULL,
             client_name VARCHAR(200) DEFAULT NULL,
             start_at DATETIME NOT NULL,
             all_day TINYINT(1) NOT NULL DEFAULT 0,
             status VARCHAR(12) NOT NULL DEFAULT 'open',
             created_at DATETIME NOT NULL,
             updated_at DATETIME DEFAULT NULL,
             KEY idx_assigned (assigned_to, start_at),
             KEY idx_created (created_by, start_at),
             KEY idx_start (start_at)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function calendar_event_scope($u) {
    if ($u['role'] === 'super_admin') return ['1=1', []];
    if ($u['role'] === 'admin') {
        $ids = team_ids($u);
        $ph = in_list($ids);
        return ["(ce.assigned_to IN ($ph) OR ce.created_by IN ($ph))", array_merge($ids, $ids)];
    }
    $id = (int)$u['id'];
    return ['(ce.assigned_to = ? OR ce.created_by = ?)', [$id, $id]];
}

function parse_calendar_datetime($value) {
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) return null;
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $value) return null;
    return $date->format('Y-m-d H:i:s');
}

if ($action === 'get_calendar_events') {
    $u = require_auth();
    ensure_calendar_events_table();
    $month = trim((string)($_GET['month'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) fail('صيغة الشهر غير صحيحة');
    $start = DateTime::createFromFormat('!Y-m-d', $month . '-01');
    $errors = DateTime::getLastErrors();
    if (!$start || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $start->format('Y-m') !== $month) fail('الشهر غير صحيح');
    $end = (clone $start)->modify('first day of next month');
    [$scope, $params] = calendar_event_scope($u);
    $events = DB::all(
        "SELECT ce.*, assigned.full_name AS assigned_name
         FROM calendar_events ce
         LEFT JOIN users assigned ON assigned.id = ce.assigned_to
         WHERE ce.start_at >= ? AND ce.start_at < ? AND $scope
         ORDER BY ce.start_at ASC, ce.id ASC",
        array_merge([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], $params)
    );
    ok(['events' => $events ?: [], 'month' => $month]);
}

if ($action === 'save_calendar_event') {
    $u = require_auth();
    ensure_calendar_events_table();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    $title = trim((string)($in['title'] ?? ''));
    $note = trim((string)($in['note'] ?? ''));
    $type = trim((string)($in['event_type'] ?? 'other'));
    $startAt = parse_calendar_datetime($in['start_at'] ?? '');
    $allDay = !empty($in['all_day']) ? 1 : 0;
    $allowedTypes = ['meeting', 'call', 'visit', 'reminder', 'other'];
    if ($title === '') fail('عنوان الموعد مطلوب');
    if (mb_strlen($title) > 200) fail('عنوان الموعد أطول من المسموح');
    if (mb_strlen($note) > 1000) fail('ملاحظات الموعد أطول من المسموح');
    if (!in_array($type, $allowedTypes, true)) fail('نوع الموعد غير صحيح');
    if (!$startAt) fail('تاريخ ووقت الموعد غير صحيح');

    if ($id) {
        [$scope, $params] = calendar_event_scope($u);
        $existing = DB::one("SELECT ce.* FROM calendar_events ce WHERE ce.id = ? AND $scope", array_merge([$id], $params));
        if (!$existing) fail('الموعد غير موجود أو ليس لديك صلاحية تعديله', 404);
        DB::q(
            "UPDATE calendar_events
             SET event_type = ?, title = ?, note = ?, start_at = ?, all_day = ?, updated_at = ?
             WHERE id = ?",
            [$type, $title, $note !== '' ? $note : null, $startAt, $allDay, date('Y-m-d H:i:s'), $id]
        );
        ok(['message' => 'تم تحديث الموعد', 'id' => $id]);
    }

    DB::q(
        "INSERT INTO calendar_events
         (assigned_to, created_by, event_type, title, note, start_at, all_day, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?)",
        [(int)$u['id'], (int)$u['id'], $type, $title, $note !== '' ? $note : null, $startAt, $allDay, date('Y-m-d H:i:s')]
    );
    ok(['message' => 'تمت إضافة الموعد', 'id' => (int)DB::conn()->lastInsertId()]);
}

if ($action === 'complete_calendar_event') {
    $u = require_auth();
    ensure_calendar_events_table();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    if (!$id) fail('id مطلوب');
    [$scope, $params] = calendar_event_scope($u);
    $event = DB::one("SELECT ce.id, ce.status FROM calendar_events ce WHERE ce.id = ? AND $scope", array_merge([$id], $params));
    if (!$event) fail('الموعد غير موجود أو ليس لديك صلاحية لتعديله', 404);
    $status = $event['status'] === 'done' ? 'open' : 'done';
    DB::q("UPDATE calendar_events SET status = ?, updated_at = ? WHERE id = ?", [$status, date('Y-m-d H:i:s'), $id]);
    ok(['message' => $status === 'done' ? 'تم إنجاز الموعد' : 'تمت إعادة فتح الموعد', 'status' => $status]);
}

if ($action === 'delete_calendar_event') {
    $u = require_auth();
    ensure_calendar_events_table();
    $in = input();
    $id = (int)($in['id'] ?? 0);
    if (!$id) fail('id مطلوب');
    [$scope, $params] = calendar_event_scope($u);
    $event = DB::one("SELECT ce.id FROM calendar_events ce WHERE ce.id = ? AND $scope", array_merge([$id], $params));
    if (!$event) fail('الموعد غير موجود أو ليس لديك صلاحية لحذفه', 404);
    DB::q("DELETE FROM calendar_events WHERE id = ?", [$id]);
    ok(['message' => 'تم حذف الموعد']);
}

// =============================================================
// Monthly collection cycles and operations center
// =============================================================
function collection_cycle_visible($u, $cycleId) {
    if ($u['role'] === 'super_admin') return true;
    if ($u['role'] === 'admin') {
        return (bool)DB::one("SELECT id FROM collection_cycles WHERE id = ? AND created_by = ?", [$cycleId, $u['id']]);
    }
    return (bool)DB::one("SELECT id FROM collection_assignments WHERE cycle_id = ? AND user_id = ?", [$cycleId, $u['id']]);
}

function require_cycle_manager($u, $cycleId = 0) {
    if (!in_array($u['role'], ['admin', 'super_admin'], true)) fail('الإجراء متاح للإدارة فقط', 403);
    if ($cycleId && !collection_cycle_visible($u, $cycleId)) fail('الدورة غير موجودة أو لا تتبع نطاقك', 404);
}

function collection_cycle_payload($cycleId) {
    $cycle = DB::one(
        "SELECT cc.*, creator.full_name AS creator_name,
                (SELECT COUNT(*) FROM clients c WHERE c.cycle_id = cc.id) AS client_count,
                (SELECT COUNT(*) FROM collection_assignments ca WHERE ca.cycle_id = cc.id) AS worker_count
         FROM collection_cycles cc
         LEFT JOIN users creator ON creator.id = cc.created_by
         WHERE cc.id = ?",
        [$cycleId]
    );
    if (!$cycle) return null;
    $cycle['assignments'] = DB::all(
        "SELECT ca.*, u.full_name, u.username, u.role,
                (SELECT COUNT(*) FROM clients c WHERE c.cycle_id = ca.cycle_id AND c.assigned_to = ca.user_id) AS assigned_clients_count,
                COALESCE((SELECT SUM(ec.amount) FROM collection_employee_collections ec
                          WHERE ec.cycle_id = ca.cycle_id AND ec.employee_id = ca.user_id), 0) AS credited_total
         FROM collection_assignments ca JOIN users u ON u.id = ca.user_id
         WHERE ca.cycle_id = ? ORDER BY u.full_name",
        [$cycleId]
    );
    foreach ($cycle['assignments'] as &$assignment) {
        $assignment['is_current_cycle'] = employee_active_collection_cycle((int)$assignment['user_id']) === (int)$cycleId;
    }
    unset($assignment);
    $cycle['source_available'] = is_file(collection_cycle_source_path($cycleId));
    return $cycle;
}

if ($action === 'preview_collection_sheet') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $cycleId = (int)($_POST['cycle_id'] ?? 0);
    $employeeId = (int)($_POST['assign_to'] ?? 0);
    require_cycle_manager($u, $cycleId);
    $cycle = DB::one("SELECT id, status FROM collection_cycles WHERE id = ?", [$cycleId]);
    if (!$cycle || !in_array($cycle['status'], ['draft', 'active'], true)) fail('الدورة غير موجودة أو لا تقبل ملفات جديدة', 409);
    $assignment = DB::one(
        "SELECT id, status FROM collection_assignments WHERE cycle_id = ? AND user_id = ?",
        [$cycleId, $employeeId]
    );
    if (!$assignment) fail('يجب إسناد الدورة إلى الموظف قبل معاينة ملفه');
    if (in_array($assignment['status'], ['submitted', 'approved'], true)) fail('تم تسليم ملف هذا الموظف أو اعتماده بالفعل', 409);
    $employee = DB::one("SELECT id, role, admin_id, is_active FROM users WHERE id = ?", [$employeeId]);
    if (!$employee || !$employee['is_active'] || !in_array($employee['role'], ['collector', 'data_entry'], true)) fail('الموظف المحدد غير صالح');
    if ($u['role'] === 'admin' && (int)$employee['admin_id'] !== (int)$u['id']) fail('الموظف ليس ضمن فريقك', 403);
    if (empty($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
        fail('اختر ملف Excel صالحًا لمعاينته');
    }
    $file = $_FILES['sheet'];
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') fail('الملف لازم يكون بصيغة .xlsx');
    if ($file['size'] > 20 * 1024 * 1024) fail('حجم الملف أكبر من 20 ميجا');

    $xlsxLib = __DIR__ . '/simple_xlsx/SimpleXLSX.php';
    if (!file_exists($xlsxLib)) $xlsxLib = __DIR__ . '/SimpleXLSX.php';
    if (!file_exists($xlsxLib)) fail('مكتبة SimpleXLSX.php مش موجودة', 500);
    require_once $xlsxLib;
    $xlsx = Shuchkin\SimpleXLSX::parse($file['tmp_name']);
    if (!$xlsx) fail('تعذر قراءة الملف: ' . Shuchkin\SimpleXLSX::parseError(), 400);

    $collectorValues = [];
    $collectorColumnFound = false;
    foreach ($xlsx->sheetNames() as $sheetIndex => $sheetName) {
        $rows = $xlsx->rows($sheetIndex);
        if (!$rows) continue;
        $header = array_map('trim', $rows[0]);
        $columnIndex = collection_coll_column_index($header);
        if ($columnIndex === null) continue;
        $collectorColumnFound = true;
        for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex++) {
            $value = trim((string)($rows[$rowIndex][$columnIndex] ?? ''));
            if ($value === '') continue;
            if (!isset($collectorValues[$value])) $collectorValues[$value] = 0;
            $collectorValues[$value]++;
        }
    }
    if (!$collectorColumnFound) fail('الشيت لا يحتوي على عمود coll');
    if (!$collectorValues) fail('عمود coll لا يحتوي على قيم يمكن اختيارها');
    if (count($collectorValues) > 500) fail('عمود coll يحتوي على أكثر من 500 قيمة مختلفة؛ نظّف بيانات العمود قبل الرفع');
    $assignedGroups = DB::all(
        "SELECT coll, MIN(assigned_to) AS assigned_to, COUNT(DISTINCT assigned_to) AS assignee_count
         FROM clients WHERE cycle_id = ? GROUP BY coll",
        [$cycleId]
    );
    $assignmentByGroup = [];
    foreach ($assignedGroups as $group) {
        $assignmentByGroup[(string)$group['coll']] = [
            'assigned_to' => (int)$group['assigned_to'],
            'assignee_count' => (int)$group['assignee_count'],
        ];
    }
    asort($collectorValues, SORT_NATURAL | SORT_FLAG_CASE);
    $values = [];
    foreach ($collectorValues as $value => $count) {
        $assigned = $assignmentByGroup[(string)$value] ?? null;
        $values[] = [
            'value' => $value,
            'count' => (int)$count,
            'assigned_elsewhere' => $assigned && (
                $assigned['assignee_count'] > 1 || $assigned['assigned_to'] !== $employeeId
            ),
        ];
    }
    ok(['collector_column' => 'coll', 'values' => $values, 'value_count' => count($values)]);
}

if ($action === 'collection_cycles') {
    $u = require_auth();
    ensure_collection_schema();
    if ($u['role'] === 'super_admin') {
        $where = '1=1';
        $params = [];
    } elseif ($u['role'] === 'admin') {
        $where = 'cc.created_by = ?';
        $params = [(int)$u['id']];
    } else {
        $where = 'EXISTS (SELECT 1 FROM collection_assignments ca WHERE ca.cycle_id = cc.id AND ca.user_id = ?)';
        $params = [(int)$u['id']];
    }
    $cycles = DB::all(
        "SELECT cc.*, creator.full_name AS creator_name,
                (SELECT COUNT(*) FROM clients c WHERE c.cycle_id = cc.id) AS client_count,
                (SELECT COUNT(*) FROM collection_assignments ca WHERE ca.cycle_id = cc.id) AS worker_count
         FROM collection_cycles cc
         LEFT JOIN users creator ON creator.id = cc.created_by
         WHERE $where ORDER BY cc.month_key DESC, cc.id DESC",
        $params
    );
    ok(['cycles' => $cycles]);
}

if ($action === 'create_collection_cycle') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $in = input();
    $name = trim((string)($in['name'] ?? ''));
    $month = trim((string)($in['month_key'] ?? date('Y-m')));
    $portfolio = trim((string)($in['portfolio_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 180) fail('اسم الدورة مطلوب وبحد أقصى 180 حرفًا');
    $date = DateTime::createFromFormat('!Y-m', $month);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m') !== $month) {
        fail('صيغة شهر الدورة غير صحيحة');
    }
    $exists = DB::one("SELECT id FROM collection_cycles WHERE created_by = ? AND month_key = ? AND status <> 'archived' LIMIT 1", [$u['id'], $month]);
    if ($exists) fail('توجد دورة لهذا الشهر بالفعل', 409);
    DB::insert('collection_cycles', [
        'name' => $name,
        'portfolio_name' => $portfolio !== '' ? $portfolio : null,
        'month_key' => $month,
        'status' => 'active',
        'created_by' => (int)$u['id'],
        'started_at' => date('Y-m-d H:i:s'),
    ]);
    ok(['message' => 'تم إنشاء دورة التحصيل', 'id' => (int)DB::conn()->lastInsertId()]);
}

if ($action === 'collection_cycle_detail') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $cycleId = (int)($_GET['cycle_id'] ?? $_GET['id'] ?? 0);
    if (!$cycleId || !collection_cycle_visible($u, $cycleId)) fail('الدورة غير موجودة أو ليس لديك صلاحية لعرضها', 404);
    $cycle = collection_cycle_payload($cycleId);
    ok(['cycle' => $cycle]);
}

if ($action === 'get_collection_workers') {
    $u = require_auth(['admin', 'super_admin']);
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if ($cycleId) require_cycle_manager($u, $cycleId);
    if ($u['role'] === 'super_admin') {
        $workers = DB::all(
            "SELECT u.id, u.full_name, u.username, u.role, adm.full_name AS admin_name
             FROM users u LEFT JOIN users adm ON adm.id = u.admin_id
             WHERE u.role IN ('collector','data_entry') AND u.is_active = 1 ORDER BY u.full_name"
        );
    } else {
        $workers = DB::all(
            "SELECT u.id, u.full_name, u.username, u.role, adm.full_name AS admin_name
             FROM users u LEFT JOIN users adm ON adm.id = u.admin_id
             WHERE u.role IN ('collector','data_entry') AND u.is_active = 1 AND u.admin_id = ?
             ORDER BY u.full_name",
            [$u['id']]
        );
    }
    ok(['workers' => $workers, 'collectors' => $workers]);
}

if ($action === 'collection_assign_employee') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $in = input();
    $cycleId = (int)($in['cycle_id'] ?? 0);
    $userId = (int)($in['user_id'] ?? $in['employee_id'] ?? 0);
    require_cycle_manager($u, $cycleId);
    $cycle = DB::one("SELECT id, status FROM collection_cycles WHERE id = ?", [$cycleId]);
    if (!$cycle || !in_array($cycle['status'], ['draft', 'active'], true)) fail('لا يمكن الإسناد إلى دورة مغلقة', 409);
    $target = DB::one("SELECT id, role, admin_id, is_active FROM users WHERE id = ?", [$userId]);
    if (!$target || !$target['is_active'] || !in_array($target['role'], ['collector', 'data_entry'], true)) fail('الموظف المحدد غير صالح');
    if ($u['role'] === 'admin' && (int)$target['admin_id'] !== (int)$u['id']) fail('الموظف ليس ضمن فريقك', 403);
    DB::q(
        "INSERT INTO collection_assignments (cycle_id, user_id, status)
         VALUES (?, ?, 'pending')
         ON DUPLICATE KEY UPDATE status = IF(status IN ('submitted','approved'), status, 'pending'), updated_at = NOW()",
        [$cycleId, $userId]
    );
    ok(['message' => 'تم إسناد الدورة إلى الموظف']);
}

if ($action === 'record_employee_collection') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $in = input();
    $cycleId = (int)($in['cycle_id'] ?? 0);
    $employeeId = (int)($in['employee_id'] ?? 0);
    $amount = trim((string)($in['amount'] ?? ''));
    require_cycle_manager($u, $cycleId);
    if (!$employeeId || !preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $amount) || (float)$amount <= 0) {
        fail('اختر موظفًا واكتب مبلغ تحصيل صحيحًا أكبر من صفر');
    }
    $cycle = DB::one("SELECT id, status FROM collection_cycles WHERE id = ?", [$cycleId]);
    if (!$cycle || !in_array($cycle['status'], ['active', 'review'], true)) {
        fail('يمكن تسجيل التحصيلات في الدورة النشطة أو قيد المراجعة فقط', 409);
    }
    $assignment = DB::one(
        "SELECT ca.id FROM collection_assignments ca
         JOIN users employee ON employee.id = ca.user_id
         WHERE ca.cycle_id = ? AND ca.user_id = ?
           AND employee.role IN ('collector','data_entry') AND employee.is_active = 1",
        [$cycleId, $employeeId]
    );
    if (!$assignment) fail('الموظف غير مسند إليه العمل في هذه الدورة', 404);
    if ($u['role'] === 'admin' && !DB::one("SELECT id FROM users WHERE id = ? AND admin_id = ?", [$employeeId, $u['id']])) {
        fail('الموظف ليس ضمن فريقك', 403);
    }
    if (employee_active_collection_cycle($employeeId) !== $cycleId) {
        fail('هذه ليست دورة الموظف الحالية؛ سجّل التحصيل في الدورة النشطة الظاهرة لديه', 409);
    }
    DB::insert('collection_employee_collections', [
        'cycle_id' => $cycleId,
        'employee_id' => $employeeId,
        'amount' => $amount,
        'created_by' => (int)$u['id'],
    ]);
    $total = DB::one(
        "SELECT COALESCE(SUM(amount), 0) AS total
         FROM collection_employee_collections WHERE cycle_id = ? AND employee_id = ?",
        [$cycleId, $employeeId]
    );
    ok([
        'message' => 'تم تسجيل التحصيل للموظف',
        'credited_total' => (float)($total['total'] ?? 0),
    ]);
}

if ($action === 'approve_collection_assignment') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $in = input();
    $cycleId = (int)($in['cycle_id'] ?? 0);
    $userId = (int)($in['user_id'] ?? $in['employee_id'] ?? 0);
    require_cycle_manager($u, $cycleId);
    $assignment = DB::one(
        "SELECT ca.* FROM collection_assignments ca JOIN collection_cycles cc ON cc.id = ca.cycle_id
         WHERE ca.cycle_id = ? AND ca.user_id = ?",
        [$cycleId, $userId]
    );
    if (!$assignment) fail('الإسناد غير موجود', 404);
    if ($u['role'] === 'admin' && !DB::one("SELECT id FROM users WHERE id = ? AND admin_id = ?", [$userId, $u['id']])) fail('الموظف ليس ضمن فريقك', 403);
    if ($assignment['status'] !== 'submitted') fail('يجب أن يسلّم الموظف ملفه قبل اعتماده', 409);
    DB::q("UPDATE collection_assignments SET status = 'approved', approved_at = NOW(), updated_at = NOW() WHERE id = ?", [$assignment['id']]);
    ok(['message' => 'تم اعتماد تسليم الموظف']);
}

if ($action === 'submit_collection_assignment') {
    $u = require_auth(['collector', 'data_entry']);
    ensure_collection_schema();
    $in = input();
    $cycleId = (int)($in['cycle_id'] ?? 0);
    $assignment = DB::one(
        "SELECT ca.id, ca.status, cc.status AS cycle_status FROM collection_assignments ca
         JOIN collection_cycles cc ON cc.id = ca.cycle_id WHERE ca.cycle_id = ? AND ca.user_id = ?",
        [$cycleId, $u['id']]
    );
    if (!$assignment) fail('لا يوجد إسناد لك في هذه الدورة', 404);
    if (!in_array($assignment['cycle_status'], ['draft', 'active', 'review'], true)) fail('الدورة مغلقة', 409);
    if ($assignment['status'] === 'approved') fail('تم اعتماد هذا التسليم بالفعل', 409);
    $count = (int)(DB::one("SELECT COUNT(*) AS n FROM clients WHERE cycle_id = ? AND assigned_to = ?", [$cycleId, $u['id']])['n'] ?? 0);
    if (!$count) fail('لا يوجد ملف عملاء مسجل في هذه الدورة لإرساله');
    DB::q(
        "UPDATE collection_assignments SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ?",
        [$assignment['id']]
    );
    DB::q(
        "UPDATE clients SET submitted_at = NOW() WHERE cycle_id = ? AND assigned_to = ?",
        [$cycleId, $u['id']]
    );
    $pending = (int)(DB::one("SELECT COUNT(*) AS n FROM collection_assignments WHERE cycle_id = ? AND status <> 'submitted' AND status <> 'approved'", [$cycleId])['n'] ?? 0);
    if (!$pending) DB::q("UPDATE collection_cycles SET status = 'review', submitted_at = NOW(), updated_at = NOW() WHERE id = ?", [$cycleId]);
    ok(['message' => 'تم إرسال الملف للمراجعة', 'client_count' => $count]);
}

if ($action === 'close_collection_cycle') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $in = input();
    $cycleId = (int)($in['cycle_id'] ?? 0);
    require_cycle_manager($u, $cycleId);
    $cycle = DB::one("SELECT id, status FROM collection_cycles WHERE id = ?", [$cycleId]);
    if (!$cycle || !in_array($cycle['status'], ['active', 'review'], true)) fail('لا يمكن أرشفة هذه الدورة في حالتها الحالية', 409);
    $notApproved = (int)(DB::one("SELECT COUNT(*) AS n FROM collection_assignments WHERE cycle_id = ? AND status <> 'approved'", [$cycleId])['n'] ?? 0);
    if ($notApproved) fail('اعتمد جميع تسليمات الموظفين قبل أرشفة الدورة', 409);
    @set_time_limit(300);
    DB::conn()->beginTransaction();
    $archivedCount = 0;
    try {
        $lastClientId = 0;
        do {
            $clients = DB::all(
                "SELECT * FROM clients WHERE cycle_id = ? AND id > ? ORDER BY id LIMIT 500",
                [$cycleId, $lastClientId]
            );
            foreach ($clients as $client) {
                DB::insert('collection_archive_clients', [
                    'cycle_id' => $cycleId,
                    'original_client_id' => (int)$client['id'],
                    'employee_id' => (int)$client['assigned_to'],
                    'snapshot_json' => json_encode($client, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
                $lastClientId = (int)$client['id'];
                $archivedCount++;
            }
        } while (count($clients) === 500);
        DB::q("UPDATE collection_cycles SET status = 'archived', closed_at = NOW(), updated_at = NOW() WHERE id = ?", [$cycleId]);
        DB::conn()->commit();
    } catch (Throwable $e) {
        DB::conn()->rollBack();
        throw $e;
    }
    ok(['message' => 'تم إغلاق الدورة وحفظ نسخة أرشيفية', 'archived_clients' => $archivedCount]);
}

if ($action === 'download_collection_sheet') {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    require_cycle_manager($u, $cycleId);
    $cycle = DB::one("SELECT id, status FROM collection_cycles WHERE id = ?", [$cycleId]);
    if (!$cycle) fail('الدورة غير موجودة', 404);
    $assignments = DB::all(
        "SELECT user_id, status FROM collection_assignments WHERE cycle_id = ?",
        [$cycleId]
    );
    if (!$assignments || count(array_filter($assignments, static fn($row) => $row['status'] !== 'approved')) > 0) {
        fail('اعتمد تسليمات جميع الموظفين قبل تنزيل الشيت المجمع', 409);
    }

    $sourcePath = collection_cycle_source_path($cycleId);
    $xlsxLib = __DIR__ . '/simple_xlsx/SimpleXLSX.php';
    if (!file_exists($xlsxLib)) $xlsxLib = __DIR__ . '/SimpleXLSX.php';
    $genLib = __DIR__ . '/simple_xlsx/SimpleXLSXGen.php';
    if (!file_exists($genLib)) $genLib = __DIR__ . '/SimpleXLSXGen.php';
    if (!file_exists($xlsxLib) || !file_exists($genLib)) fail('مكتبات قراءة أو إنشاء Excel غير موجودة', 500);
    require_once $xlsxLib;
    require_once $genLib;

    $clients = DB::all(
        "SELECT sheet_name, excel_row, phone, coll, status, ptp, ptp_date, dcr
         FROM clients WHERE cycle_id = ? ORDER BY id",
        [$cycleId]
    );
    $clientsByRow = [];
    foreach ($clients as $client) {
        $sheetName = (string)$client['sheet_name'];
        $excelRow = (int)$client['excel_row'];
        if (isset($clientsByRow[$sheetName][$excelRow])) {
            fail('يوجد صف واحد من الشيت مسند لأكثر من موظف؛ راجع توزيع مجموعات coll', 409);
        }
        $clientsByRow[$sheetName][$excelRow] = $client;
    }

    $findColumn = static function ($header, $aliases) {
        $normalized = [];
        foreach ($header as $index => $name) {
            $normalized[strtolower(trim((string)$name))] = $index;
        }
        foreach ($aliases as $alias) {
            $key = strtolower(trim((string)$alias));
            if (array_key_exists($key, $normalized)) return $normalized[$key];
        }
        return null;
    };
    $xlsx = null;
    if (is_file($sourcePath)) {
        $xlsx = Shuchkin\SimpleXLSX::parse($sourcePath);
        if (!$xlsx) fail('تعذر قراءة ملف المصدر المحفوظ: ' . Shuchkin\SimpleXLSX::parseError(), 500);
    } else {
        $matchesCycleRows = static function ($candidateXlsx) use ($clientsByRow, $findColumn) {
            if (!$clientsByRow) return false;
            $sheets = [];
            foreach ($candidateXlsx->sheetNames() as $sheetIndex => $sheetName) {
                $rows = $candidateXlsx->rows($sheetIndex);
                if (!$rows) continue;
                $header = array_map(static fn($value) => trim((string)$value), $rows[0]);
                $sheets[$sheetName] = [
                    'rows' => $rows,
                    'phone_column' => $findColumn($header, ['هاتف العميل', 'تليفون العميل', 'رقم التليفون', 'الهاتف', 'رقم الهاتف']),
                    'coll_column' => collection_coll_column_index($header),
                ];
            }
            foreach ($clientsByRow as $sheetName => $sheetClients) {
                $sheet = $sheets[$sheetName] ?? null;
                if (!$sheet || $sheet['phone_column'] === null || $sheet['coll_column'] === null) return false;
                foreach ($sheetClients as $excelRow => $client) {
                    $rowIndex = (int)$excelRow - 1;
                    if (!isset($sheet['rows'][$rowIndex])
                        || clean_phone($sheet['rows'][$rowIndex][$sheet['phone_column']] ?? '') !== clean_phone($client['phone'])
                        || trim((string)($sheet['rows'][$rowIndex][$sheet['coll_column']] ?? '')) !== trim((string)$client['coll'])) {
                        return false;
                    }
                }
            }
            return true;
        };
        foreach ($assignments as $assignment) {
            $employeeDir = __DIR__ . '/uploads/sheets/' . (int)$assignment['user_id'];
            $candidates = [$employeeDir . '/current.xlsx'];
            $history = glob($employeeDir . '/history/*.xlsx') ?: [];
            rsort($history, SORT_STRING);
            $candidates = array_merge($candidates, $history);
            foreach ($candidates as $candidate) {
                if (!is_file($candidate)) continue;
                $candidateXlsx = Shuchkin\SimpleXLSX::parse($candidate);
                if (!$candidateXlsx || !$matchesCycleRows($candidateXlsx)) continue;
                $sourceDir = dirname($sourcePath);
                if (!is_dir($sourceDir) && !@mkdir($sourceDir, 0755, true)) {
                    fail('تعذر حفظ نسخة المصدر المستعادة للدورة', 500);
                }
                if (!@copy($candidate, $sourcePath)) fail('تعذر حفظ نسخة المصدر المستعادة للدورة', 500);
                $xlsx = $candidateXlsx;
                break 2;
            }
        }
        if (!$xlsx) fail('تعذر استعادة الشيت الأصلي للدورة من ملفات الموظفين؛ أعد رفع الشيت المجمع الأصلي قبل الاعتماد', 409);
    }

    $allData = [];
    foreach ($xlsx->sheetNames() as $sheetIndex => $sheetName) {
        $rows = $xlsx->rows($sheetIndex);
        if (!$rows) {
            $allData[$sheetName] = [['']];
            continue;
        }
        $header = array_map(static fn($value) => trim((string)$value), $rows[0]);
        $columns = [
            'status' => $findColumn($header, ['الحاله', 'الحالة']),
            'ptp' => $findColumn($header, ['ptp']),
            'ptp_date' => $findColumn($header, ['ptp date']),
            'dcr' => $findColumn($header, ['DCR']),
        ];
        $cleanRows = [];
        foreach ($rows as $rowIndex => $row) {
            $row = array_values($row);
            if ($rowIndex > 0) {
                $client = $clientsByRow[$sheetName][$rowIndex + 1] ?? null;
                if ($client) {
                    foreach ($columns as $field => $columnIndex) {
                        if ($columnIndex !== null) $row[$columnIndex] = $client[$field] ?? '';
                    }
                }
            }
            $cleanRows[] = array_map(static fn($cell) => $cell === null ? '' : (string)$cell, $row);
        }
        $allData[$sheetName] = $cleanRows;
    }

    $gen = new Shuchkin\SimpleXLSXGen();
    foreach ($allData as $sheetName => $rows) $gen->addSheet($rows, $sheetName);
    $tempBase = tempnam(sys_get_temp_dir(), 'mawared-cycle-');
    if ($tempBase === false) fail('تعذر تجهيز ملف التنزيل', 500);
    @unlink($tempBase);
    $outFile = $tempBase . '.xlsx';
    if (!$gen->saveAs($outFile)) {
        @unlink($outFile);
        fail('تعذر إنشاء ملف Excel المجمع', 500);
    }

    $filename = 'collection_cycle_' . $cycleId . '_approved.xlsx';
    try {
        DB::insert('sheet_log', [
            'user_id' => (int)$u['id'],
            'cycle_id' => $cycleId,
            'action' => 'download',
            'filename' => $filename,
            'client_count' => count($clients),
        ]);
    } catch (Throwable $e) {
        @unlink($outFile);
        throw $e;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($outFile));
    header('Cache-Control: no-store');
    readfile($outFile);
    @unlink($outFile);
    exit;
}

if ($action === 'my_collection_cycle') {
    $u = require_auth(['collector', 'data_entry']);
    ensure_collection_schema();
    $cycle = DB::one(
        "SELECT cc.id, cc.name, cc.month_key, cc.status, ca.status AS assignment_status,
                ca.assigned_count, ca.uploaded_count, ca.submitted_at,
                (SELECT COUNT(*) FROM clients c WHERE c.cycle_id = cc.id AND c.assigned_to = ?) AS client_count,
                COALESCE((SELECT SUM(ec.amount) FROM collection_employee_collections ec
                          WHERE ec.cycle_id = cc.id AND ec.employee_id = ?), 0) AS credited_total
         FROM collection_assignments ca JOIN collection_cycles cc ON cc.id = ca.cycle_id
         WHERE ca.user_id = ? AND cc.status IN ('draft','active','review')
         ORDER BY cc.month_key DESC, cc.id DESC LIMIT 1",
        [$u['id'], $u['id'], $u['id']]
    );
    ok(['cycle' => $cycle ?: null]);
}

if ($action === 'collection_queue') {
    $u = require_auth();
    ensure_collection_schema();
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if (!$cycleId && in_array($u['role'], ['collector', 'data_entry'], true)) {
        $cycleId = (int)(DB::one(
            "SELECT cc.id FROM collection_assignments ca JOIN collection_cycles cc ON cc.id = ca.cycle_id
             WHERE ca.user_id = ? AND cc.status IN ('draft','active','review')
             ORDER BY cc.month_key DESC, cc.id DESC LIMIT 1",
            [$u['id']]
        )['id'] ?? 0);
    }
    if (!$cycleId || !collection_cycle_visible($u, $cycleId)) fail('الدورة غير موجودة أو ليس لديك صلاحية لعرضها', 404);
    [$scope, $params] = client_scope($u);
    $limit = max(1, min((int)($_GET['limit'] ?? 50), 200));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $total = (int)(DB::one(
        "SELECT COUNT(*) AS n FROM clients c WHERE c.cycle_id = ? AND $scope",
        array_merge([$cycleId], $params)
    )['n'] ?? 0);
    $rows = DB::all(
        "SELECT c.id, c.name, c.phone, c.delay_days, c.loan_balance, c.interest_fine_balance,
                c.collection_state, c.owner_user_id, u.full_name AS owner_name
         FROM clients c          LEFT JOIN users u ON u.id = c.assigned_to
         WHERE c.cycle_id = ? AND $scope ORDER BY c.delay_days DESC, c.id LIMIT ? OFFSET ?",
        array_merge([$cycleId], $params, [$limit, $offset])
    );
    ok(['clients' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

if ($action === 'collection_note') {
    $u = require_auth(['collector', 'data_entry', 'admin', 'super_admin']);
    ensure_collection_schema();
    $in = input();
    $clientId = (int)($in['client_id'] ?? 0);
    $body = trim((string)($in['body'] ?? $in['note'] ?? ''));
    $type = trim((string)($in['note_type'] ?? 'general'));
    $client = DB::one("SELECT id, cycle_id FROM clients WHERE id = ?", [$clientId]);
    if (!$client) fail('العميل غير موجود', 404);
    [$scope, $params] = client_scope($u);
    if (!DB::one("SELECT c.id FROM clients c WHERE c.id = ? AND $scope", array_merge([$clientId], $params))) fail('ليس لديك صلاحية على هذا العميل', 403);
    if ($body === '' || mb_strlen($body) > 4000) fail('اكتب ملاحظة لا تزيد عن 4000 حرف');
    if (!in_array($type, ['general','call','visit','promise','review'], true)) fail('نوع الملاحظة غير صحيح');
    DB::insert('collection_notes', [
        'cycle_id' => $client['cycle_id'],
        'client_id' => $clientId,
        'user_id' => (int)$u['id'],
        'note_type' => $type,
        'body' => $body,
    ]);
    collection_log_event($client['cycle_id'], $clientId, $u['id'], 'note', null, $body);
    ok(['message' => 'تم حفظ الملاحظة']);
}

if ($action === 'collection_history') {
    $u = require_auth();
    ensure_collection_schema();
    $clientId = (int)($_GET['client_id'] ?? $_GET['id'] ?? 0);
    [$scope, $params] = client_scope($u);
    $client = DB::one("SELECT c.id, c.cycle_id FROM clients c WHERE c.id = ? AND $scope", array_merge([$clientId], $params));
    if (!$client) fail('العميل غير موجود أو ليس لديك صلاحية لعرضه', 404);
    $events = DB::all(
        "SELECT e.*, u.full_name AS user_name FROM collection_events e
         LEFT JOIN users u ON u.id = e.user_id WHERE e.client_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 100",
        [$clientId]
    );
    $notes = DB::all(
        "SELECT n.*, u.full_name AS user_name FROM collection_notes n
         LEFT JOIN users u ON u.id = n.user_id WHERE n.client_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT 100",
        [$clientId]
    );
    ok(['events' => $events, 'notes' => $notes]);
}

if ($action === 'review_collection_payment') {
    $u = require_auth(['admin', 'super_admin']);
    ensure_collection_schema();
    $in = input();
    $paymentId = (int)($in['payment_id'] ?? 0);
    $decision = trim((string)($in['decision'] ?? ''));
    $reason = trim((string)($in['reason'] ?? ''));
    if (!$paymentId || !in_array($decision, ['approved', 'rejected'], true)) fail('بيانات مراجعة التحصيل غير مكتملة');
    if ($decision === 'rejected' && $reason === '') fail('سبب رفض التحصيل مطلوب');
    if (mb_strlen($reason) > 255) fail('سبب الرفض يجب ألا يتجاوز 255 حرفًا');

    [$scope, $params] = client_scope($u);
    $pdo = DB::conn();
    $pdo->beginTransaction();
    try {
        $payment = DB::one(
            "SELECT p.id, p.status, p.client_id, c.cycle_id
             FROM collection_payments p JOIN clients c ON c.id = p.client_id
             WHERE p.id = ? AND $scope FOR UPDATE",
            array_merge([$paymentId], $params)
        );
        if (!$payment) fail('عملية التحصيل غير موجودة أو خارج نطاق صلاحيتك', 404);
        if (!in_array($payment['status'], ['pending', 'pending_review'], true)) fail('تمت مراجعة عملية التحصيل بالفعل', 409);
        DB::q(
            "UPDATE collection_payments
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
             WHERE id = ?",
            [$decision, (int)$u['id'], $decision === 'rejected' ? $reason : null, $paymentId]
        );
        $auditNote = 'عملية التحصيل رقم ' . $paymentId;
        if ($decision === 'rejected') $auditNote .= ' — سبب الرفض: ' . $reason;
        collection_log_event(
            $payment['cycle_id'],
            (int)$payment['client_id'],
            (int)$u['id'],
            'payment_' . $decision,
            $payment['status'],
            $decision,
            $auditNote
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    ok(['message' => $decision === 'approved' ? 'تم اعتماد التحصيل' : 'تم رفض التحصيل']);
}

if ($action === 'control_center_v7') {
    $u = require_auth(['admin', 'super_admin', 'collector', 'data_entry']);
    ensure_collection_schema();
    [$scope, $params] = client_scope($u);
    $search = trim((string)($_GET['q'] ?? ''));
    $stage = trim((string)($_GET['stage'] ?? ''));
    $delay = trim((string)($_GET['delay'] ?? ''));
    $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));
    $offset = max(0, min((int)($_GET['offset'] ?? 0), 100000));
    $queueWhere = [$scope];
    $queueParams = $params;
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $queueWhere[] = '(c.name LIKE ? OR c.phone LIKE ?)';
        array_push($queueParams, $like, $like);
    }
    if ($stage !== '') {
        $queueWhere[] = "COALESCE(c.collection_state,'new') = ?";
        $queueParams[] = $stage;
    }
    if ($delay === 'overdue') {
        $queueWhere[] = 'c.delay_days > 0';
    } elseif ($delay === 'high') {
        $queueWhere[] = 'c.delay_days >= 30';
    } elseif ($delay === 'current') {
        $queueWhere[] = 'c.delay_days <= 0';
    }
    $queueCondition = implode(' AND ', $queueWhere);
    $taskScope = str_replace('c.', 'tc.', $scope);
    $stats = DB::one(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(COALESCE(c.loan_balance,0) + COALESCE(c.interest_fine_balance,0)),0) AS exposure,
                (SELECT COUNT(*) FROM collection_tasks t JOIN clients tc ON tc.id = t.client_id
                 WHERE t.status NOT IN ('done','completed','cancelled') AND t.due_at < NOW() AND $taskScope) AS due_actions
         FROM clients c WHERE $scope",
        array_merge($params, $params)
    );
    $callsToday = DB::one(
        "SELECT COUNT(*) AS n FROM calls k JOIN clients c ON c.id = k.client_id
         WHERE k.called_at >= CURDATE() AND (k.status IS NULL OR k.status NOT LIKE 'زيارة:%') AND $scope",
        $params
    );
    $visitsToday = DB::one(
        "SELECT COUNT(*) AS n FROM visits v JOIN clients c ON c.id = v.client_id
         WHERE v.visited_at >= CURDATE() AND v.status IS NOT NULL AND v.status <> '' AND v.status <> 'pending' AND $scope",
        $params
    );
    $recentCalls = DB::all(
        "SELECT 'call' AS activity_type, k.called_at AS activity_at, k.status, k.dcr AS details,
                c.id AS client_id, c.name AS client_name, c.phone, u.full_name AS employee_name
         FROM calls k JOIN clients c ON c.id = k.client_id
         LEFT JOIN users u ON u.id = k.user_id
         WHERE (k.status IS NULL OR k.status NOT LIKE 'زيارة:%') AND $scope
         ORDER BY k.called_at DESC, k.id DESC LIMIT 10",
        $params
    );
    $recentVisits = DB::all(
        "SELECT 'visit' AS activity_type, v.visited_at AS activity_at, v.status,
                v.collector_notes AS details, c.id AS client_id, c.name AS client_name,
                c.phone, u.full_name AS employee_name
         FROM visits v JOIN clients c ON c.id = v.client_id
         LEFT JOIN users u ON u.id = v.collector_id
         WHERE v.visited_at IS NOT NULL AND v.status IS NOT NULL AND v.status <> '' AND v.status <> 'pending' AND $scope
         ORDER BY v.visited_at DESC, v.id DESC LIMIT 10",
        $params
    );
    $recentActivity = array_merge($recentCalls, $recentVisits);
    usort($recentActivity, static fn($a, $b) => strcmp((string)$b['activity_at'], (string)$a['activity_at']));
    $recentActivity = array_slice($recentActivity, 0, 12);
    $payments = DB::one(
        "SELECT COALESCE(SUM(CASE WHEN p.status = 'approved' THEN p.amount ELSE 0 END),0) AS collected
         FROM collection_payments p JOIN clients c ON c.id = p.client_id WHERE $scope",
        $params
    );
    $pending = DB::one(
        "SELECT COUNT(*) AS n FROM collection_payments p JOIN clients c ON c.id = p.client_id
         WHERE p.status IN ('pending','pending_review') AND $scope",
        $params
    );
    $pendingPayments = DB::all(
        "SELECT p.id, p.client_id, p.amount, p.payment_date, p.method, p.status,
                p.reference_no, c.name AS client_name, c.phone, recorder.full_name AS recorded_by
         FROM collection_payments p JOIN clients c ON c.id = p.client_id
         LEFT JOIN users recorder ON recorder.id = p.recorded_by
         WHERE p.status IN ('pending','pending_review') AND $scope
         ORDER BY p.payment_date ASC, p.id ASC LIMIT 10",
        $params
    );
    $stages = DB::all(
        "SELECT DISTINCT COALESCE(c.collection_state,'new') AS collection_stage
         FROM clients c WHERE $scope ORDER BY collection_stage",
        $params
    );
    $queueTotal = (int)(DB::one(
        "SELECT COUNT(*) AS n FROM clients c WHERE $queueCondition",
        $queueParams
    )['n'] ?? 0);
    $queue = DB::all(
        "SELECT c.id, c.name, c.phone, c.delay_days, c.loan_balance, c.interest_fine_balance,
                COALESCE(c.collection_state,'new') AS collection_stage
         FROM clients c WHERE $queueCondition
         ORDER BY c.delay_days DESC,
                  (COALESCE(c.loan_balance,0) + COALESCE(c.interest_fine_balance,0)) DESC, c.id DESC
         LIMIT ? OFFSET ?",
        array_merge($queueParams, [$limit, $offset])
    );
    $overdueTasks = DB::all(
        "SELECT t.id, t.client_id, t.title, t.due_at, t.status, c.name AS client_name,
                c.phone, assigned.full_name AS assigned_name
         FROM collection_tasks t JOIN clients c ON c.id = t.client_id
         LEFT JOIN users assigned ON assigned.id = t.assigned_to
         WHERE t.status NOT IN ('done','completed','cancelled') AND t.due_at < NOW() AND $scope
         ORDER BY t.due_at LIMIT 10",
        $params
    );
    ok([
        'stats' => $stats ?: ['total' => 0, 'exposure' => 0, 'due_actions' => 0],
        'payments' => $payments ?: ['collected' => 0],
        'pending_review' => (int)($pending['n'] ?? 0),
        'calls_today' => (int)($callsToday['n'] ?? 0),
        'visits_today' => (int)($visitsToday['n'] ?? 0),
        'pending_payments' => $pendingPayments,
        'recent_activity' => $recentActivity,
        'stages' => $stages,
        'queue' => $queue,
        'queue_total' => $queueTotal,
        'queue_limit' => $limit,
        'queue_offset' => $offset,
        'overdue_tasks' => $overdueTasks,
    ]);
}

if ($action === 'client_360') {
    $u = require_auth(['admin', 'super_admin', 'collector', 'data_entry']);
    ensure_collection_schema();
    $clientId = (int)($_GET['client_id'] ?? 0);
    [$scope, $params] = client_scope($u);
    $client = DB::one(
        "SELECT c.*, assigned.full_name AS assigned_name, COALESCE(c.collection_state,'new') AS collection_stage,
                'normal' AS priority_level
         FROM clients c LEFT JOIN users assigned ON assigned.id = c.assigned_to
         WHERE c.id = ? AND $scope",
        array_merge([$clientId], $params)
    );
    if (!$client) fail('العميل غير موجود أو ليس لديك صلاحية لعرضه', 404);
    $calls = DB::all(
        "SELECT k.*, u.full_name AS user_name FROM calls k LEFT JOIN users u ON u.id = k.user_id
         WHERE k.client_id = ? ORDER BY k.called_at DESC LIMIT 50",
        [$clientId]
    );
    $visits = DB::all(
        "SELECT v.*, u.full_name AS collector_name FROM visits v LEFT JOIN users u ON u.id = v.collector_id
         WHERE v.client_id = ? ORDER BY COALESCE(v.visited_at, CONCAT(v.visit_date,' 00:00:00')) DESC LIMIT 50",
        [$clientId]
    );
    $audit = DB::all(
        "SELECT a.*, u.full_name FROM collection_audit_log a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.client_id = ? ORDER BY a.created_at DESC LIMIT 50",
        [$clientId]
    );
    $events = DB::all(
        "SELECT e.*, u.full_name AS user_name FROM collection_events e
         LEFT JOIN users u ON u.id = e.user_id
         WHERE e.client_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 50",
        [$clientId]
    );
    $notes = DB::all(
        "SELECT n.*, u.full_name AS user_name FROM collection_notes n
         LEFT JOIN users u ON u.id = n.user_id
         WHERE n.client_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT 50",
        [$clientId]
    );
    $payments = DB::all(
        "SELECT p.*, reviewer.full_name AS reviewer_name
         FROM collection_payments p LEFT JOIN users reviewer ON reviewer.id = p.reviewed_by
         WHERE p.client_id = ? ORDER BY p.payment_date DESC, p.id DESC",
        [$clientId]
    );
    $promises = DB::all("SELECT * FROM collection_promises WHERE client_id = ? ORDER BY due_date DESC, id DESC", [$clientId]);
    $tasks = DB::all(
        "SELECT t.*, assigned.full_name AS assigned_name FROM collection_tasks t
         LEFT JOIN users assigned ON assigned.id = t.assigned_to WHERE t.client_id = ? ORDER BY t.due_at DESC",
        [$clientId]
    );
    $legal = DB::all("SELECT * FROM collection_legal_cases WHERE client_id = ? ORDER BY filing_date DESC, id DESC", [$clientId]);
    ok(['client' => $client, 'calls' => $calls, 'visits' => $visits, 'audit' => $audit, 'events' => $events, 'notes' => $notes, 'payments' => $payments, 'promises' => $promises, 'tasks' => $tasks, 'legal' => $legal]);
}

// =============================================================
// Unknown action
// =============================================================
fail('Unknown action: ' . $action, 404);

fail('إجراء غير معروف', 404);
