<?php
defined('DX') or exit;
$isAdmin = can('audit.all');
$labels = ['login' => 'Logged in', 'logout' => 'Logged out', 'login_failed' => 'Wrong password', 'install' => 'Set up the portal',
  'create_client' => 'Added a client', 'edit_client' => 'Edited a client', 'delete_client' => 'Deleted a client', 'view_client' => 'Opened a client',
  'kyc_verified' => 'Marked KYC verified', 'kyc_pending' => 'Set KYC to pending', 'kyc_rejected' => 'Rejected KYC', 'kyc_expired' => 'Marked KYC expired',
  'create_transfer' => 'Created a transfer', 'view_transfer' => 'Opened a transfer', 'transfer_accept' => 'Accepted a transfer', 'transfer_reject' => 'Rejected a transfer',
  'transfer_complete' => 'Completed a transfer', 'transfer_cancel' => 'Cancelled a transfer request',
  'public_registration' => 'Client sent the online form', 'view_submission' => 'Opened an online form', 'approve_submission' => 'Added an online form as client', 'reject_submission' => 'Rejected an online form',
  'create_user' => 'Created a login', 'reset_password' => 'Reset a password', 'disable_user' => 'Switched off a login', 'enable_user' => 'Switched on a login',
  'edit_user' => 'Edited a team member', 'change_password' => 'Changed own password', 'commission_set' => 'Set commission rates',
  'rates_set' => 'Set daily rates', 'rates_proposed' => 'Daily rates sent to Telegram', 'rates_settings' => 'Changed rate settings', 'pair_add' => 'Added a currency pair', 'pair_toggle' => 'Switched a currency pair',
  'telegram_bot_set' => 'Connected the Telegram bot', 'telegram_linked' => 'Linked Telegram', 'telegram_unlinked' => 'Unlinked Telegram',
  'export_clients' => 'Exported clients', 'export_transfers' => 'Exported transfers', 'export_commission' => 'Exported commission', 'export_audit' => 'Exported activity history',
  'view_id_file' => 'Opened an ID document', 'import' => 'Imported data', 'delete_activity' => 'Deleted a log entry', 'add_activity' => 'Added a daily log entry', 'reset_link' => 'Created a password reset link', 'reset_link_used' => 'Set a new password with a reset link', 'change_role' => 'Changed a job role', 'roles_changed' => 'Changed role permissions', 'market_backfill' => 'Added past market rates', 'view_pii' => 'Looked at full ID or account numbers', 'audit_verified' => 'Checked the log is intact', 'mfa_enabled' => 'Turned on two-step login', 'mfa_disabled' => 'Turned off two-step login', 'mfa_failed' => 'Wrong two-step code', 'mfa_reset' => 'Reset someone\'s two-step login', 'mfa_new_codes' => 'Made new recovery codes', 'mfa_recovery_used' => 'Used a recovery code', 'account_activated' => 'Activated their account', 'export_rates' => 'Exported rate tracking', 'activity_done' => 'Closed a follow-up'];

$who = $isAdmin ? get('user') : (string)$u['id'];
$act = get('action'); $from = valid_date(get('from')) ?? ''; $to = valid_date(get('to')) ?? ''; $qs = get('q');
$where = []; $par = [];
if ($who === 'public') $where[] = 'a.user_id IS NULL';
elseif ($who !== '') { $where[] = 'a.user_id=?'; $par[] = (int)$who; }
if ($act !== '' && isset($labels[$act])) { $where[] = 'a.action=?'; $par[] = $act; }
if ($from) { $where[] = 'a.created_at>=?'; $par[] = $from . ' 00:00:00'; }
if ($to) { $where[] = 'a.created_at<=?'; $par[] = $to . ' 23:59:59'; }
if ($qs !== '') { $where[] = 'a.detail LIKE ?'; $par[] = "%$qs%"; }
$sql = 'SELECT a.*, u.name, u.role FROM audit a LEFT JOIN users u ON u.id=a.user_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.id DESC';
$users = $isAdmin ? all('SELECT id, name, role FROM users ORDER BY name') : [];

if ($isAdmin && get('export') === 'csv') {
    $rows = all($sql . ' LIMIT 50000', $par);
    audit('export_audit', 'audit', null, count($rows) . ' rows');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dhanxfer-activity-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'Who', 'Role', 'Action', 'What', 'Record', 'Details', 'IP']);
    foreach ($rows as $r) fputcsv($out, [$r['created_at'], $r['name'] ?: 'Public / system', $r['role'], $r['action'], $labels[$r['action']] ?? $r['action'], $r['entity'] . ($r['entity_id'] ? ' #' . $r['entity_id'] : ''), $r['detail'], $r['ip']]);
    exit;
}
$rows = all($sql . ' LIMIT 500', $par);
$check = null;
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'verify') { $check = audit_verify(); audit('audit_verified', 'audit', null, $check[1] ? 'BROKEN at row ' . $check[1] : ($check[3] ? 'checkpoint missing' : 'intact, ' . $check[0] . ' rows')); }
$counts = $isAdmin ? all("SELECT u.id, u.name, COUNT(a.id) n, MAX(a.created_at) last FROM users u LEFT JOIN audit a ON a.user_id=u.id AND a.created_at >= (NOW() - INTERVAL 30 DAY) GROUP BY u.id, u.name ORDER BY n DESC") : [];
page_head($isAdmin ? 'Activity history' : 'My activity'); ?>
<div class="spread"><div><h1><?= $isAdmin ? 'Activity history' : 'My activity' ?></h1>
  <div class="muted"><?= $isAdmin ? 'Every action in the portal and on Telegram, with who did it and when. Kept permanently.' : 'Everything you have done in the portal.' ?></div></div>
  <?php if ($isAdmin): ?><a class="btn sm" href="/?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a><?php endif; ?></div>
<?php if ($isAdmin): ?>
<div class="card row" style="justify-content:space-between">
  <span>Every row is chained to the one before it, so a row changed or removed in the database is detected.</span>
  <form method="post" class="inline"><?= csrf_field() ?><button class="btn sm" name="act" value="verify">Check the log is intact</button></form>
  <?php if ($check): ?><div class="banner <?= ($check[1] || $check[3]) ? 'bad' : 'ok' ?>" style="flex-basis:100%;margin:0"><?= $check[1] ? 'Warning: the log was changed at row ' . (int)$check[1] . '. Rows from there on cannot be trusted.' : ($check[3] ? 'Warning: ' . e($check[3]) : 'Intact: all ' . (int)$check[0] . ' chained rows match, including the latest daily checkpoint.') ?><?= $check[2] ? ' ' . (int)$check[2] . ' older rows were written before the check existed.' : '' ?></div><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($isAdmin && $counts): ?>
<div class="tablewrap"><table><thead><tr><th>Person</th><th class="r">Actions in last 30 days</th><th>Last action</th><th></th></tr></thead><tbody>
<?php foreach ($counts as $c): ?><tr><td><?= e($c['name']) ?></td><td class="r num"><?= (int)$c['n'] ?></td><td><?= fdatetime($c['last']) ?></td><td><a class="btn sm" href="/?p=audit&user=<?= (int)$c['id'] ?>">See their log</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<form class="row card" method="get" style="padding:12px"><input type="hidden" name="p" value="audit">
  <?php if ($isAdmin): ?><select name="user" style="width:auto" aria-label="Person"><option value="">Everyone</option><option value="public"<?= $who === 'public' ? ' selected' : '' ?>>Public / system</option><?php foreach ($users as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $who === (string)$x['id'] ? ' selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <select name="action" style="width:auto" aria-label="Action"><option value="">All actions</option><?php foreach ($labels as $k => $l): ?><option value="<?= e($k) ?>"<?= $act === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <label class="tag" for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>" style="width:auto">
  <label class="tag" for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>" style="width:auto">
  <input name="q" value="<?= e($qs) ?>" placeholder="Search details" style="flex:1;min-width:150px">
  <button class="btn sm">Filter</button></form>
<div class="tablewrap"><table><thead><tr><th>When</th><?php if ($isAdmin): ?><th>Who</th><?php endif; ?><th>What</th><th>Details</th><?php if ($isAdmin): ?><th>IP</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="num" style="white-space:nowrap"><?= fdatetime($r['created_at']) ?></td><?php if ($isAdmin): ?><td><?= e($r['name'] ?: ($r['ip'] === 'cron' ? 'System' : 'Public')) ?></td><?php endif; ?>
  <td><?= e($labels[$r['action']] ?? $r['action']) ?></td><td><?= e($r['detail']) ?></td><?php if ($isAdmin): ?><td class="tag"><?= e($r['ip']) ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">No activity found.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="tag">Showing the latest 500 matching actions<?= $isAdmin ? '. Export CSV for the full list.' : '.' ?></p>
<?php page_foot();
