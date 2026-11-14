<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (me()) redirect('/');
$error = '';
$next = get('next', '/');
// Only same-site paths: blocks //evil.com and /\evil.com (browsers read both as another site)
if (!preg_match('#^/(?![/\\\\])[^\\s]*$#', $next)) $next = '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = mb_strtolower(post('username'));
    $password = $_POST['password'] ?? '';
    $ip = client_ip();
    $fails = (int)val("SELECT COUNT(*) FROM login_attempts WHERE (ip=? OR username=?) AND username NOT LIKE 'mfa:%' AND username<>'status-check' AND at > (NOW() - INTERVAL 15 MINUTE)", [$ip, $username]);
    if ($fails >= 6) {
        $error = 'Too many wrong attempts. Wait 15 minutes and try again, or ask an admin to reset your password.';
    } else {
        $u = one('SELECT * FROM users WHERE username=?', [$username]);
        if ($u && $u['active'] && is_string($password) && password_verify($password, $u['password_hash'])) {
            q('DELETE FROM login_attempts WHERE username=?', [$username]);
            if ($u['mfa_enabled']) {
                // Password is right, but no session is given until the second step is done.
                session_regenerate_id(true);
                unset($_SESSION['uid'], $_SESSION['sv']);
                $_SESSION['mfa_uid'] = (int)$u['id']; $_SESSION['mfa_at'] = time(); $_SESSION['mfa_next'] = $next;
                redirect('/mfa.php');
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['sv'] = (int)$u['session_version'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            q('UPDATE users SET last_login=NOW() WHERE id=?', [$u['id']]);
            q('DELETE FROM login_attempts WHERE username=?', [$username]);
            audit('login', 'user', $u['id']);
            redirect($u['must_change_password'] ? '/?p=account&first=1' : $next);
        }
        q('INSERT INTO login_attempts (ip, username) VALUES (?,?)', [$ip, $username]);
        audit_write(isset($u['id']) ? (int)$u['id'] : null, 'login_failed', 'user', null, 'Login ID tried: ' . mb_substr($username, 0, 60), $ip);
        $error = ($u && !$u['active']) ? 'This account is switched off. Contact a DhanXfer admin.' : 'Wrong ID or password.';
    }
}

page_head('Log in');
?>
<div class="login card">
  <h1 style="margin-bottom:6px">Log in</h1>
  <p class="tag" style="margin-top:0">For DhanXfer admins and sub-agents. Clients register <a href="/register.php">here</a>.</p>
  <?php if ($error): ?><div class="banner bad"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="f" style="margin-bottom:12px"><label for="username">Login ID</label><input id="username" name="username" required autocomplete="username" autocapitalize="none" value="<?= e(post('username')) ?>"></div>
    <div class="f" style="margin-bottom:16px"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn gold" style="width:100%">Log in</button>
  </form>
  <p class="tag" style="margin-bottom:0">Forgot your password? Ask a DhanXfer admin to send you a reset link.</p>
</div>
<?php page_foot();
