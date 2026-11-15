<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
$isAdmin = is_staff();
$canDecide = can('transfers.decide');
$clients = $isAdmin
    ? all('SELECT id, client_no, name, office, kyc, agent_id FROM clients ORDER BY name LIMIT 5000')
    : all('SELECT id, client_no, name, office, kyc, agent_id FROM clients WHERE agent_id=? ORDER BY name', [$u['id']]);
$byId = array_column($clients, null, 'id');
$v = ['client_id' => get('client'), 'transfer_date' => today(), 'send_currency' => 'CAD', 'receive_currency' => 'INR', 'status' => $canDecide ? 'accepted' : 'pending'];
if ($v['client_id'] && isset($byId[$v['client_id']]) && $byId[$v['client_id']]['office'] === 'Dubai') $v['send_currency'] = 'AED';
$errors = [];
$live = current_rates();
$liveKey = pair_key($v['send_currency'], $v['receive_currency']);
if (isset($live[$liveKey])) $v['rate'] = fmt_rate($live[$liveKey]['sell_rate']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['client_id', 'transfer_date', 'send_amount', 'send_currency', 'rate', 'receive_amount', 'receive_currency', 'fee', 'payment_method', 'beneficiary_id', 'receipt_no', 'notes', 'status', 'office'] as $k) $v[$k] = mb_substr(post($k), 0, $k === 'notes' ? 2000 : 250);
    $c = $byId[(int)$v['client_id']] ?? null;
    if (!$c) $errors[] = 'Choose a client from the list.' . ($isAdmin ? '' : ' You can only send for your own clients.');
    $date = valid_date($v['transfer_date']);
    if (!$date) $errors[] = 'Enter the transfer date.';
    $amt = num_or_null($v['send_amount']); $rate = num_or_null($v['rate']); $rec = num_or_null($v['receive_amount']); $fee = num_or_null($v['fee']);
    if ($amt === null || $amt <= 0) $errors[] = 'Enter the amount sent.';
    if ($rec === null && $amt !== null && $rate !== null) $rec = round($amt * $rate, 2);
    if (!in_array($v['send_currency'], SEND_CUR, true)) $v['send_currency'] = 'CAD';
    if (!in_array($v['receive_currency'], RECV_CUR, true)) $v['receive_currency'] = 'INR';
    $status = $canDecide && in_array($v['status'], ['pending', 'accepted', 'completed'], true) ? $v['status'] : 'pending';
    if ($c) { require_once __DIR__ . '/../kyc.php'; [$okElig, $why] = transfer_eligibility(one('SELECT * FROM clients WHERE id=?', [$c['id']])); if (!$okElig) $errors[] = 'Transfers are not allowed for this client yet: ' . implode(' ', $why); }
    // the money goes to one approved beneficiary of this client
    require_once __DIR__ . '/../beneficiary.php';
    $bsel = $c ? one('SELECT * FROM beneficiaries WHERE id=?', [(int)$v['beneficiary_id']]) : null;
    if ($c) { [$bOk, $bWhy] = benef_usable($bsel, (int)$c['id']); if (!$bOk) $errors[] = 'Beneficiary: ' . $bWhy; }
    if (!$errors) {
        $v['beneficiary'] = $bsel['name'] . ($bsel['bank'] ? ' · ' . $bsel['bank'] : '') . ($bsel['account'] ? ' · ' . mask(dec($bsel['account'])) : '');
        $ref = new_transfer_no();
        q('INSERT INTO transfers (ref_no,client_id,client_name,agent_id,beneficiary,send_amount,send_currency,rate,receive_amount,receive_currency,fee,payment_method,office,transfer_date,status,receipt_no,notes,requested_by,decided_by,decided_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $ref, $c['id'], $c['name'], $c['agent_id'], nz($v['beneficiary']), $amt, $v['send_currency'], $rate, $rec, $v['receive_currency'], $fee,
            in_array($v['payment_method'], PAY, true) ? $v['payment_method'] : null, $c['office'], $date, $status, nz($v['receipt_no']), nz($v['notes']),
            $u['id'], $status !== 'pending' ? $u['id'] : null, $status !== 'pending' ? date('Y-m-d H:i:s') : null]);
        $tid = (int)db()->lastInsertId();
        q('UPDATE transfers SET beneficiary_id=? WHERE id=?', [$bsel['id'], $tid]);
        // commission and its CAD value are locked now, at the order-day rate, and never recalculated
        require_once __DIR__ . '/../wallet.php';
        commission_lock($tid);
        apply_commission($tid);
        if ($status === 'completed') wallet_sync((int)$c['agent_id']);
        audit('create_transfer', 'transfer', $tid, $ref . ' ' . $status);
        if (!$canDecide) notify('Transfer request ' . $ref . ' from ' . $u['name'], "{$u['name']} ({$u['ref_code']}) requested a transfer of " . money($amt, $v['send_currency']) . ".\n\nAccept or reject it in the portal: " . app_link("/?p=transfers&id=$tid"), 'transfer_request');
        flash($canDecide ? "Transfer $ref saved." : "Transfer request $ref sent. An admin will accept or reject it.");
        redirect('/?p=transfers&id=' . $tid);
    }
}
$benefList = []; $benefWait = 0;
if ($v['client_id'] && isset($byId[$v['client_id']])) {
    foreach (all("SELECT id, name, relation, bank, account FROM beneficiaries WHERE client_id=? AND status='active' ORDER BY name", [(int)$v['client_id']]) as $b)
        $benefList[$b['id']] = trim($b['name'] . ($b['relation'] ? " ({$b['relation']})" : '') . ($b['bank'] ? " · {$b['bank']}" : '') . ($b['account'] ? ' · ' . mask(dec($b['account'])) : ''));
    $benefWait = (int)val("SELECT COUNT(*) FROM beneficiaries WHERE client_id=? AND status='pending'", [(int)$v['client_id']]);
}

page_head($canDecide ? 'New transfer' : 'Request transfer'); ?>
<div class="spread"><div><h1><?= $canDecide ? 'New transfer' : 'Request a transfer' ?></h1>
  <div class="muted"><?= $canDecide ? 'Transfers you save as an admin are accepted straight away unless you choose otherwise.' : 'Your request stays "Waiting for admin" until an admin accepts it.' ?></div></div></div>
<?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
<?php if (!$clients): ?><div class="banner warn">There are no clients yet. <a href="/?p=client_form">Add a client</a> first.</div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <fieldset><legend>Client</legend><div class="fields">
    <div class="f wide"><label for="client_id">Client <span class="req">*</span></label><select id="client_id" name="client_id" required onchange="location='/?p=transfer_form&client='+this.value"><option value="">Select a client…</option>
      <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (string)$v['client_id'] === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['name'] . ' · ' . $c['client_no'] . ($c['kyc'] !== 'Verified' ? ' · KYC ' . $c['kyc'] : '')) ?></option><?php endforeach; ?></select></div>
    <?php if ($v['client_id'] && isset($byId[$v['client_id']]) && $byId[$v['client_id']]['kyc'] !== 'Verified'): ?><div class="f wide"><div class="banner warn" style="margin:0">This client's KYC is <?= e($byId[$v['client_id']]['kyc']) ?>. Check their ID before the money is sent.</div></div><?php endif; ?>
    <div class="f"><label for="transfer_date">Date <span class="req">*</span></label><input id="transfer_date" type="date" name="transfer_date" value="<?= e($v['transfer_date']) ?>" required></div>
    <?php if ($canDecide): ?><div class="f"><label for="status">Status</label><select id="status" name="status"><option value="accepted"<?= $v['status'] === 'accepted' ? ' selected' : '' ?>>Accepted</option><option value="completed"<?= $v['status'] === 'completed' ? ' selected' : '' ?>>Completed</option><option value="pending"<?= $v['status'] === 'pending' ? ' selected' : '' ?>>Waiting for admin</option></select></div><?php endif; ?>
  </div></fieldset>
  <script type="application/json" id="rateMap"><?= json_encode(array_map(fn($r) => fmt_rate($r['sell_rate']), $live)) ?></script>
  <?php if ($live): ?><div class="card" style="padding:12px"><span class="tag">Today's sell rates:</span> <?php foreach ($live as $lr): ?><span class="pill p-gold" style="margin:2px"><?= e(pair_label($lr['send_currency'], $lr['receive_currency']) . ' ' . fmt_rate($lr['sell_rate'])) ?></span><?php endforeach; ?></div><?php endif; ?>
  <fieldset><legend>Amounts</legend><div class="fields">
    <div class="f"><label for="send_amount">Amount sent <span class="req">*</span></label><input id="send_amount" name="send_amount" type="number" step="0.01" min="0" class="num" value="<?= e($v['send_amount'] ?? '') ?>" required></div>
    <div class="f"><label for="send_currency">Send currency</label><select id="send_currency" name="send_currency"><?= opts(SEND_CUR, $v['send_currency'], '') ?></select></div>
    <div class="f"><label for="rate">Exchange rate</label><input id="rate" name="rate" type="number" step="0.000001" min="0" class="num" value="<?= e($v['rate'] ?? '') ?>"></div>
    <div class="f"><label for="receive_currency">Receive currency</label><select id="receive_currency" name="receive_currency"><?= opts(RECV_CUR, $v['receive_currency'], '') ?></select></div>
    <div class="f"><label for="receive_amount">Amount received</label><input id="receive_amount" name="receive_amount" type="number" step="0.01" min="0" class="num" value="<?= e($v['receive_amount'] ?? '') ?>" placeholder="Auto: amount × rate"></div>
    <div class="f"><label for="fee">Fee charged</label><input id="fee" name="fee" type="number" step="0.01" min="0" class="num" value="<?= e($v['fee'] ?? '') ?>"></div>
    <div class="f"><label for="payment_method">Payment method</label><select id="payment_method" name="payment_method"><?= opts(PAY, $v['payment_method'] ?? '') ?></select></div>
  </div></fieldset>
  <fieldset><legend>Payout</legend><div class="fields">
    <div class="f wide"><label for="beneficiary_id">Beneficiary <span class="req">*</span></label><select id="beneficiary_id" name="beneficiary_id" required><option value="">Choose an approved beneficiary…</option><?php foreach ($benefList as $bid => $bl): ?><option value="<?= (int)$bid ?>"<?= (string)($v['beneficiary_id'] ?? '') === (string)$bid ? ' selected' : '' ?>><?= e($bl) ?></option><?php endforeach; ?></select>
      <div class="tag"><?= $v['client_id'] ? ($benefList ? '' : 'No approved beneficiary yet. ') . ($benefWait ? $benefWait . ' waiting for review. ' : '') . '<a href="/?p=beneficiary&client=' . (int)$v['client_id'] . '">Add a beneficiary</a>' : 'Choose the client first.' ?></div></div>
    <div class="f"><label for="receipt_no">Receipt / reference no.</label><input id="receipt_no" name="receipt_no" value="<?= e($v['receipt_no'] ?? '') ?>"></div>
    <div class="f wide"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= e($v['notes'] ?? '') ?></textarea></div>
  </div></fieldset>
  <div class="formbar"><a class="btn" href="/?p=transfers">Cancel</a><button class="btn gold"><?= $canDecide ? 'Save transfer' : 'Send request to admin' ?></button></div>
</form>
<?php page_foot();
