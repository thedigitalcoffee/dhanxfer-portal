<?php
// One-time setup, protected by the install key from the server config.
// Fresh database: creates the first account as super admin.
// Upgraded database without a super admin: the owner claims it with an existing admin login.
// Either way the account must set up two-step login before it gets a session.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/mfa.php';
strict_csp();

$key = get('key');
if (empty($CONFIG['install_key']) || !hash_equals($CONFIG['install_key'], $key)) { http_response_code(404); exit('Not found'); }
migrate();
if ((int)val("SELECT COUNT(*) FROM users WHERE role='super_admin'") > 0) { http_response_code(404); exit('Setup is already complete. Log in at /login.php'); }
$fresh = (int)val('SELECT COUNT(*) FROM users') === 0;

function start_enrollment(int $uid, bool $claim): never {
    session_regenerate_id(true);
    unset($_SESSION['uid'], $_SESSION['sv']);
    $_SESSION['enroll_uid'] = $uid; $_SESSION['enroll_at'] = time(); $_SESSION['enroll_token'] = 1;
    if ($claim) $_SESSION['claim_super'] = $uid;
    redirect('/mfa_setup.php');
}

$err = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((int)val("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND username='install' AND at > (NOW() - INTERVAL 15 MINUTE)", [client_ip()]) >= 5) {
        $err[] = 'Too many attempts. Wait 15 minutes.';
    } elseif ($fresh) {
        $name = post('name'); $username = mb_strtolower(post('username')); $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
        if ($name === '') $err[] = 'Enter your name.';
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) $err[] = 'Login ID: 3–40 characters, letters, numbers, dot, dash or underscore.';
        if (!is_string($p1) || strlen($p1) < 12) $err[] = 'Password must be at least 12 characters.';
        if ($p1 !== $p2) $err[] = 'The two passwords do not match.';
        if (!$err) {
            q("INSERT INTO users (role,username,name,email,password_hash,must_change_password,mfa_token_only) VALUES ('super_admin',?,?,?,?,0,1)",
                [$username, $name, post('email') ?: null, password_hash($p1, PASSWORD_DEFAULT)]);
            $id = (int)db()->lastInsertId();
            audit_write($id, 'install', 'user', $id, 'First account created as super admin with the install key', client_ip());
            start_enrollment($id, false);
        }
    } else {
        $u = one("SELECT * FROM users WHERE username=? AND active=1 AND role='admin'", [mb_strtolower(post('username'))]);
        $ok = $u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash']);
        if ($ok && $u['mfa_enabled']) $ok = mfa_check($u, post('code'));
        if (!$ok || post('confirm') !== '1') {
            q("INSERT INTO login_attempts (ip, username) VALUES (?, 'install')", [client_ip()]);
            $err[] = !$ok ? 'Login ID, password or code is wrong, or the account is not an admin.' : 'Tick the box to confirm you are the owner.';
        } else {
            audit_write((int)$u['id'], 'super_admin_claim_started', 'user', $u['id'], 'Install key + password' . ($u['mfa_enabled'] ? ' + two-step code' : ''), client_ip());
            if ($u['mfa_enabled']) {
                q("UPDATE users SET role='super_admin', session_version=session_version+1 WHERE id=? AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE role='super_admin') x)", [$u['id']]);
                audit_write((int)$u['id'], 'super_admin_claimed', 'user', $u['id'], 'Verified with install key, password and two-step code', client_ip());
                flash('You are now the super admin. Log in again.');
                redirect('/login.php');
            }
            start_enrollment((int)$u['id'], true);
        }
    }
}
page_head($fresh ? 'Set up' : 'Claim super admin'); ?>
<div class="login card" style="max-width:480px">
<?php if ($fresh): ?>
  <h1>Set up the DhanXfer portal</h1>
  <p class="tag">Create the owner account. It becomes the super admin and must set up two-step login next.</p>
<?php else: ?>
  <h1>Claim super admin</h1>
  <p class="tag">This portal was upgraded and has no super admin yet. Nobody is promoted automatically. The owner proves who they are with this install link, their existing admin login and two-step login.</p>
<?php endif; ?>
  <?php foreach ($err as $x): ?><div class="banner bad"><?= e($x) ?></div><?php endforeach; ?>
  <form method="post"><?= csrf_field() ?>
  <?php if ($fresh): ?>
    <div class="f" style="margin-bottom:12px"><label for="name">Your name</label><input id="name" name="name" required value="<?= e(post('name')) ?>"></div>
    <div class="f" style="margin-bottom:12px"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e(post('email')) ?>"></div>
    <div class="f" style="margin-bottom:12px"><label for="username">Login ID</label><input id="username" name="username" required autocapitalize="none" value="<?= e(post('username')) ?>"></div>
    <div class="f" style="margin-bottom:12px"><label for="password">Password (at least 12 characters)</label><input id="password" name="password" type="password" required autocomplete="new-password"></div>
    <div class="f" style="margin-bottom:16px"><label for="password2">Type it again</label><input id="password2" name="password2" type="password" required autocomplete="new-password"></div>
    <button class="btn gold" style="width:100%">Create owner account</button>
  <?php else: ?>
    <div class="f" style="margin-bottom:12px"><label for="username">Your admin login ID</label><input id="username" name="username" required autocapitalize="none" autocomplete="username"></div>
    <div class="f" style="margin-bottom:12px"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <div class="f" style="margin-bottom:12px"><label for="code">Two-step code (only if you already use it)</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code"></div>
    <label class="check" style="margin-bottom:16px"><input type="checkbox" name="confirm" value="1"> I am the owner of DhanXfer and this account should have full control.</label>
    <button class="btn gold" style="width:100%">Verify and continue</button>
  <?php endif; ?>
  </form>
</div>
<?php page_foot();
