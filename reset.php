<?php
// One-time password reset link created by an admin.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$tok = get('t');
$row = preg_match('/^[a-f0-9]{48}$/', $tok)
    ? one('SELECT r.*, u.name, u.username, u.active FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=?', [hash('sha256', $tok)])
    : null;
$valid = $row && !$row['used_at'] && strtotime($row['expires_at']) > time() && $row['active'];
$err = [];

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
    if (!is_string($p1) || strlen($p1) < 10) $err[] = 'The password must be at least 10 characters.';
    if ($p1 !== $p2) $err[] = 'The two passwords do not match.';
    if (!$err) {
        $done = q('UPDATE password_resets SET used_at=NOW() WHERE id=? AND used_at IS NULL', [$row['id']])->rowCount();
        if ($done) {
            q('UPDATE users SET password_hash=?, must_change_password=0, session_version=session_version+1 WHERE id=?', [password_hash($p1, PASSWORD_DEFAULT), $row['user_id']]);
            audit_write((int)$row['user_id'], $row['purpose'] === 'activate' ? 'account_activated' : 'reset_link_used', 'user', $row['user_id'], $row['purpose'] === 'activate' ? 'Account activated' : 'Password set with reset link', client_ip());
            $usr = one('SELECT * FROM users WHERE id=?', [$row['user_id']]);
            notify_user((int)$row['user_id'], $row['purpose'] === 'activate' ? 'Your DhanXfer portal account is active' : 'Security notice: your DhanXfer password was changed', "Hi {$row['name']},\n\n" . ($row['purpose'] === 'activate' ? 'Your DhanXfer portal account (' . $row['username'] . ') was activated just now.' : 'The password for your DhanXfer portal account (' . $row['username'] . ') was changed just now with a one-time link.') . "\n\nIf this was not you, contact DhanXfer right away.");
            if ($usr && in_array($usr['role'], MFA_REQUIRED, true) && !$usr['mfa_enabled']) {
                // The person just proved they hold the admin's link: let them set up two-step login now.
                session_regenerate_id(true);
                unset($_SESSION['uid'], $_SESSION['sv']);
                unset($_SESSION['enroll_setup'], $_SESSION['claim_super']);
                $_SESSION['enroll_uid'] = (int)$usr['id']; $_SESSION['enroll_at'] = time(); $_SESSION['enroll_token'] = (int)$usr['id'];
                redirect('/mfa_setup.php');
            }
            flash($row['purpose'] === 'activate' ? 'Your account is active. Log in with your login ID and new password.' : 'Your new password is saved. Log in with it now.');
            redirect('/login.php');
        }
        $valid = false;
    }
}
$activate = $row && $row['purpose'] === 'activate';
page_head($activate ? 'Activate your account' : 'Set a new password');
?>
<div class="login card">
  <h1 style="margin-bottom:6px"><?= $activate ? 'Activate your account' : 'Set a new password' ?></h1>
  <?php if (!$valid): ?>
    <div class="banner bad">This link has already been used or has expired. Ask a DhanXfer admin to send you a new one.</div>
    <a class="btn" href="/login.php">Go to log in</a>
  <?php else: ?>
    <p class="tag" style="margin-top:0">For <strong><?= e($row['name']) ?></strong> · login ID <strong><?= e($row['username']) ?></strong>. This link works one time.</p>
    <?php foreach ($err as $x): ?><div class="banner bad"><?= e($x) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?>
      <input type="text" name="username" value="<?= e($row['username']) ?>" autocomplete="username" hidden>
      <div class="f" style="margin-bottom:12px"><label for="password">New password (at least 10 characters)</label><input id="password" name="password" type="password" required autocomplete="new-password"></div>
      <div class="f" style="margin-bottom:16px"><label for="password2">Type it again</label><input id="password2" name="password2" type="password" required autocomplete="new-password"></div>
      <button class="btn gold" style="width:100%">Save new password</button>
    </form>
  <?php endif; ?>
</div>
<?php page_foot();
