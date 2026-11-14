<?php
declare(strict_types=1);
define('DX', true);

$CONFIG = require __DIR__ . '/config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'America/Toronto');

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/* ---------- security headers ---------- */
header('Cache-Control: private, no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
if (is_https()) header('Strict-Transport-Security: max-age=31536000');

/* ---------- session ---------- */
session_name('DXSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (isset($_SESSION['uid'], $_SESSION['last']) && time() - $_SESSION['last'] > 8 * 3600) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last'] = time();

/* ---------- database ---------- */
function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . $CONFIG['db_host'] . ';dbname=' . $CONFIG['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        migrate();
    }
    return $pdo;
}

const SCHEMA_VERSION = '3';
/** Creates missing tables/columns after an upload of a newer version. Runs one cheap query per request. */
function migrate(): void {
    $ver = null;
    try { $ver = db()->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn(); } catch (Throwable $e) { $ver = null; }
    if ($ver === SCHEMA_VERSION) return;
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) db()->exec($sql);
    $cols = [
        ['users', 'telegram_chat_id', 'VARCHAR(40) NULL'],
        ['transfers', 'commission_per_unit', 'DECIMAL(10,4) NULL'],
        ['transfers', 'commission', 'DECIMAL(14,2) NULL'],
        ['transfers', 'commission_currency', 'VARCHAR(5) NULL'],
    ];
    $cols[] = ['users', 'role', null];
    foreach ($cols as [$t, $c, $def]) {
        if ($def === null) continue;
        $has = db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $has->execute([$t, $c]);
        if (!$has->fetchColumn()) db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
    // v3: roles are free text so new job roles can be added; the first admin becomes super admin
    $type = db()->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
    if ($type === 'enum') db()->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'agent'");
    if (!(int)db()->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn()) {
        $first = db()->query("SELECT MIN(id) FROM users WHERE role='admin'")->fetchColumn();
        if ($first) db()->prepare("UPDATE users SET role='super_admin' WHERE id=?")->execute([$first]);
    }
    if (!(int)db()->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn()) {
        $ins = db()->prepare('INSERT IGNORE INTO role_permissions (role, perm) VALUES (?, ?)');
        foreach (DEFAULT_PERMS as $role => $perms) foreach ($perms as $perm) $ins->execute([$role, $perm]);
    }
    if (!(int)db()->query('SELECT COUNT(*) FROM rate_pairs')->fetchColumn()) {
        db()->exec("INSERT INTO rate_pairs (send_currency, receive_currency, sort) VALUES ('CAD','INR',1),('USD','INR',2),('AED','INR',3)");
    }
    foreach (['buy_pct' => '3', 'sell_pct' => '6', 'send_time' => '09:00', 'rates_on' => '1'] as $k => $v) {
        db()->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
    }
    db()->prepare("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)")->execute([SCHEMA_VERSION]);
}

function setting(string $k, ?string $default = null): ?string {
    $v = val('SELECT v FROM settings WHERE k=?', [$k]);
    return $v === null ? $default : (string)$v;
}
function set_setting(string $k, ?string $v): void { q('REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, $v]); }
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

/* ---------- helpers ---------- */
function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function post(string $k, string $d = ''): string { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $d; }
function get(string $k, string $d = ''): string { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d; }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf'] ?? '';
        if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
            http_response_code(400);
            exit('Your session expired. Go back, refresh the page and try again.');
        }
    }
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function money($n, string $cur = ''): string {
    if ($n === null || $n === '') return '';
    return number_format((float)$n, 2) . ($cur ? ' ' . $cur : '');
}
function fdate(?string $d): string { if (!$d) return ''; $t = strtotime($d); return $t ? date('M j, Y', $t) : e($d); }
function fdatetime(?string $d): string { if (!$d) return ''; $t = strtotime($d); return $t ? date('M j, Y g:i a', $t) : e($d); }
function today(): string { return date('Y-m-d'); }
function valid_date(string $d): ?string { $t = DateTime::createFromFormat('Y-m-d', $d); return ($t && $t->format('Y-m-d') === $d) ? $d : null; }
function num_or_null(string $v): ?float { $v = str_replace(',', '', $v); return is_numeric($v) ? (float)$v : null; }

function new_client_no(): string {
    do {
        $no = 'DX-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    } while (val('SELECT 1 FROM clients WHERE client_no=?', [$no]));
    return $no;
}
function new_transfer_no(): string {
    do {
        $no = 'TX-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    } while (val('SELECT 1 FROM transfers WHERE ref_no=?', [$no]));
    return $no;
}

/* ---------- auth ---------- */
function me(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = one('SELECT * FROM users WHERE id=? AND active=1', [$_SESSION['uid']]);
            if (!$u || (int)$u['session_version'] !== (int)($_SESSION['sv'] ?? -1)) { $u = null; session_unset(); }
        }
    }
    return $u;
}
/* ---------- roles & permissions ---------- */
const ROLES = ['super_admin' => 'Super admin', 'admin' => 'Admin', 'staff' => 'Operations staff', 'accountant' => 'Accountant', 'agent' => 'Sub-agent'];
const PERMS = [
    'clients.all' => 'See all clients and transfers',
    'clients.edit' => 'Add and edit clients',
    'clients.delete' => 'Delete clients',
    'kyc.decide' => 'Verify or reject KYC',
    'submissions.review' => 'Review online registration forms',
    'transfers.create' => 'Create transfers',
    'transfers.decide' => 'Accept, reject and complete transfers',
    'rates.set' => 'Set daily rates (portal and Telegram)',
    'rates.settings' => 'Change rate markups, pairs and Telegram bot',
    'commission.view' => 'See all sub-agent commission',
    'commission.set' => 'Set sub-agent commission rates',
    'team.agents' => 'Add sub-agents and reset their passwords',
    'team.staff' => 'Add admins, staff and accountants',
    'log.use' => 'Use the daily work log',
    'export' => 'Export CSV files',
    'import' => 'Import data',
    'audit.all' => "See everyone's activity history",
];
const DEFAULT_PERMS = [
    'admin' => ['clients.all', 'clients.edit', 'kyc.decide', 'submissions.review', 'transfers.create', 'transfers.decide', 'rates.set', 'commission.view', 'team.agents', 'log.use', 'export', 'audit.all'],
    'staff' => ['clients.all', 'clients.edit', 'submissions.review', 'transfers.create', 'log.use'],
    'accountant' => ['clients.all', 'commission.view', 'export'],
];
function role_label(string $r): string { return ROLES[$r] ?? ucfirst($r); }
function role_perms(string $role): array {
    static $cache = [];
    if ($role === 'super_admin') return array_keys(PERMS);
    if ($role === 'agent') return [];
    if (!isset($cache[$role])) $cache[$role] = array_column(all('SELECT perm FROM role_permissions WHERE role=?', [$role]), 'perm');
    return $cache[$role];
}
function role_can(string $role, string $perm): bool { return in_array($perm, role_perms($role), true); }
function can(string $perm): bool { $u = me(); return $u && role_can($u['role'], $perm); }
/** Internal DhanXfer team (everyone except sub-agents). */
function is_staff(): bool { $u = me(); return $u && $u['role'] !== 'agent'; }
function is_super(): bool { $u = me(); return $u && $u['role'] === 'super_admin'; }
function require_perm(string ...$perms): array {
    $u = require_login();
    foreach ($perms as $p) if (role_can($u['role'], $p)) return $u;
    http_response_code(403); flash('Your role does not have access to that page.', 'bad'); redirect('/');
}
function require_login(): array {
    $u = me();
    if (!$u) redirect('/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    return $u;
}

function audit(string $action, string $entity = '', $entityId = null, string $detail = ''): void {
    $u = me();
    q('INSERT INTO audit (user_id, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?)',
        [$u['id'] ?? null, $action, $entity, $entityId, mb_substr($detail, 0, 500), client_ip()]);
}
/** Can the current user see this client row? */
function can_see_client(array $c): bool {
    $u = me();
    if (!$u) return false;
    if ($u['role'] === 'agent') return (int)$c['agent_id'] === (int)$u['id'];
    return role_can($u['role'], 'clients.all');
}
function gen_password(int $len = 12): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < $len; $i++) $p .= $chars[random_int(0, strlen($chars) - 1)];
    return $p;
}
/** One-time password reset link, valid 24 hours. Returns the full URL (token is stored hashed). */
function make_reset_link(int $userId, int $byId): string {
    global $CONFIG;
    $tok = bin2hex(random_bytes(24));
    q('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL', [$userId]);
    q('INSERT INTO password_resets (user_id, token_hash, expires_at, created_by) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR),?)', [$userId, hash('sha256', $tok), $byId]);
    return rtrim($CONFIG['app_url'], '/') . '/reset.php?t=' . $tok;
}
function next_ref_code(): string {
    do { $c = 'SA-' . random_int(1000, 9999); } while (val('SELECT 1 FROM users WHERE ref_code=?', [$c]));
    return $c;
}

/* ---------- shared option lists ---------- */
const OFFICES = ['Canada', 'Dubai'];
const ID_TYPES = ['Passport', "Driver's licence", 'PR card', 'Provincial photo ID', 'Emirates ID', 'National ID', 'Other'];
const KYC = ['Pending', 'Verified', 'Rejected', 'Expired'];
const RISK = ['Low', 'Medium', 'High'];
const PURPOSES = ['Family support', 'Education / tuition', 'Business payment', 'Savings / investment', 'Property', 'Gift', 'Medical', 'Other'];
const SOF = ['Salary', 'Business income', 'Savings', 'Sale of property', 'Gift / inheritance', 'Other'];
const VOLUMES = ['Under 1,000', '1,000 – 5,000', '5,000 – 10,000', '10,000 – 25,000', 'Over 25,000'];
const HEARD = ['Walk-in', 'Referral', 'WhatsApp', 'Instagram / Facebook', 'Flyer', 'Website', 'Google', 'Other'];
const SEND_CUR = ['CAD', 'AED', 'USD', 'GBP', 'EUR'];
const RECV_CUR = ['INR', 'PKR', 'PHP', 'BDT', 'NPR', 'LKR', 'AED', 'CAD', 'USD', 'GBP', 'EUR'];
const PAY = ['Cash', 'Interac e-Transfer', 'Bank transfer', 'Debit / credit card', 'Other'];
const ACT_TYPES = ['Rate enquiry', 'KYC / documents', 'Follow-up call', 'Complaint / issue', 'Walk-in visit', 'Other'];
const COUNTRIES = ['Canada', 'United Arab Emirates', 'India', 'Pakistan', 'Philippines', 'Bangladesh', 'Nepal', 'Sri Lanka', 'United States', 'United Kingdom'];

/** Client fields shared by the public form, the staff form and the CSV import. */
const CLIENT_FIELDS = ['name', 'dob', 'nationality', 'occupation', 'employer', 'office', 'phone', 'whatsapp', 'email',
    'address', 'city', 'province', 'postal', 'country', 'id_type', 'id_number', 'id_country', 'id_expiry',
    'purpose', 'source_of_funds', 'monthly_volume', 'destination', 'referral', 'referred_by', 'notes'];

function opts(array $list, ?string $sel, string $placeholder = 'Select…'): string {
    $h = $placeholder !== '' ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($list as $o) $h .= '<option' . ((string)$sel === (string)$o ? ' selected' : '') . '>' . e($o) . '</option>';
    return $h;
}
function datalist(string $id, array $list): string {
    $h = '<datalist id="' . e($id) . '">';
    foreach ($list as $o) $h .= '<option value="' . e($o) . '"></option>';
    return $h . '</datalist>';
}

/* ---------- ID document upload ---------- */
function save_id_upload(string $field): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The ID file could not be uploaded. Try a smaller file (under 6 MB).');
    if ($f['size'] > 6 * 1024 * 1024) throw new RuntimeException('The ID file is larger than 6 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'image/heic' => 'heic'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('The ID file must be a photo (JPG, PNG, WEBP, HEIC) or a PDF.');
    $name = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dir = dirname(__DIR__) . '/storage/ids';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('The ID file could not be saved.');
    return $name;
}

function notify(string $subject, string $body): void {
    global $CONFIG;
    $to = $CONFIG['notify_email'] ?? '';
    if (!$to) return;
    $headers = "From: DhanXfer Portal <no-reply@dhanxfer.com>\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($to, $subject, $body, $headers);
}
