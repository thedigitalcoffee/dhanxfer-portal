<?php
defined('DX') or exit;
$isAdmin = is_staff();
$c = one('SELECT c.*, a.name agent_name, a.ref_code FROM clients c LEFT JOIN users a ON a.id=c.agent_id WHERE c.id=?', [(int)get('id')]);
if (!$c || !can_see_client($c)) { flash('Client not found.', 'bad'); redirect('/?p=clients'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $act = post('act');
    if ($act === 'kyc' && can('kyc.decide') && in_array(post('kyc'), KYC, true)) {
        q('UPDATE clients SET kyc=?, updated_at=NOW() WHERE id=?', [post('kyc'), $c['id']]);
        audit('kyc_' . strtolower(post('kyc')), 'client', $c['id'], $c['client_no']);
        flash('KYC status set to ' . post('kyc') . '.');
    } elseif ($act === 'delete' && can('clients.delete')) {
        q('DELETE FROM beneficiaries WHERE client_id=?', [$c['id']]);
        q('UPDATE transfers SET client_id=NULL WHERE client_id=?', [$c['id']]);
        q('DELETE FROM clients WHERE id=?', [$c['id']]);
        audit('delete_client', 'client', $c['id'], $c['client_no']);
        flash('Client deleted. Their transfers stay in the transfer list.');
        redirect('/?p=clients');
    }
    redirect('/?p=client&id=' . $c['id']);
}

$reveal = can('pii.reveal') && get('reveal') === '1';
audit($reveal ? 'view_pii' : 'view_client', 'client', $c['id'], $c['client_no'] . ($reveal ? ' · full ID and account numbers shown' : ''));
$idPlain = dec($c['id_number']);
$benefs = all('SELECT * FROM beneficiaries WHERE client_id=?', [$c['id']]);
$tx = all('SELECT * FROM transfers WHERE client_id=? ORDER BY transfer_date DESC, id DESC', [$c['id']]);
$acts = $isAdmin ? all('SELECT * FROM activities WHERE client_id=? ORDER BY act_date DESC, id DESC LIMIT 50', [$c['id']]) : [];
$sent = [];
foreach ($tx as $t) if (in_array($t['status'], ['accepted', 'completed'], true)) { $k = $t['send_currency'] ?: '—'; $sent[$k] = ($sent[$k] ?? 0) + (float)$t['send_amount']; }
$creator = $c['created_by'] ? val('SELECT name FROM users WHERE id=?', [$c['created_by']]) : null;

page_head($c['name']); ?>
<div class="spread">
  <div><div class="tag num"><?= e($c['client_no']) ?></div><h1><?= e($c['name']) ?></h1>
    <div class="row" style="margin-top:6px"><?= kyc_pill($c['kyc']) ?><span class="pill p-mute"><?= e($c['risk']) ?> risk</span><?php if ($c['office']): ?><span class="pill p-info"><?= e($c['office']) ?></span><?php endif; ?><?php if ($c['ref_code']): ?><span class="pill p-gold">Sub-agent <?= e($c['agent_name'] . ' · ' . $c['ref_code']) ?></span><?php endif; ?></div></div>
  <div class="row">
    <?php if (!$isAdmin || can('transfers.create')): ?><a class="btn gold" href="/?p=transfer_form&client=<?= (int)$c['id'] ?>"><?= can('transfers.decide') ? '+ New transfer' : '+ Request transfer' ?></a><?php endif; ?>
    <?php if (!$isAdmin || can('clients.edit')): ?><a class="btn" href="/?p=client_form&id=<?= (int)$c['id'] ?>">Edit details</a><?php endif; ?>
    <?php if (can('log.use')): ?><a class="btn" href="/?p=log&client=<?= (int)$c['id'] ?>">+ Log activity</a><?php endif; ?>
  </div>
</div>
<?php if (can('kyc.decide')): ?>
<form method="post" class="card row" style="padding:12px"><?= csrf_field() ?><input type="hidden" name="act" value="kyc">
  <span class="tag">KYC decision:</span>
  <?php foreach (KYC as $k): if ($k === $c['kyc']) continue; ?><button class="btn sm <?= $k === 'Verified' ? 'ok' : ($k === 'Pending' ? '' : 'danger') ?>" name="kyc" value="<?= e($k) ?>">Mark <?= e($k) ?></button><?php endforeach; ?>
</form>
<?php endif; ?>
<div class="grid2">
  <div>
    <div class="card"><h3 style="margin-bottom:10px">Contact</h3><dl class="kv"><?= kv('Phone', $c['phone']) . kv('WhatsApp', $c['whatsapp']) . kv('Email', $c['email']) . kv('Address', implode(', ', array_filter([$c['address'], $c['city'], $c['province'], $c['postal'], $c['country']]))) ?></dl></div>
    <div class="card"><h3 style="margin-bottom:10px">Identification</h3><dl class="kv"><?= kv('Date of birth', fdate($c['dob'])) . kv('Nationality', $c['nationality']) . kv('ID type', $c['id_type']) . kv('ID number', $reveal ? $idPlain : mask($idPlain)) . kv('Issued by', $c['id_country']) . kv('Expiry', fdate($c['id_expiry'])) . kv('Consent recorded', $c['consent'] ? 'Yes' : 'No') ?>
      <?php if (can('pii.reveal') && !$reveal && ($idPlain || $c['id_file'])): ?><dt></dt><dd><a href="/?p=client&id=<?= (int)$c['id'] ?>&reveal=1">Show full numbers</a> <span class="tag">(this is logged)</span></dd><?php endif; ?>
      <?php if ($c['id_file'] && (can('pii.reveal') || can('kyc.decide'))): ?><dt>ID document</dt><dd><a href="/file.php?f=<?= e($c['id_file']) ?>" target="_blank" rel="noopener">Open uploaded ID</a></dd><?php endif; ?></dl></div>
    <h2 style="margin:6px 0 10px">Transfers (<?= count($tx) ?>)</h2>
    <div class="tablewrap"><table><thead><tr><th>Date</th><th>Ref</th><th class="r">Sent</th><th class="r">Received</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($tx as $t): ?><tr><td class="num"><?= fdate($t['transfer_date']) ?></td><td><a href="/?p=transfers&id=<?= (int)$t['id'] ?>"><?= e($t['ref_no']) ?></a></td><td class="r num"><?= e(money($t['send_amount'], $t['send_currency'] ?? '')) ?></td><td class="r num"><?= e(money($t['receive_amount'], $t['receive_currency'] ?? '')) ?></td><td><?= tx_pill($t['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$tx): ?><tr><td colspan="5" class="empty">No transfers yet.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($isAdmin && $acts): ?>
    <h2 style="margin:6px 0 10px">Activity</h2>
    <div class="list"><?php foreach ($acts as $a): ?><div class="li"><div><strong><?= e($a['type']) ?></strong><div class="tag"><?= e($a['notes']) ?></div></div><div class="tag"><?= fdate($a['act_date']) ?></div></div><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
  <div>
    <div class="card"><h3 style="margin-bottom:10px">Profile</h3><dl class="kv"><?= kv('Occupation', $c['occupation']) . kv('Employer', $c['employer']) . kv('Purpose', $c['purpose']) . kv('Source of funds', $c['source_of_funds']) . kv('Monthly amount', $c['monthly_volume']) . kv('Usual destination', $c['destination']) . kv('Heard via', $c['referral']) . kv('Referred by', $c['referred_by']) . kv('Total sent', implode(' · ', array_map(fn($k, $v) => money($v, $k), array_keys($sent), $sent))) ?></dl></div>
    <div class="card"><h3 style="margin-bottom:10px">Beneficiaries</h3>
      <div class="list"><?php foreach ($benefs as $b): ?><div class="li"><div><strong><?= e($b['name']) ?></strong><?= $b['relation'] ? ' <span class="tag">(' . e($b['relation']) . ')</span>' : '' ?><div class="tag"><?= e(implode(' · ', array_filter([$b['bank'], $reveal ? dec($b['account']) : mask(dec($b['account']))]))) ?></div></div><div class="tag" style="text-align:right"><?= e($b['country']) ?><br><?= e($b['phone']) ?></div></div><?php endforeach; ?>
      <?php if (!$benefs): ?><div class="muted">None added.</div><?php endif; ?></div></div>
    <?php if ($c['notes']): ?><div class="card"><h3 style="margin-bottom:10px">Notes</h3><p style="white-space:pre-wrap;margin:0"><?= e($c['notes']) ?></p></div><?php endif; ?>
    <p class="tag">Registered <?= fdatetime($c['created_at']) ?><?= $creator ? ' by ' . e($creator) : '' ?> · via <?= e(['admin' => 'admin', 'agent' => 'sub-agent', 'online' => 'online form', 'import' => 'import'][$c['source']] ?? $c['source']) ?><?= $c['updated_at'] ? ' · last edited ' . fdatetime($c['updated_at']) : '' ?></p>
    <?php if (can('clients.delete')): ?><form method="post"><?= csrf_field() ?><button class="btn sm danger" name="act" value="delete" data-confirm="Delete <?= e($c['name']) ?>? This cannot be undone. Their transfers stay in the transfer list.">Delete client</button></form><?php endif; ?>
  </div>
</div>
<?php page_foot();
