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

$SNAP = [];
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
    q("DELETE FROM transfers WHERE ref_no='E2E-T2'"); q("DELETE FROM clients WHERE name IN ('E2E Client One','E2E Client Two')");
    q("DELETE FROM users WHERE username IN ('e2e.legacy','e2e.staffx','e2e.v2owner')"); q('DELETE FROM login_attempts');
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
ok(preg_match('/register\?ref=SA-9001&amp;s=[a-f0-9]{16}/', $ag->body) === 1, 'sub-agent share link is signed');

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
$ad->post('/?p=transfers', ['id' => $t2, 'act' => 'accept']); ok(val('SELECT status FROM transfers WHERE id=?', [$t2]) === 'accepted', 'admin can accept a transfer');

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
$co->post("/?p=client&id=$c1", ['act' => 'kyc', 'kyc' => 'Verified']); ok(val('SELECT kyc FROM clients WHERE id=?', [$c1]) === 'Verified', 'compliance officer can verify KYC');
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
$pf->req('/register', ['csrf' => $pf->csrf(), 'name' => 'E2E Public', 'phone' => '+1 416 555 7009', 'office' => 'Canada', 'consent' => '1', 'ref_code' => 'SA-9002']);
$sub = one("SELECT * FROM submissions WHERE phone='+1 416 555 7009' ORDER BY id DESC");
ok($sub && $sub['ref_code'] === 'SA-9001' && (int)$sub['ref_verified'] === 1 && (int)$sub['agent_id'] === (int)$a1['id'], 'posting a different code cannot change a signed referral');
ok($sub && str_starts_with((string)$sub['data'], 'enc:v1:'), 'online form stored encrypted');
preg_match('/DXA-[0-9A-Z-]+/', $pf->body, $m); $ref = $m[0] ?? '';
ok($ref !== '', 'client gets an application reference', substr(strip_tags($pf->body), 0, 200));
$sp = new Browser(); $sp->req('/status'); $sp->req('/status', ['csrf' => $sp->csrf(), 'ref' => $ref, 'last4' => '7009']);
ok(str_contains($sp->body, 'Received') && !str_contains($sp->body, 'KYC') && !str_contains($sp->body, 'risk'), 'status page shows a plain status, no KYC or risk details');
$sp->req('/status', ['csrf' => $sp->csrf(), 'ref' => $ref, 'last4' => '0000']); ok(str_contains($sp->body, 'could not find'), 'wrong phone digits reveal nothing');
$pt = new Browser(); $pt->req('/register?ref=SA-9002&s=0000000000000000'); $pt->req('/register', ['csrf' => $pt->csrf(), 'name' => 'E2E Tampered', 'phone' => '+1 416 555 7010', 'office' => 'Canada', 'consent' => '1', 'ref_code' => 'SA-9002']);
$sub2 = one("SELECT * FROM submissions WHERE phone='+1 416 555 7010'");
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
foreach (['agent_home', 'agent_clients', 'agent_new_client', 'agent_transfer_new', 'public_register', 'public_status', 'auditor_transfers'] as $k) {
    if (!isset($SNAP[$k])) continue;
    $h = preg_replace('/name="csrf" value="[a-f0-9]+"/', 'name="csrf" value="x"', $SNAP[$k]);
    $h = preg_replace('#<head>.*?</head>#s', '<!--HEAD-->', $h);
    $h = preg_replace('/>\s+</', '><', preg_replace('/\s+/', ' ', $h));
    $lite .= "=====PAGE $k=====\n" . trim($h) . "\n";
}
@file_put_contents(data_dir() . '/snapshots-lite.txt', $lite);
ok(strlen($bundle) > 1000, 'phone layout snapshots saved (' . count($SNAP) . ' pages, ' . strlen($bundle) . ' bytes)');
exit($fail ? 1 : 0);
