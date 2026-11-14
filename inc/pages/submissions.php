<?php
defined('DX') or exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = one('SELECT * FROM submissions WHERE id=?', [(int)post('id')]);
    if (!$s || $s['status'] !== 'new') { flash('That form was already reviewed.', 'bad'); redirect('/?p=submissions'); }
    $act = post('act');
    if ($act === 'reject') {
        q("UPDATE submissions SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?", [$u['id'], mb_substr(post('note'), 0, 250) ?: null, $s['id']]);
        audit('reject_submission', 'submission', $s['id'], $s['name']);
        flash('Form from ' . $s['name'] . ' rejected.');
        redirect('/?p=submissions');
    }
    if ($act === 'approve') {
        $d = json_decode($s['data'], true) ?: [];
        $v = [];
        foreach (CLIENT_FIELDS as $k) $v[$k] = (string)($d[$k] ?? '');
        $agentId = (int)post('agent_id') ?: null;
        if ($agentId && !val("SELECT 1 FROM users WHERE id=? AND role='agent'", [$agentId])) $agentId = null;
        $cid = insert_client($v, ['kyc' => 'Pending', 'agent_id' => $agentId, 'source' => 'online', 'consent' => !empty($d['consent']), 'id_file' => $s['id_file'], 'created_by' => $u['id']]);
        save_beneficiaries($cid, $d['beneficiaries'] ?? []);
        q("UPDATE submissions SET status='approved', client_id=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?", [$cid, $u['id'], $s['id']]);
        audit('approve_submission', 'client', $cid, $s['name']);
        flash($s['name'] . ' added as a client. KYC is Pending until you check their ID.');
        redirect('/?p=client&id=' . $cid);
    }
    redirect('/?p=submissions');
}

$agents = all("SELECT id,name,ref_code FROM users WHERE role='agent' ORDER BY name");
$link = rtrim($CONFIG['app_url'], '/') . '/register.php';

if (get('id') !== '') {
    $s = one('SELECT s.*, r.name reviewer FROM submissions s LEFT JOIN users r ON r.id=s.reviewed_by WHERE s.id=?', [(int)get('id')]);
    if (!$s) { flash('Form not found.', 'bad'); redirect('/?p=submissions'); }
    $d = json_decode($s['data'], true) ?: [];
    audit('view_submission', 'submission', $s['id'], $s['name']);
    $digits = substr(preg_replace('/\D/', '', $s['phone']), -10);
    $dupe = $digits ? one("SELECT id, client_no, name FROM clients WHERE RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),10)=?", [$digits]) : null;
    page_head('Online form · ' . $s['name']); ?>
    <div class="spread"><div><div class="tag">Submitted <?= fdatetime($s['created_at']) ?></div><h1><?= e($s['name']) ?></h1></div><a class="btn" href="/?p=submissions">Back to list</a></div>
    <?php if ($dupe): ?><div class="banner warn">A client with this phone number already exists: <a href="/?p=client&id=<?= (int)$dupe['id'] ?>"><?= e($dupe['name'] . ' (' . $dupe['client_no'] . ')') ?></a>. Reject this form if it is the same person.</div><?php endif; ?>
    <?php if ($s['ref_code'] && !$s['agent_id']): ?><div class="banner warn">The client typed referral ID "<?= e($s['ref_code']) ?>", which does not match any active sub-agent. Choose the right sub-agent below if you know who it is.</div><?php endif; ?>
    <div class="grid2">
      <div class="card"><dl class="kv">
        <?= kv('Phone', $d['phone'] ?? '') . kv('WhatsApp', $d['whatsapp'] ?? '') . kv('Email', $d['email'] ?? '') . kv('Office', $d['office'] ?? '') . kv('Address', implode(', ', array_filter([$d['address'] ?? '', $d['city'] ?? '', $d['province'] ?? '', $d['postal'] ?? '', $d['country'] ?? '']))) ?>
        <?= kv('Date of birth', fdate($d['dob'] ?? '')) . kv('Nationality', $d['nationality'] ?? '') . kv('Occupation', $d['occupation'] ?? '') . kv('Employer', $d['employer'] ?? '') ?>
        <?= kv('ID', implode(' · ', array_filter([$d['id_type'] ?? '', $d['id_number'] ?? '']))) . kv('ID issued by', $d['id_country'] ?? '') . kv('ID expiry', fdate($d['id_expiry'] ?? '')) ?>
        <?php if ($s['id_file']): ?><dt>ID document</dt><dd><a href="/file.php?f=<?= e($s['id_file']) ?>" target="_blank" rel="noopener">Open uploaded ID</a></dd><?php endif; ?>
        <?= kv('Purpose', $d['purpose'] ?? '') . kv('Source of funds', $d['source_of_funds'] ?? '') . kv('Monthly amount', $d['monthly_volume'] ?? '') . kv('Destination', $d['destination'] ?? '') . kv('Heard via', $d['referral'] ?? '') . kv('Referred by', $d['referred_by'] ?? '') . kv('Referral ID typed', $s['ref_code']) ?>
        <?php foreach ($d['beneficiaries'] ?? [] as $b) echo kv('Beneficiary', implode(' · ', array_filter([$b['name'] ?? '', ($b['relation'] ?? '') ? '(' . $b['relation'] . ')' : '', $b['country'] ?? '', $b['bank'] ?? '', $b['account'] ?? '', $b['phone'] ?? '']))); ?>
        <?= kv('Notes', $d['notes'] ?? '') . kv('Consent', !empty($d['consent']) ? 'Yes' : 'No') ?>
      </dl></div>
      <div>
      <?php if ($s['status'] === 'new'): ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <h3 style="margin-bottom:10px">Add as client</h3>
          <div class="f" style="margin-bottom:12px"><label for="agent_id">Referral sub-agent</label><select id="agent_id" name="agent_id"><option value="">None (direct client)</option>
            <?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$s['agent_id'] === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ' · ' . $a['ref_code']) ?></option><?php endforeach; ?></select></div>
          <button class="btn gold" name="act" value="approve">Add as client</button>
          <p class="tag" style="margin-bottom:0">You can edit any detail on the client page afterwards.</p></form>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <h3 style="margin-bottom:10px">Reject</h3>
          <div class="f" style="margin-bottom:12px"><label for="note">Reason (optional)</label><input id="note" name="note" placeholder="Duplicate, spam, incomplete…"></div>
          <button class="btn danger" name="act" value="reject" data-confirm="Reject this form?">Reject form</button></form>
      <?php else: ?>
        <div class="card"><p style="margin:0"><?= $s['status'] === 'approved' ? 'Added as a client' : 'Rejected' ?> by <?= e($s['reviewer']) ?> on <?= fdatetime($s['reviewed_at']) ?>.<?= $s['review_note'] ? ' Reason: ' . e($s['review_note']) : '' ?></p>
          <?php if ($s['client_id']): ?><p style="margin-bottom:0"><a href="/?p=client&id=<?= (int)$s['client_id'] ?>">Open client</a></p><?php endif; ?></div>
      <?php endif; ?>
      </div>
    </div>
    <?php page_foot(); return;
}

$status = in_array(get('status'), ['new', 'approved', 'rejected'], true) ? get('status') : 'new';
$rows = all('SELECT s.*, a.name agent_name, a.ref_code agent_ref FROM submissions s LEFT JOIN users a ON a.id=s.agent_id WHERE s.status=? ORDER BY s.id DESC LIMIT 500', [$status]);
page_head('Online forms'); ?>
<div class="spread"><div><h1>Online registration forms</h1><div class="muted">Clients fill these in themselves. Review each one and add it as a client.</div></div></div>
<div class="card"><div class="tag" style="text-transform:uppercase;letter-spacing:.07em">Public form link for clients</div>
  <div class="row" style="margin-top:6px"><span style="flex:1;word-break:break-all"><?= e($link) ?></span><button type="button" class="btn sm gold" data-copy="<?= e($link) ?>">Copy link</button></div>
  <p class="tag" style="margin-bottom:0">Each sub-agent also has a personal link with their referral ID filled in. You can find it under Team &amp; sub-agents.</p></div>
<div class="row" style="margin-bottom:12px"><?php foreach (['new' => 'New', 'approved' => 'Added as client', 'rejected' => 'Rejected'] as $k => $l): ?><a class="btn sm <?= $status === $k ? 'gold' : '' ?>" href="/?p=submissions&status=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="tablewrap"><table><thead><tr><th>Submitted</th><th>Name</th><th>Phone</th><th>Office</th><th>Referral</th><th>ID file</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $s): $d = json_decode($s['data'], true) ?: []; ?>
  <tr><td class="num"><?= fdatetime($s['created_at']) ?></td><td><a href="/?p=submissions&id=<?= (int)$s['id'] ?>" style="color:var(--ink)"><strong><?= e($s['name']) ?></strong></a></td><td class="num"><?= e($s['phone']) ?></td><td><?= e($d['office'] ?? '') ?></td>
    <td><?= $s['agent_name'] ? e($s['agent_name'] . ' · ' . $s['agent_ref']) : ($s['ref_code'] ? '<span class="pill p-warn">Unknown ' . e($s['ref_code']) . '</span>' : 'Direct') ?></td>
    <td><?= $s['id_file'] ? 'Yes' : '' ?></td><td><a class="btn sm" href="/?p=submissions&id=<?= (int)$s['id'] ?>">Review</a></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="empty">Nothing here.</td></tr><?php endif; ?>
</tbody></table></div>
<?php page_foot();
