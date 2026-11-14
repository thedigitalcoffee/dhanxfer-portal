<?php
// One-time setup: creates the tables and the first admin account.
// Needs the install key from the server config, and stops working once an admin exists.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$key = get('key');
if (empty($CONFIG['install_key']) || !hash_equals($CONFIG['install_key'], $key)) { http_response_code(404); exit('Not found'); }

foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/inc/schema.sql')))) as $sql) db()->exec($sql);

migrate();
if ((int)val("SELECT COUNT(*) FROM users WHERE role IN ('super_admin','admin')") > 0) { http_response_code(404); exit('Setup is already complete. Log in at /login.php'); }

$err = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = post('name'); $username = mb_strtolower(post('username')); $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
    if ($name === '') $err[] = 'Enter your name.';
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) $err[] = 'Login ID: 3–40 characters, letters, numbers, dot, dash or underscore.';
    if (!is_string($p1) || strlen($p1) < 10) $err[] = 'Password must be at least 10 characters.';
    if ($p1 !== $p2) $err[] = 'The two passwords do not match.';
    if (!$err) {
        q("INSERT INTO users (role,username,name,email,password_hash,must_change_password) VALUES ('super_admin',?,?,?,?,0)",
            [$username, $name, post('email') ?: null, password_hash($p1, PASSWORD_DEFAULT)]);
        $id = (int)db()->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['uid'] = $id; $_SESSION['sv'] = 1; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        audit('install', 'user', $id, 'First admin created');
        flash('Setup complete. Welcome to the DhanXfer portal.');
        redirect('/');
    }
}
page_head('Set up');
?>
<div class="login card" style="max-width:480px">
  <h1>Set up the DhanXfer portal</h1>
  <p class="tag">Create the first account. It becomes the super admin, who can do everything and set up roles for the team. You can add more admins and sub-agents after this.</p>
  <?php foreach ($err as $x): ?><div class="banner bad"><?= e($x) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="f" style="margin-bottom:12px"><label for="name">Your name</label><input id="name" name="name" required value="<?= e(post('name')) ?>"></div>
    <div class="f" style="margin-bottom:12px"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e(post('email')) ?>"></div>
    <div class="f" style="margin-bottom:12px"><label for="username">Login ID</label><input id="username" name="username" required autocapitalize="none" value="<?= e(post('username')) ?>" placeholder="e.g. deepak"></div>
    <div class="f" style="margin-bottom:12px"><label for="password">Password (at least 10 characters)</label><input id="password" name="password" type="password" required autocomplete="new-password"></div>
    <div class="f" style="margin-bottom:16px"><label for="password2">Type the password again</label><input id="password2" name="password2" type="password" required autocomplete="new-password"></div>
    <button class="btn gold" style="width:100%">Create admin account</button>
  </form>
</div>
<?php page_foot();
