<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
require_once __DIR__ . '/../wallet.php';
$isAdmin = is_staff();
// sub-agent: request a withdrawal (status changes after this are made only by the office)
if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'withdraw') {
    try { $wid = wd_request($u, post('amount'), post('note')); flash('Withdrawal #' . $wid . ' requested. The amount is reserved; the office will review it.'); }
    catch (RuntimeException $ex) { flash($ex->getMessage(), 'bad'); }
    redirect('/?p=commission');
}
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
<?php if (!$isAdmin): wallet_sync((int)$u['id']); $bal = wallet_balance((int)$u['id']); $byCur = wallet_by_currency((int)$u['id']); $min = wd_minimum((int)$u['id']);
  $myW = all('SELECT * FROM commission_withdrawals WHERE agent_id=? ORDER BY id DESC LIMIT 20', [$u['id']]); ?>
<div class="card" style="border-color:var(--gold)"><h3 style="margin-bottom:8px">Commission wallet (CAD)</h3>
  <div class="tiles">
    <div class="tile"><div class="k">Available</div><div class="v num">CAD <?= number_format($bal['available'], 2) ?></div><div class="s">ready to withdraw</div></div>
    <div class="tile"><div class="k">Reserved</div><div class="v num">CAD <?= number_format($bal['reserved'], 2) ?></div><div class="s">in withdrawals being handled</div></div>
    <div class="tile"><div class="k">Paid to you</div><div class="v num">CAD <?= number_format($bal['paid'], 2) ?></div><div class="s">all time</div></div>
    <div class="tile"><div class="k">Not yet earned</div><div class="v num">CAD <?= number_format($bal['pending'], 2) ?></div><div class="s">accepted orders, counted when completed</div></div>
  </div>
  <div class="tablewrap"><table><thead><tr><th>Earned in</th><th class="r">Orders</th><th class="r">Amount</th><th class="r">Locked CAD value</th></tr></thead><tbody>
  <?php foreach ($byCur as $r): ?><tr><td><?= e($r['cur']) ?></td><td class="r num"><?= (int)$r['n'] ?></td><td class="r num"><?= e(money($r['amount'], $r['cur'])) ?></td><td class="r num">CAD <?= number_format((float)$r['cad'], 2) ?><?= (int)$r['unlocked'] ? ' <span class="pill p-warn">' . (int)$r['unlocked'] . ' waiting for FX</span>' : '' ?></td></tr><?php endforeach; ?>
  <?php if ($byCur): ?><tr><td><strong>Total</strong></td><td class="r num"><?= array_sum(array_column($byCur, 'n')) ?></td><td></td><td class="r num"><strong>CAD <?= number_format($bal['earned'], 2) ?></strong></td></tr><?php else: ?><tr><td colspan="4" class="empty">No completed orders with commission yet.</td></tr><?php endif; ?></tbody></table></div>
  <p class="tag">Each order's commission is turned into CAD with that order day's rate when the order is sent, and never recalculated.</p>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="act" value="withdraw">
    <div class="f" style="min-width:140px"><label for="amount">Amount (CAD)</label><input id="amount" name="amount" inputmode="decimal" required placeholder="<?= number_format($min, 0, '.', '') ?>"></div>
    <div class="f" style="flex:1;min-width:180px"><label for="wnote">Note (optional, no account numbers)</label><input id="wnote" name="note" maxlength="200"></div>
    <button class="btn gold"<?= $bal['available'] < $min ? ' disabled' : '' ?>>Request withdrawal</button></form>
  <p class="tag">Minimum: CAD <?= number_format($min, 2) ?><?= $min === WD_FIRST_MIN_CAD ? ' for your first withdrawal (CAD 100 after your first paid withdrawal)' : '' ?>. Partial withdrawals are fine. After you send a request, only the DhanXfer office can change it; you will get an email at each step.</p>
  <?php if ($myW): ?><div class="tablewrap"><table><thead><tr><th>#</th><th class="r">Amount</th><th>Status</th><th>Requested</th><th>Paid</th></tr></thead><tbody>
  <?php foreach ($myW as $w): ?><tr><td>#<?= (int)$w['id'] ?></td><td class="r num">CAD <?= number_format((float)$w['amount_cad'], 2) ?></td><td><span class="pill <?= $w['status'] === 'paid' ? 'p-ok' : (in_array($w['status'], ['rejected', 'failed'], true) ? 'p-bad' : 'p-warn') ?>"><?= e(WD_STATUSES[$w['status']]) ?></span></td><td><?= fdate($w['requested_at']) ?></td><td><?= $w['paid_date'] ? e($w['paid_date'] . ' · ref ' . $w['paid_reference']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
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
<div class="tablewrap"><table><thead><tr><th>Date</th><th>Ref</th><?php if ($isAdmin): ?><th>Sub-agent</th><?php endif; ?><th>Client</th><th class="r">Sent</th><th class="r">Per unit</th><th class="r">Commission</th><th class="r">CAD (locked)</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="num"><?= fdate($r['transfer_date']) ?></td><td><a href="/?p=transfers&id=<?= (int)$r['id'] ?>"><?= e($r['ref_no']) ?></a></td><?php if ($isAdmin): ?><td><?= e($r['agent_name']) ?></td><?php endif; ?><td><?= e($r['client_name']) ?></td>
  <td class="r num"><?= e(money($r['send_amount'], $r['send_currency'] ?? '')) ?></td><td class="r num"><?= $r['commission_per_unit'] !== null ? e(fmt_rate($r['commission_per_unit'])) : '<span class="pill p-warn">No rate</span>' ?></td>
  <td class="r num" style="color:var(--gold2)"><?= e(money($r['commission'], $r['commission_currency'] ?? '')) ?></td><td class="r num"><?= $r['commission_cad'] !== null ? number_format((float)$r['commission_cad'], 2) . '<div class="tag">@' . e(rtrim(rtrim((string)$r['commission_fx_rate'], '0'), '.')) . ' · ' . e($r['commission_fx_date']) . '</div>' : '<span class="tag">waiting for FX</span>' ?></td><td><?= tx_pill($r['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="empty">No accepted transfers in this month.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($isAdmin): ?><p class="tag">Commission = amount sent × the sub-agent's rate for that currency pair. It is locked when the order is submitted, together with its CAD value at that day's mid-market rate (CAD → destination currency), and never recalculated. It counts in the wallet once the order is completed. Set the rates on each sub-agent's page under Team &amp; sub-agents.</p><?php endif; ?>
<?php page_foot();
