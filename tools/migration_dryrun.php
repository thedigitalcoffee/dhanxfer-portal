<?php
// READ-ONLY rehearsal of the upgrade against another database (e.g. live), without changing it or copying it.
// php tools/migration_dryrun.php /path/to/that/config.php
// Opens that database in a read-only transaction, checks every row the upgrade will touch, and reports COUNTS ONLY.
// No names, numbers or other client details are printed or saved.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
define('DX_NO_AUTO_MIGRATE', true);
require __DIR__ . '/../inc/bootstrap.php';   // only for the encryption functions (this site's own test key)
$path = (string)($argv[1] ?? '');
if (!is_file($path)) { echo "Give the path of the other site's settings file\n"; exit(2); }
$L = require $path;
if (!is_array($L) || empty($L['db_name'])) { echo "That file has no database settings\n"; exit(2); }
$pdo = new PDO('mysql:host=' . $L['db_host'] . ';dbname=' . $L['db_name'] . ';charset=utf8mb4', $L['db_user'], $L['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
$out = []; $warn = []; $stop = [];
$p = function (string $s) use (&$out) { $out[] = $s; };
$col = function (string $t, string $c) use ($pdo) {
    $s = $pdo->prepare('SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$t, $c]); return $s->fetch() ?: null;
};
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$counts = []; foreach ($tables as $t) $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
$p('Tables: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($counts), $counts)));
$ver = in_array('settings', $tables, true) ? ($pdo->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn() ?: '(none)') : '(no settings table)';
$p("Schema version now: $ver → upgrade to " . SCHEMA_VERSION);
$p('Engines: ' . implode(', ', array_unique($pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN))));
$p('Server: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . ' · sql_mode: ' . ($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn() ?: '(empty)'));

// Every value the upgrade encrypts: does it fit and come back exactly?
$sealedLen = fn(string $v) => strlen(enc($v));
foreach ([['clients', 'id_number', 255], ['beneficiaries', 'account', 255], ['submissions', 'data', null], ['users', 'mfa_secret', 255]] as [$t, $c, $newWidth]) {
    if (!in_array($t, $tables, true) || !($ci = $col($t, $c))) { $p("$t.$c: not present"); continue; }
    $n = 0; $already = 0; $maxPlain = 0; $maxSealed = 0; $roundBad = 0; $badUtf = 0; $tooWide = 0; $jsonBad = 0;
    $cap = $newWidth ?? ['text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295][strtolower($ci['DATA_TYPE'])] ?? (int)$ci['CHARACTER_MAXIMUM_LENGTH'];
    foreach ($pdo->query("SELECT `$c` AS v FROM `$t` WHERE `$c` IS NOT NULL AND `$c`<>''") as $r) {
        $v = (string)$r['v']; $n++;
        if (str_starts_with($v, 'enc:')) { $already++; continue; }
        if (!mb_check_encoding($v, 'UTF-8')) $badUtf++;
        $s = enc($v); $len = strlen($s);
        $maxPlain = max($maxPlain, mb_strlen($v)); $maxSealed = max($maxSealed, $len);
        if (dec($s) !== $v) $roundBad++;
        if ($len > $cap) $tooWide++;
        if ($t === 'submissions' && !is_array(json_decode($v, true))) $jsonBad++;
    }
    $p(sprintf('%s.%s (now %s%s): %d values, %d already encrypted, longest %d chars → %d encrypted (room %d), %d too long, %d round-trip failures, %d invalid text%s',
        $t, $c, $ci['DATA_TYPE'], $ci['CHARACTER_MAXIMUM_LENGTH'] && $ci['CHARACTER_MAXIMUM_LENGTH'] < 100000 ? '(' . $ci['CHARACTER_MAXIMUM_LENGTH'] . ')' : '', $n, $already, $maxPlain, $maxSealed, $cap, $tooWide, $roundBad, $badUtf, $t === 'submissions' ? ", $jsonBad unreadable form records" : ''));
    if ($tooWide || $roundBad) $stop[] = "$t.$c cannot be encrypted safely";
    if ($badUtf) $warn[] = "$t.$c has $badUtf value(s) that are not valid text";
    if ($jsonBad) $warn[] = "$jsonBad online form record(s) are not readable JSON (kept as they are, encrypted)";
}
// Accounts: nobody is promoted; admin-level accounts without two-step become link-only
if (in_array('users', $tables, true)) {
    $roleType = $col('users', 'role');
    $byRole = $pdo->query('SELECT role, COUNT(*) n, SUM(active) a FROM users GROUP BY role')->fetchAll();
    $p('Accounts by role: ' . implode(', ', array_map(fn($r) => "{$r['role']} {$r['n']} ({$r['a']} active)", $byRole)) . ' · role column: ' . $roleType['DATA_TYPE']);
    $unknown = array_diff(array_column($byRole, 'role'), array_keys(ROLES)); if ($unknown) $stop[] = 'unknown role(s) in accounts';
    $hasMfa = (bool)$col('users', 'mfa_enabled');
    $mfaRoles = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE active=1 AND role IN ('" . implode("','", MFA_REQUIRED) . "')" . ($hasMfa ? ' AND mfa_enabled=0' : ''))->fetchColumn();
    $p("After the upgrade: $mfaRoles active admin-level account(s) must set up two-step login through a link from the owner; 0 are promoted to super admin.");
    $dupMail = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT email FROM users WHERE email IS NOT NULL AND email<>'' GROUP BY email HAVING COUNT(*)>1) x")->fetchColumn();
    $noMail = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE active=1 AND (email IS NULL OR email='')")->fetchColumn();
    $p("Active accounts without an email address: $noMail (they get links by WhatsApp instead) · shared email addresses: $dupMail");
}
// Links between records
$orph = function (string $sql) use ($pdo) { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return -1; } };
$p('Records pointing at missing records: '
    . 'clients→agent ' . $orph('SELECT COUNT(*) FROM clients c LEFT JOIN users u ON u.id=c.agent_id WHERE c.agent_id IS NOT NULL AND u.id IS NULL')
    . ', beneficiaries→client ' . $orph('SELECT COUNT(*) FROM beneficiaries b LEFT JOIN clients c ON c.id=b.client_id WHERE c.id IS NULL')
    . ', transfers→client ' . $orph('SELECT COUNT(*) FROM transfers t LEFT JOIN clients c ON c.id=t.client_id WHERE t.client_id IS NOT NULL AND c.id IS NULL')
    . ', transfers→agent ' . $orph('SELECT COUNT(*) FROM transfers t LEFT JOIN users u ON u.id=t.agent_id WHERE t.agent_id IS NOT NULL AND u.id IS NULL'));
$dupId = $orph("SELECT COUNT(*) FROM (SELECT id_number FROM clients WHERE id_number IS NOT NULL AND id_number<>'' GROUP BY id_number HAVING COUNT(*)>1) x");
$p("Clients sharing an ID number with another client: $dupId group(s) (allowed; shown for review)");
// ID documents on disk
$idDir = dirname($path, 2) . '/storage/ids';
$withFile = $orph("SELECT COUNT(*) FROM clients WHERE id_file IS NOT NULL AND id_file<>''");
$present = 0; $missing = 0;
foreach ($pdo->query("SELECT id_file FROM clients WHERE id_file IS NOT NULL AND id_file<>''") as $r) (is_file($idDir . '/' . basename((string)$r['id_file'])) ? $present++ : $missing++);
$onDisk = count(glob($idDir . '/*') ?: []);
$p("ID documents: $withFile linked, $present found, $missing missing; $onDisk file(s) in the folder");
if ($missing) $warn[] = "$missing linked ID document(s) are missing on disk";
$pdo->rollBack();
$verdict = $stop ? 'NOT SAFE: ' . implode('; ', $stop) : ($warn ? 'SAFE with notes: ' . implode('; ', $warn) : 'SAFE: every record can be upgraded and encrypted');
$txt = date('c') . "  READ-ONLY upgrade rehearsal (counts only, nothing written)\n" . implode("\n", $out) . "\n$verdict\n";
echo $txt; @file_put_contents(data_dir() . '/dryrun-report.txt', $txt);
