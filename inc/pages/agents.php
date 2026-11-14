<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
$secret = null; // a password to show once
/** Roles the current user may create or manage. */
function manageable_roles(): array {
    $r = [];
    if (can('team.agents')) $r[] = 'agent';
    if (can('team.staff')) { $r[] = 'staff'; $r[] = 'accountant'; $r[] = 'auditor'; $r[] = 'compliance'; $r[] = 'admin'; }
    if (is_super()) $r[] = 'super_admin';
    return $r;
}
function can_manage(array $target): bool { return in_array($target['role'], manageable_roles(), true); }
function link_message(array $l): string {
    return ($l['purpose'] ?? '') === 'activate'
        ? "Welcome to DhanXfer, {$l['name']}! Open this link to set your password and activate your portal account. It works once and expires in 72 hours:\n{$l['link']}\nYour login ID: {$l['username']}" . (!empty($l['ref']) ? "\nYour agent code: {$l['ref']}" : '')
        : "Hi {$l['name']}, use this link to set a new password for the DhanXfer portal. It works once and expires in 24 hours:\n{$l['link']}\nYour login ID: {$l['username']}";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('act');
    if ($act === 'create') {
        $role = post('role');
        if (!in_array($role, manageable_roles(), true)) { flash('You cannot create that role.', 'bad'); redirect('/?p=agents'); }
        $name = mb_substr(post('name'), 0, 120);
        $username = mb_strtolower(post('username'));
        $ref = $role === 'agent' ? strtoupper(post('ref_code') ?: next_ref_code()) : null;
        $err = [];
        if ($name === '') $err[] = 'Enter the person\'s name.';
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) $err[] = 'Login ID: 3–40 characters using letters, numbers, dot, dash or underscore.';
        elseif (val('SELECT 1 FROM users WHERE username=?', [$username])) $err[] = 'That login ID is already taken.';
        elseif ($role === 'agent' && val('SELECT 1 FROM agent_codes WHERE code=?', [$username])) $err[] = 'That name was used as a referral code by another sub-agent and stays reserved for them.';
        if ($ref !== null && !preg_match('/^[A-Z0-9-]{3,20}$/', $ref)) $err[] = 'Referral ID: 3–20 letters, numbers or dashes.';
        elseif ($ref !== null && val('SELECT 1 FROM users WHERE ref_code=?', [$ref])) $err[] = 'That referral ID is already used.';
        if ($err) { foreach ($err as $x) flash($x, 'bad'); redirect('/?p=agents'); }
        // Nobody knows this random password: the person sets their own through the activation link.
        q('INSERT INTO users (role,username,name,phone,email,office,ref_code,commission_note,password_hash,must_change_password,created_by,mfa_token_only) VALUES (?,?,?,?,?,?,?,?,?,0,?,' . (in_array($role, MFA_REQUIRED, true) ? 1 : 0) . ')',
            [$role, $username, $name, post('phone') ?: null, post('email') ?: null, in_array(post('office'), OFFICES, true) ? post('office') : null, $ref, mb_substr(post('commission_note'), 0, 250) ?: null, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $u['id']]);
        $nid = (int)db()->lastInsertId();
        if ($role === 'agent') { require_once __DIR__ . '/../kyc.php'; agent_code_register($nid, $username); }
        audit('create_user', 'user', $nid, "$role $username");
        $_SESSION['show_link'] = ['name' => $name, 'username' => $username, 'link' => make_reset_link($nid, (int)$u['id'], 'activate'), 'purpose' => 'activate', 'ref' => $ref, 'uid' => $nid];
        redirect('/?p=agents&id=' . $nid);
    }
    $target = one('SELECT * FROM users WHERE id=?', [(int)post('id')]);
    if (!$target) redirect('/?p=agents');
    $self = (int)$target['id'] === (int)$u['id'];
    if (!$self && !can_manage($target)) { flash('Your role cannot change this person.', 'bad'); redirect('/?p=agents'); }
    if ($self && !in_array($act, ['save'], true)) { flash('Change your own password under My account.', 'bad'); redirect('/?p=agents&id=' . $target['id']); }
    if ((int)$target['id'] === (int)$u['id'] && in_array($act, ['disable', 'role'], true)) { flash('You cannot switch off or change your own account.', 'bad'); redirect('/?p=agents&id=' . $target['id']); }
    // Never leave the business without a working super admin. Checks run one at a time (lock held until the
    // request ends), so two super admins switching each other off at the same moment cannot both succeed.
    if ($target['role'] === 'super_admin' && in_array($act, ['disable', 'role', 'mfa_reset', 'reset_link'], true) && !($act === 'role' && post('role') === 'super_admin')) {
        if ((int)val('SELECT GET_LOCK(?, 10)', [lock_name('dx_supers')]) !== 1) { flash('Busy, try again in a moment.', 'bad'); redirect('/?p=agents&id=' . $target['id']); }
        if ((int)val("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1 AND mfa_enabled=1 AND id<>?", [$target['id']]) < 1) {
            flash('At least one other super admin with two-step login must stay active, so this change is not allowed.', 'bad'); redirect('/?p=agents&id=' . $target['id']);
        }
    }
    if ($act === 'commission' && $target['role'] === 'agent' && can('commission.set')) {
        $changes = [];
        foreach (rate_pairs(false) as $pp) {
            $f = 'com_' . $pp['send_currency'] . '_' . $pp['receive_currency'];
            $raw = post($f); $v = num_or_null($raw);
            if ($raw === '') { q('DELETE FROM agent_commissions WHERE agent_id=? AND send_currency=? AND receive_currency=?', [$target['id'], $pp['send_currency'], $pp['receive_currency']]); continue; }
            if ($v === null || $v < 0 || $v > 1000) { flash('Commission for ' . pair_label($pp['send_currency'], $pp['receive_currency']) . ' must be a number like 0.25.', 'bad'); redirect('/?p=agents&id=' . $target['id']); }
            q('INSERT INTO agent_commissions (agent_id, send_currency, receive_currency, per_unit, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE per_unit=VALUES(per_unit), updated_by=VALUES(updated_by), updated_at=NOW()',
                [$target['id'], $pp['send_currency'], $pp['receive_currency'], $v, $u['id']]);
            $changes[] = pair_label($pp['send_currency'], $pp['receive_currency']) . ' ' . fmt_rate($v);
        }
        audit('commission_set', 'user', $target['id'], $target['name'] . ': ' . implode(', ', $changes));
        if (post('recalc') === '1') {
            $ids = all("SELECT id FROM transfers WHERE agent_id=? AND status IN ('accepted','completed') AND commission IS NULL", [$target['id']]);
            foreach ($ids as $r) apply_commission((int)$r['id']);
            flash('Commission rates saved and applied to ' . count($ids) . ' earlier transfer(s) that had none.');
        } else flash('Commission rates saved. New accepted transfers use them.');
    } elseif ($act === 'reset_link') {
        try { $link = make_reset_link((int)$target['id'], (int)$u['id'], $target['last_login'] ? 'reset' : 'activate'); }
        catch (RuntimeException $ex) { flash($ex->getMessage(), 'bad'); redirect('/?p=agents&id=' . $target['id']); }
        // The old password stops working now, and every open session ends.
        q('UPDATE users SET password_hash=?, session_version=session_version+1 WHERE id=?', [password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $target['id']]);
        audit('reset_link', 'user', $target['id'], $target['username']);
        notify_user((int)$target['id'], 'Security notice: your DhanXfer password was reset', "Hi {$target['name']},\n\nA DhanXfer admin has reset the password for your portal account ({$target['username']}). Your old password no longer works. You will get a one-time link to choose a new one.\n\nIf you did not expect this, contact DhanXfer right away.");
        $_SESSION['show_link'] = ['name' => $target['name'], 'username' => $target['username'], 'link' => $link, 'purpose' => $target['last_login'] ? 'reset' : 'activate', 'ref' => $target['ref_code'], 'uid' => (int)$target['id']];
    } elseif ($act === 'rename' && $target['role'] === 'agent' && can('team.agents')) {
        require_once __DIR__ . '/../kyc.php';
        try { agent_rename($target, post('new_username'), (int)$u['id']); flash('Login ID and referral code changed. Clients and links from before still count for this sub-agent.'); }
        catch (RuntimeException $ex) { flash($ex->getMessage(), 'bad'); }
    } elseif ($act === 'email_link') {
        $ml = $_SESSION['mail_link'] ?? null; unset($_SESSION['mail_link']);
        if (!$ml || (int)$ml['uid'] !== (int)$target['id'] || time() - (int)$ml['at'] > 900 || !$target['email']) { flash('Make a new link first, then email it right away (the person needs an email address on file).', 'bad'); redirect('/?p=agents&id=' . $target['id']); }
        $isAct = $ml['purpose'] === 'activate';
        $ok = send_mail($target['email'], $isAct ? 'Activate your DhanXfer portal account' : 'Your DhanXfer password reset link', link_message($ml), $isAct ? 'activation_link' : 'reset_link');
        audit('email_link', 'user', $target['id'], $target['username'] . ($ok ? ': sent' : ': failed'));
        flash($ok ? 'Emailed the link to ' . $target['email'] . '.' : 'The email could not be sent. Share the link another way.', $ok ? 'ok' : 'bad');
    } elseif ($act === 'mfa_reset' && is_super()) {
        q('UPDATE users SET mfa_enabled=0, mfa_secret=NULL, mfa_recovery=NULL, mfa_token_only=1, session_version=session_version+1 WHERE id=?', [$target['id']]);
        audit('mfa_reset', 'user', $target['id'], $target['username']);
        notify_user((int)$target['id'], 'Security notice: two-step login was reset on your account', "Hi {$target['name']},\n\nThe DhanXfer super admin has reset two-step login for your portal account ({$target['username']}). Your old authenticator codes no longer work. You will get a one-time link to set a new password and two-step login.\n\nIf you did not expect this, contact DhanXfer right away.");
        notify('Security: two-step login reset for ' . $target['username'], 'Two-step login was reset for ' . $target['username'] . ' by ' . $u['username'] . '.', 'security_alert');
        try { $link = make_reset_link((int)$target['id'], (int)$u['id']); } catch (RuntimeException $ex) { flash($ex->getMessage(), 'bad'); redirect('/?p=agents&id=' . $target['id']); }
        q('UPDATE users SET password_hash=? WHERE id=?', [password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $target['id']]);
        $_SESSION['show_link'] = ['name' => $target['name'], 'username' => $target['username'], 'link' => $link, 'purpose' => 'reset', 'ref' => null, 'uid' => (int)$target['id']];
        flash('Two-step login was reset. Send ' . $target['name'] . ' this link: they set a new password and two-step login with it.');
    } elseif ($act === 'role') {
        $nr = post('role');
        if (!in_array($nr, manageable_roles(), true) || $target['role'] === 'agent' || $nr === 'agent') { flash('That role change is not allowed.', 'bad'); redirect('/?p=agents&id=' . $target['id']); }
        q('UPDATE users SET role=?, session_version=session_version+1, mfa_token_only=IF(mfa_enabled=0 AND ?, 1, mfa_token_only) WHERE id=?', [$nr, in_array($nr, MFA_REQUIRED, true) ? 1 : 0, $target['id']]);
        audit('change_role', 'user', $target['id'], $target['username'] . ': ' . $target['role'] . ' → ' . $nr);
        flash($target['name'] . ' is now ' . role_label($nr) . '.');
    } elseif ($act === 'disable') {
        q('UPDATE users SET active=0, session_version=session_version+1 WHERE id=?', [$target['id']]); audit('disable_user', 'user', $target['id'], $target['username']); flash($target['name'] . ' can no longer log in.');
    } elseif ($act === 'enable') {
        q('UPDATE users SET active=1 WHERE id=?', [$target['id']]); audit('enable_user', 'user', $target['id'], $target['username']); flash($target['name'] . ' can log in again.');
    } elseif ($act === 'save') {
        q('UPDATE users SET name=?, phone=?, email=?, office=?, commission_note=? WHERE id=?', [mb_substr(post('name'), 0, 120) ?: $target['name'], post('phone') ?: null, post('email') ?: null, in_array(post('office'), OFFICES, true) ? post('office') : null, mb_substr(post('commission_note'), 0, 250) ?: null, $target['id']]);
        $newMail = (string)(post('email') ?: '');
        if ((string)$target['email'] !== '' && strcasecmp((string)$target['email'], $newMail) !== 0)
            send_mail((string)$target['email'], 'Security notice: the email on your DhanXfer account changed', "Hi {$target['name']},\n\nThe email address on your DhanXfer portal account ({$target['username']}) was changed by {$u['username']}. Security notices now go to the new address.\n\nIf you did not expect this, contact DhanXfer right away.", 'security_alert');
        audit('edit_user', 'user', $target['id'], $target['username']); flash('Details saved.');
    }
    redirect('/?p=agents&id=' . $target['id']);
}

$base = rtrim($CONFIG['app_url'], '/');
if (isset($_SESSION['show_secret'])) { $secret = $_SESSION['show_secret']; unset($_SESSION['show_secret']); }
$showLink = null;
// The link is shown, and can be emailed, only on the page of the person it was made for.
if (isset($_SESSION['show_link']) && (int)($_SESSION['show_link']['uid'] ?? 0) === (int)get('id')) { $showLink = $_SESSION['show_link']; unset($_SESSION['show_link']); $_SESSION['mail_link'] = $showLink + ['at' => time()]; }

if (get('id') !== '') {
    $a = one('SELECT * FROM users WHERE id=?', [(int)get('id')]);
    if (!$a || ((int)$a['id'] !== (int)$u['id'] && !can_manage($a))) { flash('Your role cannot open that person.', 'bad'); redirect('/?p=agents'); }
    $isAgent = $a['role'] === 'agent';
    $stats = $isAgent ? one("SELECT (SELECT COUNT(*) FROM clients WHERE agent_id=?) clients, (SELECT COUNT(*) FROM transfers WHERE agent_id=? AND status IN ('accepted','completed')) tx, (SELECT COUNT(*) FROM transfers WHERE agent_id=? AND status='pending') pend", [$a['id'], $a['id'], $a['id']]) : null;
    $vol = $isAgent ? all("SELECT DATE_FORMAT(transfer_date,'%Y-%m') m, send_currency, COUNT(*) n, SUM(send_amount) s, SUM(fee) f FROM transfers WHERE agent_id=? AND status IN ('accepted','completed') GROUP BY m, send_currency ORDER BY m DESC LIMIT 24", [$a['id']]) : [];
    page_head($a['name']); ?>
    <div class="spread"><div><div class="tag"><?= e(role_label($a['role'])) ?> · login ID <strong><?= e($a['username']) ?></strong></div><h1><?= e($a['name']) ?></h1>
      <div class="row" style="margin-top:6px"><?= $a['active'] ? '<span class="pill p-ok">Active</span>' : '<span class="pill p-bad">Switched off</span>' ?><?php if ($isAgent): ?><span class="pill p-gold"><?= e($a['ref_code']) ?></span><?php endif; ?></div></div>
      <a class="btn" href="/?p=agents">Back to team</a></div>
    <?php if ($showLink): $act = ($showLink['purpose'] ?? '') === 'activate';
      $lmsg = link_message($showLink); ?>
      <div class="card" style="border-color:var(--gold)">
        <h2 style="margin-bottom:8px"><?= $act ? 'Activation link' : 'Password reset link' ?> for <?= e($showLink['name']) ?></h2>
        <p class="tag" style="margin-top:0">Send this privately. It works one time only and expires in <?= $act ? '72' : '24' ?> hours. They choose their own password; nobody else ever sees it.</p>
        <div class="secret"><?= e($showLink['link']) ?></div>
        <div class="row" style="margin-top:10px"><button type="button" class="btn gold" data-share="<?= e($showLink['link']) ?>" data-share-text="<?= e($lmsg) ?>">Share</button>
          <a class="btn" href="https://wa.me/<?= e(preg_replace('/\D/', '', (string)$a['phone'])) ?>?text=<?= e(rawurlencode($lmsg)) ?>" target="_blank" rel="noopener">Send on WhatsApp</a>
          <button type="button" class="btn" data-copy="<?= e($lmsg) ?>">Copy message</button>
          <?php if ($a['email']): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="email_link"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn">Email to <?= e($a['email']) ?></button></form><?php endif; ?></div>
      </div>
    <?php endif; ?>
    <div class="grid2">
      <div>
      <?php if ($isAgent): ?>
        <div class="tiles">
          <a class="tile" href="/?p=clients&agent=<?= (int)$a['id'] ?>"><div class="k">Clients</div><div class="v num"><?= (int)$stats['clients'] ?></div></a>
          <a class="tile" href="/?p=transfers&agent=<?= (int)$a['id'] ?>"><div class="k">Accepted transfers</div><div class="v num"><?= (int)$stats['tx'] ?></div></a>
          <a class="tile <?= $stats['pend'] ? 'alert' : '' ?>" href="/?p=transfers&status=pending&agent=<?= (int)$a['id'] ?>"><div class="k">Waiting for you</div><div class="v num"><?= (int)$stats['pend'] ?></div></a>
        </div>
        <?php share_block(agent_link($a), $a['username'], 'Personal client registration link'); ?>
        <?php if (can('team.agents')): $old = array_column(all('SELECT code FROM agent_codes WHERE agent_id=? AND is_current=0', [$a['id']]), 'code'); ?>
        <form method="post" class="card row" style="padding:12px"><?= csrf_field() ?><input type="hidden" name="act" value="rename"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <div class="f" style="flex:1;min-width:200px"><label for="new_username">Login ID and referral code</label><input id="new_username" name="new_username" value="<?= e($a['username']) ?>" pattern="[a-z0-9._\-]{3,40}"></div>
          <button class="btn">Change</button>
          <?php if ($old): ?><div class="tag" style="width:100%">Older codes still credited to this sub-agent: <?= e(implode(', ', $old)) ?></div><?php endif; ?></form>
        <?php endif; ?>
        <h2 style="margin-bottom:10px">Monthly volume (for commission)</h2>
        <div class="tablewrap"><table><thead><tr><th>Month</th><th class="r">Transfers</th><th class="r">Volume</th><th class="r">Fees</th></tr></thead><tbody>
        <?php foreach ($vol as $r): ?><tr><td><?= e(date('F Y', strtotime($r['m'] . '-01'))) ?></td><td class="r num"><?= (int)$r['n'] ?></td><td class="r num"><?= e(money($r['s'], $r['send_currency'] ?? '')) ?></td><td class="r num"><?= e(money($r['f'], $r['send_currency'] ?? '')) ?></td></tr><?php endforeach; ?>
        <?php if (!$vol): ?><tr><td colspan="4" class="empty">No accepted transfers yet.</td></tr><?php endif; ?></tbody></table></div>
      <?php endif; ?>
      </div>
      <div>
        <?php if ($isAgent && can('commission.set')): $crs = []; foreach (all('SELECT * FROM agent_commissions WHERE agent_id=?', [$a['id']]) as $cr) $crs[$cr['send_currency'] . '_' . $cr['receive_currency']] = $cr['per_unit']; ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="act" value="commission">
          <h3 style="margin-bottom:6px">Commission rates</h3>
          <p class="tag" style="margin-top:0">Amount paid to <?= e($a['name']) ?> for each unit sent. Example: 0.25 on CAD → INR means 0.25 INR for every CAD sent. Leave empty for no commission.</p>
          <div class="fields">
          <?php foreach (rate_pairs(false) as $pp): $key = $pp['send_currency'] . '_' . $pp['receive_currency']; ?>
            <div class="f"><label for="com_<?= e($key) ?>"><?= e($pp['receive_currency']) ?> per <?= e($pp['send_currency']) ?> sent</label><input id="com_<?= e($key) ?>" name="com_<?= e($key) ?>" type="number" step="0.0001" min="0" class="num" value="<?= isset($crs[$key]) ? e(fmt_rate($crs[$key])) : '' ?>" placeholder="e.g. 0.25"></div>
          <?php endforeach; ?>
          </div>
          <label class="check" style="margin-top:10px"><input type="checkbox" name="recalc" value="1"> Also apply to earlier accepted transfers that have no commission yet</label>
          <div style="margin-top:12px"><button class="btn gold">Save commission</button> <a class="btn" href="/?p=commission&agent=<?= (int)$a['id'] ?>">See commission report</a></div>
        </form>
        <?php endif; ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <h3 style="margin-bottom:10px">Details</h3>
          <div class="fields">
            <div class="f"><label for="name">Name</label><input id="name" name="name" value="<?= e($a['name']) ?>"></div>
            <div class="f"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= e($a['phone']) ?>"></div>
            <div class="f"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e($a['email']) ?>"></div>
            <div class="f"><label for="office">Office</label><select id="office" name="office"><?= opts(OFFICES, $a['office']) ?></select></div>
            <?php if ($isAgent): ?><div class="f wide"><label for="commission_note">Commission terms (only admins see this)</label><input id="commission_note" name="commission_note" value="<?= e($a['commission_note']) ?>"></div><?php endif; ?>
          </div>
          <div style="margin-top:12px"><button class="btn gold" name="act" value="save">Save details</button></div>
        </form>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <h3 style="margin-bottom:10px">Access</h3>
          <p class="tag" style="margin-top:0">Last login: <?= $a['last_login'] ? fdatetime($a['last_login']) : 'never' ?></p>
          <?php if ((int)$a['id'] !== (int)$u['id']): ?>
          <p class="tag"><?= $a['last_login'] ? 'A reset link lets them choose a new password (works once, 24 hours). Their current password stops working straight away.' : 'They have not activated their account yet. A new activation link replaces the old one (works once, 72 hours).' ?> Two-step login: <?= $a['mfa_enabled'] ? 'on' : 'off' ?>.</p>
          <div class="row"><button class="btn gold" name="act" value="reset_link" data-confirm="Create a new one-time link for <?= e($a['name']) ?>?"><?= $a['last_login'] ? 'Create reset link' : 'New activation link' ?></button>
          <?php if (is_super() && $a['mfa_enabled']): ?><button class="btn" name="act" value="mfa_reset" data-confirm="Turn off two-step login for <?= e($a['name']) ?> so they can set it up again (for a lost phone)?">Reset two-step login</button><?php endif; ?><?= $a['active'] ? '<button class="btn danger" name="act" value="disable" data-confirm="Switch off this account? They will be logged out.">Switch off login</button>' : '<button class="btn ok" name="act" value="enable">Switch login back on</button>' ?></div>
          <?php else: ?><p class="tag">This is you. Change your password under <a href="/?p=account">My account</a>.</p><?php endif; ?>
        </form>
        <?php $roleOpts = array_values(array_filter(manageable_roles(), fn($x) => $x !== 'agent')); if (!$isAgent && (int)$a['id'] !== (int)$u['id'] && $roleOpts): ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="act" value="role">
          <h3 style="margin-bottom:10px">Job role</h3>
          <div class="row"><select name="role" style="width:auto" aria-label="Role"><?php foreach ($roleOpts as $ro): ?><option value="<?= e($ro) ?>"<?= $a['role'] === $ro ? ' selected' : '' ?>><?= e(role_label($ro)) ?></option><?php endforeach; ?></select><button class="btn">Change role</button></div>
          <p class="tag" style="margin-bottom:0">What each role can do is set under <?= is_super() ? '<a href="/?p=roles">Roles</a>' : 'Roles (super admin only)' ?>.</p>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php page_foot(); return;
}

$rows = all("SELECT u.*, (SELECT COUNT(*) FROM clients c WHERE c.agent_id=u.id) nclients,
  (SELECT COUNT(*) FROM transfers t WHERE t.agent_id=u.id AND t.status IN ('accepted','completed') AND t.transfer_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) ntx_month
  FROM users u ORDER BY FIELD(u.role,'super_admin','admin','staff','accountant','agent'), u.active DESC, u.name");
$mine = manageable_roles();
page_head('Team & sub-agents'); ?>
<div class="spread"><div><h1>Team &amp; sub-agents</h1><div class="muted">Create login IDs and choose each person's job role. What a role can do is set under Roles.</div></div></div>
<div class="grid2">
  <div class="tablewrap" style="align-self:start"><table><thead><tr><th>Name</th><th>Role</th><th>Login ID</th><th>Referral ID</th><th class="r">Clients</th><th class="r">Transfers this month</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?php if (in_array($r['role'], $mine, true) || (int)$r['id'] === (int)$u['id']): ?><a href="/?p=agents&id=<?= (int)$r['id'] ?>" style="color:var(--ink)"><strong><?= e($r['name']) ?></strong></a><?php else: ?><strong><?= e($r['name']) ?></strong><?php endif; ?></td><td><span class="pill <?= $r['role'] === 'agent' ? 'p-info' : 'p-gold' ?>"><?= e(role_label($r['role'])) ?></span></td><td><?= e($r['username']) ?></td><td><?= e($r['ref_code']) ?></td><td class="r num"><?= $r['role'] === 'agent' ? (int)$r['nclients'] : '' ?></td><td class="r num"><?= $r['role'] === 'agent' ? (int)$r['ntx_month'] : '' ?></td><td><?= $r['active'] ? '<span class="pill p-ok">Active</span>' : '<span class="pill p-bad">Off</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <form method="post" class="card" style="align-self:start"><?= csrf_field() ?>
    <h2 style="margin-bottom:10px">Add a person</h2>
    <div class="fields">
      <div class="f"><label for="role">Role</label><select id="role" name="role"><?php foreach ($mine as $ro): ?><option value="<?= e($ro) ?>"><?= e(role_label($ro)) ?></option><?php endforeach; ?></select></div>
      <div class="f"><label for="nname">Full name <span class="req">*</span></label><input id="nname" name="name" required></div>
      <div class="f"><label for="username">Login ID <span class="req">*</span></label><input id="username" name="username" required autocapitalize="none" placeholder="e.g. ravi.toronto"></div>
      <div class="f"><label for="ref_code">Referral ID (sub-agents)</label><input id="ref_code" name="ref_code" placeholder="Leave blank to create one" style="text-transform:uppercase"></div>
      <div class="f"><label for="nphone">Phone / WhatsApp</label><input id="nphone" name="phone"></div>
      <div class="f"><label for="nemail">Email</label><input id="nemail" name="email" type="email"></div>
      <div class="f"><label for="noffice">Office</label><select id="noffice" name="office"><?= opts(OFFICES, '') ?></select></div>
      <div class="f wide"><label for="ncomm">Commission terms (optional, only admins see this)</label><input id="ncomm" name="commission_note" placeholder="e.g. 0.5% of volume, paid monthly"></div>
    </div>
    <p class="tag">You get a one-time activation link to send them. They choose their own password; no temporary password is ever sent.</p>
    <button class="btn gold" name="act" value="create">Create login</button>
  </form>
</div>
<?php page_foot();
