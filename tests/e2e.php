<?php
// End-to-end checks over real HTTP against STAGING (staging only). Logs in as each test role,
// tries the attacks we care about, and records evidence. php tests/e2e.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/clientform.php';
require __DIR__ . '/../inc/mfa.php';
require __DIR__ . '/../inc/recovery.php';
if (($CONFIG['environment'] ?? '') !== 'staging' || !str_contains($CONFIG['app_url'], 'staging.')) { echo "REFUSED: staging only\n"; exit(2); }
$BASE = rtrim($CONFIG['app_url'], '/');
$T = []; foreach ($CONFIG['test_users'] as $t) $T[$t['username']] = $t;
$pass = 0; $fail = 0; $out = [];
function ok(bool $c, string $n, string $ev = '') { global $pass, $fail, $out; $c ? $pass++ : $fail++; $out[] = ($c ? 'ok     ' : 'FAILED ') . $n . ($ev !== '' && !$c ? "   [evidence: $ev]" : ''); }
register_shutdown_function(function () { global $pass, $fail, $out; $r = date('Y-m-d H:i:s') . sprintf("  E2E %d passed, %d failed\n", $pass, $fail) . implode("\n", $out) . "\n"; $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) $r .= 'CRASH ' . $e['message'] . ' line ' . $e['line'] . "\n"; echo $r; @file_put_contents(data_dir() . '/e2e-report.txt', $r); });

class Browser {
    public string $jar; public int $code = 0; public string $body = ''; public string $loc = '';
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'dxjar'); }
    function req(string $path, ?array $post = null, bool $follow = true): string {
        global $BASE;
        $url = str_starts_with($path, 'http') ? $path : $BASE . $path;
        for ($i = 0; $i < 6; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'DhanXfer-E2E']);
            if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
            $raw = (string)curl_exec($ch); $this->code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
            $this->body = substr($raw, $hs);
            $this->loc = preg_match('/^location:\s*(\S+)/mi', substr($raw, 0, $hs), $m) ? $m[1] : '';
            if (!$follow || !$this->loc || !in_array($this->code, [301, 302, 303], true)) break;
            $url = str_starts_with($this->loc, 'http') ? $this->loc : $BASE . $this->loc; $post = null;
        }
        return $this->body;
    }
    function csrf(): string { return preg_match('/name="csrf" value="([a-f0-9]+)"/', $this->body, $m) ? $m[1] : ''; }
    function post(string $path, array $fields): string { $this->req($path); return $this->req($path, $fields + ['csrf' => $this->csrf()]); }
    /** Form post with a file (multipart). $file = [field, path, mime]. */
    function upload(string $path, array $fields, array $file): string {
        global $BASE;
        $ch = curl_init($BASE . $path);
        $fields[$file[0]] = new CURLFile($file[1], $file[2], 'id.jpg');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
        $this->body = (string)curl_exec($ch); $this->code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return $this->body;
    }
    function login(string $user, ?string $pw = null, bool $code = true): string {
        global $T;
        $this->req('/login.php');
        $this->req('/login.php', ['csrf' => $this->csrf(), 'username' => $user, 'password' => $pw ?? $T[$user]['password']]);
        if ($code && str_contains($this->body, 'Two-step login') && !empty($T[$user]['mfa_secret'])) {
            $this->req('/mfa.php', ['csrf' => $this->csrf(), 'code' => totp_code($T[$user]['mfa_secret'], intdiv(time(), 30))]);
        }
        return $this->body;
    }
}

$SNAP = []; $E2E_FILES = [];
function snap(string $k, string $h): void { global $SNAP; $SNAP[$k] = $h; }
function shell_exec_safe(string $cmd): ?string {
    if ($cmd === '') return '';
    if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) { $r = shell_exec($cmd . ' 2>&1'); return $r === null || $r === false ? null : (string)$r; }
    if (function_exists('proc_open')) { $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp); if (is_resource($p)) { $o = stream_get_contents($pp[1]) . stream_get_contents($pp[2]); proc_close($p); return $o; } }
    return null;
}
function fresh_step_wait(): void { usleep((30 - time() % 30) * 1000000 + 200000); } // next code window, so codes are not reused

// keep the database connection alive through the waits for a fresh code window
q('SET SESSION wait_timeout=900, interactive_timeout=900');
function e2e_cleanup(): void {
    q("DELETE FROM submissions WHERE phone IN ('+1 416 555 7009','+1 416 555 7010')");
    q("DELETE FROM transfers WHERE ref_no IN ('E2E-T2','E2E-W1')");
    foreach (array_column(all("SELECT id FROM users WHERE username IN ('test.agent1','test.agent2')"), 'id') as $ta) { q('DELETE FROM withdrawal_events WHERE withdrawal_id IN (SELECT id FROM commission_withdrawals WHERE agent_id=?)', [$ta]); q('DELETE FROM commission_withdrawals WHERE agent_id=?', [$ta]); q('DELETE FROM commission_ledger WHERE agent_id=?', [$ta]); } q("DELETE FROM clients WHERE name IN ('E2E Client One','E2E Client Two')");
    $sa14 = array_column(all("SELECT id FROM users WHERE username IN ('e2e.agentx','e2e.manager')"), 'id');
    if ($sa14) { $in14 = implode(',', array_map('intval', $sa14)); q("DELETE FROM referral_links WHERE agent_id IN ($in14)"); q("DELETE FROM agent_events WHERE agent_id IN ($in14)"); q("DELETE FROM password_resets WHERE user_id IN ($in14)"); q("DELETE FROM submissions WHERE agent_id IN ($in14)"); q("DELETE FROM agent_codes WHERE agent_id IN ($in14)"); }
    q("DELETE FROM users WHERE username IN ('e2e.legacy','e2e.staffx','e2e.v2owner','e2e.agentx','e2e.manager')"); q('DELETE FROM login_attempts');
    q("UPDATE users SET manager_id=NULL, account_status='active', active=1, agent_can_clients=1, agent_can_transfers=1 WHERE username IN ('test.agent1','test.agent2')");
    q("DELETE FROM referral_links WHERE slug='test.agent1x'"); q("UPDATE referral_links l JOIN users u ON u.id=l.agent_id SET l.is_primary=(l.slug=u.username), l.status='active' WHERE u.username IN ('test.agent1','test.agent2')");
    $ids = array_column(all("SELECT id FROM clients WHERE name LIKE 'E2E %'"), 'id');
    if ($ids) { $in = implode(',', array_map('intval', $ids));
        foreach (['document_versions', 'client_documents', 'kyc_reviews', 'rekyc_cases', 'reminders', 'upload_requests', 'beneficiaries', 'beneficiary_events', 'client_otps'] as $t) q("DELETE FROM $t WHERE client_id IN ($in)");
        q("DELETE FROM transfers WHERE client_id IN ($in)"); q("DELETE FROM clients WHERE id IN ($in)"); }
    q("DELETE FROM submissions WHERE name LIKE 'E2E %'"); q("DELETE FROM intake_drafts WHERE ip IS NOT NULL AND created_at > (NOW() - INTERVAL 1 DAY)");
    q("UPDATE users SET role='admin' WHERE username='test.admin'");
    global $E2E_FILES; foreach ($E2E_FILES ?? [] as $f) @unlink(ids_dir() . '/' . basename($f));
    q("UPDATE users SET role='admin' WHERE username='test.admin'"); q("DELETE FROM settings WHERE k='owner_claim'");
}
e2e_cleanup(); // leftovers from an interrupted run
register_shutdown_function(function () { try { e2e_cleanup(); } catch (Throwable $e) {} });

$AUDIT0 = (int)val('SELECT COALESCE(MAX(id),0) FROM audit');
// ---- test data: one client for each agent ----
$a1 = one('SELECT * FROM users WHERE username=?', ['test.agent1']); $a2 = one('SELECT * FROM users WHERE username=?', ['test.agent2']);
$c1 = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'E2E Client One', 'phone' => '+1 416 555 7001', 'office' => 'Canada', 'id_number' => 'E2E11111']), ['agent_id' => $a1['id'], 'source' => 'agent']);
$c2 = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'E2E Client Two', 'phone' => '+1 416 555 7002', 'office' => 'Canada', 'id_number' => 'E2E22222']), ['agent_id' => $a2['id'], 'source' => 'agent', 'id_file' => '20261114_' . str_repeat('a', 24) . '.jpg']);
q("INSERT INTO transfers (ref_no, client_id, client_name, agent_id, send_amount, send_currency, receive_currency, transfer_date, status, requested_by) VALUES ('E2E-T2',?,?,?,100,'CAD','INR',CURDATE(),'pending',?)", [$c2, 'E2E Client Two', $a2['id'], $a2['id']]);
$t2 = (int)db()->lastInsertId();

// ---- 1. public surface ----
$b = new Browser();
foreach (['/inc/config.php', '/inc/bootstrap.php', '/tests/run.php', '/tools/backup.php', '/storage/test-report.txt', '/.gitignore', '/inc/schema.sql'] as $p) { $b->req($p, null, false); ok(in_array($b->code, [403, 404], true), "public cannot open $p", (string)$b->code); }
$b->req('/?p=clients', null, false); ok(in_array($b->code, [302, 303], true) && str_contains($b->loc, 'login'), 'logged-out visitor is sent to login', $b->loc);
$b->req('/login.php'); $b->req('/login.php', ['username' => 'test.agent1', 'password' => $T['test.agent1']['password']]);
ok($b->code === 400, 'form without security token is rejected (CSRF)', (string)$b->code);

// ---- 2. sub-agent isolation over HTTP ----
$ag = new Browser(); $ag->login('test.agent1');
ok(str_contains($ag->body, 'Sub-agent') || str_contains($ag->req('/'), 'Sub-agent'), 'sub-agent 1 logs in');
$ag->req("/?p=client&id=$c1"); ok(str_contains($ag->body, 'E2E Client One'), 'sub-agent 1 opens own client');
$ag->req("/?p=client&id=$c2"); ok(!str_contains($ag->body, 'E2E Client Two'), "sub-agent 1 cannot open sub-agent 2's client by changing the id");
$ag->req('/?p=clients'); ok(str_contains($ag->body, 'E2E Client One') && !str_contains($ag->body, 'E2E Client Two'), 'sub-agent 1 client list shows only own clients');
$ag->req("/?p=transfers&id=$t2"); ok(!str_contains($ag->body, 'E2E-T2'), "sub-agent 1 cannot open sub-agent 2's transfer");
$ag->req("/?p=client_form&id=$c2"); ok(!str_contains($ag->body, 'E2E Client Two'), "sub-agent 1 cannot edit sub-agent 2's client");
$ag->post('/?p=transfers', ['id' => $t2, 'act' => 'accept']); ok(val('SELECT status FROM transfers WHERE id=?', [$t2]) === 'pending', 'sub-agent cannot accept transfers');
$ag->post('/?p=transfers', ['id' => $t2, 'act' => 'cancel']); ok(val('SELECT status FROM transfers WHERE id=?', [$t2]) === 'pending', "sub-agent cannot cancel another agent's request");
foreach (['agents', 'submissions', 'insights', 'roles', 'import', 'log'] as $pg) { $ag->req("/?p=$pg"); ok(!preg_match('/<h1>(Team|Online registration|Owner overview|Job roles|Import|Daily work log)/', $ag->body), "sub-agent cannot open $pg"); }
$ag->req('/file.php?f=20261114_' . str_repeat('a', 24) . '.jpg', null, false); ok($ag->code === 403, 'sub-agent cannot open ID documents', (string)$ag->code);
$ag->req('/?p=audit'); ok(!str_contains($ag->body, 'test.agent2') && !str_contains($ag->body, 'Test Agent Two'), "sub-agent activity page shows only their own actions");
$ag->req('/'); ok(str_contains($ag->body, 'class="bottombar"') && str_contains($ag->body, 'width=device-width'), 'sub-agent gets the mobile app layout (bottom bar, mobile viewport)');
ok(preg_match('#/r/test\.agent1"#', $ag->body) === 1 && !str_contains($ag->body, 'register?a=') && str_contains($ag->body, 'wa.me/') && str_contains($ag->body, 'sms:') && str_contains($ag->body, 'data-copy='), 'sub-agent shares a short /r/ link with Share, WhatsApp, SMS and Copy buttons');

// ---- 3. admin login needs two-step; no session from password alone ----
fresh_step_wait();
$ad = new Browser(); $ad->login('test.admin', null, false);
ok(str_contains($ad->body, 'Two-step login'), 'admin password leads to the two-step page');
$ad->req('/?p=clients', null, false); ok(in_array($ad->code, [302, 303], true) && str_contains($ad->loc, 'login'), 'admin has no session before the code (MFA cannot be skipped)', $ad->code . ' ' . $ad->loc);
$ad->req('/mfa.php', null); $ad->req('/mfa.php', ['csrf' => $ad->csrf(), 'code' => '000000']); ok(str_contains($ad->body, 'did not work') || str_contains($ad->body, 'log in again'), 'wrong code refused');
$code = totp_code($T['test.admin']['mfa_secret'], intdiv(time(), 30));
$ad->req('/login.php'); $ad->req('/login.php', ['csrf' => $ad->csrf(), 'username' => 'test.admin', 'password' => $T['test.admin']['password']]);
$ad->req('/mfa.php', ['csrf' => $ad->csrf(), 'code' => $code]);
$ad->req('/?p=clients'); ok(str_contains($ad->body, 'E2E Client Two'), 'admin with password + code sees all clients');
$ad2 = new Browser(); $ad2->req('/login.php'); $ad2->req('/login.php', ['csrf' => $ad2->csrf(), 'username' => 'test.admin', 'password' => $T['test.admin']['password']]); $ad2->req('/mfa.php', ['csrf' => $ad2->csrf(), 'code' => $code]);
$ad2->req('/?p=clients', null, false); ok(!str_contains($ad2->body, 'E2E Client Two'), 'the same code cannot be used again in another browser (replay)');
$ad->req("/?p=client&id=$c2"); ok(str_contains($ad->body, '•••• 2222') && !str_contains($ad->body, 'E2E22222'), 'admin sees the ID number masked');
$ad->post('/?p=transfers', ['id' => $t2, 'act' => 'accept']); ok(val('SELECT status FROM transfers WHERE id=?', [$t2]) === 'pending', 'admin cannot accept a transfer for a client whose KYC is not approved (policy enforced on the server)');

// ---- 4. read-only auditor and staff ----
$au = new Browser(); $au->login('test.auditor');
q("UPDATE transfers SET status='pending' WHERE id=?", [$t2]);
$au->post('/?p=transfers', ['id' => $t2, 'act' => 'accept']); ok(val('SELECT status FROM transfers WHERE id=?', [$t2]) === 'pending', 'auditor cannot accept transfers');
$au->req("/?p=client_form&id=$c1"); ok(!str_contains($au->body, 'Save client'), 'auditor cannot open the edit form');
$au->post("/?p=client&id=$c1", ['act' => 'kyc', 'kyc' => 'Verified']); ok(val('SELECT kyc FROM clients WHERE id=?', [$c1]) === 'Pending', 'auditor cannot change KYC');
$au->req('/?p=audit'); ok(str_contains($au->body, 'Activity history'), 'auditor can read the activity history');
$st = new Browser(); $st->login('test.staff');
$st->post('/?p=transfers', ['id' => $t2, 'act' => 'accept']); ok(val('SELECT status FROM transfers WHERE id=?', [$t2]) === 'pending', 'operations staff cannot accept transfers');
$st->req('/?p=agents'); ok(!str_contains($st->body, 'Team &amp; sub-agents</h1>'), 'operations staff cannot manage the team');
fresh_step_wait();
$co = new Browser(); $co->login('test.compliance');
$co->post("/?p=client&id=$c1", ['act' => 'kyc', 'kyc' => 'Verified']); ok(val('SELECT kyc FROM clients WHERE id=?', [$c1]) === 'Pending', 'the old one-click "Verify KYC" no longer changes anything');
$co->req("/?p=client&id=$c1"); ok(!str_contains($co->body, 'Mark Verified') && str_contains($co->body, 'Open KYC review'), 'client page shows "Open KYC review" instead of one-click buttons');
$co->post("/?p=client&id=$c2", ['act' => 'reveal']); ok(str_contains($co->body, 'E2E22222'), 'compliance can reveal the full ID number');
ok((int)val("SELECT COUNT(*) FROM audit WHERE action='view_pii' AND entity_id=?", [$c2]) >= 1, 'revealing the full number is logged');

// ---- 5. account hijack attempts ----
// a) existing admin without two-step (upgrade case): password alone must not allow enrolling an authenticator
q("INSERT INTO users (role, username, name, password_hash, mfa_token_only) VALUES ('admin','e2e.legacy','E2E Legacy Admin',?,1)", [password_hash('Legacy-Pass-123', PASSWORD_DEFAULT)]);
$lg = (int)db()->lastInsertId();
$hj = new Browser(); $hj->login('e2e.legacy', 'Legacy-Pass-123', false);
ok(str_contains($hj->body, 'through a link') && !str_contains($hj->body, 'Set up two-step login'), 'stolen password of a pre-upgrade admin cannot register an authenticator');
$hj->req('/mfa_setup.php', null, false); ok(in_array($hj->code, [302, 303], true), 'setup page refuses without a verified start', (string)$hj->code);
// b) reset link: old password stops working, link works once, leads to two-step setup, no session before it
$link = make_reset_link($lg, (int)$a1['id']);
q('UPDATE users SET password_hash=?, session_version=session_version+1 WHERE id=?', [password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $lg]);
$hj2 = new Browser(); $hj2->login('e2e.legacy', 'Legacy-Pass-123', false); ok(str_contains($hj2->body, 'Wrong ID or password'), 'old password stops working after a reset link is made');
$rb = new Browser(); $path = substr($link, strlen($BASE));
$rb->req($path); $rb->req($path, ['csrf' => $rb->csrf(), 'password' => 'New-Legacy-Pass-456', 'password2' => 'New-Legacy-Pass-456']);
ok(str_contains($rb->body, 'Set up two-step login'), 'reset link leads straight to two-step setup for an admin');
$rb->req('/?p=clients', null, false); ok(in_array($rb->code, [302, 303], true), 'no session until the authenticator is confirmed');
$rb2 = new Browser(); $rb2->req($path); ok(str_contains($rb2->body, 'already been used'), 'reset link works only once');
// c) super admin claim: install key + password + code; only one winner
fresh_step_wait();
$cl = new Browser(); $cl->req('/install.php?key=x'); $cl->req('/install.php', ['csrf' => $cl->csrf(), 'install_key' => 'wrong-key', 'username' => 'test.admin', 'password' => $T['test.admin']['password'], 'code' => totp_code($T['test.admin']['mfa_secret'], intdiv(time(), 30)), 'confirm' => '1']);
ok((int)val("SELECT COUNT(*) FROM users WHERE role='super_admin'") === 0, 'super admin claim fails with a wrong install key');
fresh_step_wait();
$cl->req('/install.php'); $cl->req('/install.php', ['csrf' => $cl->csrf(), 'install_key' => $CONFIG['install_key'], 'username' => 'test.admin', 'password' => $T['test.admin']['password'], 'code' => totp_code($T['test.admin']['mfa_secret'], intdiv(time(), 30)), 'confirm' => '1']);
ok(val("SELECT role FROM users WHERE username='test.admin'") === 'super_admin', 'owner claims super admin with install key + password + code');
ok((int)val("SELECT COUNT(*) FROM audit WHERE action='super_admin_claimed' AND id>?", [$AUDIT0]) === 1, 'the claim is recorded in the activity log');
$cl2 = new Browser(); $cl2->req('/install.php', null, false); ok($cl2->code === 404, 'install page closes once a super admin with two-step exists', (string)$cl2->code);

// ---- 6. public form and referral ----
$pf = new Browser(); $sig = ref_sig('SA-9001');
$pf->req("/register?ref=SA-9001&s=$sig"); ok(str_contains($pf->body, 'referred by DhanXfer agent'), 'signed agent link locks the referral');
$pf->req('/register', ['csrf' => $pf->csrf(), 'name' => 'E2E Public', 'phone' => '+1 416 555 7009', 'country' => 'Canada', 'consent' => '1', 'ref_code' => 'SA-9002']);
$sub = one("SELECT * FROM submissions WHERE name='E2E Public' ORDER BY id DESC");
ok($sub && $sub['ref_code'] === 'test.agent1' && (int)$sub['ref_verified'] === 1 && (int)$sub['agent_id'] === (int)$a1['id'], 'posting a different code cannot change a signed referral');
ok($sub && str_starts_with((string)$sub['data'], 'enc:v1:'), 'online form stored encrypted');
preg_match('/DXA-[0-9A-Z-]+/', $pf->body, $m); $ref = $m[0] ?? '';
ok($ref !== '', 'client gets an application reference', substr(strip_tags($pf->body), 0, 200));
$sp = new Browser(); $sp->req('/status'); $sp->req('/status', ['csrf' => $sp->csrf(), 'ref' => $ref, 'last4' => '7009']);
ok(str_contains($sp->body, 'Received') && !str_contains($sp->body, 'KYC') && !str_contains($sp->body, 'risk'), 'status page shows a plain status, no KYC or risk details');
$sp->req('/status', ['csrf' => $sp->csrf(), 'ref' => $ref, 'last4' => '0000']); ok(str_contains($sp->body, 'could not find'), 'wrong phone digits reveal nothing');
$pt = new Browser(); $pt->req('/register?ref=SA-9002&s=0000000000000000'); $pt->req('/register', ['csrf' => $pt->csrf(), 'name' => 'E2E Tampered', 'phone' => '+1 416 555 7010', 'country' => 'Canada', 'consent' => '1', 'ref_code' => 'SA-9002']);
$sub2 = one("SELECT * FROM submissions WHERE name='E2E Tampered' ORDER BY id DESC");
ok($sub2 && (int)$sub2['ref_verified'] === 0 && $sub2['agent_id'] === null, 'a tampered or typed code is kept but never credited automatically');


// ---- 7. public status page: guessing, limits, what it shows ----
q('DELETE FROM login_attempts');
q("INSERT INTO submissions (data, name, phone, app_ref, status) VALUES (?, 'E2E Status Person', '+1 416 555 7011', 'DXA-261114-E2E01', 'new')", [enc('{"name":"E2E Status Person"}')]);
$msg = fn(Browser $b) => preg_match('/class="banner bad">([^<]+)</', $b->body, $m) ? $m[1] : '';
$s1 = new Browser(); $s1->req('/status');
$s1->req('/status', ['csrf' => $s1->csrf(), 'ref' => 'DXA-261114-NOPE1', 'last4' => '7011']); $unknown = $msg($s1);
$s1->req('/status', ['csrf' => $s1->csrf(), 'ref' => 'DXA-261114-E2E01', 'last4' => '1111']); $wrong = $msg($s1);
ok($unknown !== '' && $unknown === $wrong, 'unknown reference and wrong phone digits give the identical answer (no way to tell which references exist)');
ok(!str_contains($s1->body, 'E2E Status Person') && !str_contains($s1->body, '7011'), 'failed lookup shows no name or phone');
for ($i = 0; $i < 3; $i++) $s1->req('/status', ['csrf' => $s1->csrf(), 'ref' => 'DXA-261114-E2E01', 'last4' => sprintf('%04d', 2000 + $i)]);
q("DELETE FROM login_attempts WHERE username='status-check'"); // fresh connection budget, same reference
$s1->req('/status', ['csrf' => $s1->csrf(), 'ref' => 'DXA-261114-E2E01', 'last4' => '3000']); // 5th wrong try for this reference
$s1->req('/status', ['csrf' => $s1->csrf(), 'ref' => 'DXA-261114-E2E01', 'last4' => '7011']);
ok($msg($s1) === $wrong && !str_contains($s1->body, 'Received'), 'after 5 wrong tries the reference stops answering, even with the right digits (from any connection)');
ok((int)val("SELECT COUNT(*) FROM audit WHERE action='status_ref_locked'") >= 1, 'the paused reference is recorded in the activity log');
q("DELETE FROM login_attempts WHERE username='status-check'");
for ($i = 0; $i < 10; $i++) $s1->req('/status', ['csrf' => $s1->csrf(), 'ref' => sprintf('DXA-261114-X%04d', $i), 'last4' => '1234']);
$s1->req('/status', ['csrf' => $s1->csrf(), 'ref' => 'DXA-261114-Y0000', 'last4' => '1234']);
ok(str_contains($s1->body, 'Too many checks'), 'one connection is limited to 10 lookups per 15 minutes');
$sx = new Browser(); $sx->req('/status');
$ch = curl_init($BASE . '/status'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => $sx->jar, CURLOPT_COOKIEJAR => $sx->jar, CURLOPT_HTTPHEADER => ['X-Forwarded-For: 198.51.100.7', 'X-Real-IP: 198.51.100.7', 'Client-IP: 198.51.100.7'], CURLOPT_POSTFIELDS => http_build_query(['csrf' => $sx->csrf(), 'ref' => 'DXA-261114-Z0000', 'last4' => '1234'])]);
$xb = (string)curl_exec($ch); curl_close($ch);
ok(str_contains($xb, 'Too many checks'), 'a faked forwarding header does not reset the limit');
$ch = curl_init($BASE . '/status'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true]); $hdrs = (string)curl_exec($ch); curl_close($ch);
ok(stripos($hdrs, 'no-store') !== false && stripos($hdrs, 'x-frame-options: deny') !== false, 'status page is not cached and cannot be framed', preg_replace('/\s+/', ' ', substr($hdrs, 0, 600)));
$sg = new Browser(); $sg->req('/status?ref=DXA-261114-E2E01&last4=7011'); ok(!str_contains($sg->body, 'Received'), 'lookups only work by form post (nothing in links or browser history)');
q('DELETE FROM login_attempts'); q("DELETE FROM submissions WHERE app_ref='DXA-261114-E2E01'");

// ---- 8. owner cannot be locked out by strangers; super admin stays reachable ----
// test.admin is super admin now (claimed in section 5). Strangers' wrong passwords from other addresses:
foreach (['test.admin', 'test.staff'] as $who) for ($i = 0; $i < 10; $i++) q("INSERT INTO login_attempts (ip, username) VALUES ('203.0.113.9', ?)", [$who]);
fresh_step_wait();
$sa = new Browser(); $sa->login('test.admin');
$sa->req('/', null);
ok(str_contains($sa->body, 'Owner overview') || str_contains($sa->body, 'Log out'), "10 wrong passwords from a stranger's address do not lock out the owner (two-step login protects the account)");
$sf = new Browser(); $sf->login('test.staff');
ok(str_contains($sf->body, 'Too many wrong attempts'), 'an account without two-step login is still locked after 10 wrong passwords');
q("DELETE FROM login_attempts WHERE ip='203.0.113.9'");
$sa->post('/?p=agents', ['id' => (int)val("SELECT id FROM users WHERE username='test.admin'"), 'act' => 'disable']);
ok((int)val("SELECT active FROM users WHERE username='test.admin'") === 1, 'super admin cannot switch off their own account');
$sa->post('/?p=agents', ['id' => (int)val("SELECT id FROM users WHERE username='test.admin'"), 'act' => 'mfa_reset']);
ok((int)val("SELECT mfa_enabled FROM users WHERE username='test.admin'") === 1, 'super admin cannot reset their own two-step login from the panel');
$co2 = new Browser(); fresh_step_wait(); $co2->login('test.compliance');
$co2->post('/?p=agents', ['id' => (int)val("SELECT id FROM users WHERE username='test.admin'"), 'act' => 'disable']);
ok((int)val("SELECT active FROM users WHERE username='test.admin'") === 1, 'a compliance officer cannot switch off the super admin');
$cl3 = new Browser(); $cl3->req('/install.php', null, false); ok($cl3->code === 404, 'install page stays closed while a working super admin exists');

q('DELETE FROM login_attempts');
$pad = new Browser(); $pad->req('/login.php');
for ($i = 0; $i < 10; $i++) $pad->req('/login.php', ['csrf' => $pad->csrf() ?: 'x', 'username' => 'test.agent2' . str_repeat("\x01", 60), 'password' => 'wrong-' . $i]);
ok($pad->code === 200 && (int)val("SELECT COUNT(*) FROM login_attempts WHERE ip<>'203.0.113.9'") >= 10, 'login IDs padded with invisible characters are refused and still counted (no 500 error)', (string)$pad->code);
$pad->req('/login.php'); $pad->req('/login.php', ['csrf' => $pad->csrf(), 'username' => 'test.agent2', 'password' => $T['test.agent2']['password']]);
ok(str_contains($pad->body, 'Too many wrong attempts'), 'after 10 such tries that connection is blocked');
q('DELETE FROM login_attempts');
// ---- 9. emergency recovery from the server (lost phone and recovery codes) ----
$sid = (int)val("SELECT id FROM users WHERE username='test.admin'");
$oldPw = $T['test.admin']['password']; $oldSecret = $T['test.admin']['mfa_secret'];
// same code the server tool runs (the host does not let PHP start other programs, so the tool itself is run by cron separately)
$outp = (string)recover_super('test.admin');
{
    ok(preg_match('#/reset\.php\?t=[a-f0-9]{48}#', $outp, $lm) === 1, 'server recovery gives a one-time link');
    ok((int)val('SELECT mfa_enabled FROM users WHERE id=?', [$sid]) === 0 && (int)val("SELECT COUNT(*) FROM audit WHERE action='break_glass'") >= 1, 'recovery clears the lost authenticator and is logged');
    $rv = new Browser(); $rv->login('test.admin', $oldPw, false); ok(str_contains($rv->body, 'Wrong ID or password'), 'old password stops working after recovery');
    $rl = new Browser(); $rl->req($lm[0]); $rl->req($lm[0], ['csrf' => $rl->csrf(), 'password' => 'Recovered-Pass-789', 'password2' => 'Recovered-Pass-789']);
    $sec = preg_match('/letter-spacing:\.08em;font-size:17px">([A-Z2-7 ]+)</', $rl->body, $sm) ? str_replace(' ', '', $sm[1]) : '';
    ok($sec !== '', 'recovery link leads to new two-step setup');
    $rl->req('/mfa_setup.php', ['csrf' => $rl->csrf(), 'code' => totp_code($sec, intdiv(time(), 30))]);
    ok((int)val('SELECT mfa_enabled FROM users WHERE id=?', [$sid]) === 1 && val('SELECT role FROM users WHERE id=?', [$sid]) === 'super_admin', 'owner is back in with a new authenticator and is still super admin');
    fresh_step_wait();
    $T['test.admin']['password'] = 'Recovered-Pass-789'; $T['test.admin']['mfa_secret'] = $sec;
    $rb3 = new Browser(); $rb3->login('test.admin'); $rb3->req('/');
    ok(str_contains($rb3->body, 'Owner overview'), 'owner logs in normally after recovery');
    snap('super_overview', $rb3->body);
    // put the staging test account back as it was
    q('UPDATE users SET password_hash=?, mfa_secret=?, mfa_enabled=1, mfa_token_only=0, mfa_last_step=NULL WHERE id=?', [password_hash($oldPw, PASSWORD_DEFAULT), enc($oldSecret), $sid]);
    $T['test.admin']['password'] = $oldPw; $T['test.admin']['mfa_secret'] = $oldSecret;
}
q("UPDATE users SET role='admin' WHERE id=?", [$sid]); q("DELETE FROM settings WHERE k='owner_claim'");
q("INSERT INTO users (role, username, name, password_hash) VALUES ('admin','e2e.staffx','E2E Stuck Claim','x')"); $stuck = (int)db()->lastInsertId();
owner_claim($stuck); // an admin started the claim and never finished
ok(clear_owner_claim() && val("SELECT v FROM settings WHERE k='owner_claim'") === null && val('SELECT role FROM users WHERE id=?', [$stuck]) === 'admin', 'a half-finished claim can be cleared from the server without promoting anyone');


// ---- 11. the live cutover path: the existing v2 admin (no two-step yet, link-only) claims super admin ----
q("DELETE FROM settings WHERE k='owner_claim'");
q("INSERT INTO users (role, username, name, password_hash, mfa_token_only) VALUES ('admin','e2e.v2owner','E2E V2 Owner',?,1)", [password_hash('V2-Owner-Pass-1', PASSWORD_DEFAULT)]);
$v2 = (int)db()->lastInsertId();
$lo = new Browser(); $lo->login('e2e.v2owner', 'V2-Owner-Pass-1', false);
ok(str_contains($lo->body, 'through a link') && (int)val('SELECT mfa_enabled FROM users WHERE id=?', [$v2]) === 0, 'v2 admin password alone cannot start two-step setup on the login page');
$vc = new Browser(); $vc->req('/install.php');
ok($vc->code === 200 && str_contains($vc->body, 'install_key'), 'install page is open for the claim while no working super admin exists');
$vc->req('/install.php', ['csrf' => $vc->csrf(), 'install_key' => $CONFIG['install_key'], 'username' => 'e2e.v2owner', 'password' => 'V2-Owner-Pass-1', 'code' => '', 'confirm' => '1']);
$sec2 = preg_match('/letter-spacing:\.08em;font-size:17px">([A-Z2-7 ]+)</', $vc->body, $sm2) ? str_replace(' ', '', $sm2[1]) : '';
ok($sec2 !== '' && val('SELECT role FROM users WHERE id=?', [$v2]) === 'admin', 'install key + password lead to two-step setup; still admin until the code is confirmed');
$vc->req('/?p=clients', null, false); ok(in_array($vc->code, [302, 303], true), 'no session during the claim before the code');
$vc->req('/mfa_setup.php'); $vc->req('/mfa_setup.php', ['csrf' => $vc->csrf(), 'code' => totp_code($sec2, intdiv(time(), 30))]);
ok(val('SELECT role FROM users WHERE id=?', [$v2]) === 'super_admin' && (int)val('SELECT mfa_enabled FROM users WHERE id=?', [$v2]) === 1, 'after the code the v2 admin is super admin with two-step login');
$vc2 = new Browser(); $vc2->req('/install.php', null, false); ok($vc2->code === 404, 'install page closes after the claim');
q('DELETE FROM users WHERE id=?', [$v2]); q("DELETE FROM settings WHERE k='owner_claim'");

// ---- 12. KYC review, Compliance Center, new intake (staging, synthetic data) ----
require_once __DIR__ . '/../inc/compliance.php';
$img = sys_get_temp_dir() . '/e2e-id-' . getmypid() . '.jpg';
file_put_contents($img, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
$fn = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.jpg'; id_file_write($fn, (string)file_get_contents($img)); $E2E_FILES[] = $fn;
$kc = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'E2E KYC Person', 'phone' => '+1 416 555 7020', 'office' => 'Canada', 'dob' => '1988-02-03',
    'address' => '12 Main Street', 'city' => 'Toronto', 'postal' => 'M5H 2N2', 'country' => 'Canada', 'id_type' => 'Passport', 'id_number' => 'E2EKYC77', 'id_expiry' => date('Y-m-d', strtotime('+3 years')),
    'id_has_address' => 'no']), ['agent_id' => $a1['id'], 'source' => 'agent', 'id_file' => $fn]);
q('UPDATE clients SET phone_e164=? WHERE id=?', ['+14165557020', $kc]);
ok(val('SELECT address_match FROM clients WHERE id=?', [$kc]) === 'no_id_address', 'ID without an address is flagged for proof of address');
ok(id_file_encrypted($fn) && !str_contains((string)file_get_contents(ids_dir() . '/' . $fn), 'JFIF'), 'ID image is stored encrypted on the server');
$ag12 = new Browser(); $ag12->login('test.agent1');
$ag12->req("/?p=kyc_review&id=$kc"); ok(!str_contains($ag12->body, 'Record decision'), 'sub-agent cannot open the KYC review');
$ag12->req('/?p=compliance'); ok(!str_contains($ag12->body, '<h1>Compliance Center'), 'sub-agent cannot open the Compliance Center');
$au12 = new Browser(); $au12->login('test.auditor');
$au12->req('/?p=compliance'); ok(str_contains($au12->body, 'Compliance Center') && str_contains($au12->body, 'E2E KYC Person'), 'read-only auditor can see the Compliance Center queue');
$au12->req("/?p=kyc_review&id=$kc"); ok(!str_contains($au12->body, 'Record decision'), 'auditor cannot open the KYC decision screen');
$au12->post('/?p=compliance&v=policy', ['act' => 'policy', 'refresh_months_low' => '99']); ok(setting('refresh_months_low') !== '99', 'auditor cannot change compliance settings');
$au12->req('/file.php?f=' . $fn, null, false); ok($au12->code === 403, 'auditor cannot open ID images');
// super admin decides (test.admin promoted for this test only)
q("UPDATE users SET role='super_admin' WHERE username='test.admin'");
fresh_step_wait();
$sa12 = new Browser(); $sa12->login('test.admin'); $sa12->req("/?p=kyc_review&id=$kc");
ok(str_contains($sa12->body, 'Record decision') && str_contains($sa12->body, 'Sanctions / PEP') && str_contains($sa12->body, 'Approve') && str_contains($sa12->body, 'Request more information') && str_contains($sa12->body, 'Escalate'), 'super admin sees the structured review: checklist, screening, four decisions');
snap('kyc_review', $sa12->body);
$sa12->req('/file.php?f=' . $fn, null, false); ok($sa12->code === 200 && str_starts_with($sa12->body, "\xFF\xD8"), 'super admin opens the ID image (decrypted on the fly, logged)');
$full12 = ['act' => 'decide', 'decision' => 'approve', 'checklist' => array_keys(KYC_CHECKLIST), 'risk' => 'Low', 'notes' => 'E2E: passport seen, details match, screening clear.', 'evidence' => 'E2E evidence folder', 'screening_ref' => 'E2E-SCREEN-1', 'address_evidence' => ''];
$sa12->post("/?p=kyc_review&id=$kc", array_merge($full12, ['code' => '']));
ok(val('SELECT kyc FROM clients WHERE id=?', [$kc]) === 'Pending' && str_contains($sa12->body, 'two-step code'), 'approval without the two-step code is refused');
$sa12->post("/?p=kyc_review&id=$kc", array_merge($full12, ['code' => totp_code($T['test.admin']['mfa_secret'], intdiv(time(), 30))]));
ok(val('SELECT kyc FROM clients WHERE id=?', [$kc]) === 'Pending' && str_contains($sa12->body, 'proof of address'), 'approval refused: ID has no address and no proof of address was recorded');
fresh_step_wait();
$sa12->post("/?p=kyc_review&id=$kc", array_merge($full12, ['address_evidence' => 'E2E bank statement Oct 2026', 'code' => totp_code($T['test.admin']['mfa_secret'], intdiv(time(), 30))]));
$rv12 = one('SELECT * FROM kyc_reviews WHERE client_id=? ORDER BY id DESC', [$kc]);
ok(val('SELECT kyc FROM clients WHERE id=?', [$kc]) === 'Verified' && $rv12 && $rv12['reviewer_role'] === 'super_admin' && $rv12['decision'] === 'approve', 'super admin approves through the review screen; decision, reviewer and role recorded');
q("INSERT INTO beneficiaries (client_id, name, bank, status) VALUES (?, 'E2E KYC Benef', 'SBI', 'active')", [$kc]); $kb12 = (int)db()->lastInsertId();
q("INSERT INTO transfers (ref_no, client_id, client_name, agent_id, beneficiary_id, send_amount, send_currency, receive_currency, transfer_date, status, requested_by) VALUES ('E2E-T12',?,?,?,?,50,'CAD','INR',CURDATE(),'pending',?)", [$kc, 'E2E KYC Person', $a1['id'], $kb12, $a1['id']]);
$t12 = (int)db()->lastInsertId();
$sa12->post('/?p=transfers', ['id' => $t12, 'act' => 'accept']); ok(val('SELECT status FROM transfers WHERE id=?', [$t12]) === 'accepted', 'a transfer for the verified client can now be accepted');
$sa12->req('/?p=compliance&v=completed'); snap('compliance_center', $sa12->body);
// compliance requests an updated document; the client uploads it through the secure link
$co12 = new Browser(); fresh_step_wait(); $co12->login('test.compliance');
$co12->post("/?p=client&id=$kc", ['act' => 'upload_link']);
preg_match('#/upload\?t=([a-f0-9]{48})#', $co12->body, $um);
ok(!empty($um[1]), 'compliance makes a secure upload link from the client page');
$cl12 = new Browser(); $cl12->req('/upload?t=' . ($um[1] ?? 'x'));
$cl12->req('/upload', ['csrf' => $cl12->csrf(), 'act' => 'verify', 't' => $um[1] ?? '', 'dob' => '1988-02-03', 'last4' => '0000']);
ok(str_contains($cl12->body, 'do not match'), 'upload link: wrong phone digits refused');
$cl12->req('/upload', ['csrf' => $cl12->csrf(), 'act' => 'verify', 't' => $um[1] ?? '', 'dob' => '1988-02-03', 'last4' => '7020']);
ok(str_contains($cl12->body, 'Upload your current ID'), 'upload link: client passes with date of birth + phone digits');
$cl12->upload('/upload', ['csrf' => $cl12->csrf(), 'act' => 'upload', 't' => $um[1] ?? '', 'id_type' => 'Passport', 'id_number' => 'E2EKYC88', 'id_country' => 'Canada', 'id_expiry' => date('Y-m-d', strtotime('+5 years')), 'id_has_address' => 'no'], ['id_file', $img, 'image/jpeg']);
$dv12 = doc_current($kc);
ok(str_contains($cl12->body, 'received securely') && (int)$dv12['version'] === 2 && $dv12['source'] === 'upload_link' && $dv12['outcome'] === 'unverified', 'client uploads a new ID: saved as version 2, waiting for review');
if ($dv12['file_name']) $E2E_FILES[] = $dv12['file_name'];
ok((int)val('SELECT COUNT(*) FROM document_versions WHERE client_id=?', [$kc]) === 2 && val("SELECT status FROM rekyc_cases WHERE client_id=? ORDER BY id DESC LIMIT 1", [$kc]) === 'in_progress', 'the first version is kept as history; the refresh case is in progress');
$cl12b = new Browser(); $cl12b->req('/upload?t=' . ($um[1] ?? 'x')); ok(str_contains($cl12b->body, 'Link not available'), 'upload link works only once');
[$el12, $why12] = transfer_eligibility(one('SELECT * FROM clients WHERE id=?', [$kc]));
ok(!$el12 && str_contains(implode(' ', $why12), 'not been reviewed'), 'until the uploaded ID is reviewed, transfers stay blocked');
$co12->post('/?p=compliance&v=policy', ['act' => 'policy', 'refresh_months_low' => '98', 'code' => '']); ok(setting('refresh_months_low') !== '98', 'compliance settings need a fresh two-step code');
// public form: phone rules, address on ID, drafts, referral by login ID
$pf12 = new Browser(); $pf12->req('/register?a=' . $a1['id'] . '&s=' . agent_sig((int)$a1['id']));
ok(str_contains($pf12->body, 'referred by DhanXfer agent <strong>test.agent1'), 'signed agent link shows the login ID as the referral code');
snap('register_new', $pf12->body);
$pf12->req('/register', ['csrf' => $pf12->csrf(), 'name' => 'E2E Phone', 'country' => 'Canada', 'phone' => '416 555 701', 'consent' => '1']);
ok(str_contains($pf12->body, '10 digits'), 'Canadian number with 9 digits refused with a clear message');
$pf12->req('/register', ['csrf' => $pf12->csrf(), 'name' => 'E2E Phone', 'country' => 'Canada', 'phone' => '(416) 555-7021', 'consent' => '1', 'wa_same' => 'yes',
    'id_type' => 'Passport', 'id_number' => 'E2EADDR1', 'id_expiry' => date('Y-m-d', strtotime('+2 years')), 'address' => '12 Main Street', 'city' => 'Toronto', 'postal' => 'M5H 2N2',
    'id_has_address' => 'yes', 'id_addr_same' => 'no', 'id_addr_line1' => '88 King Road', 'id_addr_city' => 'Brampton', 'id_addr_postal' => 'L6T 1A1', 'occupation' => 'Student', 'employer' => 'Should Be Dropped']);
$sd = one("SELECT * FROM submissions WHERE name='E2E Phone' ORDER BY id DESC");
$sdd = $sd ? json_decode((string)dec($sd['data']), true) : [];
ok($sd && ($sdd['phone_e164'] ?? '') === '+14165557021' && (int)$sd['agent_id'] === (int)$a1['id'], 'valid number stored in international form; referral credited by agent ID');
ok(($sdd['id_addr_city'] ?? '') === 'Brampton' && ($sdd['employer'] ?? 'x') === '' && ($sdd['office'] ?? '') === 'Canada', 'ID address kept; hidden questions ignored (employer for a student); office follows the country');
$dr = new Browser(); $dr->req('/register');
$dr->req('/register', ['csrf' => $dr->csrf(), 'act' => 'draft', 'name' => 'E2E Draft Person', 'country' => 'Canada', 'phone' => '416 555 7022', 'city' => 'Ottawa']);
preg_match('#/register\?draft=([a-f0-9]{48})#', $dr->body, $dm);
ok(!empty($dm[1]) && str_contains($dr->body, 'Saved for later'), 'save and finish later gives a private link');
$dr2 = new Browser(); $dr2->req('/register?draft=' . ($dm[1] ?? 'x'));
ok(str_contains($dr2->body, 'Last 4 digits') && !str_contains($dr2->body, 'E2E Draft Person'), 'the link alone shows nothing: it asks for the phone digits');
$dr2->req('/register', ['csrf' => $dr2->csrf(), 'act' => 'resume', 'draft' => $dm[1] ?? '', 'last4' => '1111']);
ok(str_contains($dr2->body, 'do not match') && !str_contains($dr2->body, 'E2E Draft Person'), 'wrong digits: draft stays closed');
$dr2->req('/register', ['csrf' => $dr2->csrf(), 'act' => 'resume', 'draft' => $dm[1] ?? '', 'last4' => '7022']);
ok(str_contains($dr2->body, 'value="E2E Draft Person"') && str_contains($dr2->body, 'value="Ottawa"'), 'right digits: the form comes back filled in');
q("UPDATE intake_drafts SET fails=5 WHERE token_hash=?", [hash('sha256', $dm[1] ?? '')]);
$dr3 = new Browser(); $dr3->req('/register?draft=' . ($dm[1] ?? 'x')); ok(str_contains($dr3->body, 'locked after too many'), 'after 5 wrong tries the draft is locked');
q("UPDATE clients SET email='admin@dhanxfer.com' WHERE id=?", [$kc]);
$ret = new Browser(); $ret->req('/register?returning=1');
$ret->req('/register', ['csrf' => $ret->csrf(), 'act' => 'returning', 'client_no' => (string)val('SELECT client_no FROM clients WHERE id=?', [$kc]), 'dob' => '1988-02-03', 'last4' => '7020']);
$otpPage = $ret->body;
require_once __DIR__ . '/../inc/otp.php';
q('UPDATE client_otps SET code_hash=? WHERE client_id=? AND used_at IS NULL', [otp_hash($kc, 'returning', '864209'), $kc]);
$ret->req('/register', ['csrf' => $ret->csrf(), 'act' => 'returning_otp', 'code' => '864209']);
ok(str_contains($otpPage, 'a•') && !str_contains($otpPage, 'admin@dhanxfer') && str_contains($ret->body, 'Welcome back') && !preg_match('/E2EKYC|12 Main Street/', $otpPage . $ret->body), 'returning client is recognised (after an emailed code) and sees only masked hints');
snap('returning', $ret->body);
ok(!str_contains($ret->body, 'lives in') && !str_contains($ret->body, 'valid until') && !str_contains($ret->body, 'E2EKYC'), 'returning page shows no city or ID details, only the phone ending');
$cno12 = (string)val('SELECT client_no FROM clients WHERE id=?', [$kc]);
for ($i = 0; $i < 5; $i++) q('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', ['198.51.100.' . $i, 'returning:' . substr(hash('sha256', $cno12), 0, 40)]);
$ret2 = new Browser(); $ret2->req('/register?returning=1');
$ret2->req('/register', ['csrf' => $ret2->csrf(), 'act' => 'returning', 'client_no' => $cno12, 'dob' => '1988-02-03', 'last4' => '7020']);
ok(!str_contains($ret2->body, 'Welcome back'), 'after 5 wrong tries from other addresses the returning check for that client is locked');
for ($i = 0; $i < 12; $i++) q("INSERT INTO login_attempts (ip, username) VALUES (?, 'addr-lookup')", [(string)val("SELECT ip FROM login_attempts WHERE username='returning' ORDER BY id DESC LIMIT 1") ?: '0.0.0.0']);
$lg12 = new Browser(); $lg12->login('test.agent2'); $lg12->req('/');
ok(str_contains($lg12->body, 'Log out'), 'address lookups and returning-client checks never lock staff out of logging in');
// referral code = login ID; renaming keeps attribution
$ad12 = $sa12; $ad12->post('/?p=agents', ['id' => $a1['id'], 'act' => 'rename', 'new_username' => 'test.agent1x']);
ok(val('SELECT username FROM users WHERE id=?', [$a1['id']]) === 'test.agent1x' && (int)agent_by_code('test.agent1')['id'] === (int)$a1['id'], 'sub-agent renamed: the old code still credits the same agent');
$ad12->post('/?p=agents', ['id' => $a2['id'], 'act' => 'rename', 'new_username' => 'test.agent1']);
ok(val('SELECT username FROM users WHERE id=?', [$a2['id']]) === 'test.agent2', "another sub-agent cannot take the old code");
// a sub-agent edits identity details of their verified client: back to review
q("UPDATE clients SET kyc='Verified' WHERE id=?", [$kc]); q("UPDATE document_versions SET outcome='verified' WHERE client_id=?", [$kc]);
$ag12->post("/?p=client_form&id=$kc", ['name' => 'E2E KYC Person Changed', 'phone' => '+1 416 555 7020', 'country' => 'Canada', 'office' => 'Canada', 'dob' => '1988-02-03', 'address' => '12 Main Street', 'city' => 'Toronto', 'postal' => 'M5H 2N2', 'consent' => '1', 'dupe_ok' => '1']);
ok(val('SELECT kyc FROM clients WHERE id=?', [$kc]) === 'Pending', 'a sub-agent changing the name of a verified client sends it back to KYC review');
require_once __DIR__ . '/../inc/agents.php';
ok(ref_resolve('test.agent1x')['id'] == $a1['id'] && ref_resolve('test.agent1')['id'] == $a1['id'], 'after a rename the new short link works and the old /r/ link still credits the same agent');
q("UPDATE users SET username='test.agent1' WHERE id=?", [$a1['id']]); q("UPDATE agent_codes SET is_current=IF(code='test.agent1',1,0) WHERE agent_id=?", [$a1['id']]); q("DELETE FROM agent_codes WHERE code='test.agent1x'");
q("DELETE FROM referral_links WHERE slug='test.agent1x'"); q("UPDATE referral_links SET is_primary=1, status='active' WHERE slug='test.agent1'");
q("UPDATE users SET role='admin' WHERE username='test.admin'");

// ---- 13. beneficiaries, one identity per sender, one-time codes ----
require_once __DIR__ . '/../inc/beneficiary.php'; require_once __DIR__ . '/../inc/otp.php';
$fnB = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.jpg'; id_file_write($fnB, (string)file_get_contents($img)); $E2E_FILES[] = $fnB;
$vc13 = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'E2E Sender Verified', 'phone' => '+1 416 555 7030', 'office' => 'Canada', 'dob' => '1979-09-09', 'country' => 'Canada',
    'id_type' => 'Passport', 'id_number' => 'E2ESEND1', 'id_expiry' => date('Y-m-d', strtotime('+3 years')), 'id_has_address' => 'no']), ['agent_id' => $a1['id'], 'source' => 'agent', 'id_file' => $fnB]);
q("UPDATE clients SET kyc='Verified', phone_e164='+14165557030' WHERE id=?", [$vc13]); q("UPDATE document_versions SET outcome='verified' WHERE client_id=?", [$vc13]);
$oc13 = insert_client(array_merge(array_fill_keys(CLIENT_FIELDS, ''), ['name' => 'E2E Other Agent Client', 'phone' => '+1 416 555 7031', 'office' => 'Canada']), ['agent_id' => $a2['id'], 'source' => 'agent']);
$ag13 = new Browser(); $ag13->login('test.agent1');
$ag13->post("/?p=beneficiary&client=$vc13", ['act' => 'add', 'name' => 'E2E Benef One', 'relation' => 'Mother', 'country' => 'India', 'bank' => 'SBI', 'account' => 'E2E-ACC-0001']);
$eb1 = one("SELECT * FROM beneficiaries WHERE client_id=? AND name='E2E Benef One'", [$vc13]);
ok($eb1 && $eb1['status'] === 'pending' && str_contains($ag13->body, 'Waiting for review'), 'sub-agent adds a beneficiary for their client; it waits for review');
$ag13->post("/?p=beneficiary&client=$vc13", ['act' => 'add', 'name' => 'E2E Benef Copy', 'bank' => 'SBI', 'account' => 'e2e acc 0001']);
ok((int)val("SELECT COUNT(*) FROM beneficiaries WHERE client_id=?", [$vc13]) === 1 && str_contains($ag13->body, 'already saved'), 'the same account cannot be added twice');
$ag13->post("/?p=beneficiary&client=$oc13", ['act' => 'add', 'name' => 'E2E Sneaky', 'bank' => 'X', 'account' => 'E2E-ACC-9999']);
ok(!val("SELECT 1 FROM beneficiaries WHERE client_id=?", [$oc13]), "sub-agent cannot add a beneficiary to another agent's client");
$ag13->post("/?p=beneficiary&client=$vc13&id=" . $eb1['id'], ['act' => 'review', 'rev' => '0', 'decision' => 'approve', 'screening_ref' => 'X', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Agent trying to approve.']);
ok(val('SELECT status FROM beneficiaries WHERE id=?', [$eb1['id']]) === 'pending', 'sub-agent cannot approve beneficiaries');
$ag13->post('/?p=transfer_form', ['client_id' => $vc13, 'transfer_date' => date('Y-m-d'), 'send_amount' => '100', 'send_currency' => 'CAD', 'receive_currency' => 'INR', 'beneficiary_id' => $eb1['id']]);
ok(!val("SELECT 1 FROM transfers WHERE client_id=?", [$vc13]) && stripos($ag13->body, 'waiting for review') !== false, 'no transfer to a beneficiary that is still waiting for review');
$co13 = new Browser(); fresh_step_wait(); $co13->login('test.compliance');
$co13->req('/?p=compliance&v=benef'); ok(str_contains($co13->body, 'E2E Benef One'), 'beneficiary appears in the Compliance Center review list');
$co13->post("/?p=beneficiary&client=$vc13&id=" . $eb1['id'], ['act' => 'review', 'rev' => '0', 'decision' => 'approve', 'notes' => 'Checked with the client.']);
ok(val('SELECT status FROM beneficiaries WHERE id=?', [$eb1['id']]) === 'pending' && str_contains($co13->body, 'screening'), 'approval without a screening reference is refused');
$co13->post("/?p=beneficiary&client=$vc13&id=" . $eb1['id'], ['act' => 'review', 'rev' => '0', 'decision' => 'approve', 'screening_ref' => 'E2E-SCR-77', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Confirmed by phone with the client.']);
ok(val('SELECT status FROM beneficiaries WHERE id=?', [$eb1['id']]) === 'active', 'compliance approves the beneficiary');
snap('beneficiary', $co13->body);
$ag13->req('/?p=transfer_form&client=' . $vc13); ok(str_contains($ag13->body, 'E2E Benef One') && str_contains($ag13->body, 'name="beneficiary_id"'), 'transfer form offers only approved beneficiaries');
$ag13->post('/?p=transfer_form', ['client_id' => $vc13, 'transfer_date' => date('Y-m-d'), 'send_amount' => '100', 'send_currency' => 'CAD', 'receive_currency' => 'INR', 'beneficiary_id' => $eb1['id']]);
$tr13 = one('SELECT * FROM transfers WHERE client_id=?', [$vc13]);
ok($tr13 && (int)$tr13['beneficiary_id'] === (int)$eb1['id'] && !str_contains((string)$tr13['beneficiary'], 'E2E-ACC-0001'), 'transfer request names the beneficiary (account masked in the transfer record)');
q("INSERT INTO beneficiaries (client_id, name, status) VALUES (?, 'E2E Foreign Benef', 'active')", [$oc13]); $foreign = (int)db()->lastInsertId();
$ag13->post('/?p=transfer_form', ['client_id' => $vc13, 'transfer_date' => date('Y-m-d'), 'send_amount' => '100', 'send_currency' => 'CAD', 'receive_currency' => 'INR', 'beneficiary_id' => $foreign]);
ok((int)val('SELECT COUNT(*) FROM transfers WHERE client_id=?', [$vc13]) === 1, "a beneficiary of another client cannot be used by changing the id");
$ag13->post("/?p=beneficiary&client=$vc13&id=" . $eb1['id'], ['act' => 'edit', 'name' => 'E2E Benef One', 'relation' => 'Mother', 'country' => 'India', 'bank' => 'HDFC', 'account' => 'E2E-ACC-0002']);
$eb1n = one('SELECT * FROM beneficiaries WHERE replaced_by IS NULL AND client_id=? AND name=? AND id<>?', [$vc13, 'E2E Benef One', $eb1['id']]);
$eb1o = one('SELECT * FROM beneficiaries WHERE id=?', [$eb1['id']]);
ok($eb1n && $eb1n['status'] === 'pending' && $eb1o['status'] === 'deactivated' && (int)$eb1o['replaced_by'] === (int)$eb1n['id'] && dec($eb1o['account']) === 'E2E-ACC-0001' && dec($eb1n['account']) === 'E2E-ACC-0002',
   'changing the bank account makes a new version for review; the approved details stay unchanged');
$co13->req("/?p=beneficiary&client=$vc13&id=" . $eb1n['id']); $revSeen = preg_match('/name="rev" value="(\d+)"/', $co13->body, $rm) ? $rm[1] : '';
q('UPDATE beneficiaries SET rev=rev+1 WHERE id=?', [$eb1n['id']]); // the agent changed it again while compliance had the page open
$co13->post("/?p=beneficiary&client=$vc13&id=" . $eb1n['id'], ['act' => 'review', 'rev' => $revSeen, 'decision' => 'approve', 'screening_ref' => 'E2E-SCR-78', 'verification' => BENEF_VERIFY_METHODS[0], 'notes' => 'Approving the page I saw.']);
ok($revSeen === '0' && val('SELECT status FROM beneficiaries WHERE id=?', [$eb1n['id']]) === 'pending' && str_contains($co13->body, 'changed while you were reviewing'), 'compliance cannot approve details that changed after the page was opened');
$sa13 = new Browser(); q("UPDATE users SET role='super_admin' WHERE username='test.admin'"); fresh_step_wait(); $sa13->login('test.admin');
$sa13->post('/?p=transfers', ['id' => $tr13['id'], 'act' => 'accept']);
ok(val('SELECT status FROM transfers WHERE id=?', [$tr13['id']]) === 'pending', 'a pending transfer cannot be accepted after its beneficiary changed (re-checked at acceptance)');
$sa13->post("/?p=beneficiary&client=$vc13&id=" . $eb1n['id'], ['act' => 'deactivate', 'reason' => 'E2E: client asked to remove']);
ok(val('SELECT status FROM beneficiaries WHERE id=?', [$eb1n['id']]) === 'deactivated'
   && implode(',', array_column(all('SELECT action FROM beneficiary_events WHERE beneficiary_id IN (?,?) ORDER BY id', [$eb1['id'], $eb1n['id']]), 'action')) === 'added,approved,added,replaced,edited,deactivated',
   'beneficiary deactivated with a reason; full history kept for both versions');
// a request made before beneficiaries were reviewed: the decider links it to an approved beneficiary when accepting
q("INSERT INTO beneficiaries (client_id, name, bank, status, created_by) VALUES (?, 'E2E Legacy Benef', 'SBI', 'active', NULL)", [$vc13]); $lb13 = (int)db()->lastInsertId();
q("INSERT INTO transfers (ref_no, client_id, client_name, agent_id, beneficiary, send_amount, send_currency, status, transfer_date, requested_by) VALUES ('E2E-LEGACY-1', ?, 'E2E Sender Verified', ?, 'Legacy text', 50, 'CAD', 'pending', ?, ?)", [$vc13, $a1['id'], today(), $a1['id']]); $lt13 = (int)db()->lastInsertId();
$sa13->req('/?p=transfers&id=' . $lt13);
$sa13->post('/?p=transfers', ['id' => $lt13, 'act' => 'accept']);
$okNo = val('SELECT status FROM transfers WHERE id=?', [$lt13]) === 'pending';
$sa13->req('/?p=transfers&id=' . $lt13);
$sa13->post('/?p=transfers', ['id' => $lt13, 'act' => 'accept', 'beneficiary_id' => $lb13]);
$lt = one('SELECT * FROM transfers WHERE id=?', [$lt13]);
ok($okNo && $lt['status'] === 'accepted' && (int)$lt['beneficiary_id'] === $lb13, 'an older request without a linked beneficiary is accepted only after an approved beneficiary is chosen');
q("UPDATE beneficiaries SET status='deactivated' WHERE id=?", [$lb13]);
$sa13->req('/?p=transfers&id=' . $lt13);
$sa13->post('/?p=transfers', ['id' => $lt13, 'act' => 'complete', 'receipt_no' => 'E2E-R1']);
ok(val('SELECT status FROM transfers WHERE id=?', [$lt13]) === 'accepted', 'an accepted transfer cannot be marked paid after its beneficiary was deactivated or changed');
q("UPDATE users SET role='admin' WHERE username='test.admin'");
// several beneficiaries at registration
$pf13 = new Browser(); $pf13->req('/register');
$pf13->req('/register', ['csrf' => $pf13->csrf(), 'name' => 'E2E Multi Benef', 'country' => 'Canada', 'phone' => '416 555 7032', 'consent' => '1',
    'b_name' => ['E2E Mum', 'E2E Dad', 'E2E Sister'], 'b_country' => ['India', 'India', 'Canada'], 'b_bank' => ['SBI', 'PNB', ''], 'b_account' => ['M-1001', 'D-2002', ''], 'b_relation' => ['Mother', 'Father', 'Sister'], 'b_phone' => ['', '', '']]);
$ms = one("SELECT * FROM submissions WHERE name='E2E Multi Benef' ORDER BY id DESC");
ok($ms && count(json_decode((string)dec($ms['data']), true)['beneficiaries'] ?? []) === 3, 'public form takes several beneficiaries at registration');
$st13 = new Browser(); $st13->login('test.staff');
$st13->post('/?p=submissions', ['id' => $ms['id'], 'act' => 'approve', 'agent_id' => '']);
$mc = (int)val('SELECT client_id FROM submissions WHERE id=?', [$ms['id']]);
ok($mc && (int)val("SELECT COUNT(*) FROM beneficiaries WHERE client_id=? AND status='pending'", [$mc]) === 3, 'approved registration: client created with all 3 beneficiaries waiting for review');
// one identity per sender
$ag13->post('/?p=client_form', ['name' => 'E2E Same ID Person', 'phone' => '+1 416 555 7033', 'country' => 'Canada', 'office' => 'Canada', 'id_type' => 'Passport', 'id_number' => 'E2ESEND1', 'consent' => '1', 'dupe_ok' => '1']);
ok(!val("SELECT 1 FROM clients WHERE name='E2E Same ID Person'") && str_contains($ag13->body, 'already belongs to another client'), 'a second client with the same ID number is refused: every sender keeps their own KYC identity');
// one-time code for returning clients
q("UPDATE clients SET email=NULL WHERE id=?", [$vc13]);
$rt = new Browser(); $rt->req('/register?returning=1');
$cno13 = (string)val('SELECT client_no FROM clients WHERE id=?', [$vc13]);
$rt->req('/register', ['csrf' => $rt->csrf(), 'act' => 'returning', 'client_no' => $cno13, 'dob' => '1979-09-09', 'last4' => '7030']);
ok(str_contains($rt->body, 'no verified email') && !str_contains($rt->body, 'Welcome back'), 'returning client without a verified email or phone cannot self-serve (sent to staff)');
q("UPDATE clients SET email='admin@dhanxfer.com' WHERE id=?", [$vc13]);
$rt->req('/register', ['csrf' => $rt->csrf(), 'act' => 'returning', 'client_no' => $cno13, 'dob' => '1979-09-09', 'last4' => '7030']);
ok(str_contains($rt->body, 'Enter your code') && str_contains($rt->body, 'a•') && !str_contains($rt->body, 'admin@dhanxfer.com'), 'after the identity check a one-time code is emailed (address shown masked)');
snap('otp', $rt->body);
q('UPDATE client_otps SET code_hash=? WHERE client_id=? AND used_at IS NULL', [otp_hash($vc13, 'returning', '246810'), $vc13]); // the code the client reads in the email
$rt->req('/register', ['csrf' => $rt->csrf(), 'act' => 'returning_otp', 'code' => '135791']);
ok(str_contains($rt->body, 'did not work') && !str_contains($rt->body, 'Welcome back'), 'wrong code refused');
$rt->req('/register', ['csrf' => $rt->csrf(), 'act' => 'returning_otp', 'code' => '246810']);
ok(str_contains($rt->body, 'Welcome back'), 'right code: the client can send their update');
$rt->req('/register', ['csrf' => $rt->csrf(), 'act' => 'returning_update', 'changes' => ['beneficiary'], 'b_name' => ['E2E New Cousin', 'E2E New Uncle'], 'b_bank' => ['ICICI', 'BOB'], 'b_account' => ['C-3003', 'U-4004'], 'b_country' => ['India', 'India'], 'b_relation' => ['', ''], 'b_phone' => ['', ''], 'consent' => '1']);
$ru = one('SELECT * FROM submissions WHERE client_id_returning=? ORDER BY id DESC', [$vc13]);
$st13->post('/?p=submissions', ['id' => $ru['id'] ?? 0, 'act' => 'approve']);
ok((int)val("SELECT COUNT(*) FROM beneficiaries WHERE client_id=? AND status='pending' AND name LIKE 'E2E New %'", [$vc13]) === 2, 'returning client adds two beneficiaries; after staff approval both wait for compliance review');
// upload link now also asks for a code when an email is on file
$co13->post("/?p=client&id=$vc13", ['act' => 'upload_link']);
preg_match('#/upload\?t=([a-f0-9]{48})#', $co13->body, $um13);
$up13 = new Browser(); $up13->req('/upload?t=' . ($um13[1] ?? 'x'));
$up13->req('/upload', ['csrf' => $up13->csrf(), 'act' => 'verify', 't' => $um13[1] ?? '', 'dob' => '1979-09-09', 'last4' => '7030']);
ok(str_contains($up13->body, 'Enter your code') && !str_contains($up13->body, 'Upload your current ID'), 'upload link: with an email on file, a one-time code is needed before the upload form');
q('UPDATE client_otps SET code_hash=? WHERE client_id=? AND purpose=? AND used_at IS NULL', [otp_hash($vc13, 'upload', '975310'), $vc13, 'upload']);
$up13->req('/upload', ['csrf' => $up13->csrf(), 'act' => 'otp', 't' => $um13[1] ?? '', 'code' => '975310']);
ok(str_contains($up13->body, 'Upload your current ID'), 'right code opens the upload form');
// ---- 14. Sub-agent Control Center and short referral links /r/{username} ----
require_once __DIR__ . '/../inc/agents.php';
q("UPDATE submissions SET created_at = created_at - INTERVAL 2 HOUR WHERE name LIKE 'E2E %'"); // keep the per-connection form limit for this section
$adm14 = new Browser(); fresh_step_wait(); $adm14->login('test.admin');
$adm14->post('/?p=agents', ['act' => 'create', 'role' => 'agent', 'name' => 'E2E Agent X', 'username' => 'e2e.agentx', 'phone' => '+1 416 555 7090', 'office' => 'Canada']);
$ax = one("SELECT * FROM users WHERE username='e2e.agentx'");
ok($ax && $ax['account_status'] === 'pending' && val("SELECT slug FROM referral_links WHERE agent_id=? AND is_primary=1", [$ax['id']]) === 'e2e.agentx', 'new sub-agent starts as pending activation with the link /r/e2e.agentx');
$vp = new Browser(); $vp->req('/r/e2e.agentx', null, false);
ok(in_array($vp->code, [302, 303], true) && str_ends_with($vp->loc, '/register') && !str_contains($vp->loc, '?'), 'a pending agent link opens the plain form (no credit, no token in the address)');
$adm14->post('/?p=agents', ['act' => 'create', 'role' => 'agent', 'name' => 'E2E Admin Name', 'username' => 'admin']);
ok(!val("SELECT 1 FROM users WHERE username='admin'"), 'a reserved name cannot become a sub-agent login or link');
// activation (password chosen through the one-time link) makes the account and link active
$axPw = 'E2E-Agent-X-' . bin2hex(random_bytes(4));
$tok = bin2hex(random_bytes(24)); q('INSERT INTO password_resets (user_id, token_hash, expires_at, purpose) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR),?)', [$ax['id'], hash('sha256', $tok), 'activate']);
$act14 = new Browser(); $act14->req('/reset.php?t=' . $tok); $act14->req('/reset.php?t=' . $tok, ['csrf' => $act14->csrf(), 'password' => $axPw, 'password2' => $axPw]);
ok(val('SELECT account_status FROM users WHERE id=?', [$ax['id']]) === 'active', 'activation link sets the password and makes the sub-agent active');
$T['e2e.agentx'] = ['password' => $axPw];
// a visitor follows the short link (any letter case): clean redirect to the form, credit remembered on the server only
$v1 = new Browser(); $v1->req('/r/E2E.AgentX', null, false);
$loc1 = $v1->loc; $v1->req('/register');
ok(str_ends_with($loc1, '/register') && str_contains($v1->body, 'e2e.agentx') && str_contains($v1->body, 'width=device-width'), 'short link (any letter case) redirects to the mobile registration form with the referral shown');
$v1->req('/register', ['csrf' => $v1->csrf(), 'name' => 'E2E Short Link Person', 'country' => 'Canada', 'phone' => '416 555 7091', 'consent' => '1', 'ref_code' => 'test.agent2']);
$s14 = one("SELECT * FROM submissions WHERE name='E2E Short Link Person' ORDER BY id DESC");
ok($s14 && (int)$s14['agent_id'] === (int)$ax['id'] && (int)$s14['ref_verified'] === 1 && (int)$s14['ref_link_id'] === (int)val("SELECT id FROM referral_links WHERE slug='e2e.agentx'"), 'the form is credited to the agent by internal id and link id; a typed code cannot change it');
// staff cannot move a link-proven referral to another agent at approval
$st14 = new Browser(); $st14->login('test.staff');
$st14->post('/?p=submissions', ['id' => $s14['id'], 'act' => 'approve', 'agent_id' => (string)$a2['id']]);
ok((int)val('SELECT agent_id FROM clients WHERE id=?', [(int)val('SELECT client_id FROM submissions WHERE id=?', [$s14['id']])]) === (int)$ax['id'], 'at approval a link-proven referral stays with its agent (posted agent ignored)');
// the agent sees their short link with share buttons
$agx = new Browser(); $agx->login('e2e.agentx');
ok(str_contains($agx->body, '/r/e2e.agentx') && str_contains($agx->body, 'wa.me/') && str_contains($agx->body, 'data-share='), "agent's home shows /r/e2e.agentx with Share, WhatsApp and Copy");
// manager with two-step login, assigned to agent X only
$mSec = mfa_new_secret(); $mPw = 'E2E-Manager-' . bin2hex(random_bytes(4));
q("INSERT INTO users (role, username, name, password_hash, must_change_password, mfa_secret, mfa_enabled, mfa_token_only) VALUES ('manager','e2e.manager','E2E Manager',?,0,?,1,0)", [password_hash($mPw, PASSWORD_DEFAULT), enc($mSec)]);
$mid = (int)db()->lastInsertId(); $T['e2e.manager'] = ['password' => $mPw, 'mfa_secret' => $mSec];
$adm14->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'assign', 'manager_id' => (string)$mid, 'reason' => '']);
ok(!(int)val('SELECT manager_id FROM users WHERE id=?', [$ax['id']]), 'assigning a manager needs a reason');
$adm14->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'assign', 'manager_id' => (string)$mid, 'reason' => 'E2E Toronto team']);
ok((int)val('SELECT manager_id FROM users WHERE id=?', [$ax['id']]) === $mid, 'admin assigns the sub-agent to the manager');
$mg = new Browser(); fresh_step_wait(); $mg->login('e2e.manager'); $mg->req('/');
ok(str_contains($mg->body, 'Sub-agent Control Center') && str_contains($mg->body, 'E2E Agent X') && !str_contains($mg->body, 'test.agent1'), 'manager lands on the Control Center and sees only their own sub-agent');
$mg->req('/?p=subagents&id=' . $a1['id']); ok(!str_contains($mg->body, 'Portal access'), "manager cannot open another team's sub-agent");
foreach (['clients' => 'E2E Client One', 'agents' => 'Add a person', 'roles' => 'Job roles', 'submissions' => 'E2E Short Link', 'compliance' => 'Compliance Center'] as $pg => $needle) { $mg->req("/?p=$pg"); ok(!str_contains($mg->body, $needle), "manager cannot see $pg"); }
$mg->post('/?p=subagents', ['id' => $a1['id'], 'act' => 'suspend', 'reason' => 'Trying another team']);
ok(val('SELECT account_status FROM users WHERE id=?', [$a1['id']]) === 'active', "manager cannot suspend another team's sub-agent");
$mg->post('/?p=subagents', ['id' => $a1['id'], 'act' => 'assign', 'manager_id' => (string)$mid, 'reason' => 'Taking this agent']);
$mg->post('/?p=subagents', ['id' => (int)val("SELECT id FROM users WHERE username='test.admin'"), 'act' => 'suspend', 'reason' => 'Escalation attempt']);
ok(!(int)val('SELECT manager_id FROM users WHERE id=?', [$a1['id']]) && val("SELECT account_status FROM users WHERE username='test.admin'") === 'active', 'manager cannot take over agents or touch admin accounts (no escalation)');
$mg->post('/?p=agents', ['id' => $mid, 'act' => 'role', 'role' => 'admin']); $mg->post('/?p=roles', ['role' => 'manager', 'perm' => ['team.agents']]);
ok(val('SELECT role FROM users WHERE id=?', [$mid]) === 'manager' && !role_can('manager', 'team.agents'), 'manager cannot raise their own role or permissions');
// a visitor opened the link before the suspension and sends the form after it: no credit
$v2 = new Browser(); $v2->req('/r/e2e.agentx'); $v2->req('/register');
$mg->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'suspend', 'reason' => 'E2E complaint under review']);
$agx->req('/?p=clients', null, false);
ok(val('SELECT account_status FROM users WHERE id=?', [$ax['id']]) === 'suspended' && in_array($agx->code, [302, 303], true) && str_contains($agx->loc, 'login'), "suspension ends the agent's open session at once");
$v2->req('/register', ['csrf' => $v2->csrf(), 'name' => 'E2E After Suspend', 'country' => 'Canada', 'phone' => '416 555 7092', 'consent' => '1']);
ok(($as = one("SELECT * FROM submissions WHERE name='E2E After Suspend' ORDER BY id DESC")) && !$as['agent_id'] && !(int)$as['ref_verified'], 'a form sent after the suspension is not credited, even if the link was opened before');
$v3 = new Browser(); $v3->req('/r/e2e.agentx'); $v3->req('/register'); ok(!str_contains($v3->body, 'e2e.agentx'), 'the short link of a suspended agent credits nobody');
$agx2 = new Browser(); $agx2->login('e2e.agentx'); ok(str_contains($agx2->body, 'switched off') && !str_contains($agx2->body, 'Welcome,'), 'a suspended agent cannot log in');
ok((int)val("SELECT agent_id FROM clients WHERE id=?", [(int)val('SELECT client_id FROM submissions WHERE id=?', [$s14['id']])]) === (int)$ax['id'], 'the client referred before the suspension stays credited to the agent');
// reactivate, switch off one link, regenerate
$mg->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'reactivate', 'reason' => 'E2E review closed']);
$lnk = (int)val("SELECT id FROM referral_links WHERE slug='e2e.agentx'");
$mg->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'link_disable', 'link' => (string)$lnk, 'reason' => 'E2E link posted publicly']);
$v4 = new Browser(); $v4->req('/r/e2e.agentx'); $v4->req('/register');
ok(val('SELECT account_status FROM users WHERE id=?', [$ax['id']]) === 'active' && val('SELECT status FROM referral_links WHERE id=?', [$lnk]) === 'disabled' && !str_contains($v4->body, 'e2e.agentx'), 'manager reactivates; a switched-off link credits nobody while the account stays active');
$mg->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'link_regenerate', 'slug' => 'e2e.agentx2', 'reason' => 'E2E new link after misuse']);
$v5 = new Browser(); $v5->req('/r/e2e.agentx2'); $v5->req('/register');
ok(str_contains($v5->body, 'e2e.agentx') && val("SELECT status FROM referral_links WHERE id=?", [$lnk]) === 'retired', 'regenerated link /r/e2e.agentx2 works; the old one is retired');
$adm14->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'link_enable', 'link' => (string)$lnk, 'reason' => 'E2E try to revive retired link']);
ok(val("SELECT status FROM referral_links WHERE id=?", [$lnk]) === 'retired', 'a retired link cannot be switched back on');
// limits are enforced on the server
$mg->post('/?p=subagents', ['id' => $ax['id'], 'act' => 'limits', 'can_transfers' => '1', 'reason' => 'E2E no new clients for now']);
$agx3 = new Browser(); $agx3->login('e2e.agentx'); $agx3->req('/?p=client_form');
ok(!str_contains($agx3->body, 'name="name"') && (int)val('SELECT agent_can_clients FROM users WHERE id=?', [$ax['id']]) === 0, 'an agent whose client rights are switched off cannot open or post the client form');
$evs14 = array_column(all('SELECT action FROM agent_events WHERE agent_id=? ORDER BY id', [$ax['id']]), 'action');
ok(implode(',', $evs14) === 'created,assigned,suspended,reactivated,link_disabled,link_regenerated,limits' && (int)val("SELECT COUNT(*) FROM agent_events WHERE agent_id=? AND (reason IS NULL OR reason='') AND action<>'created'", [$ax['id']]) === 0, 'every restriction is in the control history with its reason');
$mg->req('/?p=subagents&id=' . $ax['id']); snap('subagents', $mg->body);

// ---- 15. commission wallet and manual withdrawals ----
require_once __DIR__ . '/../inc/wallet.php';
q("DELETE FROM commission_ledger WHERE agent_id=?", [$a1['id']]); q("DELETE FROM withdrawal_events WHERE withdrawal_id IN (SELECT id FROM commission_withdrawals WHERE agent_id=?)", [$a1['id']]); q("DELETE FROM commission_withdrawals WHERE agent_id=?", [$a1['id']]);
q("INSERT INTO transfers (ref_no, client_name, agent_id, send_amount, send_currency, receive_currency, status, transfer_date, commission_per_unit, commission, commission_currency, commission_fx_rate, commission_fx_date, commission_fx_source, commission_cad, commission_locked_at)
   VALUES ('E2E-W1','E2E Wallet',?,84000,'CAD','INR','completed',CURDATE(),0.5,42000,'INR',60,CURDATE(),'market test',700,NOW())", [$a1['id']]);
$wag = new Browser(); $wag->login('test.agent1'); $wag->req('/?p=commission');
ok(str_contains($wag->body, 'CAD 700.00') && str_contains($wag->body, 'Request withdrawal') && str_contains($wag->body, 'CAD 500.00'), "agent's wallet shows the locked CAD earnings and the CAD 500 first minimum");
// two withdrawal requests for most of the balance sent at the same moment from two sessions: only one may reserve
$wb1 = new Browser(); $wb1->login('test.agent1'); $wb1->req('/?p=commission'); $wb2 = new Browser(); $wb2->login('test.agent1'); $wb2->req('/?p=commission');
$mh = curl_multi_init(); $hs = [];
foreach ([$wb1, $wb2] as $bw) { $ch = curl_init($BASE . '/?p=commission'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf' => $bw->csrf(), 'act' => 'withdraw', 'amount' => '600', 'note' => '']), CURLOPT_COOKIEFILE => $bw->jar, CURLOPT_COOKIEJAR => $bw->jar, CURLOPT_TIMEOUT => 30]); curl_multi_add_handle($mh, $ch); $hs[] = $ch; }
do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh); } while ($run && $st === CURLM_OK);
foreach ($hs as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); } curl_multi_close($mh);
$bal15 = wallet_balance((int)$a1['id']);
ok((int)val("SELECT COUNT(*) FROM commission_withdrawals WHERE agent_id=?", [$a1['id']]) === 1 && $bal15['reserved'] == 600.0 && $bal15['available'] == 100.0, 'two requests at the same moment: exactly one reserves the money (no over-withdrawal)');
$w15 = (int)val('SELECT id FROM commission_withdrawals WHERE agent_id=?', [$a1['id']]);
$wag->post('/?p=withdrawals', ['id' => (string)$w15, 'act' => 'move', 'to' => 'approved', 'reason' => 'Agent approving own']);
ok(val('SELECT status FROM commission_withdrawals WHERE id=?', [$w15]) === 'requested', 'the sub-agent cannot change the status of their own withdrawal');
$adw = new Browser(); fresh_step_wait(); $adw->login('test.admin');
$adw->post('/?p=withdrawals', ['id' => (string)$w15, 'act' => 'move', 'to' => 'under_review', 'reason' => 'E2E checking orders']);
$adw->post('/?p=withdrawals', ['id' => (string)$w15, 'act' => 'move', 'to' => 'approved', 'reason' => 'E2E orders verified']);
$adw->post('/?p=withdrawals', ['id' => (string)$w15, 'act' => 'move', 'to' => 'processing', 'reason' => 'E2E admin tries finance step']);
ok(val('SELECT status FROM commission_withdrawals WHERE id=?', [$w15]) === 'approved', 'admin reviews and approves; processing needs finance');
q("UPDATE users SET role='super_admin' WHERE username='test.admin'");
$sw = new Browser(); fresh_step_wait(); $sw->login('test.admin');
$sw->post('/?p=withdrawals', ['id' => (string)$w15, 'act' => 'move', 'to' => 'processing', 'reason' => 'E2E sent by Interac']);
$sw->post('/?p=withdrawals', ['id' => (string)$w15, 'act' => 'move', 'to' => 'paid', 'reason' => 'E2E paid', 'payment_date' => date('Y-m-d'), 'reference' => 'E2E-REF-' . $w15]);
ok(val('SELECT status FROM commission_withdrawals WHERE id=?', [$w15]) === 'processing', 'Paid without the payment confirmation tick is refused');
$sw->req('/?p=withdrawals&id=' . $w15); snap('withdrawal_admin', $sw->body);
$sw->post('/?p=withdrawals', ['id' => (string)$w15, 'act' => 'move', 'to' => 'paid', 'reason' => 'E2E paid', 'confirm' => '1', 'payment_date' => date('Y-m-d'), 'reference' => 'E2E-REF-' . $w15, 'method' => 'Interac']);
$bal15 = wallet_balance((int)$a1['id']);
ok(val('SELECT status FROM commission_withdrawals WHERE id=?', [$w15]) === 'paid' && $bal15['paid'] == 600.0 && $bal15['reserved'] == 0.0 && wd_verify($w15) === null, 'finance marks it Paid with date and reference; ledger and history agree');
q("UPDATE users SET role='admin' WHERE username='test.admin'");
$wag->req('/?p=commission'); snap('agent_wallet', $wag->body);
ok(str_contains($wag->body, 'E2E-REF-' . $w15) && str_contains($wag->body, 'CAD 100.00') && str_contains($wag->body, 'Minimum: CAD 100.00'), 'the agent sees the payout and the CAD 100 minimum after the first paid withdrawal');
q("DELETE FROM transfers WHERE ref_no='E2E-W1'");

// ---- 10. phone-size layout snapshots (synthetic staging data) ----
$m1 = new Browser(); $m1->login('test.agent1');
foreach (['agent_home' => '/', 'agent_clients' => '/?p=clients', 'agent_new_client' => '/?p=client_form', 'agent_rates' => '/?p=rates', 'agent_transfers' => '/?p=transfers', 'agent_commission' => '/?p=commission', 'agent_transfer_new' => '/?p=transfer_form'] as $k => $pth) { $m1->req($pth); snap($k, $m1->body); }
$m2 = new Browser(); $m2->req('/register?ref=SA-9001&s=' . ref_sig('SA-9001')); snap('public_register', $m2->body);
$m2->req('/status'); snap('public_status', $m2->body); $m2->req('/login.php'); snap('login', $m2->body);
$m3 = new Browser(); $m3->login('test.auditor'); $m3->req('/?p=clients'); snap('auditor_clients', $m3->body); $m3->req('/?p=transfers'); snap('auditor_transfers', $m3->body);
$bundle = '';
foreach ($SNAP as $k => $h) {
    $h = preg_replace('/name="csrf" value="[a-f0-9]+"/', 'name="csrf" value="x"', $h);
    $h = preg_replace('/>\s+</', '><', preg_replace('/[ \t]+/', ' ', $h));
    $bundle .= "=====PAGE $k=====\n" . trim($h) . "\n";
}
@file_put_contents(data_dir() . '/snapshots.txt', $bundle);
$lite = '';
foreach (['beneficiary', 'otp', 'register_new', 'subagents', 'agent_wallet', 'withdrawal_admin'] as $k) {
    if (!isset($SNAP[$k])) continue;
    $h = preg_replace('/name="csrf" value="[a-f0-9]+"/', 'name="csrf" value="x"', $SNAP[$k]);
    $h = preg_replace('#<head>.*?</head>#s', '<!--HEAD-->', $h);
    $h = preg_replace('/>\s+</', '><', preg_replace('/\s+/', ' ', $h));
    $lite .= "=====PAGE $k=====\n" . trim($h) . "\n";
}
@file_put_contents(data_dir() . '/snapshots-lite.txt', $lite);
ok(strlen($bundle) > 1000, 'phone layout snapshots saved (' . count($SNAP) . ' pages, ' . strlen($bundle) . ' bytes)');
exit($fail ? 1 : 0);
