<?php
// Automated checks for Phase 1. Command line only, against the separate TEST database (wiped each run).
// Run on the server:  php tests/run.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DX_TEST', true);
define('DX_NO_AUTO_MIGRATE', true); // the safety check below must look at the database before anything is created
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/clientform.php';
require __DIR__ . '/../inc/rates.php';
require __DIR__ . '/../inc/mfa.php';

$pass = 0; $fail = 0; $out = []; $finished = false;
// If anything crashes, still write what ran, plus the error, to the report.
register_shutdown_function(function () {
    global $pass, $fail, $out, $finished;
    if ($finished) return;
    $err = error_get_last();
    $report = date('Y-m-d H:i:s') . sprintf("  STOPPED EARLY  %d passed, %d failed\n", $pass, $fail) . implode("\n", $out) . "\nCRASH: " . ($err ? $err['message'] . ' at ' . basename($err['file']) . ':' . $err['line'] : 'unknown') . "\n";
    echo $report;
    @file_put_contents(__DIR__ . '/../storage/test-report.txt', $report);
});
set_exception_handler(function (Throwable $e) { global $out; $out[] = 'CRASH  ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(); });
function ok(bool $cond, string $name) { global $pass, $fail, $out; $cond ? $pass++ : $fail++; $out[] = ($cond ? 'ok     ' : 'FAILED ') . $name; }
function as_user(?array $u) { $_SESSION['uid'] = $u['id'] ?? 0; $_SESSION['sv'] = $u['session_version'] ?? 0; }
function mkuser(string $role, string $name, ?string $ref = null): array {
    q('INSERT INTO users (role, username, name, ref_code, password_hash, must_change_password) VALUES (?,?,?,?,?,0)', [$role, strtolower(str_replace(' ', '.', $name)) . bin2hex(random_bytes(2)), $name, $ref, password_hash('x', PASSWORD_DEFAULT)]);
    return one('SELECT * FROM users WHERE id=?', [(int)db()->lastInsertId()]);
}

// Safety: never touch a database that could be live. The test database must differ from the live config
// and must be empty or carry the test marker written below.
(function () {
    global $CONFIG;
    $liveCfg = is_file(dirname(__DIR__, 2) . '/dx-config.php') ? dirname(__DIR__, 2) . '/dx-config.php' : __DIR__ . '/../inc/config.php';
    if (is_file($liveCfg)) { $live = (function ($f) { return require $f; })($liveCfg); if (($live['db_name'] ?? '') === $CONFIG['db_name']) { echo "REFUSED: test database is the same as the live one.\n"; exit(2); } }
    if (($CONFIG['environment'] ?? '') === 'live') { echo "REFUSED: environment is live.\n"; exit(2); }
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $marker = in_array('settings', $tables, true) ? db()->query("SELECT v FROM settings WHERE k='db_role'")->fetchColumn() : false;
    if ($tables && $marker !== 'test') {
        // Allowed only when no table holds any row (for example tables created but never used).
        foreach ($tables as $t) if ((int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() > 0 && !in_array($t, ['settings', 'rate_pairs', 'role_permissions'], true)) { echo "REFUSED: database holds data in $t and is not marked as a test database.\n"; exit(2); }
        if (in_array('settings', $tables, true) && (int)db()->query("SELECT COUNT(*) FROM settings WHERE k NOT IN ('schema_version','buy_pct','sell_pct','send_time','rates_on')")->fetchColumn() > 0) { echo "REFUSED: settings table has real settings.\n"; exit(2); }
    }
    $CONFIG['ids_dir'] = sys_get_temp_dir() . '/dx-test-ids-' . getmypid();
})();
function mark_test_db() { q("REPLACE INTO settings (k, v) VALUES ('db_role', 'test')"); }
function wipe_db() { db()->exec('SET FOREIGN_KEY_CHECKS=0'); foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) db()->exec("DROP TABLE `$t`"); }
require_once __DIR__ . '/../inc/backup.php';

// ---- 0. upgrade from version 2 with synthetic data ----
wipe_db();
foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/fixtures/schema_v2.sql')))) as $sql) db()->exec($sql);
mark_test_db();
db()->exec("INSERT INTO users (role, username, name, password_hash) VALUES ('admin','owner','Synthetic Owner','x'),('admin','second','Synthetic Admin','x'),('agent','agent1','Synthetic Agent','x')");
db()->exec("INSERT INTO clients (client_no, name, phone, id_number, agent_id) VALUES ('DX-TEST-0001','Synthetic Client','+1 416 555 0100','AB123456',3)");
db()->exec("INSERT INTO beneficiaries (client_id, name, account) VALUES (1,'Synthetic Beneficiary','000999888777')");
db()->exec("INSERT INTO submissions (data, name, phone) VALUES ('{\"name\":\"Synthetic\",\"id_number\":\"ZZ998877\"}','Synthetic','+1 416 555 0199')");
// edge cases a real database may hold: the longest values the old 80-character columns allow, non-Latin text, empty values
$longId = str_repeat('L', 80); $iban = 'GB82WEST12345698765432XXXXXXXXXXXX'; $uni = 'ÄÖ-१२३४५-محمد';
q("INSERT INTO clients (client_no, name, phone, id_number, agent_id) VALUES ('DX-TEST-0002','Synthetic Long','+1 416 555 0101',?,3),('DX-TEST-0003','Synthetic Uni','+1 416 555 0102',?,3),('DX-TEST-0004','Synthetic Empty','+1 416 555 0103','',3)", [$longId, $uni]);
q("INSERT INTO beneficiaries (client_id, name, account) VALUES (2,'Synthetic IBAN',?),(2,'Synthetic Long',?)", [$iban, str_repeat('9', 80)]);
ok(in_array('STRICT_ALL_TABLES', explode(',', (string)db()->query('SELECT @@SESSION.sql_mode')->fetchColumn()), true), 'database rejects truncated values instead of cutting them silently');
// rollback drill: back up the old (v2) database, upgrade, restore the backup, upgrade again
$fpV2 = db_fingerprint(); $bkV2 = backup_create('pre-upgrade');
migrate();
ok((string)setting('schema_version') === SCHEMA_VERSION && $fpV2 != db_fingerprint(), 'rollback drill: upgrade ran on the v2 copy');
$rr = backup_restore($bkV2['file']);
ok($rr['match'] && db_fingerprint() == $fpV2 && (string)val("SELECT id_number FROM clients WHERE client_no='DX-TEST-0001'") === 'AB123456', 'rollback drill: restoring the pre-upgrade backup returns the exact v2 database');
ok((int)val("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='id_number'") === 80, 'rollback drill: old table layout is back too');
@unlink(backup_dir() . '/' . $bkV2['file']);
migrate();
require_once __DIR__ . '/../inc/verify.php';
ok(dec((string)val("SELECT id_number FROM clients WHERE client_no='DX-TEST-0002'")) === $longId, 'longest possible old ID number (80 characters) survives encryption exactly');
ok(dec((string)val("SELECT id_number FROM clients WHERE client_no='DX-TEST-0003'")) === $uni && val("SELECT id_number_hash FROM clients WHERE client_no='DX-TEST-0003'") === id_hash($uni), 'non-Latin ID number survives encryption and stays searchable');
ok((string)val("SELECT id_number FROM clients WHERE client_no='DX-TEST-0004'") === '', 'empty ID number stays empty');
ok(dec((string)val("SELECT account FROM beneficiaries WHERE name='Synthetic IBAN'")) === $iban && dec((string)val("SELECT account FROM beneficiaries WHERE name='Synthetic Long'")) === str_repeat('9', 80), 'IBAN and 80-digit account numbers survive encryption exactly');
ok((int)val("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='id_number'") === 255, 'ID number column widened before encrypting');
$hh = data_health();
ok(!$hh['problems'], 'post-upgrade data check finds no problems' . ($hh['problems'] ? ' (' . implode('; ', $hh['problems']) . ')' : ''));
ok($hh['encrypted']['clients.id_number']['plain'] === 0 && $hh['encrypted']['clients.id_number']['sealed'] === 3, 'data check counts every encrypted ID number');
q("UPDATE clients SET id_number='PLAINTEXT1' WHERE client_no='DX-TEST-0002'");
ok((bool)array_filter(data_health()['problems'], fn($x) => str_contains($x, 'still readable')), 'data check catches a value left readable');
q("UPDATE clients SET id_number=?, id_number_hash=? WHERE client_no='DX-TEST-0002'", [enc($longId), id_hash($longId)]);
ok((string)setting('schema_version') === SCHEMA_VERSION, 'v2 database upgrades to schema ' . SCHEMA_VERSION);
ok((int)val("SELECT COUNT(*) FROM users WHERE role='super_admin'") === 0, 'upgrade promotes nobody to super admin');
ok((int)val("SELECT COUNT(*) FROM users WHERE role='admin' AND mfa_token_only=1") === 2, 'existing admins can only set up two-step login through a verified link or claim');
ok((int)val("SELECT mfa_token_only FROM users WHERE username='agent1'") === 0, 'sub-agents are not locked out');
$cid = one('SELECT * FROM clients WHERE client_no=?', ['DX-TEST-0001']);
ok(str_starts_with((string)$cid['id_number'], 'enc:v1:') && dec($cid['id_number']) === 'AB123456' && $cid['id_number_hash'] === id_hash('AB123456'), 'existing ID number encrypted and searchable after upgrade');
ok(dec((string)val('SELECT account FROM beneficiaries LIMIT 1')) === '000999888777' && str_starts_with((string)val('SELECT account FROM beneficiaries LIMIT 1'), 'enc:'), 'existing account number encrypted after upgrade');
ok(!str_contains((string)val('SELECT data FROM submissions LIMIT 1'), 'ZZ998877') && str_contains((string)dec(val('SELECT data FROM submissions LIMIT 1')), 'ZZ998877'), 'existing online forms encrypted after upgrade');
migrate(); // running again must change nothing
ok(dec(val("SELECT id_number FROM clients WHERE client_no='DX-TEST-0001'")) === 'AB123456', 'upgrade is safe to run twice (no double encryption)');

// ---- 0b. backup and restore ----
$before = db_fingerprint();
$bk = backup_create('test');
q("UPDATE clients SET name='Damaged' WHERE client_no='DX-TEST-0001'"); q('DELETE FROM beneficiaries');
ok(db_fingerprint() != $before, 'test damage changes the fingerprint');
$res = backup_restore($bk['file']);
ok($res['match'] && db_fingerprint() == $before, 'restore brings back an identical database (fingerprint match)');
ok(dec((string)val('SELECT account FROM beneficiaries LIMIT 1')) === '000999888777', 'restored data still decrypts');
$raw = (string)file_get_contents(backup_dir() . '/' . $bk['file']);
ok(!str_contains($raw, 'Synthetic') && !str_contains(gzdecode(substr($raw, 4)) ?: '', 'Synthetic'), 'backup file is encrypted (no readable names inside)');
ok(!str_starts_with(realpath(backup_dir()), realpath(__DIR__ . '/..')) && !str_contains(realpath(backup_dir()), 'public_html'), 'backups are stored outside the website folder');
ok(str_starts_with(ids_dir(), sys_get_temp_dir()), 'tests use their own ID-file folder, never the real one');
foreach (glob(backup_dir() . '/dx-*.bak') ?: [] as $f) @unlink($f);

// ---- fresh test database ----
wipe_db();
migrate();
mark_test_db();
ok((string)setting('schema_version') === SCHEMA_VERSION, 'database builds from scratch to schema ' . SCHEMA_VERSION);

$super = mkuser('super_admin', 'Test Super'); $admin = mkuser('admin', 'Test Admin'); $comp = mkuser('compliance', 'Test Compliance');
$staff = mkuser('staff', 'Test Staff'); $acct = mkuser('accountant', 'Test Accountant'); $aud = mkuser('auditor', 'Test Auditor');
$agA = mkuser('agent', 'Agent A', 'SA-1001'); $agB = mkuser('agent', 'Agent B', 'SA-1002');
$base = ['name' => 'Client', 'phone' => '+1 416 555 0101', 'office' => 'Canada', 'id_number' => 'P1234567', 'id_type' => 'Passport'];
$cA = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), $base, ['name' => 'Client of A']), ['agent_id' => $agA['id'], 'source' => 'agent']);
$cB = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), $base, ['name' => 'Client of B', 'phone' => '+1 416 555 0202', 'id_number' => 'X9988776']), ['agent_id' => $agB['id'], 'source' => 'agent']);
$rowA = one('SELECT * FROM clients WHERE id=?', [$cA]); $rowB = one('SELECT * FROM clients WHERE id=?', [$cB]);

// ---- 1. cross-agent isolation ----
as_user($agA);
ok(can_see_client($rowA), 'agent A sees own client');
ok(!can_see_client($rowB), "agent A cannot see agent B's client");
[$w, $p] = scope_where(me(), 'c');
$ids = array_column(all("SELECT c.id FROM clients c WHERE $w", $p), 'id');
ok($ids === [(string)$cA] || $ids === [$cA], 'agent A client list holds only own client');
q("INSERT INTO transfers (ref_no, client_id, client_name, agent_id, send_amount, send_currency, receive_currency, transfer_date, status) VALUES ('T1',?,?,?,1000,'CAD','INR',CURDATE(),'accepted'),('T2',?,?,?,500,'CAD','INR',CURDATE(),'pending')", [$cA, 'Client of A', $agA['id'], $cB, 'Client of B', $agB['id']]);
[$w, $p] = scope_where(me(), 't');
ok(array_column(all("SELECT t.ref_no FROM transfers t WHERE $w", $p), 'ref_no') === ['T1'], 'agent A transfer list holds only own transfers');
ok(!can_see_transfer(one("SELECT * FROM transfers WHERE ref_no='T2'")), "agent A cannot open agent B's transfer");
as_user($agB);
ok(!can_see_client($rowA), "agent B cannot see agent A's client");
as_user(null);
ok(!can_see_client($rowA) && scope_where(me(), 'c')[0] === '1=0', 'logged-out visitor sees no clients');

// ---- 2. role permissions ----
foreach ([$staff, $admin, $comp, $acct, $aud, $super] as $x) { as_user($x); ok(can_see_client($rowA) && can_see_client($rowB), role_label($x['role']) . ' sees all clients'); }
ok(!role_can('agent', 'kyc.decide') && !role_can('agent', 'transfers.decide') && !role_can('agent', 'audit.all') && !role_can('agent', 'pii.reveal'), 'sub-agent has no approval, audit or reveal rights');
ok(role_can('compliance', 'kyc.decide') && !role_can('admin', 'kyc.decide'), 'KYC decisions belong to Compliance, not Admin (default)');
$edit = ['clients.edit', 'clients.delete', 'kyc.decide', 'transfers.create', 'transfers.decide', 'rates.set', 'rates.settings', 'commission.set', 'team.agents', 'team.staff', 'log.use', 'import', 'submissions.review'];
ok(!array_intersect($edit, role_perms('auditor')), 'Read-only auditor has no editing permission');
ok(role_can('auditor', 'audit.all') && role_can('auditor', 'clients.all'), 'auditor can read clients and the activity log');
ok(count(role_perms('super_admin')) === count(PERMS), 'super admin has every permission');
ok(!role_can('staff', 'transfers.decide'), 'operations staff cannot accept transfers');

// ---- 3. encryption and masking ----
ok(str_starts_with((string)$rowA['id_number'], 'enc:v1:') && dec($rowA['id_number']) === 'P1234567', 'ID number stored encrypted and decrypts');
ok(enc('P1234567') !== enc('P1234567'), 'same value encrypts differently each time');
$fake = 'enc:v1:' . base64_encode(random_bytes(40));
ok(enc($fake) !== $fake && dec(enc($fake)) === $fake, 'text that looks encrypted is still encrypted, never stored as given');
$t = $rowA['id_number']; $t[20] = $t[20] === 'A' ? 'B' : 'A';
ok(dec($t) === null, 'tampered ciphertext is rejected');
ok(mask('P1234567') === '•••• 4567' && mask('12') === '••', 'masking shows last 4 only');
ok(val('SELECT id FROM clients WHERE id_number_hash=?', [id_hash('p-123 4567')]) == $cA, 'exact ID search works through the hash');
save_beneficiaries($cA, [['name' => 'Mother', 'relation' => 'Mother', 'country' => 'India', 'bank' => 'SBI', 'account' => '000123456789', 'phone' => '']]);
$acc = val('SELECT account FROM beneficiaries WHERE client_id=?', [$cA]);
ok(str_starts_with((string)$acc, 'enc:v1:') && dec($acc) === '000123456789', 'beneficiary account stored encrypted');
ok(!str_contains((string)val('SELECT data FROM submissions LIMIT 1') ?? '', 'P1234567'), 'no plain ID in submissions table');

// ---- 4. signed referral links ----
ok(ref_valid('SA-1001', ref_sig('SA-1001')), 'agent link signature accepted');
ok(!ref_valid('SA-1002', ref_sig('SA-1001')), 'changing the code in the link is rejected');
ok(!ref_valid('SA-1001', ''), 'link without signature is not trusted');
ok(str_contains(referral_link('SA-1001'), '&s=' . ref_sig('SA-1001')), 'share link carries the signature');

// ---- 5. reset / activation links ----
$link = make_reset_link((int)$agA['id'], (int)$admin['id'], 'activate');
parse_str((string)parse_url($link, PHP_URL_QUERY), $qq);
$row = one('SELECT * FROM password_resets WHERE token_hash=?', [hash('sha256', $qq['t'])]);
ok($row && $row['purpose'] === 'activate' && strtotime($row['expires_at']) > time() + 71 * 3600, 'activation link stored hashed, 72-hour expiry');
$first = q('UPDATE password_resets SET used_at=NOW() WHERE id=? AND used_at IS NULL', [$row['id']])->rowCount();
$second = q('UPDATE password_resets SET used_at=NOW() WHERE id=? AND used_at IS NULL', [$row['id']])->rowCount();
ok($first === 1 && $second === 0, 'a link can be used only once, even by two requests at the same time');
make_reset_link((int)$agA['id'], (int)$admin['id']); $old = (int)val('SELECT COUNT(*) FROM password_resets WHERE user_id=? AND used_at IS NULL', [$agA['id']]);
make_reset_link((int)$agA['id'], (int)$admin['id']);
ok((int)val('SELECT COUNT(*) FROM password_resets WHERE user_id=? AND used_at IS NULL', [$agA['id']]) === 1 && $old === 1, 'a new link cancels the older one');
$limited = false; try { for ($i = 0; $i < 6; $i++) make_reset_link((int)$agA['id'], (int)$admin['id']); } catch (RuntimeException $e) { $limited = true; }
ok($limited, 'link creation is rate limited (5 per hour)');

// ---- 6. two-step login ----
ok(totp_code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1) === '287082', 'TOTP matches RFC 6238 test vector');
$sec = mfa_new_secret();
q('UPDATE users SET mfa_secret=?, mfa_enabled=1, mfa_recovery=? WHERE id=?', [enc($sec), json_encode([password_hash('ABCDE12345', PASSWORD_DEFAULT)]), $admin['id']]);
$adm = one('SELECT * FROM users WHERE id=?', [$admin['id']]);
$code = totp_code($sec, intdiv(time(), 30));
ok(mfa_check($adm, $code), 'current code accepted');
ok(!mfa_check(one('SELECT * FROM users WHERE id=?', [$admin['id']]), $code), 'same code cannot be used twice');
ok(!mfa_check(one('SELECT * FROM users WHERE id=?', [$admin['id']]), '000000') || $code === '000000', 'wrong code rejected');
ok(mfa_check(one('SELECT * FROM users WHERE id=?', [$admin['id']]), 'abcde-12345'), 'recovery code works');
ok(!mfa_check(one('SELECT * FROM users WHERE id=?', [$admin['id']]), 'ABCDE-12345'), 'recovery code works only once');
q('UPDATE users SET mfa_recovery=? WHERE id=?', [json_encode([password_hash('RACE000001', PASSWORD_DEFAULT)]), $admin['id']]);
$snap1 = one('SELECT * FROM users WHERE id=?', [$admin['id']]); $snap2 = $snap1; // two requests read the same row
ok(mfa_check($snap1, 'RACE0-00001') && !mfa_check($snap2, 'RACE0-00001'), 'two requests at once cannot both use one recovery code');
ok(mfa_required($admin) && mfa_required($super) && mfa_required($comp) && !mfa_required($agA), 'two-step login required for super admin, admin and compliance');

// ---- 7. tamper-evident activity log ----
as_user($super);
for ($i = 0; $i < 5; $i++) audit('test_event', 'test', $i, "row $i");
[$n, $broken] = audit_verify();
ok($n >= 5 && $broken === null, 'log chain verifies when untouched');
$mid = (int)val("SELECT id FROM audit WHERE action='test_event' ORDER BY id LIMIT 1 OFFSET 2");
q("UPDATE audit SET detail='edited' WHERE id=?", [$mid]);
ok(audit_verify()[1] === $mid, 'editing a log row is detected at that row');
q('DELETE FROM audit WHERE id=?', [$mid]);
ok(audit_verify()[1] !== null, 'deleting a log row is detected');
// fresh chain for the next checks
q('TRUNCATE TABLE audit'); @unlink(audit_anchor_file());
for ($i = 0; $i < 3; $i++) audit('test_event', 'test', $i, "row $i");
q("INSERT INTO audit (user_id, action, detail) VALUES (NULL, 'forged', 'no hash')");
ok(audit_verify()[1] !== null, 'a forged row without a hash, after the chain started, is detected');
q("DELETE FROM audit WHERE action='forged'");
audit_write_anchor();
$last = (int)val('SELECT MAX(id) FROM audit');
ok(audit_verify()[1] === null && audit_verify()[3] === null, 'chain and daily checkpoint agree');
q('DELETE FROM audit WHERE id=?', [$last]);
ok(audit_verify()[3] !== null, 'cutting off the newest rows is detected by the checkpoint');
@unlink(audit_anchor_file());

// ---- 8. commission ----
q("INSERT INTO agent_commissions (agent_id, send_currency, receive_currency, per_unit) VALUES (?, 'CAD', 'INR', 0.25)", [$agA['id']]);
$tid = (int)val("SELECT id FROM transfers WHERE ref_no='T1'");
apply_commission($tid);
$t1 = one('SELECT * FROM transfers WHERE id=?', [$tid]);
ok((float)$t1['commission'] === 250.0 && $t1['commission_currency'] === 'INR', 'commission 0.25 INR per CAD on 1,000 CAD = 250 INR');
q('UPDATE agent_commissions SET per_unit=0.5 WHERE agent_id=?', [$agA['id']]); apply_commission($tid);
ok((float)val('SELECT commission FROM transfers WHERE id=?', [$tid]) === 250.0, 'commission stays fixed after the rate changes');

// ---- 9. Telegram messages carry no client data ----
$txt = rates_table_text(['CAD>INR' => ['send' => 'CAD', 'recv' => 'INR', 'market' => 61.2, 'buy' => 63.0, 'sell' => 64.9]]);
ok(!preg_match('/Client|P1234567|0001234/', $txt), 'rate message holds rates only');

$summary = sprintf("%s  %d passed, %d failed\n", date('Y-m-d H:i:s'), $pass, $fail);
$report = $summary . implode("\n", $out) . "\n";
echo $report;
@file_put_contents(__DIR__ . '/../storage/test-report.txt', $report);
@file_put_contents(data_dir() . '/test-report.txt', $report);
// leave the test database empty
wipe_db();
$finished = true;
exit($fail ? 1 : 0);
