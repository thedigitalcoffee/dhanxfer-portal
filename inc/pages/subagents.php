<?php
defined('DX') or exit;
// Sub-agent Control Center: status, short referral links, limits, manager, activity and results.
// Admins (team.agents) see every sub-agent; managers (agents.assigned) only the sub-agents assigned to them.
require_once __DIR__ . '/../agents.php';
require_once __DIR__ . '/../kyc.php';
$scope = agent_scope($u);
if (!$scope) { flash('Your role cannot open the Sub-agent Control Center.', 'bad'); redirect('/'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = one("SELECT * FROM users WHERE id=? AND role='agent'", [(int)post('id')]);
    if (!can_control_agent($a, $u)) { audit('agent_control_refused', 'user', (int)post('id'), post('act')); flash('You cannot change this account.', 'bad'); redirect('/?p=subagents'); }
    $act = post('act');
    try {
        switch ($act) {
            case 'suspend': agent_set_status($a, 'suspended', post('reason'), $u); flash($a['name'] . ' is suspended: logged out everywhere, referral link stopped.'); break;
            case 'deactivate': agent_set_status($a, 'deactivated', post('reason'), $u); flash($a['name'] . ' is deactivated. Their clients and commissions stay credited to them.'); break;
            case 'reactivate': $st = agent_set_status($a, 'active', post('reason'), $u); flash($st === 'pending' ? $a['name'] . ' is back to "pending activation": send a new activation link from the team page.' : $a['name'] . ' is active again.'); break;
            case 'link_disable': case 'link_enable':
                $l = one('SELECT * FROM referral_links WHERE id=? AND agent_id=?', [(int)post('link'), $a['id']]);
                if (!$l) throw new RuntimeException('Link not found.');
                ref_link_set($a, $l, $act === 'link_enable' ? 'active' : 'disabled', post('reason'), $u);
                flash($act === 'link_enable' ? 'Link switched on.' : 'Link switched off: it no longer credits new clients.'); break;
            case 'link_regenerate': ref_link_regenerate($a, post('slug'), post('reason'), $u); flash('New link made. Every older link of this sub-agent is retired and credits nobody.'); break;
            case 'limits': agent_set_limits($a, post('can_clients') === '1', post('can_transfers') === '1', post('reason'), $u); flash('Limits saved.'); break;
            case 'assign': agent_assign_manager($a, (int)post('manager_id') ?: null, post('reason'), $u); flash('Manager saved.'); break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $ex) { flash($ex->getMessage(), 'bad'); }
    redirect('/?p=subagents&id=' . $a['id']);
}

$pill = fn($s) => '<span class="pill ' . (['active' => 'p-ok', 'pending' => 'p-info', 'suspended' => 'p-warn', 'deactivated' => 'p-bad', 'disabled' => 'p-warn', 'retired' => 'p-mute'][$s] ?? 'p-mute') . '">' . e(AGENT_STATUSES[$s] ?? LINK_STATUSES[$s] ?? $s) . '</span>';

/* ----- one sub-agent ----- */
if (get('id') !== '') {
    $a = one("SELECT * FROM users WHERE id=? AND role='agent'", [(int)get('id')]);
    if (!can_control_agent($a, $u)) { flash('Sub-agent not found in your team.', 'bad'); redirect('/?p=subagents'); }
    $aid = (int)$a['id'];
    $links = all('SELECT * FROM referral_links WHERE agent_id=? ORDER BY is_primary DESC, id DESC', [$aid]);
    $primary = $links && $links[0]['is_primary'] ? $links[0] : null;
    $events = all('SELECT e.*, x.name by_name FROM agent_events e LEFT JOIN users x ON x.id=e.user_id WHERE e.agent_id=? ORDER BY e.id DESC LIMIT 40', [$aid]);
    $acts = all('SELECT action, created_at FROM audit WHERE user_id=? ORDER BY id DESC LIMIT 15', [$aid]);
    $refs = all("SELECT s.app_ref, s.status, s.created_at, s.name, l.slug FROM submissions s LEFT JOIN referral_links l ON l.id=s.ref_link_id WHERE s.agent_id=? ORDER BY s.id DESC LIMIT 20", [$aid]);
    $pend = ['Transfers waiting for a decision' => (int)val("SELECT COUNT(*) FROM transfers WHERE agent_id=? AND status='pending'", [$aid]),
             'Online forms waiting for staff' => (int)val("SELECT COUNT(*) FROM submissions WHERE agent_id=? AND status='new'", [$aid]),
             'Clients waiting for KYC' => (int)val("SELECT COUNT(*) FROM clients WHERE agent_id=? AND kyc IN ('Pending','Info requested','Escalated')", [$aid])];
    $perf = all("SELECT DATE_FORMAT(transfer_date,'%Y-%m') m, send_currency, COUNT(*) n, SUM(send_amount) s FROM transfers WHERE agent_id=? AND status IN ('accepted','completed') GROUP BY m, send_currency ORDER BY m DESC LIMIT 12", [$aid]);
    $mgr = $a['manager_id'] ? one('SELECT name, username FROM users WHERE id=?', [$a['manager_id']]) : null;
    $managers = $scope === 'all' ? array_values(array_filter(all("SELECT id, name, username, role FROM users WHERE role<>'agent' AND active=1 ORDER BY name"), fn($m) => role_can($m['role'], 'agents.assigned'))) : [];
    audit('view_agent_center', 'user', $aid, $a['username']);
    $reason = fn($ph = 'Reason (kept in the history)') => '<input name="reason" required minlength="5" maxlength="255" placeholder="' . e($ph) . '" style="flex:1;min-width:180px">';
    page_head('Sub-agent · ' . $a['name']); ?>
    <div class="spread"><div><div class="tag">Sub-agent · login ID <strong><?= e($a['username']) ?></strong> · #<?= $aid ?><?= $mgr ? ' · manager ' . e($mgr['name']) : '' ?></div><h1><?= e($a['name']) ?></h1>
      <div class="row" style="margin-top:6px"><?= $pill($a['account_status']) ?><?php if ($a['status_reason']): ?><span class="tag"><?= e($a['status_reason']) ?> · <?= fdatetime($a['status_changed_at']) ?></span><?php endif; ?></div></div>
      <a class="btn" href="/?p=subagents">All sub-agents</a></div>
    <div class="grid2"><div>
      <div class="card"><h3 style="margin-bottom:10px">Portal access</h3>
        <p class="tag" style="margin-top:0">Suspending or deactivating logs the sub-agent out everywhere at once, cancels unused activation links and stops their referral links. Clients, transfers and commissions stay credited to them.</p>
        <?php if (in_array($a['account_status'], ['active', 'pending'], true)): ?>
          <form method="post" class="row" style="margin-bottom:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><?= $reason() ?><button class="btn" name="act" value="suspend">Suspend</button><button class="btn danger" name="act" value="deactivate" data-confirm="Deactivate <?= e($a['name']) ?>?">Deactivate</button></form>
        <?php elseif ($a['account_status'] === 'suspended'): ?>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><?= $reason() ?><button class="btn ok" name="act" value="reactivate">Reactivate</button><button class="btn danger" name="act" value="deactivate">Deactivate</button></form>
        <?php elseif ($scope === 'all'): ?>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><?= $reason() ?><button class="btn ok" name="act" value="reactivate" data-confirm="Bring back <?= e($a['name']) ?>?">Reactivate</button></form>
        <?php else: ?><p class="tag">Only an admin can bring back a deactivated sub-agent.</p><?php endif; ?>
        <p class="tag" style="margin-bottom:0">Last login: <?= $a['last_login'] ? fdatetime($a['last_login']) : 'never' ?></p>
      </div>
      <div class="card"><h3 style="margin-bottom:10px">Referral links</h3>
        <?php if ($primary && $primary['status'] === 'active' && $a['account_status'] === 'active') share_block(ref_short_url($primary['slug']), $a['username'], 'Main link'); elseif ($a['account_status'] !== 'active') { ?><div class="banner warn">The account is not active, so no link credits new clients.</div><?php } ?>
        <div class="list">
        <?php foreach ($links as $l): ?><div class="li" style="flex-wrap:wrap;gap:6px"><div><strong>/r/<?= e($l['slug']) ?></strong> <?= $pill($l['status']) ?><?= $l['is_primary'] ? ' <span class="tag">main</span>' : '' ?>
            <div class="tag">since <?= fdate($l['created_at']) ?><?= $l['reason'] ? ' · ' . e($l['reason']) : '' ?> · <?= (int)val('SELECT COUNT(*) FROM submissions WHERE ref_link_id=?', [$l['id']]) ?> referral(s)</div></div>
          <?php if ($l['status'] !== 'retired'): ?><form method="post" class="row" style="width:100%"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="link" value="<?= (int)$l['id'] ?>"><?= $reason() ?>
            <?= $l['status'] === 'active' ? '<button class="btn sm" name="act" value="link_disable">Switch off</button>' : '<button class="btn sm ok" name="act" value="link_enable">Switch on</button>' ?></form><?php endif; ?></div>
        <?php endforeach; ?></div>
        <form method="post" class="row" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="act" value="link_regenerate">
          <div class="f" style="min-width:150px"><label for="slug">New link name</label><input id="slug" name="slug" required value="<?= e(slug_suggest($a)) ?>" autocapitalize="none" pattern="[A-Za-z0-9._\-]{3,30}"></div>
          <?= $reason('Why (e.g. link was misused)') ?><button class="btn" data-confirm="Retire every older link of this sub-agent and make a new one?">Regenerate</button></form>
        <p class="tag" style="margin-bottom:0">Link names are never given to anyone else. Old links that are retired or switched off open the plain form and credit nobody.</p>
      </div>
      <div class="card"><h3 style="margin-bottom:10px">What this sub-agent may do</h3>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="act" value="limits">
          <label class="check"><input type="checkbox" name="can_clients" value="1"<?= $a['agent_can_clients'] ? ' checked' : '' ?>> Add and edit their own clients</label>
          <label class="check"><input type="checkbox" name="can_transfers" value="1"<?= $a['agent_can_transfers'] ? ' checked' : '' ?>> Request transfers for their clients</label>
          <p class="tag">Sub-agents only ever see their own clients and transfers. They never approve KYC, beneficiaries or transfers.</p>
          <div class="row"><?= $reason() ?><button class="btn">Save limits</button></div></form>
      </div>
      <?php if ($scope === 'all'): ?>
      <div class="card"><h3 style="margin-bottom:10px">Manager</h3>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="act" value="assign">
          <div class="f" style="margin-bottom:8px"><label for="manager_id">Assigned to</label><select id="manager_id" name="manager_id"><option value="">No manager (admins only)</option><?php foreach ($managers as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)$a['manager_id'] === (int)$m['id'] ? ' selected' : '' ?>><?= e($m['name'] . ' · ' . role_label($m['role'])) ?></option><?php endforeach; ?></select></div>
          <div class="row"><?= $reason() ?><button class="btn">Save</button></div></form>
      </div>
      <?php endif; ?>
    </div><div>
      <div class="tiles"><?php foreach ($pend as $k => $n): ?><div class="tile <?= $n ? 'alert' : '' ?>"><div class="k"><?= e($k) ?></div><div class="v num"><?= $n ?></div></div><?php endforeach; ?></div>
      <div class="card"><h3 style="margin-bottom:10px">Referrals (latest 20)</h3><div class="tablewrap"><table><thead><tr><th>Form</th><th>Name</th><th>Link</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($refs as $r): ?><tr><td class="num"><?= e($r['app_ref']) ?></td><td><?= e(explode(' ', (string)$r['name'])[0]) ?></td><td><?= $r['slug'] ? '/r/' . e($r['slug']) : '<span class="tag">older link / typed</span>' ?></td><td><?= e($r['status']) ?></td><td><?= fdate($r['created_at']) ?></td></tr><?php endforeach; ?>
        <?php if (!$refs): ?><tr><td colspan="5" class="empty">No referrals yet.</td></tr><?php endif; ?></tbody></table></div></div>
      <div class="card"><h3 style="margin-bottom:10px">Results (accepted transfers)</h3><div class="tablewrap"><table><thead><tr><th>Month</th><th class="r">Transfers</th><th class="r">Volume</th></tr></thead><tbody>
        <?php foreach ($perf as $r): ?><tr><td><?= e(date('F Y', strtotime($r['m'] . '-01'))) ?></td><td class="r num"><?= (int)$r['n'] ?></td><td class="r num"><?= e(money($r['s'], $r['send_currency'] ?? '')) ?></td></tr><?php endforeach; ?>
        <?php if (!$perf): ?><tr><td colspan="3" class="empty">No accepted transfers yet.</td></tr><?php endif; ?></tbody></table></div></div>
      <div class="card"><h3 style="margin-bottom:10px">Control history</h3><div class="list">
        <?php foreach ($events as $ev): ?><div class="li"><div><strong><?= e(str_replace('_', ' ', $ev['action'])) ?></strong><?= $ev['detail'] ? ' <span class="tag">' . e($ev['detail']) . '</span>' : '' ?><div class="tag"><?= e($ev['by_name'] ?? 'system') ?><?= $ev['reason'] ? ' · ' . e($ev['reason']) : '' ?></div></div><div class="tag"><?= fdatetime($ev['at']) ?></div></div><?php endforeach; ?>
        <?php if (!$events): ?><div class="muted">Nothing yet.</div><?php endif; ?></div></div>
      <div class="card"><h3 style="margin-bottom:10px">Recent portal activity</h3><div class="list">
        <?php foreach ($acts as $ev): ?><div class="li"><div><?= e(str_replace('_', ' ', $ev['action'])) ?></div><div class="tag"><?= fdatetime($ev['created_at']) ?></div></div><?php endforeach; ?>
        <?php if (!$acts): ?><div class="muted">No activity.</div><?php endif; ?></div></div>
    </div></div>
    <?php page_foot(); return;
}

/* ----- list ----- */
[$sc, $sp] = agent_scope_sql('u', $u);
$where = [$sc]; $par = $sp;
$st = get('status');
if (isset(AGENT_STATUSES[$st])) { $where[] = 'u.account_status=?'; $par[] = $st; }
if (($qs = get('q')) !== '') { $where[] = '(u.name LIKE ? OR u.username LIKE ?)'; array_push($par, "%$qs%", "%$qs%"); }
$rows = all("SELECT u.id, u.name, u.username, u.account_status, u.last_login, m.name mgr,
    (SELECT slug FROM referral_links l WHERE l.agent_id=u.id AND l.is_primary=1 ORDER BY id DESC LIMIT 1) slug,
    (SELECT status FROM referral_links l WHERE l.agent_id=u.id AND l.is_primary=1 ORDER BY id DESC LIMIT 1) link_status,
    (SELECT COUNT(*) FROM clients c WHERE c.agent_id=u.id) nclients,
    (SELECT COUNT(*) FROM submissions s WHERE s.agent_id=u.id AND s.ref_verified=1) nrefs,
    (SELECT COUNT(*) FROM transfers t WHERE t.agent_id=u.id AND t.status='pending') + (SELECT COUNT(*) FROM submissions s WHERE s.agent_id=u.id AND s.status='new') npend,
    (SELECT COUNT(*) FROM transfers t WHERE t.agent_id=u.id AND t.status IN ('accepted','completed') AND t.transfer_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) nmonth
    FROM users u LEFT JOIN users m ON m.id=u.manager_id WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(u.account_status,'active','pending','suspended','deactivated'), u.name LIMIT 500", $par);
$counts = []; foreach (all('SELECT u.account_status s, COUNT(*) n FROM users u WHERE ' . $sc . ' GROUP BY u.account_status', $sp) as $r) $counts[$r['s']] = (int)$r['n'];
page_head('Sub-agent Control Center'); ?>
<div class="spread"><div><h1>Sub-agent Control Center</h1><div class="muted"><?= $scope === 'all' ? 'All sub-agents.' : 'Sub-agents assigned to you.' ?> Short links look like <strong><?= e(ref_base_url()) ?>/r/username</strong>.</div></div></div>
<div class="row" style="margin-bottom:12px"><a class="btn sm<?= $st === '' ? ' gold' : '' ?>" href="/?p=subagents">All (<?= array_sum($counts) ?>)</a><?php foreach (AGENT_STATUSES as $k => $lab): ?><a class="btn sm<?= $st === $k ? ' gold' : '' ?>" href="/?p=subagents&status=<?= e($k) ?>"><?= e($lab) ?> (<?= $counts[$k] ?? 0 ?>)</a><?php endforeach; ?>
  <form method="get" class="row" style="margin-left:auto"><input type="hidden" name="p" value="subagents"><input name="q" value="<?= e(get('q')) ?>" placeholder="Search name or login ID" aria-label="Search"><button class="btn sm">Search</button></form></div>
<div class="tablewrap"><table><thead><tr><th>Sub-agent</th><th>Status</th><th>Link</th><th>Manager</th><th class="r">Clients</th><th class="r">Link referrals</th><th class="r">Waiting</th><th class="r">Transfers this month</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="/?p=subagents&id=<?= (int)$r['id'] ?>" style="color:var(--ink)"><strong><?= e($r['name']) ?></strong></a><div class="tag"><?= e($r['username']) ?></div></td><td><?= $pill($r['account_status']) ?></td>
  <td><?= $r['slug'] ? '/r/' . e($r['slug']) . ' ' . $pill($r['link_status']) : '' ?></td><td><?= e($r['mgr'] ?? '') ?></td><td class="r num"><?= (int)$r['nclients'] ?></td><td class="r num"><?= (int)$r['nrefs'] ?></td><td class="r num"><?= (int)$r['npend'] ?></td><td class="r num"><?= (int)$r['nmonth'] ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="empty">No sub-agents here.</td></tr><?php endif; ?></tbody></table></div>
<?php page_foot();
