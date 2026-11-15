<?php
defined('DX') or exit;
// Commission withdrawals: every status change is made by an authorised person, with a reason, and kept in an
// insert-only history. Nothing is paid automatically.
require_once __DIR__ . '/../wallet.php';
require_once __DIR__ . '/../agents.php';
$canReview = can('withdrawals.review'); $canTeam = can('withdrawals.team'); $canPay = can('withdrawals.pay');
if (!$canReview && !$canTeam && !$canPay) { flash('Your role cannot open withdrawals.', 'bad'); redirect('/'); }
/** May this person see this withdrawal? Team reviewers only see their own team. */
$visible = function (array $w) use ($u, $canReview, $canPay): bool {
    if ($canReview || $canPay) return true;
    return (int)val('SELECT manager_id FROM users WHERE id=?', [$w['agent_id']]) === (int)$u['id'];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('act');
    try {
        if ($act === 'lock_fx') {
            try { $res = commission_lock_manual((int)post('transfer'), $u, post('rate'), post('source'), post('reason')); flash($res === 'proposed' ? 'Rate proposed. A different finance user must confirm it.' : 'Commission locked in CAD.'); }
            catch (RuntimeException $ex) { flash($ex->getMessage(), 'bad'); }
            redirect('/?p=withdrawals&v=fx');
        }
        $w = one('SELECT * FROM commission_withdrawals WHERE id=?', [(int)post('id')]);
        if (!$w || !$visible($w)) throw new RuntimeException('Withdrawal not found.');
        if ($act === 'reconcile') { wd_reconcile((int)$w['id'], $u, post('outcome'), post('reason')); flash('Reconciliation recorded.'); }
        elseif ($act === 'move') { wd_transition((int)$w['id'], post('to'), $u, ['reason' => post('reason'), 'confirm' => post('confirm'), 'payment_date' => post('payment_date'), 'reference' => post('reference'), 'method' => post('method')]); flash('Withdrawal #' . $w['id'] . ' is now ' . WD_STATUSES[post('to')] . '. The sub-agent was notified.'); }
        else throw new RuntimeException('Unknown action.');
    } catch (RuntimeException $ex) { flash($ex->getMessage(), 'bad'); }
    redirect('/?p=withdrawals&id=' . (int)post('id'));
}
$pill = fn($s) => '<span class="pill ' . (['paid' => 'p-ok', 'approved' => 'p-info', 'processing' => 'p-gold', 'under_review' => 'p-warn', 'requested' => 'p-warn', 'failed' => 'p-bad', 'rejected' => 'p-bad', 'cancelled' => 'p-mute'][$s] ?? 'p-mute') . '">' . e(WD_STATUSES[$s] ?? $s) . '</span>';
$cad = fn($v) => 'CAD ' . number_format((float)$v, 2);

if (get('id') !== '') {
    $w = one('SELECT w.*, a.name agent_name, a.username FROM commission_withdrawals w JOIN users a ON a.id=w.agent_id WHERE w.id=?', [(int)get('id')]);
    if (!$w || !$visible($w)) { flash('Withdrawal not found.', 'bad'); redirect('/?p=withdrawals'); }
    $evs = all('SELECT e.*, x.name by_name FROM withdrawal_events e LEFT JOIN users x ON x.id=e.user_id WHERE e.withdrawal_id=? ORDER BY e.id', [$w['id']]);
    $allowed = wd_allowed($w, $u);
    $bal = wallet_balance((int)$w['agent_id']);
    $broken = wd_verify((int)$w['id']);
    audit('view_withdrawal', 'withdrawal', $w['id'], '');
    page_head('Withdrawal #' . $w['id']); ?>
    <div class="spread"><div><div class="tag">Withdrawal #<?= (int)$w['id'] ?> · <?= e($w['agent_name']) ?> (<?= e($w['username']) ?>)</div><h1><?= $cad($w['amount_cad']) ?></h1><div class="row" style="margin-top:6px"><?= $pill($w['status']) ?><?php if ($w['status'] === 'failed'): ?><span class="tag">Reconciliation: <?= e($w['reconcile_outcome'] ? ($w['reconcile_outcome'] === 'paid' ? 'money did arrive' : 'not paid out') : 'not done yet') ?></span><?php endif; ?></div></div>
      <a class="btn" href="/?p=withdrawals">All withdrawals</a></div>
    <?php if ($broken): ?><div class="banner bad">The history of this withdrawal was changed outside the portal<?= $broken > 0 ? ' (row ' . (int)$broken . ')' : ' (rows removed)' ?>. Report this to the owner.</div><?php endif; ?>
    <div class="grid2"><div>
      <div class="card"><dl class="kv"><?= kv('Requested', fdatetime($w['requested_at'])) . kv('Minimum that applied', $cad($w['min_applied'])) . kv('Note from sub-agent', $w['note']) . kv('Approved', $w['approved_at'] ? fdatetime($w['approved_at']) . ' by ' . (string)val('SELECT name FROM users WHERE id=?', [$w['approved_by']]) : '') . kv('Paid', $w['paid_at'] ? $w['paid_date'] . ' · ref ' . $w['paid_reference'] . ($w['paid_method'] ? ' · ' . $w['paid_method'] : '') . ' · by ' . (string)val('SELECT name FROM users WHERE id=?', [$w['paid_by']]) : '') ?></dl>
        <p class="tag" style="margin-bottom:0">Sub-agent wallet now: available <?= $cad($bal['available']) ?> · reserved <?= $cad($bal['reserved']) ?> · paid <?= $cad($bal['paid']) ?></p></div>
      <?php foreach ($allowed as $to): ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="act" value="move"><input type="hidden" name="to" value="<?= e($to) ?>">
          <h3 style="margin-bottom:8px">Move to <?= e(WD_STATUSES[$to]) ?></h3>
          <?php if ($to === 'paid'): ?><div class="fields">
            <div class="f"><label for="payment_date">Payment date</label><input id="payment_date" name="payment_date" type="date" max="<?= e(today()) ?>" required></div>
            <div class="f"><label for="reference">Transaction / reference no.</label><input id="reference" name="reference" required maxlength="60"></div>
            <div class="f wide"><label for="method">Method</label><input id="method" name="method" maxlength="60" placeholder="e.g. Interac e-Transfer, bank wire"></div></div>
            <label class="check"><input type="checkbox" name="confirm" value="1" required> I confirm the money was actually sent (payment confirmation seen).</label><?php endif; ?>
          <div class="row" style="margin-top:8px"><input name="reason" required minlength="5" maxlength="400" placeholder="Reason (kept in the history)" style="flex:1;min-width:180px"><button class="btn <?= in_array($to, ['rejected', 'cancelled', 'failed'], true) ? 'danger' : 'gold' ?>"><?= e(WD_STATUSES[$to]) ?></button></div></form>
      <?php endforeach; ?>
      <?php if ($w['status'] === 'failed' && $canPay && (int)$u['id'] !== (int)$w['agent_id']): ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="act" value="reconcile">
          <h3 style="margin-bottom:8px">Reconcile the failed payment</h3>
          <p class="tag" style="margin-top:0">Check the bank statement or the provider before anything else. The money is released or paid again only after this.</p>
          <div class="f"><label for="outcome">What was confirmed?</label><select id="outcome" name="outcome"><option value="">Choose…</option><option value="not_paid">The money did not go out (returned or never sent)</option><option value="paid">The money did arrive after all</option></select></div>
          <div class="row" style="margin-top:8px"><input name="reason" required minlength="10" maxlength="400" placeholder="What was checked (statement, provider reply…)" style="flex:1;min-width:180px"><button class="btn">Record</button></div></form>
      <?php endif; ?>
      <?php if (!$allowed && !in_array($w['status'], ['paid', 'rejected', 'cancelled'], true)): ?><p class="tag">Nothing you can change here<?= (int)$u['id'] === (int)$w['agent_id'] ? ' (your own request)' : '' ?>.</p><?php endif; ?>
    </div><div>
      <div class="card"><h3 style="margin-bottom:10px">History (cannot be edited)</h3><div class="list">
        <?php foreach ($evs as $ev): ?><div class="li"><div><strong><?= e(($ev['from_status'] ? (WD_STATUSES[$ev['from_status']] ?? $ev['from_status']) . ' → ' : '') . (WD_STATUSES[$ev['to_status']] ?? $ev['to_status'])) ?></strong><div class="tag"><?= e($ev['by_name'] ?? 'system') ?><?= $ev['reason'] ? ' · ' . e($ev['reason']) : '' ?></div></div><div class="tag"><?= e($ev['at']) ?></div></div><?php endforeach; ?></div></div>
    </div></div>
    <?php page_foot(); return;
}

if (get('v') === 'fx' && $canPay) {
    $rows = all("SELECT t.id, t.ref_no, DATE(t.created_at) transfer_date, t.commission, t.commission_currency, t.fx_proposed_rate, t.fx_proposed_source, t.fx_proposed_by, a.name FROM transfers t JOIN users a ON a.id=t.agent_id WHERE t.status='completed' AND t.completed_via='panel' AND t.created_at >= ? AND t.commission IS NOT NULL AND t.commission_locked_at IS NULL ORDER BY t.created_at LIMIT 200", [wallet_start()]);
    page_head('Commission waiting for an FX rate'); ?>
    <div class="spread"><div><h1>Commission waiting for an FX rate</h1><div class="muted">Completed orders whose CAD value could not be locked automatically (no market rate saved for the order day). Enter that day's CAD rate and its source.</div></div><a class="btn" href="/?p=withdrawals">Back</a></div>
    <div class="tablewrap"><table><thead><tr><th>Order</th><th>Day</th><th>Sub-agent</th><th class="r">Commission</th><th>Lock</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td class="num"><?= e($r['ref_no']) ?></td><td><?= e($r['transfer_date']) ?></td><td><?= e($r['name']) ?></td><td class="r num"><?= e($r['commission'] . ' ' . $r['commission_currency']) ?><?= $r['fx_proposed_rate'] ? '<div class="tag">proposed ' . e((float)$r['fx_proposed_rate'] . ' · ' . $r['fx_proposed_source']) . ' by #' . (int)$r['fx_proposed_by'] . '</div>' : '' ?></td>
      <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="act" value="lock_fx"><input type="hidden" name="transfer" value="<?= (int)$r['id'] ?>"><input name="rate" required placeholder="<?= e($r['commission_currency']) ?> per 1 CAD" style="width:110px"><input name="source" required placeholder="Source (e.g. Bank of Canada)" style="width:150px"><input name="reason" required minlength="5" placeholder="Reason" style="width:120px"><button class="btn sm"><?= $r['fx_proposed_rate'] ? 'Confirm' : 'Propose' ?></button></form></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">Nothing waiting.</td></tr><?php endif; ?></tbody></table></div>
    <?php page_foot(); return;
}

$st = get('status');
$where = ['1=1']; $par = [];
if (!$canReview && !$canPay) { $where[] = 'a.manager_id=?'; $par[] = (int)$u['id']; }
if (isset(WD_STATUSES[$st])) { $where[] = 'w.status=?'; $par[] = $st; }
$rows = all('SELECT w.*, a.name agent_name, a.username FROM commission_withdrawals w JOIN users a ON a.id=w.agent_id WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(w.status,'requested','under_review','approved','processing','failed','paid','rejected','cancelled'), w.id DESC LIMIT 500", $par);
$nfx = $canPay ? (int)val("SELECT COUNT(*) FROM transfers WHERE status='completed' AND completed_via='panel' AND created_at >= ? AND commission IS NOT NULL AND commission_locked_at IS NULL", [wallet_start()]) : 0;
page_head('Commission withdrawals'); ?>
<div class="spread"><div><h1>Commission withdrawals</h1><div class="muted">Requested → Under review → Approved → Processing → Paid. Every change is made by a person and logged. <?= $canReview || $canPay ? 'All sub-agents.' : 'Your team only.' ?></div></div>
  <?php if ($nfx): ?><a class="btn" href="/?p=withdrawals&v=fx"><?= $nfx ?> order(s) waiting for an FX rate</a><?php endif; ?></div>
<div class="row" style="margin-bottom:12px"><a class="btn sm<?= $st === '' ? ' gold' : '' ?>" href="/?p=withdrawals">All</a><?php foreach (WD_STATUSES as $k => $lab): ?><a class="btn sm<?= $st === $k ? ' gold' : '' ?>" href="/?p=withdrawals&status=<?= e($k) ?>"><?= e($lab) ?></a><?php endforeach; ?></div>
<div class="tablewrap"><table><thead><tr><th>#</th><th>Sub-agent</th><th class="r">Amount</th><th>Status</th><th>Requested</th><th>Paid ref.</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="/?p=withdrawals&id=<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></a></td><td><?= e($r['agent_name']) ?><div class="tag"><?= e($r['username']) ?></div></td><td class="r num"><?= $cad($r['amount_cad']) ?></td><td><?= $pill($r['status']) ?></td><td><?= fdate($r['requested_at']) ?></td><td><?= e($r['paid_reference']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="empty">No withdrawals.</td></tr><?php endif; ?></tbody></table></div>
<?php page_foot();
