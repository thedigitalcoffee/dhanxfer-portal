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

/* ---------- maintenance window (set 'maintenance' => true in dx-config.php during a cutover) ---------- */
if (!empty($CONFIG['maintenance']) && PHP_SAPI !== 'cli') {
    http_response_code(503); header('Retry-After: 1800'); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>DhanXfer</title><div style="font-family:system-ui;max-width:420px;margin:15vh auto;padding:0 20px;text-align:center"><h1>Back soon</h1><p>The DhanXfer portal is being upgraded. Please try again in about 30 minutes.</p><p>WhatsApp +1 (942)-6600-0600</p></div>');
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
        // Any truncation or bad value is an error, never silently stored
        $modes = array_filter(explode(',', (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()));
        if (!in_array('STRICT_ALL_TABLES', $modes, true)) { $modes[] = 'STRICT_ALL_TABLES'; $pdo->exec("SET SESSION sql_mode = '" . implode(',', $modes) . "'"); }
        // Live upgrades run only through tools/migrate.php (after a backup); staging and tests upgrade on their own.
        if (!defined('DX_NO_AUTO_MIGRATE') && ($CONFIG['auto_migrate'] ?? (($CONFIG['environment'] ?? 'live') !== 'live'))) migrate();
        // New code must never serve an old database: web pages wait until the upgrade is done.
        if (PHP_SAPI !== 'cli' && !schema_current()) {
            http_response_code(503); header('Retry-After: 1800'); header('Content-Type: text/html; charset=utf-8');
            exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>DhanXfer</title><div style="font-family:system-ui;max-width:420px;margin:15vh auto;padding:0 20px;text-align:center"><h1>Back soon</h1><p>The DhanXfer portal is being upgraded. Please try again shortly.</p></div>');
        }
    }
    return $pdo;
}

const SCHEMA_VERSION = '12';
/** True when the database is at the version this code expects. */
function schema_current(): bool {
    try { return (string)db()->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn() === SCHEMA_VERSION; } catch (Throwable $e) { return false; }
}
/** Named locks are shared by every database on the server, so each name carries this database's name. */
function lock_name(string $n): string { global $CONFIG; return $n . ':' . $CONFIG['db_name']; }
/** Creates missing tables/columns after an upload of a newer version. Runs one cheap query per request. */
function migrate(): void {
    $ver = null;
    try { $ver = db()->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn(); } catch (Throwable $e) { $ver = null; }
    if ($ver === SCHEMA_VERSION) return;
    // One upgrade at a time: a second request waits, then finds the work done.
    if ((int)val('SELECT GET_LOCK(?, 60)', [lock_name('dx_migrate')]) !== 1) throw new RuntimeException('Another upgrade is running. Try again in a minute.');
    try { migrate_locked($ver === false ? null : $ver); } finally { val('SELECT RELEASE_LOCK(?)', [lock_name('dx_migrate')]); }
}
function migrate_locked(?string $ver): void {
    $ver2 = db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings'")->fetchColumn()
        ? db()->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn() : false;
    if ($ver2 === SCHEMA_VERSION) return;
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
        ['users', 'mfa_token_only', 'TINYINT(1) NOT NULL DEFAULT 0'],
        // v7: KYC review workflow, address checks, phone in international form
        ['clients', 'phone_e164', 'VARCHAR(20) NULL'], ['clients', 'phone_region', 'CHAR(2) NULL'],
        ['clients', 'address_source', "VARCHAR(10) NULL"], ['clients', 'id_has_address', 'TINYINT(1) NULL'],
        ['clients', 'id_address_json', 'TEXT NULL'], ['clients', 'address_match', 'VARCHAR(20) NULL'],
        ['clients', 'kyc_reviewed_at', 'DATETIME NULL'], ['clients', 'kyc_reviewed_by', 'INT UNSIGNED NULL'],
        ['clients', 'kyc_next_review', 'DATE NULL'], ['submissions', 'client_id_returning', 'INT UNSIGNED NULL'],
        // v8: beneficiaries are reviewed and never deleted once used; transfers point to one
        ['beneficiaries', 'status', "VARCHAR(12) NOT NULL DEFAULT 'pending'"], ['beneficiaries', 'account_hash', 'CHAR(64) NULL'],
        ['beneficiaries', 'screening_ref', 'VARCHAR(120) NULL'], ['beneficiaries', 'verification', 'VARCHAR(200) NULL'],
        ['beneficiaries', 'reviewed_by', 'INT UNSIGNED NULL'], ['beneficiaries', 'reviewed_at', 'DATETIME NULL'],
        ['beneficiaries', 'source', "VARCHAR(12) NULL"], ['beneficiaries', 'created_by', 'INT UNSIGNED NULL'],
        ['beneficiaries', 'created_at', 'DATETIME NULL'], ['beneficiaries', 'updated_at', 'DATETIME NULL'],
        ['transfers', 'beneficiary_id', 'INT UNSIGNED NULL'],
        // v9: a reviewer decides the exact version they saw; approved details are never overwritten (a change makes a new row)
        ['beneficiaries', 'rev', 'INT UNSIGNED NOT NULL DEFAULT 0'], ['beneficiaries', 'replaced_by', 'INT UNSIGNED NULL'],
        ['beneficiaries', 'updated_by', 'INT UNSIGNED NULL'],
        // v10: sub-agent control: account status with reason, manager scope, per-agent limits, link history
        ['users', 'account_status', "VARCHAR(12) NOT NULL DEFAULT 'active'"], ['users', 'status_reason', 'VARCHAR(255) NULL'],
        ['users', 'status_changed_at', 'DATETIME NULL'], ['users', 'status_changed_by', 'INT UNSIGNED NULL'],
        ['users', 'manager_id', 'INT UNSIGNED NULL'], ['users', 'ref_epoch', 'INT NOT NULL DEFAULT 0'],
        ['users', 'agent_can_clients', 'TINYINT(1) NOT NULL DEFAULT 1'], ['users', 'agent_can_transfers', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['submissions', 'ref_link_id', 'INT UNSIGNED NULL'], ['intake_drafts', 'ref_link_id', 'INT UNSIGNED NULL'],
        // v11: commission locked in CAD at the order-day rate; manager approval limit for withdrawals
        ['transfers', 'commission_fx_rate', 'DECIMAL(16,6) NULL'], ['transfers', 'commission_fx_date', 'DATE NULL'],
        ['transfers', 'commission_fx_source', 'VARCHAR(60) NULL'], ['transfers', 'commission_cad', 'DECIMAL(14,2) NULL'],
        ['transfers', 'commission_locked_at', 'DATETIME NULL'], ['users', 'approval_limit_cad', 'DECIMAL(14,2) NULL'],
        // v12: earnings only from orders completed in the panel; two-person manual FX; reconciliation and payment controls
        ['transfers', 'completed_via', 'VARCHAR(10) NULL'], ['transfers', 'fx_proposed_rate', 'DECIMAL(16,6) NULL'], ['transfers', 'fx_proposed_source', 'VARCHAR(60) NULL'],
        ['transfers', 'fx_proposed_by', 'INT UNSIGNED NULL'], ['commission_withdrawals', 'paid_ref_norm', 'VARCHAR(60) NULL'],
        ['commission_withdrawals', 'last_hash', 'CHAR(64) NULL']);
    foreach ($cols as [$t, $c, $def]) {
        if ($def === null) continue;
        $has = db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $has->execute([$t, $c]);
        if (!$has->fetchColumn()) db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
    // v9: indexes for duplicate checks and look-ups (not unique: older data may hold duplicates that a person must sort out)
    foreach ([['beneficiaries', 'account_hash'], ['transfers', 'beneficiary_id'], ['clients', 'id_number_hash'], ['document_versions', 'number_hash'], ['users', 'manager_id']] as [$t, $c]) {
        $ix = db()->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        $ix->execute([$t, $c]);
        if (!$ix->fetchColumn()) db()->exec("ALTER TABLE `$t` ADD INDEX `ix_$c` (`$c`)");
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
    // v7: more KYC states (a review can ask for more information or escalate)
    $kt = (string)db()->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='kyc'")->fetchColumn();
    if ($kt !== '' && !str_contains($kt, 'Escalated')) db()->exec("ALTER TABLE clients MODIFY kyc ENUM('Pending','Verified','Rejected','Expired','Info requested','Escalated') NOT NULL DEFAULT 'Pending'");
    if (!(int)db()->query('SELECT COUNT(*) FROM reminder_rules')->fetchColumn()) {
        foreach ([90, 60, 30, 7, 0] as $d) foreach (['email', 'staff'] as $ch) db()->prepare('INSERT IGNORE INTO reminder_rules (offset_days, channel) VALUES (?, ?)')->execute([$d, $ch]);
    }
    foreach (COMPLIANCE_DEFAULTS as $k => $v) db()->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
    // v11/12: the wallet credits only orders submitted from this moment; earlier commission was settled outside the panel
    db()->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)')->execute(['wallet_start', date('Y-m-d H:i:s')]);
    $ix = db()->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commission_withdrawals' AND INDEX_NAME='paid_ref_norm'")->fetchColumn();
    if (!$ix) db()->exec('ALTER TABLE commission_withdrawals ADD UNIQUE KEY paid_ref_norm (paid_ref_norm)');
    if (!(int)db()->query("SELECT COUNT(*) FROM role_permissions WHERE perm='beneficiaries.manage'")->fetchColumn()) {
        $ins = db()->prepare('INSERT IGNORE INTO role_permissions (role, perm) VALUES (?, ?)');
        foreach (['admin' => ['beneficiaries.manage'], 'staff' => ['beneficiaries.manage'], 'compliance' => ['beneficiaries.manage', 'beneficiaries.review']] as $role => $ps) foreach ($ps as $perm) $ins->execute([$role, $perm]);
    }
    if (!(int)db()->query("SELECT COUNT(*) FROM role_permissions WHERE perm='agents.assigned'")->fetchColumn()) db()->exec("INSERT IGNORE INTO role_permissions (role, perm) VALUES ('manager','agents.assigned')");
    if (!(int)db()->query("SELECT COUNT(*) FROM role_permissions WHERE perm IN ('withdrawals.review','withdrawals.team','withdrawals.pay')")->fetchColumn())
        db()->exec("INSERT IGNORE INTO role_permissions (role, perm) VALUES ('admin','withdrawals.review'),('manager','withdrawals.team'),('accountant','withdrawals.pay')");
    if (!(int)db()->query("SELECT COUNT(*) FROM role_permissions WHERE perm='compliance.view'")->fetchColumn()) {
        $ins = db()->prepare('INSERT IGNORE INTO role_permissions (role, perm) VALUES (?, ?)');
        foreach (['compliance' => ['compliance.view', 'compliance.manage'], 'admin' => ['compliance.view'], 'auditor' => ['compliance.view']] as $role => $ps) foreach ($ps as $perm) $ins->execute([$role, $perm]);
    }
    // v6: encrypted values are longer than the plain text, so these columns must be wide enough first
    // (an 80-character column could not hold a long IBAN or ID number once encrypted).
    foreach ([['clients', 'id_number', 'VARCHAR(255) NULL'], ['beneficiaries', 'account', 'VARCHAR(255) NULL'], ['users', 'mfa_secret', 'VARCHAR(255) NULL']] as [$t, $c, $def]) {
        $len = db()->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $len->execute([$t, $c]);
        $n = $len->fetchColumn();
        if ($n !== false && (int)$n < 255) db()->exec("ALTER TABLE `$t` MODIFY `$c` $def");
    }
    // v4: encrypt ID and account numbers that were saved before encryption existed.
    // Without a key the version is not recorded, so this runs again until the key is in place.
    // v10 (needs no key): switched-off accounts are "deactivated"; every sub-agent gets a short link named after their login ID
    require_once __DIR__ . '/agents.php';
    agents_migrate_v10();
    if (!enc_ready()) return;
    $jobs = [
        ['clients', 'id_number', "id_number IS NOT NULL AND id_number<>'' AND id_number NOT LIKE 'enc:%'", true],
        ['beneficiaries', 'account', "account IS NOT NULL AND account<>'' AND account NOT LIKE 'enc:%'", false],
        ['submissions', 'data', "data NOT LIKE 'enc:%'", false],
        ['users', 'mfa_secret', "mfa_secret IS NOT NULL AND mfa_secret NOT LIKE 'enc:%'", false],
    ];
    db()->beginTransaction();
    try {
        foreach ($jobs as [$t, $c, $where, $hash]) {
            foreach (db()->query("SELECT id, `$c` AS v FROM `$t` WHERE $where")->fetchAll() as $r) {
                $sealed = enc((string)$r['v']);
                if (dec($sealed) !== (string)$r['v']) throw new RuntimeException("Encryption check failed in $t #{$r['id']}");
                $hash ? q("UPDATE `$t` SET `$c`=?, id_number_hash=? WHERE id=?", [$sealed, id_hash((string)$r['v']), $r['id']])
                      : q("UPDATE `$t` SET `$c`=? WHERE id=?", [$sealed, $r['id']]);
                // read it back: what is stored must open to exactly the original
                if (dec((string)val("SELECT `$c` FROM `$t` WHERE id=?", [$r['id']])) !== (string)$r['v']) throw new RuntimeException("Stored value check failed in $t #{$r['id']}");
            }
        }
        // v7: every client's ID moves into the document history; agents get their login ID as referral code
        require_once __DIR__ . '/kyc.php';
        kyc_migrate_v7();
        // v8: existing beneficiaries were never screened: they wait for review; duplicate-check keys added
        require_once __DIR__ . '/beneficiary.php';
        benef_migrate_v8();
        db()->prepare("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)")->execute([SCHEMA_VERSION]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
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
/** The visitor's address. IPv6 addresses are grouped by /64, since one home or phone usually owns a whole /64. */
function client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ($bin = @inet_pton($ip)) !== false) $ip = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    return substr($ip, 0, 45);
}
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
        $u = one("SELECT * FROM users WHERE id=? AND active=1 AND account_status='active'", [$uid]);
        if (!$u || (int)$u['session_version'] !== (int)($_SESSION['sv'] ?? -1)) { $u = null; unset($_SESSION['uid'], $_SESSION['sv']); }
        $cache = [$key => $u];
    }
    return $cache[$key];
}
/* ---------- roles & permissions ---------- */
const ROLES = ['super_admin' => 'Super admin', 'admin' => 'Admin / Operations manager', 'manager' => 'Sub-agent manager', 'compliance' => 'Compliance officer', 'staff' => 'Operations staff', 'accountant' => 'Accountant', 'auditor' => 'Read-only auditor', 'agent' => 'Sub-agent'];
/** Roles that must use two-step login (authenticator app). */
const MFA_REQUIRED = ['super_admin', 'admin', 'compliance', 'manager'];
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
    'team.agents' => 'Add sub-agents and reset their passwords; control every sub-agent and assign managers',
    'agents.assigned' => 'Control only the sub-agents assigned to them (status, links, limits, reports)',
    'withdrawals.review' => 'Review, approve, reject or cancel commission withdrawals of any sub-agent',
    'withdrawals.team' => 'Review commission withdrawals of their own team, approve up to their approval limit',
    'withdrawals.pay' => 'Finance: process withdrawals, mark them Paid or Failed, reconcile failed payments',
    'team.staff' => 'Add admins, staff and accountants',
    'log.use' => 'Use the daily work log',
    'export' => 'Export CSV files',
    'import' => 'Import data',
    'audit.all' => "See everyone's activity history",
    'compliance.view' => 'See the Compliance Center (KYC queue, expiring documents, reminders)',
    'compliance.manage' => 'Request updated documents, manage reminders and compliance settings',
    'beneficiaries.manage' => 'Add, edit and deactivate beneficiaries (sub-agents: their own clients)',
    'beneficiaries.review' => 'Screen and approve or reject beneficiaries',
];
/** Compliance settings (changeable in the Compliance Center). Values are starting points pending compliance sign-off. */
const COMPLIANCE_DEFAULTS = [
    'refresh_months_low' => '36', 'refresh_months_medium' => '24', 'refresh_months_high' => '12',
    'block_unverified' => '1', 'block_expired_doc' => '1', 'expired_grace_days' => '0', 'block_rekyc_overdue' => '1',
    'rekyc_open_days' => '90', 'benef_high_risk_countries' => '', 'benef_max_per_client' => '10', 'benef_four_eyes' => '1', 'escalate_after_days' => '7', 'upload_link_days' => '7', 'reminder_max_attempts' => '3',
];
const DEFAULT_PERMS = [
    'admin' => ['clients.all', 'clients.edit', 'submissions.review', 'transfers.create', 'transfers.decide', 'rates.set', 'commission.view', 'team.agents', 'log.use', 'export', 'audit.all', 'compliance.view', 'beneficiaries.manage', 'withdrawals.review'],
    'compliance' => ['clients.all', 'kyc.decide', 'pii.reveal', 'submissions.review', 'audit.all', 'export', 'compliance.view', 'compliance.manage', 'beneficiaries.manage', 'beneficiaries.review'],
    'auditor' => ['clients.all', 'commission.view', 'audit.all', 'export', 'compliance.view'],
    'staff' => ['clients.all', 'clients.edit', 'submissions.review', 'transfers.create', 'log.use', 'beneficiaries.manage'],
    'accountant' => ['clients.all', 'commission.view', 'export', 'withdrawals.pay'],
    'manager' => ['agents.assigned', 'withdrawals.team'],
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
    $lock = (int)val('SELECT GET_LOCK(?, 10)', [lock_name('dx_audit')]);
    if (!$lock) throw new RuntimeException('The activity log is busy. Please try again.');
    try {
        $prev = (string)(db()->query('SELECT row_hash FROM audit ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '');
        $at = date('Y-m-d H:i:s');
        $hash = audit_hash($prev, $userId, $action, $entity, $entityId, $detail, $ip, $at);
        q('INSERT INTO audit (user_id, action, entity, entity_id, detail, ip, created_at, prev_hash, row_hash) VALUES (?,?,?,?,?,?,?,?,?)',
            [$userId, $action, $entity, $entityId, $detail, $ip, $at, $prev ?: null, $hash]);
    } finally { val('SELECT RELEASE_LOCK(?)', [lock_name('dx_audit')]); }
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
require_once __DIR__ . '/keyring.php';
function enc_ready(): bool { try { keyring_key(keyring_active()); keyring_key(keyring_stable()); return true; } catch (Throwable $e) { return false; } }
/**
 * A separate key for each job, derived from a root key of the key ring (inc/keyring.php).
 * Sealing jobs (data, files, backup) use the active key unless a stored value names its key; search keys and
 * signatures use the stable key. Without a key ring both are k0 = the config master key, exactly as before.
 */
function sec_key(string $purpose, ?string $kid = null): string {
    $kid ??= in_array($purpose, ['data', 'files', 'backup'], true) ? keyring_active() : keyring_stable();
    return hash_hmac('sha256', 'dhanxfer:' . $purpose, keyring_key($kid), true);
}
/**
 * Authenticated encryption: AES-256-GCM (OpenSSL, available on every host) with a random 12-byte nonce.
 * Stored as "enc:v1:" + base64(nonce | tag | ciphertext). Any change to the stored value makes decryption fail.
 */
function aead_seal(string $plain, string $key, string $aad = ''): string {
    $nonce = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return $nonce . $tag . $ct;
}
function aead_open(string $raw, string $key, string $aad = ''): ?string {
    if (strlen($raw) < 28) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
    return $p === false ? null : $p;
}
/** "enc:v1:…" = sealed with k0 (the config key); "enc:v2:<kid>:…" = sealed with key ring key <kid> (bound in as AAD). */
function enc(?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    $kid = keyring_active();
    if ($kid === 'k0') return 'enc:v1:' . base64_encode(aead_seal($plain, sec_key('data', 'k0')));
    return 'enc:v2:' . $kid . ':' . base64_encode(aead_seal($plain, sec_key('data', $kid), 'v2:' . $kid));
}
function dec(?string $stored): ?string {
    if ($stored === null) return null;
    if (str_starts_with($stored, 'enc:v1:')) { $raw = base64_decode(substr($stored, 7), true); return $raw === false ? null : aead_open($raw, sec_key('data', 'k0')); }
    if (str_starts_with($stored, 'enc:v2:') && preg_match('/^enc:v2:(k[0-9]{1,4}):(.+)$/s', $stored, $m)) {
        $raw = base64_decode($m[2], true);
        if ($raw === false || !isset(keyring()['keys'][$m[1]])) return null; // damaged value or a key this server does not have
        return aead_open($raw, sec_key('data', $m[1]), 'v2:' . $m[1]);
    }
    if (str_starts_with($stored, 'enc:')) return null; // unknown format: never hand back sealed text as if it were data
    return $stored;
}
/** Which key sealed a stored value (null = not sealed). Used by the re-seal tool. */
function enc_kid(?string $stored): ?string {
    if ($stored === null) return null;
    if (str_starts_with($stored, 'enc:v1:')) return 'k0';
    return preg_match('/^enc:v2:(k[0-9]{1,4}):/', $stored, $m) ? $m[1] : null;
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
const KYC = ['Pending', 'Verified', 'Rejected', 'Expired', 'Info requested', 'Escalated'];
const RISK = ['Low', 'Medium', 'High'];
const PURPOSES = ['Family support', 'Education / tuition', 'Business payment', 'Savings / investment', 'Property', 'Gift', 'Medical', 'Other'];
const SOF = ['Salary', 'Business income', 'Savings', 'Sale of property', 'Gift / inheritance', 'Other'];
const VOLUMES = ['Under 1,000', '1,000 – 5,000', '5,000 – 10,000', '10,000 – 25,000', 'Over 25,000'];
const OCCUPATIONS = ['Employed', 'Self-employed', 'Student', 'Retired', 'Homemaker', 'Not working'];
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
    if (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') throw new RuntimeException('The ID file could not be saved.');
    $plain = (string)file_get_contents($f['tmp_name']);
    if ($plain === '' || !id_file_write($name, $plain)) throw new RuntimeException('The ID file could not be saved.');
    @unlink($f['tmp_name']);
    return $name;
}
/*
 * ID documents are stored encrypted (AES-256-GCM, own key derived from the master key, file name bound in).
 * File format: "DXF1" + nonce + tag + ciphertext. Files saved before encryption existed are read as they are
 * until the upgrade encrypts them.
 */
function id_file_write(string $name, string $plain): bool {
    $dir = ids_dir();
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $tmp = $dir . '/.' . $name . '.' . bin2hex(random_bytes(4));
    $kid = keyring_active();
    $body = $kid === 'k0' ? 'DXF1' . aead_seal($plain, sec_key('files', 'k0'), $name) : 'DXF2' . chr(strlen($kid)) . $kid . aead_seal($plain, sec_key('files', $kid), $kid . ':' . $name);
    if (file_put_contents($tmp, $body) === false) return false;
    @chmod($tmp, 0640);
    return rename($tmp, $dir . '/' . $name);
}
function id_file_read(string $name): ?string {
    $path = ids_dir() . '/' . basename($name);
    if (!is_file($path)) return null;
    $raw = (string)file_get_contents($path);
    if (str_starts_with($raw, 'DXF1')) return aead_open(substr($raw, 4), sec_key('files', 'k0'), basename($name));
    if (str_starts_with($raw, 'DXF2')) {
        $kid = substr($raw, 5, ord($raw[4] ?? "\0"));
        if (!preg_match('/^k[0-9]{1,4}$/', $kid) || !isset(keyring()['keys'][$kid])) return null;
        return aead_open(substr($raw, 5 + strlen($kid)), sec_key('files', $kid), $kid . ':' . basename($name));
    }
    return $raw; // older plain file
}
function id_file_encrypted(string $name): bool {
    $h = @fopen(ids_dir() . '/' . basename($name), 'rb'); if (!$h) return false;
    $m = fread($h, 4); fclose($h); return $m === 'DXF1' || $m === 'DXF2';
}
/** Key id of an ID file (k0 for DXF1), null if not encrypted. */
function id_file_kid(string $name): ?string {
    $h = @fopen(ids_dir() . '/' . basename($name), 'rb'); if (!$h) return null;
    $m = (string)fread($h, 10); fclose($h);
    if (str_starts_with($m, 'DXF1')) return 'k0';
    return str_starts_with($m, 'DXF2') ? substr($m, 5, ord($m[4] ?? "\0")) : null;
}

/**
 * Sends a plain-text email. Never put passwords, codes, keys or client ID/account numbers in $body.
 * $kind is a short label for the activity log (the address itself is not logged).
 */
function send_mail(string $to, string $subject, string $body, string $kind = 'notice'): bool {
    global $CONFIG;
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) return false;
    if (($CONFIG['environment'] ?? '') === 'staging') $subject = '[STAGING] ' . $subject;
    if (!empty($CONFIG['mail_disabled'])) { try { audit_write(null, 'email_sent', 'email', null, $kind . ' (test mode, not sent)', 'mail'); } catch (Throwable $e) {} return true; }
    $from = $CONFIG['mail_from'] ?? 'no-reply@dhanxfer.com';
    $domain = substr(strrchr($from, '@') ?: '@dhanxfer.com', 1);
    $headers = implode("\r\n", [
        'From: DhanXfer Portal <' . $from . '>',
        'Reply-To: ' . ($CONFIG['mail_reply_to'] ?? 'Info@dhanxfer.com'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
    ]);
    $text = $body . "\n\n--\nDhanXfer Portal · WhatsApp +1 (942)-6600-0600 · www.dhanxfer.com\nThis is an automatic message.";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (!empty($CONFIG['smtp']['host']) && !empty($CONFIG['smtp']['pass'])) {
        // authenticated mailbox: signed and accepted as genuine (PHP mail() from the web server lands in Junk)
        require_once __DIR__ . '/smtp.php';
        $ok = smtp_send($CONFIG['smtp'], $from, $to, $subj, $headers, $text);
    } else $ok = @mail($to, $subj, $text, $headers, '-f' . $from);
    try { audit_write(null, $ok ? 'email_sent' : 'email_failed', 'email', null, $kind, 'mail'); } catch (Throwable $e) {}
    return $ok;
}
function app_link(string $path): string { global $CONFIG; return rtrim($CONFIG['app_url'], '/') . $path; }
/** Message to the owner/operations inbox (notify_email in the settings file). */
function notify(string $subject, string $body, string $kind = 'notice'): void {
    global $CONFIG;
    if (!empty($CONFIG['notify_email'])) send_mail($CONFIG['notify_email'], $subject, $body, $kind);
}
/** Security notice to a team member's own email, if one is on file. */
function notify_user(int $uid, string $subject, string $body, string $kind = 'security_alert'): bool {
    $em = (string)val('SELECT email FROM users WHERE id=?', [$uid]);
    return $em !== '' && send_mail($em, $subject, $body, $kind);
}
