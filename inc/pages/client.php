<?php
defined('DX') or exit;
$isAdmin = is_staff();
$c = one('SELECT c.*, a.name agent_name, a.ref_code FROM clients c LEFT JOIN users a ON a.id=c.agent_id WHERE c.id=?', [(int)get('id')]);
if (!$c || !can_see_client($c)) { flash('Client not found.', 'bad'); redirect('/?p=clients'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && post('act') !== 'reveal') {
    $act = post('act');
    if ($act === 'kyc') {
        // the old one-click buttons are gone: KYC changes only through the review screen
        flash('KYC decisions are made on the KYC review screen.', 'bad');
    } elseif ($act === 'upload_link' && can('compliance.manage')) {
        $_SESSION['upload_link'] = ['client' => (int)$c['id'], 'link' => upload_request_create((int)$c['id'], (int)me()['id'])];
        flash('Secure upload link made. Send it to the client privately; it works once.');
    } elseif ($act === 'delete' && can('clients.delete')) {
        if (val('SELECT 1 FROM kyc_reviews WHERE client_id=? UNION SELECT 1 FROM document_versions WHERE client_id=? LIMIT 1', [$c['id'], $c['id']])) {
            flash('This client has KYC records, which must be kept under the record-keeping rules. It cannot be deleted.', 'bad');
            redirect('/?p=client&id=' . $c['id']);
        }
        if (val('SELECT 1 FROM transfers WHERE client_id=? LIMIT 1', [$c['id']])) { flash('This client has transfers, so the record must be kept.', 'bad'); redirect('/?p=client&id=' . $c['id']); }
        if (val('SELECT 1 FROM beneficiaries WHERE client_id=? AND reviewed_at IS NOT NULL LIMIT 1', [$c['id']])) { flash('This client has screened beneficiaries, which must be kept. Deactivate the client\'s beneficiaries instead.', 'bad'); redirect('/?p=client&id=' . $c['id']); }
        // the beneficiary history (beneficiary_events) and the activity log are kept
        q('DELETE FROM beneficiaries WHERE client_id=?', [$c['id']]);
        q('UPDATE transfers SET client_id=NULL WHERE client_id=?', [$c['id']]);
        q('DELETE FROM clients WHERE id=?', [$c['id']]);
        audit('delete_client', 'client', $c['id'], $c['client_no']);
        flash('Client deleted. Their transfers stay in the transfer list.');
        redirect('/?p=clients');
    }
    redirect('/?p=client&id=' . $c['id']);
}

$reveal = can('pii.reveal') && $_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'reveal';
audit($reveal ? 'view_pii' : 'view_client', 'client', $c['id'], $c['client_no'] . ($reveal ? ' · full ID and account numbers shown' : ''));
$idPlain = dec($c['id_number']);
require_once __DIR__ . '/../beneficiary.php';
$benefs = all("SELECT * FROM beneficiaries WHERE client_id=? ORDER BY FIELD(status,'pending','active','rejected','deactivated'), name, id DESC", [$c['id']]);
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
<?php if ($isAdmin): [$eligible, $why] = transfer_eligibility($c); $ul = ($_SESSION['upload_link']['client'] ?? 0) === (int)$c['id'] ? $_SESSION['upload_link']['link'] : null; unset($_SESSION['upload_link']); ?>
<div class="card">
  <div class="spread"><div><h3 style="margin:0 0 4px">KYC</h3>
    <div class="tag"><?= $c['kyc_reviewed_at'] ? 'Last decision ' . fdatetime($c['kyc_reviewed_at']) . ' by ' . e((string)val('SELECT name FROM users WHERE id=?', [$c['kyc_reviewed_by']])) : 'Not reviewed yet' ?><?= $c['kyc_next_review'] ? ' · next refresh due ' . fdate($c['kyc_next_review']) : '' ?></div>
    <div class="tag">Address: <?= e(addr_result_label($c['address_match'])) ?> · Transfers: <?= $eligible ? '<span style="color:var(--ok)">allowed</span>' : '<span style="color:var(--bad)">blocked</span> (' . e(implode(' ', $why)) . ')' ?></div></div>
    <div class="row"><?php if (can('kyc.decide')): ?><a class="btn gold" href="/?p=kyc_review&id=<?= (int)$c['id'] ?>">Open KYC review</a><?php endif; ?>
      <?php if (can('compliance.manage')): ?><form method="post" class="inline"><?= csrf_field() ?><button class="btn" name="act" value="upload_link">Request updated document</button></form><?php endif; ?></div></div>
  <?php if ($ul): ?><div class="secret" style="margin-top:10px"><?= e($ul) ?></div><div class="row" style="margin-top:8px"><button type="button" class="btn sm" data-copy="<?= e($ul) ?>">Copy link</button><a class="btn sm" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', (string)($c['phone_e164'] ?: $c['whatsapp'] ?: $c['phone']))) ?>?text=<?= e(rawurlencode("Hello {$c['name']}, please upload your updated ID for DhanXfer with this secure link (it works once, for " . setting('upload_link_days', '7') . " days):\n$ul")) ?>">Send on WhatsApp</a></div><p class="tag">The client confirms their date of birth and phone digits before uploading.</p><?php endif; ?>
</div>
<?php endif; ?>
<div class="grid2">
  <div>
    <div class="card"><h3 style="margin-bottom:10px">Contact</h3><dl class="kv"><?= kv('Phone', $c['phone']) . kv('WhatsApp', $c['whatsapp']) . kv('Email', $c['email']) . kv('Address', implode(', ', array_filter([$c['address'], $c['city'], $c['province'], $c['postal'], $c['country']]))) ?></dl></div>
    <div class="card"><h3 style="margin-bottom:10px">Identification</h3><dl class="kv"><?= kv('Date of birth', fdate($c['dob'])) . kv('Nationality', $c['nationality']) . kv('ID type', $c['id_type']) . kv('ID number', $reveal ? $idPlain : mask($idPlain)) . kv('Issued by', $c['id_country']) . kv('Expiry', fdate($c['id_expiry'])) . kv('Address on ID', $c['id_has_address'] === null ? '' : ((int)$c['id_has_address'] ? (($ia = json_decode((string)$c['id_address_json'], true)) ? implode(', ', array_filter($ia)) : 'Same as home address') : 'No address on the ID')) . kv('Consent recorded', $c['consent'] ? 'Yes' : 'No') ?>
      <?php if (can('pii.reveal') && !$reveal && ($idPlain || $c['id_file'])): ?><dt></dt><dd><form method="post" class="inline"><?= csrf_field() ?><button class="linkbtn" name="act" value="reveal">Show full numbers</button></form> <span class="tag">(this is logged)</span></dd><?php endif; ?>
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
    <div class="card"><div class="spread"><h3 style="margin:0">Beneficiaries</h3><?php if (!$isAdmin || can('beneficiaries.manage')): ?><a class="btn sm gold" href="/?p=beneficiary&client=<?= (int)$c['id'] ?>">+ Add beneficiary</a><?php endif; ?></div>
      <div class="list" style="margin-top:10px"><?php foreach ($benefs as $b): ?><a class="li" style="color:inherit;text-decoration:none" href="/?p=beneficiary&client=<?= (int)$c['id'] ?>&id=<?= (int)$b['id'] ?>"><div><strong><?= e($b['name']) ?></strong><?= $b['relation'] ? ' <span class="tag">(' . e($b['relation']) . ')</span>' : '' ?><?= $b['replaced_by'] ? ' <span class="tag">· old version</span>' : '' ?><div class="tag"><?= e(implode(' · ', array_filter([$b['country'], $b['bank'], $reveal ? dec($b['account']) : mask(dec($b['account']))]))) ?></div></div><div style="text-align:right"><?= benef_pill($b['status']) ?></div></a><?php endforeach; ?>
      <?php if (!$benefs): ?><div class="muted">None added.</div><?php endif; ?></div></div>
    <?php if ($c['notes']): ?><div class="card"><h3 style="margin-bottom:10px">Notes</h3><p style="white-space:pre-wrap;margin:0"><?= e($c['notes']) ?></p></div><?php endif; ?>
    <p class="tag">Registered <?= fdatetime($c['created_at']) ?><?= $creator ? ' by ' . e($creator) : '' ?> · via <?= e(['admin' => 'admin', 'agent' => 'sub-agent', 'online' => 'online form', 'import' => 'import'][$c['source']] ?? $c['source']) ?><?= $c['updated_at'] ? ' · last edited ' . fdatetime($c['updated_at']) : '' ?></p>
    <?php if (can('clients.delete')): ?><form method="post"><?= csrf_field() ?><button class="btn sm danger" name="act" value="delete" data-confirm="Delete <?= e($c['name']) ?>? This cannot be undone. Their transfers stay in the transfer list.">Delete client</button></form><?php endif; ?>
  </div>
</div>
<?php page_foot();
