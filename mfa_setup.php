<?php
// First-time two-step login setup for roles that require it. The person has proved their password
// (or holds an admin-issued link) but gets no working session until a code from the app is confirmed.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/mfa.php';
strict_csp();

$uid = (int)($_SESSION['enroll_uid'] ?? 0);
if (!$uid || time() - (int)($_SESSION['enroll_at'] ?? 0) > 900) { unset($_SESSION['enroll_uid'], $_SESSION['enroll_setup']); flash('The setup time ran out. Log in again.', 'warn'); redirect('/login.php'); }
$user = one('SELECT * FROM users WHERE id=? AND active=1', [$uid]);
if (!$user || $user['mfa_enabled']) { unset($_SESSION['enroll_uid']); redirect('/login.php'); }
if ($user['mfa_token_only'] && empty($_SESSION['enroll_token'])) { unset($_SESSION['enroll_uid']); flash('Ask the super admin for a link to set up two-step login.', 'bad'); redirect('/login.php'); }
if (empty($_SESSION['enroll_setup'])) $_SESSION['enroll_setup'] = mfa_new_secret();
$secret = $_SESSION['enroll_setup'];
$codes = null; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($lock = mfa_locked($uid)) { $err = $lock; }
    elseif (($step = totp_match($secret, post('code'))) !== null) {
        $codes = mfa_recovery_codes();
        q('UPDATE users SET mfa_secret=?, mfa_enabled=1, mfa_recovery=?, mfa_last_step=?, mfa_token_only=0, last_login=NOW() WHERE id=? AND mfa_enabled=0',
            [enc($secret), json_encode(array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $codes)), $step, $uid]);
        unset($_SESSION['enroll_uid'], $_SESSION['enroll_at'], $_SESSION['enroll_setup'], $_SESSION['enroll_token']);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid; $_SESSION['sv'] = (int)$user['session_version']; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        audit_write($uid, 'mfa_enabled', 'user', $uid, 'first setup', client_ip());
        if (($_SESSION['claim_super'] ?? 0) === $uid) {
            unset($_SESSION['claim_super']);
            $n = q("UPDATE users SET role='super_admin' WHERE id=? AND role='admin' AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE role='super_admin') x)", [$uid])->rowCount();
            if ($n) audit_write($uid, 'super_admin_claimed', 'user', $uid, 'Verified with install key, password and new two-step login', client_ip());
        }
        audit_write($uid, 'login', 'user', $uid, 'with two-step code', client_ip());
    } else { mfa_fail($uid); $err = 'That code did not match. Check the time on your phone and use the newest code.'; }
}
$uri = 'otpauth://totp/' . rawurlencode('DhanXfer:' . $user['username']) . '?secret=' . $secret . '&issuer=DhanXfer&digits=6&period=30';
page_head('Set up two-step login'); ?>
<div class="login card" style="max-width:520px">
  <h1 style="margin-bottom:6px">Set up two-step login</h1>
  <?php if ($codes): ?>
    <div class="banner ok">Two-step login is on. From now on you enter a code from your app each time you log in.</div>
    <h3>Your recovery codes</h3>
    <p class="tag">Each code works once if you lose your phone. Write them down or save them somewhere safe now; they are not shown again.</p>
    <div class="secret" style="white-space:pre-line"><?= e(implode("\n", $codes)) ?></div>
    <div class="row" style="margin-top:10px"><button type="button" class="btn" data-copy="<?= e(implode("\n", $codes)) ?>">Copy codes</button><a class="btn gold" href="<?= $user['must_change_password'] ? '/?p=account&first=1' : '/' ?>">Continue</a></div>
  <?php else: ?>
    <p class="tag" style="margin-top:0">Your role (<?= e(role_label($user['role'])) ?>) must use two-step login. It keeps client data safe even if someone learns your password.</p>
    <?php if ($err): ?><div class="banner bad"><?= e($err) ?></div><?php endif; ?>
    <ol>
      <li>Install an authenticator app: Google Authenticator, Microsoft Authenticator or Authy.</li>
      <li>On this phone, <a href="<?= e($uri) ?>">tap here to add DhanXfer to the app</a>. On a computer, choose "Enter a setup key" in the app and type:<br><strong class="num" style="letter-spacing:.08em;font-size:17px"><?= e(trim(chunk_split($secret, 4, ' '))) ?></strong><br><span class="tag">Account name: DhanXfer · Type: time based</span></li>
      <li>Type the 6-digit code the app now shows.</li>
    </ol>
    <form method="post"><?= csrf_field() ?>
      <div class="f" style="margin:12px 0"><label for="code">Code from the app</label><input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" style="font-size:20px;letter-spacing:.2em;text-align:center"></div>
      <button class="btn gold" style="width:100%">Turn on two-step login</button></form>
  <?php endif; ?>
</div>
<?php page_foot();
