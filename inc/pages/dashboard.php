<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
$liveRates = current_rates();
function rates_strip(array $live): void {
    if (!$live) { echo '<div class="banner warn">Today\'s rates are not set yet.</div>'; return; }
    echo '<div class="card" style="padding:12px 16px"><div class="row" style="justify-content:space-between"><span class="tag" style="text-transform:uppercase;letter-spacing:.07em">Today\'s rates</span><a class="tag" href="/?p=rates">Details</a></div><div class="row" style="margin-top:8px;gap:18px">';
    foreach ($live as $r) {
        $old = $r['rate_date'] !== today();
        echo '<div><div class="tag">' . e(pair_label($r['send_currency'], $r['receive_currency'])) . ($old ? ' <span class="pill p-warn">not updated today</span>' : '') . '</div><div class="num" style="font-size:15px">Buy <strong>' . e(fmt_rate($r['buy_rate'])) . '</strong> · Sell <strong style="color:var(--gold2)">' . e(fmt_rate($r['sell_rate'])) . '</strong></div></div>';
    }
    echo '</div></div>';
}

if ($u['role'] === 'agent') {
    $aid = (int)$u['id'];
    require_once __DIR__ . '/../kyc.php';
    $link = agent_link($u);
    $nClients = (int)val('SELECT COUNT(*) FROM clients WHERE agent_id=?', [$aid]);
    $nPending = (int)val("SELECT COUNT(*) FROM transfers WHERE agent_id=? AND status='pending'", [$aid]);
    $month = all("SELECT send_currency, SUM(send_amount) s, COUNT(*) n FROM transfers WHERE agent_id=? AND status IN ('accepted','completed') AND transfer_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY send_currency", [$aid]);
    $mCount = array_sum(array_column($month, 'n'));
    $myCom = all("SELECT commission_currency c, SUM(commission) s FROM transfers WHERE agent_id=? AND status IN ('accepted','completed') AND commission IS NOT NULL AND transfer_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY commission_currency", [$aid]);
    $subs = all("SELECT name, phone, status, created_at FROM submissions WHERE agent_id=? AND status='new' ORDER BY id DESC LIMIT 20", [$aid]);
    $recent = all('SELECT t.*, c.client_no FROM transfers t LEFT JOIN clients c ON c.id=t.client_id WHERE t.agent_id=? ORDER BY t.id DESC LIMIT 10', [$aid]);
    page_head('Home'); ?>
    <div class="spread"><div><h1>Welcome, <?= e($u['name']) ?></h1><div class="muted">Your referral code is <strong style="color:var(--gold2)"><?= e($u['username']) ?></strong></div></div>
      <div class="row"><a class="btn gold" href="/?p=client_form">+ New client</a></div></div>
    <?php rates_strip($liveRates); ?>
    <?php if ($link) share_block($link, $u['username']); else { ?><div class="banner warn">Your referral link is switched off. Contact the DhanXfer office for a new one.</div><?php } ?>
    <div class="tiles">
      <a class="tile" href="/?p=clients"><div class="k">My clients</div><div class="v num"><?= $nClients ?></div></a>
      <a class="tile <?= $nPending ? 'alert' : '' ?>" href="/?p=transfers&status=pending"><div class="k">Waiting for admin</div><div class="v num"><?= $nPending ?></div><div class="s">transfer requests</div></a>
      <div class="tile"><div class="k">Accepted this month</div><div class="v num"><?= $mCount ?></div><div class="s"><?= $month ? e(implode(' · ', array_map(fn($r) => money($r['s'], $r['send_currency']), $month))) : 'No transfers yet' ?></div></div>
      <a class="tile" href="/?p=commission"><div class="k">My commission this month</div><div class="v num <?= count($myCom) > 1 ? 'sm' : '' ?>"><?= $myCom ? implode('<br>', array_map(fn($r) => e(money($r['s'], $r['c'] ?? '')), $myCom)) : '0.00' ?></div></a>
      <div class="tile <?= $subs ? 'alert' : '' ?>"><div class="k">Online sign-ups waiting</div><div class="v num"><?= count($subs) ?></div><div class="s">admin is reviewing</div></div>
    </div>
    <div class="grid2">
      <div><h2 style="margin-bottom:10px">Recent transfers</h2>
        <div class="tablewrap"><table><thead><tr><th>Date</th><th>Client</th><th class="r">Sent</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recent as $t): ?><tr><td class="num"><?= fdate($t['transfer_date']) ?></td><td><?= e($t['client_name']) ?></td><td class="r num"><?= e(money($t['send_amount'], $t['send_currency'] ?? '')) ?></td><td><?= tx_pill($t['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="4" class="empty">No transfers yet.</td></tr><?php endif; ?>
        </tbody></table></div></div>
      <div><h2 style="margin-bottom:10px">Online sign-ups with your ID</h2>
        <div class="list"><?php foreach ($subs as $s): ?><div class="li"><div><strong><?= e($s['name']) ?></strong><div class="tag"><?= e($s['phone']) ?></div></div><div class="tag"><?= fdate($s['created_at']) ?><br>Under review</div></div><?php endforeach; ?>
        <?php if (!$subs): ?><div class="muted">None waiting.</div><?php endif; ?></div></div>
    </div>
    <?php page_foot(); return;
}

/* ---------------- roles without access to all clients (e.g. sub-agent managers) never see the operations board ---------------- */
if (!can('clients.all')) {
    require_once __DIR__ . '/../agents.php';
    if (agent_scope($u)) redirect('/?p=subagents');
    page_head('Home'); ?><h1>Welcome, <?= e($u['name']) ?></h1><p class="muted">Use the menu above.</p><?php page_foot(); return;
}

/* ---------------- super admin lands on the owner overview ---------------- */
if ($u['role'] === 'super_admin' && get('ops') !== '1' && get('d') === '') { require __DIR__ . '/insights.php'; return; }

/* ---------------- admin ---------------- */
$d = valid_date(get('d')) ?? today();
$tx = all("SELECT * FROM transfers WHERE transfer_date=? AND status IN ('accepted','completed')", [$d]);
$vol = []; $fee = [];
foreach ($tx as $t) { $c = $t['send_currency'] ?: '—'; $vol[$c] = ($vol[$c] ?? 0) + (float)$t['send_amount']; $fee[$c] = ($fee[$c] ?? 0) + (float)$t['fee']; }
$fmt = fn($m) => $m ? implode('<br>', array_map(fn($c, $v) => e(money($v, $c)), array_keys($m), $m)) : '0.00';
$pending = all('SELECT t.*, a.name agent_name, a.ref_code FROM transfers t LEFT JOIN users a ON a.id=t.agent_id WHERE t.status=\'pending\' ORDER BY t.id ASC LIMIT 30');
$nSubs = (int)val("SELECT COUNT(*) FROM submissions WHERE status='new'");
$newClients = (int)val('SELECT COUNT(*) FROM clients WHERE DATE(created_at)=?', [$d]);
$kyc = all("SELECT id, client_no, name, office FROM clients WHERE kyc='Pending' ORDER BY id DESC LIMIT 8");
$nKyc = (int)val("SELECT COUNT(*) FROM clients WHERE kyc='Pending'");
$fu = all("SELECT * FROM activities WHERE status='Open' AND follow_up IS NOT NULL AND follow_up <= CURDATE() ORDER BY follow_up LIMIT 10");
$prev = date('Y-m-d', strtotime($d . ' -1 day')); $next = date('Y-m-d', strtotime($d . ' +1 day'));
page_head('Today'); ?>
<div class="spread">
  <div><h1><?= $d === today() ? 'Today' : fdate($d) ?></h1><div class="muted"><?= date('l, F j, Y', strtotime($d)) ?></div></div>
  <form class="row" method="get"><input type="hidden" name="p" value="dashboard">
    <a class="btn sm" href="/?p=dashboard&d=<?= $prev ?>" aria-label="Previous day">◀</a>
    <input type="date" name="d" value="<?= e($d) ?>" style="width:auto" onchange="this.form.submit()">
    <a class="btn sm" href="/?p=dashboard&d=<?= $next ?>" aria-label="Next day">▶</a>
    <a class="btn sm" href="/?p=dashboard">Today</a></form>
</div>
<?php rates_strip($liveRates); ?>
<div class="tiles">
  <a class="tile <?= $pending ? 'alert' : '' ?>" href="/?p=transfers&status=pending"><div class="k">Transfers to accept</div><div class="v num"><?= count($pending) ?></div><div class="s">waiting for an admin</div></a>
  <div class="tile"><div class="k">Accepted / completed</div><div class="v num"><?= count($tx) ?></div><div class="s">on this day</div></div>
  <div class="tile"><div class="k">Volume sent</div><div class="v num <?= count($vol) > 1 ? 'sm' : '' ?>"><?= $fmt($vol) ?></div></div>
  <div class="tile"><div class="k">Fees earned</div><div class="v num <?= count($fee) > 1 ? 'sm' : '' ?>"><?= $fmt($fee) ?></div></div>
  <a class="tile <?= $nSubs ? 'alert' : '' ?>" href="/?p=submissions"><div class="k">New online forms</div><div class="v num"><?= $nSubs ?></div><div class="s">to review</div></a>
  <div class="tile"><div class="k">New clients</div><div class="v num"><?= $newClients ?></div><div class="s"><?= $nKyc ?> with KYC pending</div></div>
</div>
<div class="grid2">
  <div>
    <h2 style="margin-bottom:10px"><?= can('transfers.decide') ? 'Waiting for your decision' : 'Waiting for an admin' ?></h2>
    <div class="tablewrap"><table><thead><tr><th>Client</th><th>Sub-agent</th><th class="r">Sent</th><th class="r">Received</th><th></th></tr></thead><tbody>
    <?php foreach ($pending as $t): ?>
      <tr><td><a href="/?p=transfers&id=<?= (int)$t['id'] ?>"><?= e($t['client_name']) ?></a><div class="tag"><?= e($t['ref_no']) ?> · <?= fdate($t['transfer_date']) ?></div></td>
        <td><?= e($t['agent_name'] ? $t['agent_name'] . ' · ' . $t['ref_code'] : 'Direct') ?></td>
        <td class="r num"><?= e(money($t['send_amount'], $t['send_currency'] ?? '')) ?></td>
        <td class="r num"><?= e(money($t['receive_amount'], $t['receive_currency'] ?? '')) ?></td>
        <td style="white-space:nowrap"><?php if (can('transfers.decide')): ?><form method="post" action="/?p=transfers" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn sm ok" name="act" value="accept">Accept</button> <button class="btn sm danger" name="act" value="reject" data-confirm="Reject this transfer request?">Reject</button></form><?php else: ?><a class="btn sm" href="/?p=transfers&id=<?= (int)$t['id'] ?>">View</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$pending): ?><tr><td colspan="5" class="empty">Nothing waiting. All transfer requests are decided.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <div>
    <h2 style="margin-bottom:10px">Follow-ups due</h2>
    <div class="list"><?php foreach ($fu as $f): ?><div class="li <?= $f['follow_up'] < today() ? 'overdue' : 'due' ?>"><div><strong><?= e($f['client_name'] ?: 'No client') ?></strong><div class="tag"><?= e($f['type']) ?><?= $f['notes'] ? ' · ' . e(mb_strimwidth($f['notes'], 0, 80, '…')) : '' ?></div></div><div class="tag" style="text-align:right;white-space:nowrap"><?= $f['follow_up'] < today() ? 'Overdue' : 'Today' ?><br><?= fdate($f['follow_up']) ?></div></div><?php endforeach; ?>
    <?php if (!$fu): ?><div class="muted">No follow-ups due.</div><?php endif; ?></div>
    <h2 style="margin:20px 0 10px">KYC waiting for review</h2>
    <div class="list"><?php foreach ($kyc as $c): ?><a class="li" href="/?p=client&id=<?= (int)$c['id'] ?>" style="text-decoration:none;color:inherit"><div><strong><?= e($c['name']) ?></strong><div class="tag"><?= e($c['client_no']) ?> · <?= e($c['office']) ?></div></div><div><?= kyc_pill('Pending') ?></div></a><?php endforeach; ?>
    <?php if (!$kyc): ?><div class="muted">All clients are reviewed.</div><?php endif; ?></div>
  </div>
</div>
<?php page_foot();
