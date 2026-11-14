<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
require_once __DIR__ . '/../beneficiary.php';
function benef_usable_tx(array $t): array { return benef_usable(one('SELECT * FROM beneficiaries WHERE id=?', [$t['beneficiary_id']]), (int)$t['client_id']); }
$isAdmin = is_staff();
$canDecide = can('transfers.decide');

/* ----- actions ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = one('SELECT * FROM transfers WHERE id=?', [(int)post('id')]);
    $act = post('act');
    if (!$t || !can_see_transfer($t)) { flash('Transfer not found.', 'bad'); redirect('/?p=transfers'); }
    $note = mb_substr(post('note'), 0, 250);
    $ok = false;
    if ($canDecide && $act === 'accept' && $t['status'] === 'pending' && $t['client_id'] && !($elig = transfer_eligibility(one('SELECT * FROM clients WHERE id=?', [$t['client_id']]) ?? ['id' => 0, 'kyc' => 'Pending']))[0]) {
        flash('Cannot accept: ' . implode(' ', $elig[1]), 'bad'); redirect('/?p=transfers&id=' . $t['id']);
    }
    if ($canDecide && $act === 'accept' && $t['status'] === 'pending') {
        require_once __DIR__ . '/../beneficiary.php';
        // requests made before beneficiaries were reviewed have no linked beneficiary: the decider picks an approved one
        $bid = $t['beneficiary_id'] ?: (int)post('beneficiary_id');
        $bsel = $bid ? one('SELECT * FROM beneficiaries WHERE id=?', [$bid]) : null;
        [$bOk, $bWhy] = benef_usable($bsel, (int)$t['client_id']);
        if (!$bOk) { flash('Cannot accept: beneficiary: ' . $bWhy, 'bad'); redirect('/?p=transfers&id=' . $t['id']); }
        $label = $bsel['name'] . ($bsel['bank'] ? ' · ' . $bsel['bank'] : '') . ($bsel['account'] ? ' · ' . mask(dec($bsel['account'])) : '');
        if (q("UPDATE transfers SET status='accepted', decided_by=?, decided_at=NOW(), decision_note=?, beneficiary_id=?, beneficiary=? WHERE id=? AND status='pending'", [$u['id'], $note ?: null, $bsel['id'], $label, $t['id']])->rowCount())
            $ok = 'Transfer ' . $t['ref_no'] . ' accepted.' . (!$t['beneficiary_id'] ? ' Linked to beneficiary #' . $bsel['id'] . '.' : '');
    } elseif ($canDecide && $act === 'reject' && $t['status'] === 'pending') {
        q("UPDATE transfers SET status='rejected', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=? AND status='pending'", [$u['id'], $note ?: null, $t['id']]); $ok = 'Transfer ' . $t['ref_no'] . ' rejected.';
    } elseif ($canDecide && in_array($act, ['complete', 'reject'], true) && $t['status'] === 'accepted' && $t['beneficiary_id']
              && !benef_usable_tx($t)[0]) {
        // the beneficiary was changed, rejected or deactivated after acceptance: do not pay out; the request can be rejected
        if ($act === 'complete') { flash('Cannot complete: beneficiary: ' . benef_usable_tx($t)[1] . ' Reject this transfer and request it again for the current beneficiary.', 'bad'); redirect('/?p=transfers&id=' . $t['id']); }
        q("UPDATE transfers SET status='rejected', decision_note=? WHERE id=? AND status='accepted'", [$note ?: 'Beneficiary changed after acceptance', $t['id']]); $ok = 'Transfer ' . $t['ref_no'] . ' rejected.';
    } elseif ($canDecide && $act === 'complete' && $t['status'] === 'accepted') {
        $receipt = mb_substr(post('receipt_no'), 0, 60);
        q("UPDATE transfers SET status='completed', receipt_no=COALESCE(?, receipt_no) WHERE id=?", [$receipt ?: null, $t['id']]); $ok = 'Transfer ' . $t['ref_no'] . ' marked completed.';
    } elseif ($act === 'cancel' && $t['status'] === 'pending' && ($canDecide || (int)$t['requested_by'] === (int)$u['id'])) {
        q("UPDATE transfers SET status='cancelled' WHERE id=?", [$t['id']]); $ok = 'Transfer request cancelled.';
    }
    if ($ok) apply_commission((int)$t['id']);
    if ($ok) { audit('transfer_' . $act, 'transfer', $t['id'], $t['ref_no'] . ($note ? ' · ' . $note : '')); flash($ok); }
    else flash('That action is not allowed for this transfer.', 'bad');
    $back = $_SERVER['HTTP_REFERER'] ?? '';
    redirect(($back && parse_url($back, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) ? $back : '/?p=transfers');
}

/* ----- single transfer ----- */
if (get('id') !== '') {
    $t = one('SELECT t.*, a.name agent_name, a.ref_code, r.name req_name, d.name dec_name, c.client_no FROM transfers t LEFT JOIN users a ON a.id=t.agent_id LEFT JOIN users r ON r.id=t.requested_by LEFT JOIN users d ON d.id=t.decided_by LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?', [(int)get('id')]);
    if (!$t || !can_see_transfer($t)) { flash('Transfer not found.', 'bad'); redirect('/?p=transfers'); }
    audit('view_transfer', 'transfer', $t['id'], $t['ref_no']);
    page_head('Transfer ' . $t['ref_no']); ?>
    <div class="spread"><div><div class="tag num"><?= e($t['ref_no']) ?></div><h1><?= e($t['client_name']) ?></h1><div style="margin-top:6px"><?= tx_pill($t['status']) ?></div></div>
      <a class="btn" href="/?p=transfers">Back to transfers</a></div>
    <div class="grid2">
      <div class="card"><dl class="kv">
        <?= kv('Client', $t['client_name'] . ($t['client_no'] ? ' · ' . $t['client_no'] : '')) . kv('Date', fdate($t['transfer_date'])) . kv('Office', $t['office']) . kv('Amount sent', money($t['send_amount'], $t['send_currency'] ?? '')) . kv('Exchange rate', $t['rate'] !== null ? rtrim(rtrim((string)$t['rate'], '0'), '.') : '') . kv('Amount received', money($t['receive_amount'], $t['receive_currency'] ?? '')) . kv('Fee', money($t['fee'], $t['send_currency'] ?? '')) . kv('Payment method', $t['payment_method']) . kv('Beneficiary', $t['beneficiary']) . kv('Receipt no.', $t['receipt_no']) . kv('Notes', $t['notes']) ?>
        <?= kv('Sub-agent', $t['agent_name'] ? $t['agent_name'] . ' · ' . $t['ref_code'] : 'Direct') . kv('Requested by', ($t['req_name'] ?? '') . ' · ' . fdatetime($t['created_at'])) . kv('Decided by', $t['dec_name'] ? $t['dec_name'] . ' · ' . fdatetime($t['decided_at']) : '') . kv('Decision note', $t['decision_note']) . kv('Sub-agent commission', $t['commission'] !== null ? money($t['commission'], $t['commission_currency'] ?? '') . ' (' . fmt_rate($t['commission_per_unit']) . ' per ' . $t['send_currency'] . ')' : '') ?>
      </dl>
      <?php if ($t['client_id']): ?><p style="margin-bottom:0"><a href="/?p=client&id=<?= (int)$t['client_id'] ?>">Open client profile</a></p><?php endif; ?></div>
      <div>
      <?php if ($canDecide && $t['status'] === 'pending'): ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <h3 style="margin-bottom:10px">Your decision</h3>
          <div class="f" style="margin-bottom:12px"><label for="note">Note (optional, the sub-agent sees it)</label><input id="note" name="note" maxlength="250"></div>
          <?php if (!$t['beneficiary_id'] && $t['client_id']): $opts = all("SELECT id, name, bank, account FROM beneficiaries WHERE client_id=? AND status='active' ORDER BY name", [$t['client_id']]); ?>
          <div class="f" style="margin-bottom:12px"><label for="beneficiary_id">Pay to (approved beneficiary) <span class="req">*</span></label><select id="beneficiary_id" name="beneficiary_id"><option value="">Choose…</option><?php foreach ($opts as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name'] . ($o['bank'] ? ' · ' . $o['bank'] : '') . ($o['account'] ? ' · ' . mask(dec($o['account'])) : '')) ?></option><?php endforeach; ?></select>
            <div class="tag">This request was made before beneficiaries were reviewed. Written on the request: <?= e($t['beneficiary']) ?><?= $opts ? '' : ' · No approved beneficiary yet: review one on the client page first.' ?></div></div>
          <?php endif; ?>
          <div class="row"><button class="btn ok" name="act" value="accept">Accept transfer</button><button class="btn danger" name="act" value="reject" data-confirm="Reject this transfer request?">Reject</button></div></form>
      <?php elseif ($canDecide && $t['status'] === 'accepted'): ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <h3 style="margin-bottom:10px">Mark as completed</h3>
          <?php if ($t['beneficiary_id'] && !($bu = benef_usable_tx($t))[0]): ?><div class="banner bad">Do not pay out. Beneficiary: <?= e($bu[1]) ?></div><button class="btn danger" name="act" value="reject" data-confirm="Reject this accepted transfer?">Reject transfer</button><?php endif; ?>
          <div class="f" style="margin-bottom:12px"><label for="receipt_no">Receipt / payout reference</label><input id="receipt_no" name="receipt_no" value="<?= e($t['receipt_no']) ?>"></div>
          <button class="btn gold" name="act" value="complete">Mark completed</button></form>
      <?php endif; ?>
      <?php if ($t['status'] === 'pending' && ($canDecide || (int)$t['requested_by'] === (int)$u['id'])): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn sm danger" name="act" value="cancel" data-confirm="Cancel this transfer request?">Cancel request</button></form>
      <?php endif; ?>
      </div>
    </div>
    <?php page_foot(); return;
}

/* ----- list ----- */
$status = get('status'); $from = valid_date(get('from')) ?? ''; $to = valid_date(get('to')) ?? ''; $agent = get('agent'); $qs = get('q');
$where = []; $par = [];
[$scSql, $scPar] = scope_where($u, 't'); $where[] = $scSql; $par = array_merge($par, $scPar);
if (!$isAdmin) {}
elseif ($agent === 'none') $where[] = 't.agent_id IS NULL';
elseif ($agent !== '') { $where[] = 't.agent_id=?'; $par[] = (int)$agent; }
if (in_array($status, ['pending', 'accepted', 'completed', 'rejected', 'cancelled'], true)) { $where[] = 't.status=?'; $par[] = $status; }
if ($from) { $where[] = 't.transfer_date>=?'; $par[] = $from; }
if ($to) { $where[] = 't.transfer_date<=?'; $par[] = $to; }
if ($qs !== '') { $where[] = '(t.client_name LIKE ? OR t.ref_no LIKE ? OR t.receipt_no LIKE ?)'; $l = "%$qs%"; array_push($par, $l, $l, $l); }
$rows = all('SELECT t.*, a.name agent_name, a.ref_code FROM transfers t LEFT JOIN users a ON a.id=t.agent_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.transfer_date DESC, t.id DESC LIMIT 1000', $par);
$agents = $isAdmin ? all("SELECT id,name,ref_code FROM users WHERE role='agent' ORDER BY name") : [];
$tot = []; $fees = [];
foreach ($rows as $r) if (in_array($r['status'], ['accepted', 'completed'], true)) { $k = $r['send_currency'] ?: '—'; $tot[$k] = ($tot[$k] ?? 0) + (float)$r['send_amount']; $fees[$k] = ($fees[$k] ?? 0) + (float)$r['fee']; }

if (get('export') === 'csv' && can('export')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dhanxfer-transfers-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ref no.', 'Date', 'Client', 'Sub-agent', 'Office', 'Amount sent', 'Send currency', 'Rate', 'Amount received', 'Receive currency', 'Fee', 'Payment', 'Beneficiary', 'Receipt no.', 'Status', 'Commission', 'Commission currency', 'Notes']);
    foreach ($rows as $r) fputcsv($out, [$r['ref_no'], $r['transfer_date'], $r['client_name'], $r['ref_code'], $r['office'], $r['send_amount'], $r['send_currency'], $r['rate'], $r['receive_amount'], $r['receive_currency'], $r['fee'], $r['payment_method'], $r['beneficiary'], $r['receipt_no'], $r['status'], $r['commission'], $r['commission_currency'], $r['notes']]);
    audit('export_transfers', 'transfer', null, count($rows) . ' rows');
    exit;
}

page_head($isAdmin ? 'Transfers' : 'My transfers'); ?>
<div class="spread"><div><h1><?= $isAdmin ? 'Transfers' : 'My transfers' ?></h1>
  <div class="muted"><?= count($rows) ?> shown<?= $tot ? ' · accepted volume ' . e(implode(' · ', array_map(fn($k, $v) => money($v, $k), array_keys($tot), $tot))) : '' ?><?= can('commission.view') && array_sum($fees) ? ' · fees ' . e(implode(' · ', array_map(fn($k, $v) => money($v, $k), array_keys($fees), $fees))) : '' ?></div></div>
  <div class="row"><?php if (can('export')): ?><a class="btn sm" href="/?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a><?php endif; ?><?php if (!$isAdmin || can('transfers.create')): ?><a class="btn sm gold" href="/?p=transfer_form"><?= $canDecide ? '+ New transfer' : '+ Request transfer' ?></a><?php endif; ?></div></div>
<form class="row card" method="get" style="padding:12px"><input type="hidden" name="p" value="transfers">
  <input name="q" value="<?= e($qs) ?>" placeholder="Client, ref or receipt no." style="flex:1;min-width:180px">
  <select name="status" style="width:auto"><option value="">All statuses</option><?php foreach (['pending' => 'Waiting for admin', 'accepted' => 'Accepted', 'completed' => 'Completed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <?php if ($isAdmin): ?><select name="agent" style="width:auto"><option value="">All sub-agents</option><option value="none"<?= $agent === 'none' ? ' selected' : '' ?>>Direct</option><?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $agent === (string)$a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ' · ' . $a['ref_code']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <label class="tag" for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>" style="width:auto">
  <label class="tag" for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>" style="width:auto">
  <button class="btn sm">Filter</button></form>
<div class="tablewrap"><table>
  <thead><tr><th>Date</th><th>Ref</th><th>Client</th><?php if ($isAdmin): ?><th>Sub-agent</th><?php endif; ?><th class="r">Sent</th><th class="r">Rate</th><th class="r">Received</th><th class="r">Fee</th><th class="r">Commission</th><th>Status</th><?php if ($canDecide): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $t): ?>
  <tr><td class="num"><?= fdate($t['transfer_date']) ?></td><td><a href="/?p=transfers&id=<?= (int)$t['id'] ?>"><?= e($t['ref_no']) ?></a></td>
    <td><?= $t['client_id'] ? '<a href="/?p=client&id=' . (int)$t['client_id'] . '" style="color:var(--ink)">' . e($t['client_name']) . '</a>' : e($t['client_name']) ?><?= $t['decision_note'] ? '<div class="tag">Admin: ' . e($t['decision_note']) . '</div>' : '' ?></td>
    <?php if ($isAdmin): ?><td><?= e($t['ref_code'] ? $t['agent_name'] . ' · ' . $t['ref_code'] : 'Direct') ?></td><?php endif; ?>
    <td class="r num"><?= e(money($t['send_amount'], $t['send_currency'] ?? '')) ?></td><td class="r num"><?= $t['rate'] !== null ? e(rtrim(rtrim((string)$t['rate'], '0'), '.')) : '' ?></td>
    <td class="r num"><?= e(money($t['receive_amount'], $t['receive_currency'] ?? '')) ?></td><td class="r num"><?= e(money($t['fee'])) ?></td><td class="r num"><?= e(money($t['commission'], $t['commission_currency'] ?? '')) ?></td><td><?= tx_pill($t['status']) ?></td>
    <?php if ($canDecide): ?><td style="white-space:nowrap"><?php if ($t['status'] === 'pending'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn sm ok" name="act" value="accept">Accept</button> <button class="btn sm danger" name="act" value="reject" data-confirm="Reject this transfer request?">Reject</button></form><?php elseif ($t['status'] === 'accepted'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn sm" name="act" value="complete">Mark completed</button></form><?php endif; ?></td><?php endif; ?>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="11" class="empty">No transfers found.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php page_foot();
