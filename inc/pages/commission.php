<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
$isAdmin = is_staff();
$month = preg_match('/^\d{4}-\d{2}$/', get('m')) ? get('m') : date('Y-m');
$agentF = $isAdmin ? (int)get('agent') : (int)$u['id'];
$from = $month . '-01'; $to = date('Y-m-t', strtotime($from));

$where = "t.status IN ('accepted','completed') AND t.agent_id IS NOT NULL AND t.transfer_date BETWEEN ? AND ?";
$par = [$from, $to];
if ($agentF) { $where .= ' AND t.agent_id=?'; $par[] = $agentF; }
$rows = all("SELECT t.*, a.name agent_name, a.ref_code FROM transfers t JOIN users a ON a.id=t.agent_id WHERE $where ORDER BY t.transfer_date DESC, t.id DESC", $par);
$sum = all("SELECT a.id, a.name, a.ref_code, t.commission_currency cur, COUNT(*) n, SUM(t.send_amount) vol, SUM(t.commission) com, SUM(t.commission IS NULL) missing
  FROM transfers t JOIN users a ON a.id=t.agent_id WHERE $where GROUP BY a.id, a.name, a.ref_code, t.commission_currency ORDER BY a.name", $par);
$agents = $isAdmin ? all("SELECT id,name,ref_code FROM users WHERE role='agent' ORDER BY name") : [];
$myRates = !$isAdmin ? all('SELECT * FROM agent_commissions WHERE agent_id=? ORDER BY send_currency, receive_currency', [$u['id']]) : [];

if (get('export') === 'csv' && can('export')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dhanxfer-commission-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Ref no.', 'Sub-agent', 'Referral ID', 'Client', 'Amount sent', 'Send currency', 'Receive currency', 'Commission per unit', 'Commission', 'Commission currency', 'Status']);
    foreach ($rows as $r) fputcsv($out, [$r['transfer_date'], $r['ref_no'], $r['agent_name'], $r['ref_code'], $r['client_name'], $r['send_amount'], $r['send_currency'], $r['receive_currency'], $r['commission_per_unit'], $r['commission'], $r['commission_currency'], $r['status']]);
    audit('export_commission', 'commission', null, $month);
    exit;
}
$prevM = date('Y-m', strtotime("$from -1 month")); $nextM = date('Y-m', strtotime("$from +1 month"));
page_head($isAdmin ? 'Commission' : 'My commission'); ?>
<div class="spread"><div><h1><?= $isAdmin ? 'Sub-agent commission' : 'My commission' ?></h1><div class="muted"><?= date('F Y', strtotime($from)) ?> · accepted and completed transfers only</div></div>
  <form class="row" method="get"><input type="hidden" name="p" value="commission">
    <a class="btn sm" href="/?p=commission&m=<?= $prevM ?><?= $isAdmin && $agentF ? '&agent=' . $agentF : '' ?>">◀</a>
    <input type="month" name="m" value="<?= e($month) ?>" style="width:auto" onchange="this.form.submit()">
    <a class="btn sm" href="/?p=commission&m=<?= $nextM ?><?= $isAdmin && $agentF ? '&agent=' . $agentF : '' ?>">▶</a>
    <?php if ($isAdmin): ?><select name="agent" style="width:auto" onchange="this.form.submit()"><option value="">All sub-agents</option><?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $agentF === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ' · ' . $a['ref_code']) ?></option><?php endforeach; ?></select>
    <?php if (can('export')): ?><a class="btn sm" href="/?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a><?php endif; ?><?php endif; ?></form></div>
<?php if (!$isAdmin): ?>
<div class="card"><h3 style="margin-bottom:8px">Your commission rates</h3>
  <?php if ($myRates): ?><div class="row"><?php foreach ($myRates as $r): ?><span class="pill p-gold"><?= e(fmt_rate($r['per_unit']) . ' ' . $r['receive_currency'] . ' per ' . $r['send_currency'] . ' sent to ' . $r['receive_currency']) ?></span><?php endforeach; ?></div>
  <?php else: ?><div class="muted">An admin has not set your commission rates yet.</div><?php endif; ?></div>
<?php endif; ?>
<div class="tiles">
<?php foreach ($sum as $s): ?>
  <div class="tile"><div class="k"><?= $isAdmin ? e($s['name'] . ' · ' . $s['ref_code']) : 'Commission' ?></div>
    <div class="v num"><?= $s['com'] !== null ? e(money($s['com'], $s['cur'] ?? '')) : '—' ?></div>
    <div class="s"><?= (int)$s['n'] ?> transfers<?= (int)$s['missing'] ? ' · ' . (int)$s['missing'] . ' without a commission rate' : '' ?></div></div>
<?php endforeach; ?>
<?php if (!$sum): ?><div class="tile"><div class="k">Commission</div><div class="v num">0.00</div><div class="s">No accepted transfers this month</div></div><?php endif; ?>
</div>
<div class="tablewrap"><table><thead><tr><th>Date</th><th>Ref</th><?php if ($isAdmin): ?><th>Sub-agent</th><?php endif; ?><th>Client</th><th class="r">Sent</th><th class="r">Per unit</th><th class="r">Commission</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="num"><?= fdate($r['transfer_date']) ?></td><td><a href="/?p=transfers&id=<?= (int)$r['id'] ?>"><?= e($r['ref_no']) ?></a></td><?php if ($isAdmin): ?><td><?= e($r['agent_name']) ?></td><?php endif; ?><td><?= e($r['client_name']) ?></td>
  <td class="r num"><?= e(money($r['send_amount'], $r['send_currency'] ?? '')) ?></td><td class="r num"><?= $r['commission_per_unit'] !== null ? e(fmt_rate($r['commission_per_unit'])) : '<span class="pill p-warn">No rate</span>' ?></td>
  <td class="r num" style="color:var(--gold2)"><?= e(money($r['commission'], $r['commission_currency'] ?? '')) ?></td><td><?= tx_pill($r['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="empty">No accepted transfers in this month.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($isAdmin): ?><p class="tag">Commission = amount sent × the sub-agent's rate for that currency pair. It is worked out when a transfer is accepted and stays fixed after that. Set the rates on each sub-agent's page under Team &amp; sub-agents.</p><?php endif; ?>
<?php page_foot();
