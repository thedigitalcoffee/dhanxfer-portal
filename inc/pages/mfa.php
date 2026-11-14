<?php
defined('DX') or exit;
require_once __DIR__ . '/../mfa.php';
strict_csp();
$codesShown = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('act');
    if ($act === 'enable') {
        $secret = $_SESSION['mfa_setup'] ?? '';
        if ($secret && totp_match($secret, post('code')) !== null) {
            $codes = mfa_recovery_codes();
            $hashes = array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $codes);
            q('UPDATE users SET mfa_secret=?, mfa_enabled=1, mfa_recovery=?, mfa_last_step=NULL WHERE id=?', [enc($secret), json_encode($hashes), $u['id']]);
            unset($_SESSION['mfa_setup']);
            audit('mfa_enabled', 'user', $u['id']);
            $_SESSION['mfa_codes'] = $codes;
            redirect('/?p=mfa');
        }
        flash('That code did not match. Check the time on your phone and try the newest code.', 'bad');
    } elseif ($act === 'disable' && !mfa_required($u)) {
        if (mfa_check($u, post('code'))) {
            q('UPDATE users SET mfa_enabled=0, mfa_secret=NULL, mfa_recovery=NULL WHERE id=?', [$u['id']]);
            audit('mfa_disabled', 'user', $u['id']); flash('Two-step login is off.');
        } else flash('That code did not work.', 'bad');
    } elseif ($act === 'new_codes' && $u['mfa_enabled']) {
        if (mfa_check($u, post('code'))) {
            $codes = mfa_recovery_codes();
            q('UPDATE users SET mfa_recovery=? WHERE id=?', [json_encode(array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $codes)), $u['id']]);
            audit('mfa_new_codes', 'user', $u['id']);
            $_SESSION['mfa_codes'] = $codes;
        } else flash('That code did not work.', 'bad');
    }
    redirect('/?p=mfa');
}
if (isset($_SESSION['mfa_codes'])) { $codesShown = $_SESSION['mfa_codes']; unset($_SESSION['mfa_codes']); }
if (!$u['mfa_enabled'] && empty($_SESSION['mfa_setup'])) $_SESSION['mfa_setup'] = mfa_new_secret();
$secret = $_SESSION['mfa_setup'] ?? '';
$uri = 'otpauth://totp/' . rawurlencode('DhanXfer:' . $u['username']) . '?secret=' . $secret . '&issuer=DhanXfer&digits=6&period=30';
page_head('Two-step login'); ?>
<div class="login card" style="max-width:520px">
  <h1 style="margin-bottom:6px">Two-step login</h1>
  <?php if ($codesShown): ?>
    <div class="banner ok">Two-step login is on.</div>
    <h3>Your recovery codes</h3>
    <p class="tag">Each code works once if you lose your phone. Save them somewhere safe now; they are not shown again.</p>
    <div class="secret" style="columns:2;white-space:pre-line"><?= e(implode("\n", $codesShown)) ?></div>
    <div class="row" style="margin-top:10px"><button type="button" class="btn" data-copy="<?= e(implode("\n", $codesShown)) ?>">Copy codes</button><a class="btn gold" href="/">Continue</a></div>
  <?php elseif ($u['mfa_enabled']): ?>
    <p><span class="pill p-ok">On</span> You enter a code from your authenticator app each time you log in.</p>
    <form method="post" class="row"><?= csrf_field() ?><input name="code" placeholder="Current code" inputmode="numeric" autocomplete="one-time-code" style="width:160px" required><button class="btn" name="act" value="new_codes">New recovery codes</button><?php if (!mfa_required($u)): ?><button class="btn danger" name="act" value="disable">Turn off</button><?php endif; ?></form>
    <?php if (mfa_required($u)): ?><p class="tag">Your role must use two-step login. If you lose your phone, use a recovery code or ask the super admin to reset it.</p><?php endif; ?>
  <?php else: ?>
    <p class="tag" style="margin-top:0"><?= mfa_required($u) ? 'Your role needs this before you can continue.' : 'Recommended: protects your account even if someone learns your password.' ?></p>
    <ol>
      <li>Install an authenticator app on your phone: Google Authenticator, Microsoft Authenticator or Authy.</li>
      <li>On this phone, <a href="<?= e($uri) ?>">tap here to add DhanXfer to the app</a>. On a computer, choose "Enter a setup key" in the app and type the key below.</li>
    </ol>
    <p class="tag">Setup key: <strong class="num" style="letter-spacing:.08em"><?= e(trim(chunk_split($secret, 4, ' '))) ?></strong></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="enable">
      <div class="f" style="margin:12px 0"><label for="code">3. Type the 6-digit code the app shows</label><input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" style="font-size:20px;letter-spacing:.2em;text-align:center"></div>
      <button class="btn gold" style="width:100%">Turn on two-step login</button></form>
  <?php endif; ?>
</div>
<?php page_foot();
