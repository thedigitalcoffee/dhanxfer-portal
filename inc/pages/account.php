<?php
defined('DX') or exit;
$err = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur = $_POST['current'] ?? ''; $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
    if (!is_string($cur) || !password_verify($cur, $u['password_hash'])) $err[] = 'Your current password is wrong.';
    if (!is_string($p1) || strlen($p1) < 10) $err[] = 'The new password must be at least 10 characters.';
    if ($p1 !== $p2) $err[] = 'The two new passwords do not match.';
    if (is_string($p1) && is_string($cur) && $p1 === $cur) $err[] = 'Choose a password different from the current one.';
    if (!$err) {
        q('UPDATE users SET password_hash=?, must_change_password=0, session_version=session_version+1 WHERE id=?', [password_hash($p1, PASSWORD_DEFAULT), $u['id']]);
        $_SESSION['sv'] = (int)$u['session_version'] + 1;
        session_regenerate_id(true);
        audit('change_password', 'user', $u['id']);
        flash('Password changed.');
        redirect('/');
    }
}
page_head('My account'); ?>
<div class="login card" style="max-width:480px">
  <h1 style="margin-bottom:6px">My account</h1>
  <p class="tag" style="margin-top:0">Login ID <strong><?= e($u['username']) ?></strong><?= $u['ref_code'] ? ' · referral ID <strong>' . e($u['ref_code']) . '</strong>' : '' ?></p>
  <?php if (get('first') === '1' || $u['must_change_password']): ?><div class="banner warn">Please choose your own password before you continue.</div><?php endif; ?>
  <?php foreach ($err as $x): ?><div class="banner bad"><?= e($x) ?></div><?php endforeach; ?>
  <form method="post"><?= csrf_field() ?>
    <div class="f" style="margin-bottom:12px"><label for="current">Current (or temporary) password</label><input id="current" name="current" type="password" required autocomplete="current-password"></div>
    <div class="f" style="margin-bottom:12px"><label for="password">New password (at least 10 characters)</label><input id="password" name="password" type="password" required autocomplete="new-password"></div>
    <div class="f" style="margin-bottom:16px"><label for="password2">Type the new password again</label><input id="password2" name="password2" type="password" required autocomplete="new-password"></div>
    <button class="btn gold" style="width:100%">Change password</button>
  </form>
</div>
<?php page_foot();
