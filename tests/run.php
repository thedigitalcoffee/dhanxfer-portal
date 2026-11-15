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
require_once __DIR__ . '/../inc/compliance.php';
require_once __DIR__ . '/../inc/beneficiary.php';
require_once __DIR__ . '/../inc/otp.php';

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
    $nu = one('SELECT * FROM users WHERE id=?', [(int)db()->lastInsertId()]);
    if ($role === 'agent') { require_once __DIR__ . '/../inc/agents.php'; ref_link_create($nu, slug_suggest($nu), null); }
    return $nu;
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
    $CONFIG['mail_disabled'] = true; // tests never send real email
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
q("INSERT INTO clients (client_no, name, phone, kyc, agent_id) VALUES ('DX-TEST-0005','Synthetic Legacy Verified','+1 416 555 0105','Verified',3)");
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
$c1doc = one("SELECT v.* FROM document_versions v JOIN clients c ON c.id=v.client_id WHERE c.client_no='DX-TEST-0001'");
ok($c1doc && $c1doc['source'] === 'migration' && dec($c1doc['number_enc']) === 'AB123456' && (int)val("SELECT COUNT(*) FROM client_documents d JOIN clients c ON c.id=d.client_id WHERE c.client_no='DX-TEST-0001'") === 1, 'upgrade moves each existing ID into the document history (version 1), once');
ok(agent_by_code('agent1') && (int)agent_by_code('agent1')['id'] === (int)val("SELECT id FROM users WHERE username='agent1'"), 'upgrade gives each sub-agent their login ID as referral code');
ok(val("SELECT phone_e164 FROM clients WHERE client_no='DX-TEST-0001'") === '+14165550100', 'upgrade stores existing phone numbers in international form');
ok(val("SELECT kyc_next_review FROM clients WHERE client_no='DX-TEST-0005'") === date('Y-m-d', strtotime('+30 days')), 'clients verified under the old one-click process get a KYC refresh within 30 days');
ok((int)val("SELECT COUNT(*) FROM beneficiaries WHERE status='pending' AND account_hash IS NOT NULL") === 3 && val("SELECT account_hash FROM beneficiaries WHERE name='Synthetic Beneficiary'") === benef_account_hash('000999888777'), 'upgrade: existing beneficiaries wait for review and get a duplicate-check key');
ok((string)val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='kyc'") !== '' && str_contains((string)val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='kyc'"), 'Escalated'), 'KYC states extended (information requested, escalated)');

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


// ---- 10. phone numbers (libphonenumber) ----
$pc = fn($n, $r) => phone_check($n, $r);
ok($pc('(416) 555-0123', 'CA')['e164'] === '+14165550123' && $pc('416.555.0123', 'CA')['e164'] === '+14165550123' && $pc('1 416 555 0123', 'CA')['e164'] === '+14165550123', 'Canadian number accepted with brackets, dots, spaces or a leading 1; stored as +1…');
ok(!$pc('416 555 012', 'CA')['ok'] && !$pc('416 555 01234', 'CA')['ok'], 'Canadian number with 9 or 11 digits refused (formatting characters are not counted)');
ok(!$pc('123 456 7890', 'CA')['ok'], 'impossible Canadian number (area code 123) refused');
ok($pc('613 555 0199', 'US')['ok'] && $pc('(212) 555-0187', 'US')['e164'] === '+12125550187', 'US numbers accepted');
ok($pc('98765 43210', 'IN')['e164'] === '+919876543210' && $pc('050 123 4567', 'AE')['e164'] === '+971501234567' && $pc('0300 1234567', 'PK')['e164'] === '+923001234567', 'India, UAE and Pakistan numbers follow their own lengths');
ok($pc('+44 7911 123456', 'CA')['ok'] && $pc('0044 7911 123456', null)['ok'], 'a number starting with + or 00 is read as international, whatever country was chosen');
ok(!$pc('98765 4321', 'IN')['ok'] && !$pc('416555012a', 'CA')['ok'] && !$pc('4165550123', null)['ok'], 'too-short foreign numbers, letters, and numbers without a country are refused');
ok(phone_region('United Arab Emirates') === 'AE' && phone_dial_code('CA') === '+1', 'country chosen by the client sets the expected country code');

// ---- 11. address comparison ----
$home = ['line1' => 'Unit 5, 12 Main St.', 'city' => 'Toronto', 'province' => 'Ontario', 'postal' => 'm5h 2n2', 'country' => 'Canada'];
ok(addr_compare($home, ['line1' => '12 Main Street Apt 5', 'city' => 'toronto', 'province' => 'ON', 'postal' => 'M5H2N2', 'country' => 'CA'])['result'] === 'match', 'same address written differently counts as a match');
ok(addr_compare($home, ['line1' => '12 Mian Street #5', 'city' => 'Torronto', 'postal' => 'M5H 2N2', 'country' => 'Canada'])['result'] === 'minor', 'small spelling slips are "minor", not a mismatch');
ok(addr_compare($home, ['line1' => '88 King Road', 'city' => 'Brampton', 'postal' => 'L6T 1A1', 'country' => 'Canada'])['result'] === 'mismatch', 'a different address is flagged for review');
ok(addr_compare($home, null)['result'] === 'no_id_address' && addr_needs_evidence('no_id_address') && !addr_needs_evidence('minor'), 'an ID without an address needs proof of address; minor differences do not');

$CONFIG['address_provider'] = 'mock'; $envWas = $CONFIG['environment'] ?? null; $CONFIG['environment'] = 'test';
$sug = addr_lookup('100 Queen', 'Canada');
ok(count($sug) >= 1 && $sug[0]['city'] !== '' && $sug[0]['postal'] !== '' && isset($sug[0]['label']), 'address lookup returns full components (street, city, province, postal) to fill the form');
$CONFIG['environment'] = 'live'; ok(!addr_lookup_enabled(), 'the test address provider can never run on the live site');
$CONFIG['address_provider'] = ''; $CONFIG['environment'] = $envWas; ok(!addr_lookup_enabled() && addr_lookup('100 Queen') === [], 'without a provider the form falls back to typing the address');
// ---- 12. encrypted ID images ----
$fname = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.jpg';
$img = "\xFF\xD8\xFF\xE0 synthetic-image Synthetic Client ID";
ok(id_file_write($fname, $img) && id_file_read($fname) === $img, 'ID image is saved and read back exactly');
ok(!str_contains((string)file_get_contents(ids_dir() . '/' . $fname), 'Synthetic') && id_file_encrypted($fname), 'ID image on disk is encrypted');
$raw = (string)file_get_contents(ids_dir() . '/' . $fname); file_put_contents(ids_dir() . '/' . $fname, substr($raw, 0, -1) . chr(ord(substr($raw, -1)) ^ 1));
ok(id_file_read($fname) === null, 'a tampered ID image is refused');
id_file_write($fname, $img);

// ---- 13. KYC review: only through the structured decision ----
$secret = mfa_new_secret();
q('UPDATE users SET mfa_secret=?, mfa_enabled=1 WHERE id IN (?,?)', [enc($secret), $super['id'], $comp['id']]);
$super = one('SELECT * FROM users WHERE id=?', [$super['id']]); $comp = one('SELECT * FROM users WHERE id=?', [$comp['id']]);
$kc = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'KYC Synthetic', 'phone' => '+1 416 555 0303', 'office' => 'Canada', 'dob' => '1990-01-01',
    'address' => '12 Main Street', 'city' => 'Toronto', 'postal' => 'M5H 2N2', 'country' => 'Canada', 'id_type' => 'Passport', 'id_number' => 'KY123456', 'id_expiry' => date('Y-m-d', strtotime('+2 years')),
    'id_has_address' => 'yes', 'id_addr_same' => 'no', 'id_addr_line1' => '88 King Road', 'id_addr_city' => 'Brampton', 'id_addr_postal' => 'L6T 1A1']), ['agent_id' => $agA['id'], 'source' => 'agent', 'id_file' => $fname]);
$kcr = fn() => one('SELECT * FROM clients WHERE id=?', [$kc]);
ok($kcr()['address_match'] === 'mismatch' && doc_current($kc)['version'] == 1 && doc_current($kc)['file_name'] === $fname, 'new client: ID saved as document version 1, address mismatch flagged');
$full = ['decision' => 'approve', 'checklist' => array_keys(KYC_CHECKLIST), 'risk' => 'Low', 'notes' => 'Checked passport in person, all details match.', 'evidence' => 'scan folder 2026/11', 'screening_ref' => 'SCREEN-001', 'address_evidence' => ''];
$code = fn() => totp_code($secret, intdiv(time(), 30));
$reset = fn() => q('UPDATE users SET mfa_last_step=NULL WHERE id IN (?,?)', [$super['id'], $comp['id']]);
foreach (['agent' => $agA, 'admin' => $admin, 'auditor' => $aud, 'staff' => $staff] as $who => $usr) { as_user($usr); $r = kyc_decide($kcr(), $usr, $full + ['code' => '000000']); ok(!$r[0] && $kcr()['kyc'] === 'Pending', "$who cannot approve KYC"); }
as_user($super);
ok(!kyc_decide($kcr(), $super, $full + ['code' => '000000'])[0], 'approval refused without a correct two-step code'); $reset();
$r = kyc_decide($kcr(), $super, array_merge($full, ['checklist' => array_slice(array_keys(KYC_CHECKLIST), 1)]) + ['code' => $code()]); $reset();
ok(!$r[0] && str_contains(implode(' ', $r[1]), 'checklist'), 'approval refused with an unticked checklist item');
$r = kyc_decide($kcr(), $super, array_merge($full, ['screening_ref' => '', 'evidence' => '']) + ['code' => $code()]); $reset();
ok(!$r[0] && str_contains(implode(' ', $r[1]), 'screening') && str_contains(implode(' ', $r[1]), 'evidence reference'), 'approval refused without screening and evidence references');
$r = kyc_decide($kcr(), $super, $full + ['code' => $code()]); $reset();
ok(!$r[0] && str_contains(implode(' ', $r[1]), 'proof of address'), 'mismatched address: approval needs a proof-of-address reference');
$r = kyc_decide($kcr(), $super, array_merge($full, ['notes' => 'short']) + ['code' => $code()]); $reset();
ok(!$r[0], 'decision refused without proper notes');
q("UPDATE document_versions SET expires_on=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE client_id=?", [$kc]);
$r = kyc_decide($kcr(), $super, array_merge($full, ['address_evidence' => 'Hydro bill']) + ['code' => $code()]); $reset();
ok(!$r[0] && str_contains(implode(' ', $r[1]), 'expired'), 'approval refused when the ID has expired');
q("UPDATE document_versions SET expires_on=DATE_ADD(CURDATE(), INTERVAL 2 YEAR) WHERE client_id=?", [$kc]);
$noDoc = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'No Doc', 'phone' => '+1 416 555 0304', 'office' => 'Canada', 'dob' => '1991-01-01']), ['source' => 'admin']);
$r = kyc_decide(one('SELECT * FROM clients WHERE id=?', [$noDoc]), $super, $full + ['code' => $code()]); $reset();
ok(!$r[0] && str_contains(implode(' ', $r[1]), 'No identity document'), 'approval refused when no ID document is on file');
[$el] = transfer_eligibility($kcr()); ok(!$el, 'transfers blocked while KYC is pending');
$r = kyc_decide($kcr(), $super, array_merge($full, ['address_evidence' => 'Hydro bill 2026-10-02']) + ['code' => $code()]); $reset();
$kv = $kcr(); $rev = one('SELECT * FROM kyc_reviews WHERE id=?', [(int)$r[2]]);
ok($r[0] && $kv['kyc'] === 'Verified' && (int)$kv['kyc_reviewed_by'] === (int)$super['id'] && $rev['reviewer_role'] === 'super_admin' && str_contains((string)$rev['checklist'], 'Hydro bill'), 'super admin approves with every prerequisite; reviewer, role and evidence are recorded');
ok(doc_current($kc)['outcome'] === 'verified' && $kv['kyc_next_review'] === date('Y-m-d', strtotime(today() . ' +36 months')), 'document marked verified; next refresh set by the risk policy (low risk: 36 months)');
ok((int)val("SELECT COUNT(*) FROM audit WHERE action='kyc_approve' AND entity_id=?", [$kc]) === 1, 'approval written to the tamper-evident activity log');
ok(!kyc_decide($kcr(), $super, $full + ['code' => $code()])[0], 'an already verified client cannot be approved again without a refresh case'); $reset();
[$el] = transfer_eligibility($kcr()); ok($el, 'transfers allowed once verified with a valid ID');
as_user($comp);
$r = kyc_decide($kcr(), $comp, ['decision' => 'escalate', 'notes' => 'Second opinion needed on source of funds.', 'code' => $code()]); $reset();
ok($r[0] && $kcr()['kyc'] === 'Escalated', 'compliance officer can escalate with notes');
[$el] = transfer_eligibility($kcr()); ok(!$el, 'escalated client cannot transfer');

// review fixes: identity changes reopen the review; a new unreviewed ID does not count; approval closes every open case
q("UPDATE clients SET kyc='Verified' WHERE id=?", [$kc]); kyc_identity_changed($kc, 'name', (int)$super['id']);
ok($kcr()['kyc'] === 'Pending' && (int)val("SELECT COUNT(*) FROM audit WHERE action='kyc_reopened' AND entity_id=?", [$kc]) === 1, 'changing identity details of a verified client sends it back to review');
q("UPDATE clients SET kyc='Verified' WHERE id=?", [$kc]);
$vNew = doc_add_version($kc, ['doc_type' => 'Passport', 'number' => 'KY999999', 'expires_on' => date('Y-m-d', strtotime('+4 years')), 'file_name' => $fname], 'upload_link', null);
[$el, $why] = transfer_eligibility($kcr());
ok(!$el && str_contains(implode(' ', $why), 'not been reviewed'), 'a newer ID that nobody reviewed blocks transfers until it is approved');
q("INSERT INTO rekyc_cases (client_id, reason, status, due_date, open_key) VALUES (?, 'manual', 'in_progress', DATE_ADD(CURDATE(), INTERVAL 9 DAY), 'case-a'), (?, 'periodic', 'required', DATE_ADD(CURDATE(), INTERVAL 9 DAY), 'case-b')", [$kc, $kc]);
as_user($super);
$r = kyc_decide($kcr(), $super, array_merge($full, ['address_evidence' => 'Hydro bill 2026-10-02']) + ['code' => $code()]); $reset();
ok($r[0] && (int)val("SELECT COUNT(*) FROM rekyc_cases WHERE client_id=? AND status IN ('required','in_progress','escalated')", [$kc]) === 0 && doc_current($kc)['outcome'] === 'verified' && transfer_eligibility($kcr())[0], 'approving the new ID closes every open case and allows transfers again');
q("UPDATE document_versions SET outcome='verified' WHERE client_id=?", [$kc]);

// ---- 14. eligibility policy ----
q("UPDATE clients SET kyc='Verified' WHERE id=?", [$kc]);
q("UPDATE document_versions SET expires_on=DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE client_id=?", [$kc]);
ok(!transfer_eligibility($kcr())[0], 'expired ID blocks transfers');
set_setting('expired_grace_days', '5'); ok(transfer_eligibility($kcr())[0], 'grace days after expiry are respected when the policy allows them');
set_setting('expired_grace_days', '0');
q("UPDATE document_versions SET expires_on=DATE_ADD(CURDATE(), INTERVAL 25 DAY) WHERE client_id=?", [$kc]);
q("INSERT INTO rekyc_cases (client_id, reason, status, due_date, open_key) VALUES (?, 'manual', 'required', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'test-overdue')", [$kc]);
ok(!transfer_eligibility($kcr())[0], 'overdue KYC refresh blocks transfers');
q("DELETE FROM rekyc_cases WHERE open_key='test-overdue'");

// ---- 15. reminders and KYC refresh cases ----
q("UPDATE clients SET email='synthetic@example.com' WHERE id=?", [$kc]);
$d1 = compliance_daily();
$rem = all('SELECT event_key, channel, status FROM reminders WHERE client_id=? ORDER BY event_key, channel', [$kc]);
$pend = array_filter($rem, fn($r) => $r['event_key'] === 'exp-30');
$skip = array_filter($rem, fn($r) => in_array($r['event_key'], ['exp-90', 'exp-60'], true) && $r['status'] === 'skipped');
ok(count($pend) === 2 && count($skip) === 4, 'ID expiring in 25 days: the 30-day reminder is made (email + staff); missed earlier stages are skipped, not sent late');
ok((int)val("SELECT COUNT(*) FROM rekyc_cases WHERE client_id=? AND reason='doc_expiring'", [$kc]) === 1, 'a KYC refresh case opens for the expiring ID');
$before = (int)val('SELECT COUNT(*) FROM reminders'); compliance_daily(); compliance_daily();
ok((int)val('SELECT COUNT(*) FROM reminders') === $before && (int)val("SELECT COUNT(*) FROM rekyc_cases WHERE client_id=? AND open_key LIKE 'doc%'", [$kc]) === 1, 'running the daily check again creates no duplicates');
compliance_daily(date('Y-m-d', strtotime('+20 days')));
ok((int)val("SELECT COUNT(*) FROM reminders WHERE client_id=? AND event_key='exp-7' AND status IN ('pending','sent')", [$kc]) === 2, 'the 7-day reminder follows when its date comes');
$sentMails = [];
q("UPDATE reminders SET status='pending', attempts=0, next_attempt_at=NOW() WHERE client_id=? AND event_key='exp-30' AND channel='email'", [$kc]);
$fail1 = reminders_send(function () { return false; });
$rr = one("SELECT * FROM reminders WHERE client_id=? AND event_key='exp-30' AND channel='email'", [$kc]);
ok($fail1['retry'] === 1 && $rr['status'] === 'pending' && $rr['attempts'] == 1 && strtotime($rr['next_attempt_at']) > time(), 'a failed send is retried later, not dropped');
q('UPDATE reminders SET next_attempt_at=NOW() WHERE id=?', [$rr['id']]); reminders_send(fn() => false);
q('UPDATE reminders SET next_attempt_at=NOW() WHERE id=?', [$rr['id']]); reminders_send(fn() => false);
ok(val('SELECT status FROM reminders WHERE id=?', [$rr['id']]) === 'failed' && (int)val('SELECT COUNT(*) FROM notification_attempts WHERE reminder_id=? AND ok=0', [$rr['id']]) === 3, 'after 3 failed attempts the reminder is marked failed; every attempt is recorded');
q("UPDATE reminders SET status='pending', attempts=0, next_attempt_at=NOW() WHERE id=?", [$rr['id']]);
$cap = []; reminders_send(function ($to, $sub, $body) use (&$cap) { $cap[] = $sub . ' ' . $body; return true; });
ok(val('SELECT status FROM reminders WHERE id=?', [$rr['id']]) === 'sent' && $cap && !preg_match('/KY123456|Passport|' . date('Y') . '-/', implode(' ', $cap)), 'reminder sent; the email holds no document type, number or dates');
q("UPDATE clients SET email=NULL WHERE id=?", [$kc]); q("UPDATE reminders SET status='pending', attempts=0, next_attempt_at=NOW() WHERE id=?", [$rr['id']]);
reminders_send(fn() => true);
ok(val('SELECT last_error FROM reminders WHERE id=?', [$rr['id']]) === 'No email address on file', 'no email on file: marked failed for staff to follow up');
q("UPDATE document_versions SET expires_on=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE client_id=?", [$kc]);
compliance_daily();
ok(val("SELECT reason FROM rekyc_cases WHERE client_id=? AND status IN ('required','in_progress','escalated')", [$kc]) === 'doc_expired' && (int)val("SELECT COUNT(*) FROM reminders WHERE client_id=? AND event_key='expiry'", [$kc]) >= 1, 'on expiry the case becomes "expired" and the expiry-day alert is made');
q("UPDATE rekyc_cases SET due_date=DATE_SUB(CURDATE(), INTERVAL 30 DAY) WHERE client_id=? AND open_key LIKE 'doc%'", [$kc]);
compliance_daily();
ok(val("SELECT status FROM rekyc_cases WHERE client_id=? AND open_key LIKE 'doc%' ORDER BY id LIMIT 1", [$kc]) === 'escalated', 'overdue refresh case is escalated');
$pc2 = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'Periodic Synthetic', 'phone' => '+1 416 555 0305', 'office' => 'Canada']), ['source' => 'admin', 'kyc' => 'Verified']);
q('UPDATE clients SET kyc_next_review=DATE_ADD(CURDATE(), INTERVAL 10 DAY) WHERE id=?', [$pc2]);
compliance_daily();
ok(val("SELECT reason FROM rekyc_cases WHERE client_id=?", [$pc2]) === 'periodic', 'periodic KYC refresh opens a case on its own schedule, separate from ID expiry');

// ---- 16. secure upload link ----
$link = upload_request_create($kc, (int)$super['id']);
$tok = substr($link, strpos($link, 't=') + 2);
$ur = upload_request_find($tok);
ok($ur && (int)$ur['client_id'] === $kc && preg_match('#/upload\?t=[a-f0-9]{48}$#', $link), 'upload link made; it carries only a random token, not the client number');
for ($i = 0; $i < 5; $i++) upload_request_verify(upload_request_find($tok) ?? $ur, '1990-01-01', '0000');
ok(upload_request_find($tok) === null, 'upload link locks after 5 wrong identity checks');
$link = upload_request_create($kc, (int)$super['id']); $tok = substr($link, strpos($link, 't=') + 2);
ok(upload_request_verify(upload_request_find($tok), '1990-01-01', '0303'), 'the client passes with date of birth + phone digits');
ok(upload_request_find(substr(upload_request_create($kc, (int)$super['id']), -48)) && upload_request_find($tok) === null, 'a new link cancels the older one');

// ---- 17. referral codes: login ID as the code, stable agent ID ----
foreach ([$agA, $agB] as $ag) agent_code_register((int)$ag['id'], $ag['username']);
ok(agent_by_code(strtoupper($agA['username']))['id'] == $agA['id'], 'typed code is the sub-agent login ID (any letter case)');
q("INSERT INTO submissions (data, name, phone, agent_id) VALUES ('x','Ref Synthetic','+1',?)", [$agA['id']]);
$oldName = $agA['username'];
agent_rename($agA, 'renamed.agent', (int)$super['id']);
ok(agent_by_code($oldName)['id'] == $agA['id'] && agent_by_code('renamed.agent')['id'] == $agA['id'], 'after a rename both the new and the old code credit the same sub-agent');
ok((int)val("SELECT agent_id FROM submissions WHERE name='Ref Synthetic'") === (int)$agA['id'], 'past referrals stay with the agent (stored by internal ID)');
$thrown = false; try { agent_rename(one('SELECT * FROM users WHERE id=?', [$agB['id']]), $oldName, (int)$super['id']); } catch (RuntimeException $e) { $thrown = true; }
ok($thrown && agent_by_code($oldName)['id'] == $agA['id'], "another agent cannot take a retired code (no reassignment)");
$thrown = false; try { agent_code_register((int)$agB['id'], 'renamed.agent'); } catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'a current code cannot be registered to a second agent');
$ag2 = one('SELECT * FROM users WHERE id=?', [$agA['id']]);
ok(agent_from_link((string)$agA['id'], '', agent_sig((int)$agA['id']))['id'] == $agA['id'] && agent_from_link((string)$agB['id'], '', agent_sig((int)$agA['id'])) === null, 'signed links use the internal ID and cannot be re-pointed to another agent');
ok(str_contains(agent_link($ag2), '/r/') && agent_from_link('', 'SA-1001', ref_sig('SA-1001')) !== null, 'the shared link is the short /r/ link; old SA-#### signed links keep working');


// ---- 18. beneficiaries ----
as_user($agA);
$bc = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'Benef Sender', 'phone' => '+1 416 555 0401', 'office' => 'Canada', 'dob' => '1985-05-05']), ['agent_id' => $agA['id'], 'source' => 'agent', 'kyc' => 'Verified']);
$bc2 = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'Other Sender', 'phone' => '+1 416 555 0402', 'office' => 'Canada']), ['agent_id' => $agB['id'], 'source' => 'agent']);
[$b1, $e] = benef_add($bc, ['name' => 'Ravi Kumar', 'relation' => 'Brother', 'country' => 'India', 'bank' => 'SBI', 'account' => 'IN 1234-5678-90', 'phone' => '+91 98765 43210'], 'agent', (int)$agA['id']);
$br = fn($id) => one('SELECT * FROM beneficiaries WHERE id=?', [$id]);
ok($b1 && $br($b1)['status'] === 'pending' && str_starts_with((string)$br($b1)['account'], 'enc:v1:') && dec($br($b1)['account']) === 'IN 1234-5678-90', 'new beneficiary waits for review; account number stored encrypted');
[$bx, $e] = benef_add($bc, ['name' => 'Ravi K', 'bank' => 'SBI', 'account' => 'in1234567890'], 'agent', (int)$agA['id']);
ok(!$bx && str_contains(implode(' ', $e), 'already saved'), 'the same account twice for one client is refused (spaces, dashes and letter case ignored)');
[$b2] = benef_add($bc2, ['name' => 'Ravi Kumar', 'bank' => 'SBI', 'account' => 'IN1234567890', 'country' => 'India'], 'agent', (int)$agB['id']);
ok($b2 && (bool)array_filter(benef_flags($br($b2)), fn($f) => str_contains($f, 'other client')), 'the same account under another client is allowed but flagged for the reviewer');
[$bn, $e] = benef_add($bc, ['name' => 'X', 'account' => '12<script>'], 'agent', null);
ok(!$bn && count($e) >= 2, 'bad name and account formats are refused');
$rv = fn($id, $x) => $x + ['rev' => $br($id)['rev']];
foreach (['agent' => $agA, 'admin' => $admin, 'auditor' => $aud, 'staff' => $staff] as $who => $usr) { [$rok] = benef_review($br($b1), $usr, $rv($b1, ['decision' => 'approve', 'screening_ref' => 'S-1', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Checked with the client.'])); ok(!$rok && $br($b1)['status'] === 'pending', "$who cannot approve a beneficiary"); }
[$rok, $re] = benef_review($br($b1), $comp, $rv($b1, ['decision' => 'approve', 'notes' => 'Checked with the client.']));
ok(!$rok && str_contains(implode(' ', $re), 'screening') && str_contains(implode(' ', $re), 'verified'), 'approval needs a screening reference and a verification method');
// the reviewer opened the page, then the agent changed the account: the old decision must not approve the new details
$seen = $br($b1);
benef_update($br($b1), ['name' => 'Ravi Kumar', 'relation' => 'Brother', 'country' => 'India', 'bank' => 'SBI', 'account' => 'IN 5555-0000-11', 'phone' => '+91 98765 43210'], (int)$agA['id']);
[$rok, $re] = benef_review($seen, $comp, ['decision' => 'approve', 'screening_ref' => 'SCREEN-B1', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Confirmed by phone with the client.', 'rev' => $seen['rev']]);
ok(!$rok && $br($b1)['status'] === 'pending' && str_contains(implode(' ', $re), 'changed'), 'a decision on details that changed while the reviewer looked is refused');
ok(!benef_review($br($b1), $comp, ['decision' => 'approve', 'screening_ref' => 'S', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'No version given here.'])[0], 'a decision without the reviewed version is refused');
benef_update($br($b1), ['name' => 'Ravi Kumar', 'relation' => 'Brother', 'country' => 'India', 'bank' => 'SBI', 'account' => 'IN 1234-5678-90', 'phone' => '+91 98765 43210'], (int)$agA['id']);
[$rok] = benef_review($br($b1), $comp, $rv($b1, ['decision' => 'approve', 'screening_ref' => 'SCREEN-B1', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Confirmed by phone with the client.']));
ok($rok && $br($b1)['status'] === 'active' && (int)$br($b1)['reviewed_by'] === (int)$comp['id'] && $br($b1)['screening_ref'] === 'SCREEN-B1', 'compliance approves: active, reviewer and screening recorded');
[$rok] = benef_review($br($b1), $super, $rv($b1, ['decision' => 'reject', 'notes' => 'Second decision attempt.']));
ok(!$rok, 'a decided beneficiary cannot be decided again');
ok(benef_usable($br($b1), $bc)[0] && !benef_usable($br($b1), $bc2)[0] && !benef_usable($br($b2), $bc2)[0], 'only an approved beneficiary of the same client can receive a transfer');
// four-eyes: whoever adds a beneficiary cannot approve it
[$bf] = benef_add($bc, ['name' => 'Asha Patel', 'country' => 'India', 'bank' => 'ICICI', 'account' => 'IC00112233'], 'admin', (int)$comp['id']);
[$rok, $re] = benef_review($br($bf), $comp, $rv($bf, ['decision' => 'approve', 'screening_ref' => 'S-F', 'verification' => BENEF_VERIFY_METHODS[1], 'notes' => 'Bank statement seen.']));
ok(!$rok && str_contains(implode(' ', $re), 'another reviewer'), 'four-eyes: the person who added a beneficiary cannot approve it');
ok(benef_review($br($bf), $super, $rv($bf, ['decision' => 'approve', 'screening_ref' => 'S-F', 'verification' => BENEF_VERIFY_METHODS[1], 'notes' => 'Bank statement seen.']))[0], 'a second reviewer can approve it');
[$bg] = benef_add($bc, ['name' => 'Gita Rao', 'country' => 'India', 'bank' => 'AXIS', 'account' => 'AX445566'], 'agent', (int)$agA['id']);
benef_update($br($bg), ['name' => 'Gita Rao', 'country' => 'India', 'bank' => 'AXIS', 'account' => 'AX999000'], (int)$comp['id']);
benef_update($br($bg), ['name' => 'Gita Rao', 'country' => 'India', 'bank' => 'AXIS', 'account' => '', 'phone' => '+91 91111 11111'], (int)$agA['id']);
ok(!benef_review($br($bg), $comp, $rv($bg, ['decision' => 'approve', 'screening_ref' => 'S-G', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Approving my own account change.']))[0], 'four-eyes covers everyone who changed it, not only the last editor');
benef_deactivate($br($bg), 'test', null);
// approved details are never overwritten: a change (even only the phone) is a new version that waits for review
$before = $br($b1);
q("INSERT INTO transfers (ref_no, client_id, client_name, beneficiary_id, send_amount, status, transfer_date) VALUES ('TX-BENEF-1', ?, 'Benef Sender', ?, 100, 'completed', ?)", [$bc, $b1, today()]);
[$v2, $e] = benef_update($br($b1), ['name' => 'Ravi Kumar', 'relation' => 'Brother', 'country' => 'India', 'bank' => 'SBI', 'account' => '', 'phone' => '+91 90000 00000'], (int)$agA['id']);
$old = $br($b1);
ok($v2 && $v2 !== $b1 && $old['status'] === 'deactivated' && (int)$old['replaced_by'] === $v2 && $old['account'] === $before['account'] && $old['phone'] === $before['phone'] && $old['screening_ref'] === 'SCREEN-B1',
   'editing an approved beneficiary keeps the approved row unchanged and makes a new version');
ok($br($v2)['status'] === 'pending' && dec($br($v2)['account']) === 'IN 1234-5678-90' && $br($v2)['phone'] === '+91 90000 00000' && !benef_usable($br($v2), $bc)[0], 'the new version (phone changed, account kept) waits for review');
ok((int)val("SELECT beneficiary_id FROM transfers WHERE ref_no='TX-BENEF-1'") === $b1 && dec($old['account']) === 'IN 1234-5678-90', 'the past transfer still points to the exact details that were approved and paid');
ok(!benef_usable($old, $bc)[0] && str_contains(benef_usable($old, $bc)[1], 'new version #' . $v2), 'the old version cannot receive new transfers and says where the new one is');
[$v2b] = benef_update($br($v2), ['name' => 'Ravi Kumar', 'relation' => 'Brother', 'country' => 'India', 'bank' => 'HDFC', 'account' => 'IN99887766', 'phone' => '+91 90000 00000'], (int)$agA['id']);
ok($v2b === $v2 && (int)$br($v2)['rev'] === 1 && dec($br($v2)['account']) === 'IN99887766', 'a version still waiting for review is corrected in place (its version number goes up)');
[$rok, $re] = benef_review($br($v2), $comp, $rv($v2, ['decision' => 'approve', 'screening_ref' => 'S', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Confirmed by phone again.']));
ok($rok, 'the reviewer approves the new version');
$evs = all('SELECT * FROM beneficiary_events WHERE beneficiary_id IN (?,?) ORDER BY id', [$b1, $v2]);
ok(implode(',', array_column($evs, 'action')) === 'added,edited,edited,approved,added,replaced,edited,edited,approved'
   && !preg_match('/9988|1234|5555/', json_encode($evs)) && !preg_match('/9988|1234|5555/', (string)val("SELECT GROUP_CONCAT(detail) FROM audit WHERE action LIKE 'beneficiary_%'")), 'history records each step and which fields changed, never the account numbers');
benef_deactivate($br($v2), 'No longer used', (int)$agA['id']);
[$uok] = benef_update($br($v2), ['name' => 'Ravi Kumar', 'bank' => 'SBI', 'account' => 'IN1'], (int)$agA['id']);
ok($br($v2)['status'] === 'deactivated' && !$uok && !benef_usable($br($v2), $bc)[0], 'deactivated beneficiary is kept, cannot be edited back, cannot receive money');
// a rejected beneficiary stays rejected: it cannot be edited, and adding it again shows the reviewer the earlier rejection
[$rok] = benef_review($br($b2), $comp, $rv($b2, ['decision' => 'reject', 'notes' => 'Name matched a sanctions list entry.']));
[$uok, $ue] = benef_update($br($b2), ['name' => 'Ravi Kumaar', 'bank' => 'SBI', 'account' => 'IN1234567890'], (int)$agB['id']);
ok($rok && !$uok && $br($b2)['status'] === 'rejected', 'a rejected beneficiary cannot be edited back into review');
[$b2b] = benef_add($bc2, ['name' => 'R. Kumar', 'bank' => 'SBI', 'account' => 'IN-1234-5678-90'], 'agent', (int)$agB['id']);
ok($b2b && (bool)array_filter(benef_flags($br($b2b)), fn($f) => str_contains($f, 'rejected before')), 'the same account added again is flagged as rejected before');
// older records with an account in a format the form no longer accepts can still be edited
q("INSERT INTO beneficiaries (client_id, name, bank, account, status, account_hash, rev) VALUES (?, 'Legacy Long', 'Old Bank', ?, 'active', ?, 0)", [$bc, enc(str_repeat('7', 80)), benef_account_hash(str_repeat('7', 80))]);
$lg = (int)db()->lastInsertId();
[$lv] = benef_update($br($lg), ['name' => 'Legacy Long', 'relation' => 'Cousin', 'bank' => 'Old Bank', 'account' => ''], (int)$agA['id']);
ok($lv && dec($br($lv)['account']) === str_repeat('7', 80), 'a legacy 80-digit account can be kept while other details are edited');
benef_deactivate($br($lv), 'test', null);
[$b3] = benef_add($bc, ['name' => 'Ravi Kumar', 'bank' => 'HDFC', 'account' => 'IN99887766'], 'agent', null);
ok((bool)$b3, 'after deactivation the same person can be added again (and is reviewed again)');
set_setting('benef_max_per_client', '2'); benef_add($bc, ['name' => 'Second Person', 'bank' => 'B', 'account' => 'AC0002'], 'agent', null);
[$b5, $e] = benef_add($bc, ['name' => 'Third Person', 'bank' => 'B', 'account' => 'AC0003'], 'agent', null);
ok(!$b5 && str_contains(implode(' ', $e), 'at most 2'), 'limit on active beneficiaries per client');
set_setting('benef_max_per_client', '10');
set_setting('benef_high_risk_countries', 'Atlantis, Elbonia');
[$b6] = benef_add($bc, ['name' => 'Far Person', 'country' => 'Elbonia', 'bank' => 'EB', 'account' => 'EL555555'], 'agent', null);
ok((bool)array_filter(benef_flags($br($b6)), fn($f) => str_contains($f, 'high-risk')), 'beneficiary in a high-risk country is flagged');
$errs = save_beneficiaries($bc2, [['name' => 'Mum', 'bank' => 'B1', 'account' => 'M0001'], ['name' => 'Dad', 'bank' => 'B2', 'account' => 'D0002'], ['name' => 'Sis', 'country' => 'India']], 'online', null);
ok(!$errs && (int)val("SELECT COUNT(*) FROM beneficiaries WHERE client_id=? AND status='pending'", [$bc2]) === 4, 'several beneficiaries from one registration are all saved for review');

// ---- 19. one-time codes for client self-service ----
q("UPDATE clients SET email=NULL WHERE id=?", [$bc]);
[$sent, $msg] = otp_send(one('SELECT * FROM clients WHERE id=?', [$bc]), 'returning');
ok(!$sent && str_contains($msg, 'no verified'), 'no email (or SMS) on file: no code, the client is sent to staff');
q("UPDATE clients SET email='otp.synthetic@example.com' WHERE id=?", [$bc]);
$ocl = one('SELECT * FROM clients WHERE id=?', [$bc]);
[$sent, $msg] = otp_send($ocl, 'returning');
ok($sent && str_contains($msg, 'o•') && !str_contains($msg, 'otp.synthetic@'), 'code sent; the message shows a masked address only');
$setCode = fn($c) => q('UPDATE client_otps SET code_hash=? WHERE client_id=? AND purpose=? AND used_at IS NULL', [otp_hash($bc, 'returning', $c), $bc, 'returning']);
$setCode('482913');
ok(strlen((string)val('SELECT code_hash FROM client_otps WHERE client_id=? ORDER BY id DESC', [$bc])) === 64 && !str_contains(json_encode(all('SELECT * FROM client_otps')), '482913'), 'codes are stored only as a keyed hash');
ok(!otp_verify($bc, 'upload', '482913'), 'a code works only for its own purpose');
ok(otp_verify($bc, 'returning', '482 913') && !otp_verify($bc, 'returning', '482913'), 'right code works once (spaces ignored), never twice');
otp_send($ocl, 'returning'); $setCode('111222');
for ($i = 0; $i < 5; $i++) otp_verify($bc, 'returning', '000000');
ok(!otp_verify($bc, 'returning', '111222'), 'after 5 wrong tries the code stops working');
[$s3] = otp_send($ocl, 'returning'); [$s4] = otp_send($ocl, 'returning');
ok(!$s4, 'at most 3 codes per hour for one client');
q('DELETE FROM client_otps WHERE client_id=?', [$bc]); otp_send($ocl, 'upload'); q('UPDATE client_otps SET code_hash=?, expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE client_id=?', [otp_hash($bc, 'upload', '333444'), $bc]);
ok(!otp_verify($bc, 'upload', '333444'), 'an expired code is refused');
ok(otp_channels(['email' => 'x@example.com', 'phone_e164' => '+14165550000'] + $ocl) === ['email' => otp_mask_email('x@example.com')], 'SMS is not offered while no SMS provider is connected');
q('DELETE FROM client_otps WHERE client_id=?', [$bc]);
for ($i = 0; $i < 6; $i++) q("INSERT INTO client_otps (client_id, purpose, channel, code_hash, expires_at, created_at) VALUES (?, 'returning', 'email', 'x', NOW(), DATE_SUB(NOW(), INTERVAL 3 HOUR))", [$bc]);
[$s5, $m5] = otp_send($ocl, 'returning');
ok(!$s5 && str_contains($m5, 'today') && (int)val("SELECT COUNT(*) FROM audit WHERE action='otp_daily_cap' AND entity_id=?", [$bc]) === 1, 'at most 6 codes a day per client; staff are alerted once');

// ---- 20. one KYC identity per sender, enforced where every ID is saved ----
$taken = false; try { doc_add_version($bc, ['doc_type' => 'Passport', 'number' => 'X9988776', 'expires_on' => date('Y-m-d', strtotime('+3 years'))], 'upload_link', null); } catch (IdNumberTaken $ex) { $taken = true; }
ok($taken && !val('SELECT 1 FROM document_versions WHERE client_id=? AND number_hash=?', [$bc, id_hash('X9988776')]), 'an ID number of another client cannot be added to a client through any path (upload link, update, import)');
$taken = false; $nc = (int)val('SELECT COUNT(*) FROM clients'); try { insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'Copy Cat', 'phone' => '+1 416 555 0499', 'office' => 'Canada', 'id_number' => 'x9988776']), ['source' => 'admin']); } catch (IdNumberTaken $ex) { $taken = true; }
ok($taken && (int)val('SELECT COUNT(*) FROM clients') === $nc, 'a new client with an ID number already on file is refused before any record is made');
$own = doc_add_version($cB, ['doc_type' => 'Passport', 'number' => 'X9988776', 'expires_on' => date('Y-m-d', strtotime('+3 years'))], 'admin', null);
ok($own > 0, 'the owner of the ID number can still add a new version of it');

// ---- 22. sub-agent control center and short referral links ----
require_once __DIR__ . '/../inc/agents.php';
ok((int)val("SELECT COUNT(*) FROM users u WHERE role='agent' AND NOT EXISTS (SELECT 1 FROM referral_links l WHERE l.agent_id=u.id AND l.is_primary=1)") === 0, 'every sub-agent has a main short link');
$mgr = mkuser('manager', 'Mgr One'); $mgr2 = mkuser('manager', 'Mgr Two');
$sx = mkuser('agent', 'Short X'); $sy = mkuser('agent', 'Short Y');
$re = fn($id) => one('SELECT * FROM users WHERE id=?', [$id]);
agent_assign_manager($sx, (int)$mgr['id'], 'Toronto team assignment', $admin);
$sx = $re($sx['id']);
ok(can_control_agent($sx, $mgr) && !can_control_agent($sy, $mgr) && !can_control_agent($sx, $mgr2), 'a manager controls only the sub-agents assigned to them');
ok(!can_control_agent($admin, $mgr) && !can_control_agent($super, $mgr) && !can_control_agent($mgr2, $mgr) && !can_control_agent($mgr, $mgr), 'a manager cannot control admin, super admin, other managers or themselves');
ok(agent_scope($comp) === null && agent_scope($staff) === null && !can_control_agent($sx, $comp), 'roles without the permission cannot control sub-agents');
$thr = ''; try { agent_assign_manager($sy, (int)$mgr['id'], 'I want this agent too', $mgr); } catch (RuntimeException $ex) { $thr = $ex->getMessage(); }
ok($thr !== '' && (int)$re($sy['id'])['manager_id'] === 0, 'a manager cannot assign sub-agents to themselves (no scope escalation)');
ok(!role_can('manager', 'team.agents') && !role_can('manager', 'team.staff') && !role_can('manager', 'clients.all') && in_array('manager', MFA_REQUIRED, true), 'managers have no team, staff or all-client rights by default and need two-step login');
$slugX = ref_primary((int)$sx['id'])['slug'];
ok(ref_resolve(strtoupper($slugX))['id'] == $sx['id'] && str_ends_with(ref_short_url($slugX), '/r/' . $slugX), 'short link /r/name finds the agent (any letter case) and is a clean URL');
ok(slug_problem('admin', (int)$sx['id']) !== null && slug_problem('dhanxfer-help', (int)$sx['id']) !== null && slug_problem('login', (int)$sx['id']) !== null && slug_problem('a', (int)$sx['id']) !== null && slug_problem('bad name', (int)$sx['id']) !== null, 'reserved names, system words and bad formats cannot be link names');
ok(slug_problem($slugX, (int)$sy['id']) !== null && slug_problem($sy['username'], (int)$sx['id']) !== null, 'a link name or login ID of another agent cannot be taken');
$thr = ''; try { agent_set_status($sx, 'suspended', '', $mgr); } catch (RuntimeException $ex) { $thr = $ex->getMessage(); }
ok(str_contains($thr, 'reason') && $re($sx['id'])['account_status'] === 'active', 'a reason is required to restrict an account');
// suspension: sessions end, links stop, activation links cancelled
q("INSERT INTO password_resets (user_id, token_hash, expires_at, created_by) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), ?)", [$sx['id'], hash('sha256', 'tok-sx'), $admin['id']]);
$svBefore = (int)$sx['session_version'];
q("INSERT INTO submissions (data, name, phone, agent_id, ref_verified, ref_link_id) VALUES ('x','Kept Referral','+1',?,1,?)", [$sx['id'], ref_primary((int)$sx['id'])['id']]);
q("INSERT INTO transfers (ref_no, client_name, agent_id, send_amount, status, transfer_date, commission, commission_currency) VALUES ('TX-SX-1','Kept',?,100,'completed',?,25,'INR')", [$sx['id'], today()]);
agent_set_status($sx, 'suspended', 'Under review for complaints', $mgr);
$sxs = $re($sx['id']);
ok($sxs['account_status'] === 'suspended' && !(int)$sxs['active'] && (int)$sxs['session_version'] === $svBefore + 1, 'suspend: account off and every session version invalidated (logged out everywhere)');
ok(!val('SELECT 1 FROM password_resets WHERE user_id=? AND used_at IS NULL', [$sx['id']]), 'suspend: unused activation and reset links are cancelled');
ok(ref_resolve($slugX) === null && !ref_still_valid((int)$sx['id'], (int)ref_primary((int)$sx['id'])['id']) && agent_from_link((string)$sx['id'], '', agent_sig((int)$sx['id'])) === null, 'suspend: short link and older signed links stop crediting at once');
ok((int)val("SELECT agent_id FROM submissions WHERE name='Kept Referral'") === (int)$sx['id'] && (float)val("SELECT commission FROM transfers WHERE ref_no='TX-SX-1'") == 25.0 && (int)val("SELECT agent_id FROM transfers WHERE ref_no='TX-SX-1'") === (int)$sx['id'], 'existing referrals and commissions keep their original agent');
ok((int)val("SELECT COUNT(*) FROM agent_events WHERE agent_id=? AND action='suspended' AND reason='Under review for complaints' AND user_id=?", [$sx['id'], $mgr['id']]) === 1 && (int)val("SELECT COUNT(*) FROM audit WHERE action='agent_suspended' AND entity_id=?", [$sx['id']]) === 1, 'the restriction, reason and person are in the history and the tamper-evident log');
agent_set_status($sxs, 'deactivated', 'Contract ended in November', $mgr);
$thr = ''; try { agent_set_status($re($sx['id']), 'active', 'Manager wants them back', $mgr); } catch (RuntimeException $ex) { $thr = $ex->getMessage(); }
ok(str_contains($thr, 'Only an admin') && $re($sx['id'])['account_status'] === 'deactivated', 'a manager cannot bring back a deactivated sub-agent');
agent_set_status($re($sx['id']), 'active', 'New contract signed', $admin);
ok($re($sx['id'])['account_status'] === 'pending' && ref_resolve($slugX) === null, 'an admin can bring them back; someone who never logged in returns to pending activation, link still off until active');
q("UPDATE users SET account_status='active', active=1, last_login=NOW() WHERE id=?", [$sx['id']]); $sx = $re($sx['id']);
ok(ref_resolve($slugX)['id'] == $sx['id'], 'once active again the link credits new referrals');
// one link off / on, regenerate
$lx = ref_primary((int)$sx['id']);
ref_link_set($sx, $lx, 'disabled', 'Posted on a public spam forum', $mgr);
ok(ref_resolve($slugX) === null && $re($sx['id'])['account_status'] === 'active', 'switching off one link stops it without touching the account');
ref_link_set($sx, ref_primary((int)$sx['id']), 'active', 'Spam post removed', $mgr);
ok(ref_resolve($slugX)['id'] == $sx['id'], 'the link can be switched back on');
$oldSig = agent_sig((int)$sx['id']);
$newL = ref_link_regenerate($sx, 'shortx.new', 'Link was copied by a stranger', $mgr);
ok(ref_resolve($slugX) === null && ref_resolve('shortx.new')['id'] == $sx['id'] && val('SELECT status FROM referral_links WHERE slug=?', [$slugX]) === 'retired', 'regenerate: new link works, every older link is retired');
ok(agent_from_link((string)$sx['id'], '', $oldSig) === null && agent_from_link((string)$sx['id'], '', agent_sig((int)$sx['id'])) !== null, 'regenerate also stops older long signed links');
$thr = ''; try { ref_link_set($sx, one('SELECT * FROM referral_links WHERE slug=?', [$slugX]), 'active', 'Bring the old one back', $admin); } catch (RuntimeException $ex) { $thr = $ex->getMessage(); }
ok($thr !== '' && slug_problem($slugX, (int)$sy['id']) !== null, 'a retired link stays retired and its name is never given to another agent');
ok((int)val("SELECT agent_id FROM submissions WHERE name='Kept Referral'") === (int)$sx['id'] && (int)val("SELECT ref_link_id FROM submissions WHERE name='Kept Referral'") === (int)$lx['id'], 'referral history keeps the original agent and link after links change');
// attribution cannot be pointed at another agent
ok(!ref_still_valid((int)$sy['id'], (int)$newL) && ref_still_valid((int)$sx['id'], (int)$newL), "one agent's link cannot credit another agent");
$thr = ''; try { ref_link_set($sy, one('SELECT * FROM referral_links WHERE id=?', [$newL]), 'disabled', 'Wrong agent link trick', $admin); } catch (RuntimeException $ex) { $thr = $ex->getMessage(); }
ok($thr !== '', "a link can only be changed through its own agent");
$thr = ''; try { ref_link_set($sy, ref_primary((int)$sy['id']), 'disabled', 'Not my team', $mgr); } catch (RuntimeException $ex) { $thr = $ex->getMessage(); }
ok($thr !== '' && ref_primary((int)$sy['id'])['status'] === 'active', "a manager cannot switch off another team's link");
agent_set_limits($sx, false, true, 'No new clients until training', $mgr);
ok(!(int)$re($sx['id'])['agent_can_clients'] && (int)$re($sx['id'])['agent_can_transfers'], 'per-agent limits are saved (checked on every request to those pages)');

// ---- 21. key ring: envelope encryption, rotation, Cloud KMS adapter (mocked: no cloud service is called) ----
require_once __DIR__ . '/../inc/reseal.php';
require_once __DIR__ . '/../inc/backup.php';
$krFile = data_dir() . '/test-keyring-' . bin2hex(random_bytes(4)) . '.json';
$cfgKeep = $CONFIG;
$CONFIG['keyring_file'] = $krFile; $CONFIG['local_kek'] = base64_encode(random_bytes(32));
keyring_init(); keyring(true); keyring_key('', true);
$v1 = enc('KR-PLAIN-1'); $idh = id_hash('KR123456');
ok(str_starts_with($v1, 'enc:v1:') && keyring_active() === 'k0', 'without a key ring nothing changes: values are sealed with k0 as before');
keyring_new('k1', 'local');
ok(str_starts_with(enc('x'), 'enc:v1:'), 'a new key is not used until it is activated');
ok(!str_contains((string)file_get_contents($krFile), base64_encode(keyring_key('k1'))) && (fileperms($krFile) & 0777) === 0600, 'the key ring file holds the data key only wrapped, and only the owner can read it');
keyring_activate('k1');
$v2 = enc('KR-PLAIN-2');
ok(str_starts_with($v2, 'enc:v2:k1:') && dec($v2) === 'KR-PLAIN-2' && dec($v1) === 'KR-PLAIN-1', 'after activation new values name key k1; older k0 values still open');
ok(id_hash('KR123456') === $idh && sec_key('index') === hash_hmac('sha256', 'dhanxfer:index', master_key(), true), 'search keys and signatures stay on the stable key (look-ups keep matching)');
ok(dec(str_replace('enc:v2:k1:', 'enc:v2:k0:', $v2)) === null && dec(str_replace('enc:v2:k1:', 'enc:v2:k9:', $v2)) === null, 'a value relabelled with another key id does not open (key id is bound in)');
$fk0 = 'kr_' . bin2hex(random_bytes(6)) . '.jpg';
unset($CONFIG['keyring_file']); keyring(true); keyring_key('', true); id_file_write($fk0, 'OLD-FILE'); $CONFIG['keyring_file'] = $krFile; keyring(true); keyring_key('', true);
$fk1 = 'kr_' . bin2hex(random_bytes(6)) . '.jpg'; id_file_write($fk1, 'NEW-FILE');
ok(id_file_kid($fk0) === 'k0' && id_file_kid($fk1) === 'k1' && id_file_read($fk0) === 'OLD-FILE' && id_file_read($fk1) === 'NEW-FILE', 'ID files: new ones sealed with k1, older ones still open');
$bkk = backup_create('keyring');
ok(str_starts_with((string)file_get_contents(backup_dir() . '/' . $bkk['file']), 'DXB2') && is_array(backup_read($bkk['file'])['tables'] ?? null), 'backups are sealed with the active key and read back');
@unlink(backup_dir() . '/' . $bkk['file']);
$krc = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'Keyring Person', 'phone' => '+1 416 555 0601', 'office' => 'Canada', 'id_number' => 'KR123456']), ['source' => 'admin']);
$dry = reseal_run(false);
$other = array_sum(array_column($dry['columns'], 'other_key'));
ok($other > 0 && $dry['files']['other_key'] >= 1 && (int)val("SELECT COUNT(*) FROM clients WHERE id_number LIKE 'enc:v1:%'") > 0, 'dry run counts what is still sealed with the old key and changes nothing');
$wet = reseal_run(true, 7);
$left = 0; foreach (ENC_COLUMNS as [$t, $c]) $left += (int)val("SELECT COUNT(*) FROM `$t` WHERE `$c` LIKE 'enc:v1:%'");
ok($wet['failed'] === 0 && $left === 0 && id_file_kid($fk0) === 'k1' && id_file_read($fk0) === 'OLD-FILE', 're-seal moves every value and file to k1 (in batches), each read back before it counts');
ok(dec((string)val('SELECT id_number FROM clients WHERE id=?', [$krc])) === 'KR123456' && (int)val('SELECT id FROM clients WHERE id_number_hash=?', [id_hash('KR123456')]) === $krc, 'after re-sealing the data opens and ID search still finds the client');
ok(reseal_run(true)['columns']['clients.id_number']['resealed'] === 0, 're-seal can run again safely (nothing left to do)');
ok(!preg_match('/wrapped|[A-Za-z0-9+\/]{40,}=/', json_encode(keyring_status())), 'key status shows ids and providers only, never key material');
$CONFIG['keyring_file'] = $krFile . '.missing'; $gone = ''; try { keyring(true); } catch (Throwable $ex) { $gone = $ex->getMessage(); }
ok(str_contains($gone, 'cannot be read') && !enc_ready(), 'a configured key ring that goes missing stops encryption with a clear error (no silent fall-back to the old key)');
$CONFIG['keyring_file'] = $krFile; $ringJ = json_decode((string)file_get_contents($krFile), true);
file_put_contents($krFile, json_encode(['stable' => 'k1'] + $ringJ)); $st = ''; try { keyring(true); } catch (Throwable $ex) { $st = $ex->getMessage(); }
file_put_contents($krFile, json_encode($ringJ)); keyring(true); keyring_key('', true);
ok(str_contains($st, 'may not change'), 'the key ring cannot move search keys and signatures off the stable key'); 
// Cloud KMS adapter against a stand-in: checks the sign-in, the key name and that the key id is bound in
$saKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($saKey, $saPem);
$saFile = data_dir() . '/test-sa-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($saFile, json_encode(['type' => 'service_account', 'client_email' => 'dx-test@example.iam.gserviceaccount.com', 'private_key' => $saPem]));
$CONFIG['kms_enabled'] = true; $CONFIG['kms_sa_file'] = $saFile;
$kmsName = 'projects/dx-test/locations/northamerica-northeast1/keyRings/dx/cryptoKeys/data-kek';
$kek = random_bytes(32); $calls = []; $kmsDown = false;
$GLOBALS['KMS_HTTP'] = function (string $url, array $h, string $body) use (&$calls, &$kek, &$kmsDown, $kmsName, $saKey) {
    $calls[] = $url;
    if ($kmsDown) return [503, '{}'];
    if ($url === 'https://oauth2.googleapis.com/token') {
        parse_str($body, $f); [$hd, $pl, $sg] = explode('.', $f['assertion']);
        $ok = openssl_verify("$hd.$pl", base64_decode(strtr($sg, '-_', '+/')), openssl_pkey_get_details($saKey)['key'], OPENSSL_ALGO_SHA256) === 1;
        $claims = json_decode(base64_decode(strtr($pl, '-_', '+/')), true);
        return $ok && $claims['scope'] === 'https://www.googleapis.com/auth/cloudkms' ? [200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])] : [401, '{}'];
    }
    if (!in_array('Authorization: Bearer tok-1', $h, true)) return [401, '{}'];
    $j = json_decode($body, true); $aad = base64_decode($j['additionalAuthenticatedData'] ?? '');
    if ($url === "https://cloudkms.googleapis.com/v1/$kmsName:encrypt") return [200, json_encode(['ciphertext' => base64_encode(aead_seal(base64_decode($j['plaintext']), $kek, $aad))])];
    if ($url === "https://cloudkms.googleapis.com/v1/$kmsName:decrypt") { $p = aead_open(base64_decode($j['ciphertext']), $kek, $aad); return $p === null ? [400, '{}'] : [200, json_encode(['plaintext' => base64_encode($p)])]; }
    return [404, '{}'];
};
keyring_new('k2', 'gcpkms', $kmsName); keyring_activate('k2');
$v3 = enc('KMS-PLAIN');
ok(str_starts_with($v3, 'enc:v2:k2:') && dec($v3) === 'KMS-PLAIN' && dec($v2) === 'KR-PLAIN-2', 'Cloud KMS key: the data key is unwrapped by KMS with a signed service-account sign-in; older keys still open');
$n = count($calls); keyring_key('', true); dec($v3); dec($v3); dec(enc('again'));
ok(count($calls) - $n === 1, 'KMS is called once per request to unwrap the key, not once per value (cost and speed)');
$wrappedBefore = keyring()['keys']['k2']['wrapped']; $kekOld = $kek;
keyring_key('k2'); $dekK2 = keyring_key('k2');
// KMS rotation: a new key version; old wrapped key still opens (KMS keeps old versions); rewrap moves it to the new primary
$kek2 = random_bytes(32);
$GLOBALS['KMS_HTTP_PREV'] = $GLOBALS['KMS_HTTP'];
$kek = $kek2; $oldKek = $kekOld;
$GLOBALS['KMS_HTTP'] = function (string $url, array $h, string $body) use (&$calls, $kmsName, $oldKek, &$kek) {
    $calls[] = $url; if ($url === 'https://oauth2.googleapis.com/token') return [200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])];
    $j = json_decode($body, true); $aad = base64_decode($j['additionalAuthenticatedData'] ?? '');
    if (str_ends_with($url, ':encrypt')) return [200, json_encode(['ciphertext' => base64_encode(aead_seal(base64_decode($j['plaintext']), $kek, $aad))])];
    $p = aead_open(base64_decode($j['ciphertext']), $kek, $aad) ?? aead_open(base64_decode($j['ciphertext']), $oldKek, $aad);
    return $p === null ? [400, '{}'] : [200, json_encode(['plaintext' => base64_encode($p)])];
};
keyring_key('', true); keyring_rewrap('k2');
ok(keyring()['keys']['k2']['wrapped'] !== $wrappedBefore && keyring_key('k2') === $dekK2 && dec($v3) === 'KMS-PLAIN', 'after a KMS key rotation the data key is wrapped again; no client data has to be re-encrypted');
$GLOBALS['KMS_HTTP'] = $GLOBALS['KMS_HTTP_PREV']; $kmsDown = true; keyring_key('', true);
$downErr = ''; try { dec($v3); } catch (Throwable $ex) { $downErr = $ex->getMessage(); }
ok(!enc_ready() && str_contains($downErr, 'HTTP 503') && dec($v1) === 'KR-PLAIN-1', 'if KMS cannot be reached, values sealed with the KMS key cannot be opened (clear error, nothing leaked); k0 values still open');
$kmsDown = false;
$CONFIG['local_kek'] = base64_encode(random_bytes(32)); keyring_key('', true);
$wrongKek = ''; try { keyring_key('k1'); } catch (Throwable $ex) { $wrongKek = $ex->getMessage(); }
ok(str_contains($wrongKek, 'could not be unwrapped'), 'a wrong key-encryption key is detected, never used');
$bad = false; try { keyring_new('k1', 'local'); } catch (Throwable $ex) { $bad = true; }
ok($bad, 'key ids are never reused');
// put everything back: no key ring, KMS off
$GLOBALS['KMS_HTTP'] = null; $CONFIG = $cfgKeep; @unlink($krFile); @unlink($saFile); keyring(true); keyring_key('', true);
foreach ([$fk0, $fk1] as $f) @unlink(ids_dir() . '/' . $f);
ok(keyring_active() === 'k0' && str_starts_with(enc('z'), 'enc:v1:'), 'with the key ring removed the panel is back on the config key');

$summary = sprintf("%s  %d passed, %d failed\n", date('Y-m-d H:i:s'), $pass, $fail);
$report = $summary . implode("\n", $out) . "\n";
echo $report;
@file_put_contents(__DIR__ . '/../storage/test-report.txt', $report);
@file_put_contents(data_dir() . '/test-report.txt', $report);
// leave the test database empty
wipe_db();
$finished = true;
exit($fail ? 1 : 0);
