<?php
declare(strict_types=1);
define('DX', true);

/*
 * Settings live OUTSIDE the website folder (one level above public_html) so deploys never overwrite or expose them:
 *   dx-config.php       live/staging settings and keys
 *   dx-config.test.php  test database settings
 * inc/config.php is only a fallback for the first upload; tools/move_secrets.php moves it out.
 */
$__name = defined('DX_TEST') ? 'dx-config.test.php' : 'dx-config.php';
$__outside = dirname(__DIR__, 2) . '/' . $__name;
$__inside = __DIR__ . (defined('DX_TEST') ? '/config.test.php' : '/config.php');
$CONFIG = require (is_file($__outside) ? $__outside : $__inside);
/** Folder for ID documents, checkpoints and reports: outside the website folder once settings are moved out. */
function data_dir(): string { global $CONFIG; return rtrim($CONFIG['data_dir'] ?? (dirname(__DIR__) . '/storage'), '/'); }
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
        if (!defined('DX_NO_AUTO_MIGRATE')) migrate();
    }
    return $pdo;
}

const SCHEMA_VERSION = '5';
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
    array_push($cols,
        ['users', 'mfa_secret', 'VARCHAR(255) NULL'], ['users', 'mfa_enabled', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['users', 'mfa_recovery', 'TEXT NULL'], ['users', 'mfa_last_step', 'BIGINT NULL'],
        ['clients', 'id_number_hash', 'CHAR(64) NULL'],
        ['submissions', 'app_ref', 'VARCHAR(30) NULL'], ['submissions', 'ref_verified', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['password_resets', 'purpose', "VARCHAR(20) NOT NULL DEFAULT 'reset'"],
        ['audit', 'prev_hash', 'CHAR(64) NULL'], ['audit', 'row_hash', 'CHAR(64) NULL'],
        ['users', 'mfa_token_only', 'TINYINT(1) NOT NULL DEFAULT 0']);
    foreach ($cols as [$t, $c, $def]) {
        if ($def === null) continue;
        $has = db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $has->execute([$t, $c]);
        if (!$has->fetchColumn()) db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
    // v3: roles are free text so new job roles can be added; the first admin becomes super admin
    $type = db()->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
    if ($type === 'enum') db()->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'agent'");
    // Nobody is promoted automatically. The owner claims super admin with the install key, their
    // password and two-step login (install.php, "Claim super admin").
    // Accounts in roles that need two-step login but have none may only set it up through a link or that claim,
    // so a stolen password cannot be used to register an attacker's authenticator.
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
    $hasCol = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='mfa_token_only'")->fetchColumn();
    if ($hasCol && $ver !== SCHEMA_VERSION) {
        db()->exec("UPDATE users SET mfa_token_only=1 WHERE mfa_enabled=0 AND role IN ('" . implode("','", MFA_REQUIRED) . "')");
    }
    // v4: Compliance Officer and Read-only Auditor get their default permissions once
    if (!(int)db()->query("SELECT COUNT(*) FROM role_permissions WHERE role IN ('compliance','auditor')")->fetchColumn()) {
        $ins = db()->prepare('INSERT IGNORE INTO role_permissions (role, perm) VALUES (?, ?)');
        foreach (['compliance', 'auditor'] as $role) foreach (DEFAULT_PERMS[$role] as $perm) $ins->execute([$role, $perm]);
        $ins->execute(['super_admin', 'pii.reveal']);
    }
    // v4: encrypt ID and account numbers that were saved before encryption existed.
    // Without a key the version is not recorded, so this runs again until the key is in place.
    if (!enc_ready()) return;
    {
        foreach (db()->query("SELECT id, id_number FROM clients WHERE id_number IS NOT NULL AND id_number<>'' AND id_number NOT LIKE 'enc:%'")->fetchAll() as $r)
            db()->prepare('UPDATE clients SET id_number=?, id_number_hash=? WHERE id=?')->execute([enc($r['id_number']), id_hash($r['id_number']), $r['id']]);
        foreach (db()->query("SELECT id, account FROM beneficiaries WHERE account IS NOT NULL AND account<>'' AND account NOT LIKE 'enc:%'")->fetchAll() as $r)
            db()->prepare('UPDATE beneficiaries SET account=? WHERE id=?')->execute([enc($r['account']), $r['id']]);
        foreach (db()->query("SELECT id, data FROM submissions WHERE data NOT LIKE 'enc:%'")->fetchAll() as $r)
            db()->prepare('UPDATE submissions SET data=? WHERE id=?')->execute([enc($r['data']), $r['id']]);
        foreach (db()->query("SELECT id, mfa_secret FROM users WHERE mfa_secret IS NOT NULL AND mfa_secret NOT LIKE 'enc:%'")->fetchAll() as $r)
            db()->prepare('UPDATE users SET mfa_secret=? WHERE id=?')->execute([enc($r['mfa_secret']), $r['id']]);
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
    static $cache = [];
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (!$uid) return null;
    $key = $uid . ':' . (int)($_SESSION['sv'] ?? -1);
    if (!array_key_exists($key, $cache)) {
        $u = one('SELECT * FROM users WHERE id=? AND active=1', [$uid]);
        if (!$u || (int)$u['session_version'] !== (int)($_SESSION['sv'] ?? -1)) { $u = null; unset($_SESSION['uid'], $_SESSION['sv']); }
        $cache = [$key => $u];
    }
    return $cache[$key];
}
/* ---------- roles & permissions ---------- */
const ROLES = ['super_admin' => 'Super admin', 'admin' => 'Admin / Operations manager', 'compliance' => 'Compliance officer', 'staff' => 'Operations staff', 'accountant' => 'Accountant', 'auditor' => 'Read-only auditor', 'agent' => 'Sub-agent'];
/** Roles that must use two-step login (authenticator app). */
const MFA_REQUIRED = ['super_admin', 'admin', 'compliance'];
const PERMS = [
    'clients.all' => 'See all clients and transfers',
    'clients.edit' => 'Add and edit clients',
    'clients.delete' => 'Delete clients',
    'kyc.decide' => 'Verify or reject KYC',
    'pii.reveal' => 'See full ID and bank account numbers (each look is logged)',
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
    'admin' => ['clients.all', 'clients.edit', 'submissions.review', 'transfers.create', 'transfers.decide', 'rates.set', 'commission.view', 'team.agents', 'log.use', 'export', 'audit.all'],
    'compliance' => ['clients.all', 'kyc.decide', 'pii.reveal', 'submissions.review', 'audit.all', 'export'],
    'auditor' => ['clients.all', 'commission.view', 'audit.all', 'export'],
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
    // A role that needs two-step login never gets a working session without it.
    if (mfa_required($u) && !$u['mfa_enabled']) {
        session_unset();
        flash('Your role needs two-step login. Log in again to set it up.', 'warn');
        redirect('/login.php');
    }
    return $u;
}
function mfa_required(array $user): bool { return in_array($user['role'], MFA_REQUIRED, true); }

function audit(string $action, string $entity = '', $entityId = null, string $detail = ''): void {
    $u = me();
    audit_write($u['id'] ?? null, $action, $entity, $entityId, $detail, client_ip());
}
/**
 * Every activity-log row is chained to the one before it with an HMAC, so editing or deleting
 * a row in the database breaks the chain and shows up on the Activity history check.
 */
function audit_write(?int $userId, string $action, string $entity, $entityId, string $detail, string $ip): void {
    $detail = mb_substr($detail, 0, 500);
    $lock = (int)db()->query("SELECT GET_LOCK('dx_audit', 10)")->fetchColumn();
    if (!$lock) throw new RuntimeException('The activity log is busy. Please try again.');
    try {
        $prev = (string)(db()->query('SELECT row_hash FROM audit ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '');
        $at = date('Y-m-d H:i:s');
        $hash = audit_hash($prev, $userId, $action, $entity, $entityId, $detail, $ip, $at);
        q('INSERT INTO audit (user_id, action, entity, entity_id, detail, ip, created_at, prev_hash, row_hash) VALUES (?,?,?,?,?,?,?,?,?)',
            [$userId, $action, $entity, $entityId, $detail, $ip, $at, $prev ?: null, $hash]);
    } finally { db()->query("SELECT RELEASE_LOCK('dx_audit')"); }
}
function audit_hash(string $prev, $userId, string $action, ?string $entity, $entityId, ?string $detail, ?string $ip, string $at): string {
    $msg = '';
    foreach ([$prev, (string)$userId, $action, (string)$entity, (string)$entityId, (string)$detail, (string)$ip, $at] as $f) $msg .= strlen($f) . ':' . $f . ';';
    return hash_hmac('sha256', $msg, sec_key('audit'));
}
/**
 * Checks the whole chain. Returns [rows checked, first broken row id or null, rows written before the chain existed, anchor problem or null].
 * Rows without a hash are only accepted before the first chained row. The newest checkpoint saved outside the
 * database (storage/audit-anchors.log, plus the daily email) must still be in the chain, so cutting off the newest rows shows up.
 */
function audit_verify(): array {
    $prev = ''; $n = 0; $legacy = 0; $chained = false; $seen = [];
    $st = db()->query('SELECT * FROM audit ORDER BY id');
    while ($r = $st->fetch()) {
        if ($r['row_hash'] === null) { if ($chained) return [$n, (int)$r['id'], $legacy, null]; $legacy++; continue; }
        $chained = true;
        $n++;
        if ((string)$r['prev_hash'] !== $prev) return [$n, (int)$r['id'], $legacy, null];
        if (!hash_equals($r['row_hash'], audit_hash((string)$r['prev_hash'], $r['user_id'], $r['action'], $r['entity'], $r['entity_id'], $r['detail'], $r['ip'], $r['created_at']))) return [$n, (int)$r['id'], $legacy, null];
        $prev = $r['row_hash'];
        $seen[(int)$r['id']] = $r['row_hash'];
    }
    $anchor = audit_last_anchor();
    $problem = null;
    if ($anchor && (!isset($seen[$anchor['id']]) || !hash_equals($seen[$anchor['id']], $anchor['hash']))) $problem = 'Rows up to #' . $anchor['id'] . ' were in the log on ' . $anchor['at'] . ' but are now missing or changed.';
    return [$n, null, $legacy, $problem];
}
function audit_anchor_file(): string { return data_dir() . '/audit-anchors' . (defined('DX_TEST') ? '.test' : '') . '.log'; }
/** Saves today's newest row and its hash outside the database. Returns the line written. */
function audit_write_anchor(): ?string {
    $r = db()->query('SELECT id, row_hash FROM audit WHERE row_hash IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch();
    if (!$r) return null;
    $line = date('c') . ' ' . $r['id'] . ' ' . $r['row_hash'];
    @file_put_contents(audit_anchor_file(), $line . "\n", FILE_APPEND | LOCK_EX);
    return $line;
}
function audit_last_anchor(): ?array {
    $f = audit_anchor_file();
    if (!is_file($f)) return null;
    $lines = array_filter(array_map('trim', file($f)));
    $last = end($lines);
    if (!$last || count($p = explode(' ', $last)) !== 3) return null;
    return ['at' => $p[0], 'id' => (int)$p[1], 'hash' => $p[2]];
}

/* ---------- encryption, masking, signing ---------- */
function master_key(): string {
    global $CONFIG;
    $k = base64_decode((string)($CONFIG['enc_key'] ?? ''), true);
    if ($k === false || strlen($k) !== 32) throw new RuntimeException('The encryption key is missing from the server config.');
    return $k;
}
function enc_ready(): bool { try { master_key(); return true; } catch (Throwable $e) { return false; } }
/** A separate key for each job, derived from the master key. */
function sec_key(string $purpose): string { return hash_hmac('sha256', 'dhanxfer:' . $purpose, master_key(), true); }
function enc(?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc:v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sec_key('data')));
}
function dec(?string $stored): ?string {
    if ($stored === null || !str_starts_with($stored, 'enc:v1:')) return $stored;
    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) return null;
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sec_key('data'));
    return $p === false ? null : $p;
}
/** Exact-match search key for an ID number, without storing it readable. */
function id_hash(?string $idNo): ?string {
    $n = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$idNo));
    return $n === '' ? null : hash_hmac('sha256', $n, sec_key('idhash'));
}
/** "•••• 4821": shows only the last 4 characters. */
function mask(?string $v, int $keep = 4): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $len = mb_strlen($v);
    return $len <= $keep ? str_repeat('•', $len) : '•••• ' . mb_substr($v, -$keep);
}
/** Signed client-registration link for a sub-agent. The signature stops clients changing the referral code. */
function ref_sig(string $code): string { return substr(hash_hmac('sha256', strtoupper($code), sec_key('ref')), 0, 16); }
function referral_link(string $code): string {
    global $CONFIG;
    return rtrim($CONFIG['app_url'], '/') . '/register?ref=' . rawurlencode($code) . '&s=' . ref_sig($code);
}
function ref_valid(string $code, string $sig): bool { return $code !== '' && $sig !== '' && hash_equals(ref_sig($code), $sig); }
/** Can the current user see this client row? */
function can_see_client(array $c): bool {
    $u = me();
    if (!$u) return false;
    if ($u['role'] === 'agent') return (int)$c['agent_id'] === (int)$u['id'];
    return role_can($u['role'], 'clients.all');
}
/**
 * Data ownership in one place, used by every list and every single-record page:
 * sub-agents see only clients and transfers credited to them; internal roles need clients.all.
 * Returns [sql condition, params] for a table alias with an agent_id column.
 */
function scope_where(?array $u, string $alias): array {
    if (!$u) return ['1=0', []];
    if ($u['role'] === 'agent') return ["$alias.agent_id=?", [(int)$u['id']]];
    return role_can($u['role'], 'clients.all') ? ['1=1', []] : ['1=0', []];
}
function can_see_transfer(array $t): bool { return can_see_client(['agent_id' => $t['agent_id']]); }
/** Exactly one owner (super admin) claim can ever succeed: the first insert of this settings row wins. */
function owner_claim(int $uid): bool {
    return q("INSERT IGNORE INTO settings (k, v) VALUES ('owner_claim', ?)", [(string)$uid])->rowCount() === 1
        || (string)val("SELECT v FROM settings WHERE k='owner_claim'") === (string)$uid;
}
function gen_password(int $len = 12): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < $len; $i++) $p .= $chars[random_int(0, strlen($chars) - 1)];
    return $p;
}
/** One-time password reset link, valid 24 hours. Returns the full URL (token is stored hashed). */
function make_reset_link(int $userId, int $byId, string $purpose = 'reset'): string {
    global $CONFIG;
    if ((int)val('SELECT COUNT(*) FROM password_resets WHERE user_id=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$userId]) >= 5)
        throw new RuntimeException('Too many links were made for this person in the last hour. Try again later.');
    $tok = bin2hex(random_bytes(24));
    $hours = $purpose === 'activate' ? 72 : 24;
    q('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL', [$userId]);
    q("INSERT INTO password_resets (user_id, token_hash, expires_at, created_by, purpose) VALUES (?,?,DATE_ADD(NOW(), INTERVAL $hours HOUR),?,?)", [$userId, hash('sha256', $tok), $byId, $purpose]);
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
function ids_dir(): string { global $CONFIG; return rtrim($CONFIG['ids_dir'] ?? (data_dir() . '/ids'), '/'); }
function save_id_upload(string $field): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The ID file could not be uploaded. Try a smaller file (under 6 MB).');
    if ($f['size'] > 6 * 1024 * 1024) throw new RuntimeException('The ID file is larger than 6 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'image/heic' => 'heic'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('The ID file must be a photo (JPG, PNG, WEBP, HEIC) or a PDF.');
    $name = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dir = ids_dir();
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
