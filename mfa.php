<?php
// Second login step: 6-digit code from the authenticator app, or a recovery code.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/mfa.php';
strict_csp();

$uid = (int)($_SESSION['mfa_uid'] ?? 0);
if (!$uid || time() - (int)($_SESSION['mfa_at'] ?? 0) > 300) { unset($_SESSION['mfa_uid']); flash('Please log in again.', 'warn'); redirect('/login.php'); }
$user = one("SELECT * FROM users WHERE id=? AND active=1 AND account_status='active'", [$uid]);
if (!$user || !$user['mfa_enabled']) { unset($_SESSION['mfa_uid']); redirect('/login.php'); }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $key = 'mfa:' . $uid;
    if ($lockMsg = mfa_locked($uid)) {
        $err = $lockMsg;
        unset($_SESSION['mfa_uid']);
    } elseif (mfa_check($user, post('code'))) {
        $next = $_SESSION['mfa_next'] ?? '/';
        unset($_SESSION['mfa_uid'], $_SESSION['mfa_at'], $_SESSION['mfa_next']);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid; $_SESSION['sv'] = (int)$user['session_version']; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        q('UPDATE users SET last_login=NOW() WHERE id=?', [$uid]);
        q('DELETE FROM login_attempts WHERE username=?', [$key]);
        audit_write($uid, 'login', 'user', $uid, 'with two-step code', client_ip());
        redirect($user['must_change_password'] ? '/?p=account&first=1' : $next);
    } else {
        mfa_fail($uid);
        $err = 'That code did not work. Use the newest code from your app.';
    }
}
page_head('Two-step login'); ?>
<div class="login card">
  <h1 style="margin-bottom:6px">Two-step login</h1>
  <p class="tag" style="margin-top:0">Open your authenticator app and type the 6-digit code for DhanXfer. Lost your phone? Type one of your recovery codes.</p>
  <?php if ($err): ?><div class="banner bad"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <div class="f" style="margin-bottom:16px"><label for="code">Code</label><input id="code" name="code" required autocomplete="one-time-code" inputmode="numeric" autofocus style="font-size:22px;letter-spacing:.2em;text-align:center"></div>
    <button class="btn gold" style="width:100%">Continue</button>
  </form>
  <p class="tag" style="margin-bottom:0"><a href="/login.php">Start again</a></p>
</div>
<?php page_foot();
