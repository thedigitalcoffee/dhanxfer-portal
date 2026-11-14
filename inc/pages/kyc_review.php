<?php
defined('DX') or exit;
// Structured KYC review. Only roles with kyc.decide get here (checked in index.php); every rule is enforced again in kyc_decide().
$c = one('SELECT c.*, a.name agent_name, a.username agent_login FROM clients c LEFT JOIN users a ON a.id=c.agent_id WHERE c.id=?', [(int)get('id')]);
if (!$c || !can_see_client($c)) { flash('Client not found.', 'bad'); redirect('/?p=compliance'); }
$errors = []; $in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'decide') {
    $in = ['decision' => post('decision'), 'checklist' => is_array($_POST['checklist'] ?? null) ? array_map('strval', $_POST['checklist']) : [],
           'risk' => post('risk'), 'notes' => post('notes'), 'evidence' => post('evidence'), 'address_evidence' => post('address_evidence'),
           'screening_ref' => post('screening_ref'), 'code' => post('code')];
    [$ok, $errors, $rid] = kyc_decide($c, me(), $in);
    if ($ok) { flash(KYC_DECISIONS[$in['decision']] . ' recorded (review #' . $rid . ').'); redirect('/?p=kyc_review&id=' . $c['id']); }
}
$reveal = $_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'reveal' && can('pii.reveal');
audit($reveal ? 'view_pii' : 'kyc_review_opened', 'client', $c['id'], $c['client_no'] . ($reveal ? ' · full ID number shown on review' : ''));
$ver = doc_current((int)$c['id']);
$hist = doc_history((int)$c['id']);
$reviews = all('SELECT r.*, u.name reviewer FROM kyc_reviews r JOIN users u ON u.id=r.reviewer_id WHERE r.client_id=? ORDER BY r.id DESC', [$c['id']]);
$cases = all("SELECT * FROM rekyc_cases WHERE client_id=? ORDER BY id DESC LIMIT 10", [$c['id']]);
$log = all("SELECT a.*, u.name FROM audit a LEFT JOIN users u ON u.id=a.user_id WHERE a.entity='client' AND a.entity_id=? AND a.action LIKE 'kyc_%' ORDER BY a.id DESC LIMIT 20", [$c['id']]);
$home = ['line1' => $c['address'], 'city' => $c['city'], 'province' => $c['province'], 'postal' => $c['postal'], 'country' => $c['country']];
$idAddr = $ver && $ver['address_json'] ? json_decode($ver['address_json'], true) : null;
$cmp = $ver && $ver['has_address'] !== null ? addr_compare($home, (int)$ver['has_address'] ? ($idAddr ?: $home) : null) : ['result' => null, 'notes' => ['Not stated whether the ID shows an address.']];
$daysLeft = $ver && $ver['expires_on'] ? (int)floor((strtotime($ver['expires_on']) - strtotime(today())) / 86400) : null;
[$eligible, $why] = transfer_eligibility($c);
$plainNo = $ver ? dec($ver['number_enc']) : null;
$flags = [];
if ($c['risk'] === 'High') $flags[] = 'Client marked high risk.';
if (in_array($c['monthly_volume'], ['10,000 – 25,000', 'Over 25,000'], true)) $flags[] = 'Expected monthly amount is high: check source of funds evidence.';
if (in_array($c['source_of_funds'], ['Sale of property', 'Gift / inheritance', 'Other'], true)) $flags[] = 'Source of funds needs supporting evidence (' . $c['source_of_funds'] . ').';
if ($c['nationality'] && $c['country'] && $c['nationality'] !== $c['country'] && $c['destination'] && $c['destination'] !== $c['nationality']) $flags[] = 'Destination differs from nationality and residence.';
if ((int)val("SELECT COUNT(*) FROM clients WHERE id_number_hash=? AND id<>?", [$c['id_number_hash'], $c['id']]) && $c['id_number_hash']) $flags[] = 'The same ID number is on another client record.';
if ($c['phone_e164'] && (int)val('SELECT COUNT(*) FROM clients WHERE phone_e164=? AND id<>?', [$c['phone_e164'], $c['id']])) $flags[] = 'The same phone number is on another client record.';
$tick = fn($k) => in_array($k, $in['checklist'] ?? [], true) ? 'checked' : '';
page_head('KYC review · ' . $c['name']); ?>
<div class="spread"><div><div class="tag num"><a href="/?p=client&id=<?= (int)$c['id'] ?>"><?= e($c['client_no']) ?></a></div><h1>KYC review: <?= e($c['name']) ?></h1>
  <div class="row" style="margin-top:6px"><?= kyc_pill($c['kyc']) ?><span class="pill p-mute"><?= e($c['risk']) ?> risk</span><?= $eligible ? '<span class="pill p-ok">Transfers allowed</span>' : '<span class="pill p-bad">Transfers blocked</span>' ?></div></div>
  <a class="btn" href="/?p=compliance">Compliance Center</a></div>
<?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
<div class="grid2">
<div>
  <div class="card"><h3 style="margin-bottom:10px">1. Identity and submitted information</h3><dl class="kv">
    <?= kv('Full name', $c['name']) . kv('Date of birth', fdate($c['dob'])) . kv('Nationality', $c['nationality']) . kv('Phone', $c['phone_e164'] ? $c['phone_e164'] . ' (format checked, not ownership)' : $c['phone']) . kv('Email', $c['email'])
      . kv('Occupation', trim($c['occupation'] . ($c['employer'] ? ' · ' . $c['employer'] : ''))) . kv('Office', $c['office']) . kv('Sub-agent', $c['agent_name'] ? $c['agent_name'] . ' (' . $c['agent_login'] . ')' : 'Direct')
      . kv('Registered', fdatetime($c['created_at']) . ' via ' . $c['source']) ?></dl></div>
  <div class="card"><h3 style="margin-bottom:10px">2. Identity document</h3>
    <?php if (!$ver): ?><div class="banner bad" style="margin:0">No identity document on file. Request one before approving.</div>
    <?php else: ?><dl class="kv">
      <?= kv('Type', $ver['doc_type']) . kv('Number', $reveal ? (string)$plainNo : mask($plainNo)) . kv('Issued by', $ver['issuing_country']) . kv('Version', 'v' . $ver['version'] . ' · ' . $ver['source'] . ' · ' . fdatetime($ver['created_at'])) . kv('Previous outcome', $ver['outcome']) ?>
      <dt>Expiry</dt><dd><?= $ver['expires_on'] ? fdate($ver['expires_on']) . ' · ' . ($daysLeft < 0 ? '<span style="color:var(--bad)">expired ' . (-$daysLeft) . ' days ago</span>' : ($daysLeft <= 90 ? '<span style="color:var(--warn)">' . $daysLeft . ' days left</span>' : $daysLeft . ' days left')) : '<span style="color:var(--bad)">missing</span>' ?></dd>
      <dt>Image / scan</dt><dd><?= $ver['file_name'] ? '<a href="/file.php?f=' . e($ver['file_name']) . '" target="_blank" rel="noopener">Open the document (logged)</a>' . (id_file_encrypted($ver['file_name']) ? ' <span class="tag">stored encrypted</span>' : '') : '<span style="color:var(--bad)">none on file</span>' ?></dd>
      <?php if (can('pii.reveal') && !$reveal && $plainNo): ?><dt></dt><dd><form method="post" class="inline"><?= csrf_field() ?><button class="linkbtn" name="act" value="reveal">Show full number</button></form> <span class="tag">(logged)</span></dd><?php endif; ?>
    </dl><?php endif; ?></div>
  <div class="card"><h3 style="margin-bottom:10px">3. Address check</h3><dl class="kv">
    <?= kv('Home address', implode(', ', array_filter($home)) . ($c['address_source'] === 'lookup' ? ' (picked from address lookup: not proof)' : '')) ?>
    <?= kv('On the ID', $ver && $ver['has_address'] !== null ? ((int)$ver['has_address'] ? ($idAddr ? implode(', ', array_filter($idAddr)) : 'Same as home (client said)') : 'No address on the ID') : 'Not stated') ?>
    <dt>Result</dt><dd><strong><?= e(addr_result_label($cmp['result'])) ?></strong><?php foreach ($cmp['notes'] as $nt): ?><div class="tag"><?= e($nt) ?></div><?php endforeach; ?></dd></dl>
    <?php if (addr_needs_evidence($cmp['result'])): ?><p class="tag" style="margin-bottom:0">Approval needs a proof-of-address reference (e.g. utility bill or bank statement under 3 months old). Address changes are normal: do not reject for this alone.</p><?php endif; ?></div>
  <div class="card"><h3 style="margin-bottom:10px">4. Risk and compliance checks</h3>
    <dl class="kv"><?= kv('Purpose', $c['purpose']) . kv('Source of funds', $c['source_of_funds']) . kv('Expected monthly', $c['monthly_volume']) . kv('Usual destination', $c['destination']) ?></dl>
    <?php foreach ($flags as $f): ?><div class="banner warn" style="margin:8px 0 0"><?= e($f) ?></div><?php endforeach; ?>
    <?php if (!$flags): ?><div class="tag">No automatic flags. Still complete the screening.</div><?php endif; ?>
    <?php if (!$eligible): ?><p class="tag">Transfers are blocked now: <?= e(implode(' ', $why)) ?></p><?php endif; ?></div>
</div>
<div>
  <form method="post" class="card" style="border-color:var(--gold)"><?= csrf_field() ?><input type="hidden" name="act" value="decide">
    <h3 style="margin-bottom:10px">5. Checklist and decision</h3>
    <?php foreach (KYC_CHECKLIST as $k => $label): ?><label class="check" style="margin:6px 0"><input type="checkbox" name="checklist[]" value="<?= e($k) ?>" <?= $tick($k) ?>><span><?= e($label) ?></span></label><?php endforeach; ?>
    <div class="fields" style="margin-top:10px">
      <div class="f"><label for="screening_ref">Sanctions / PEP screening reference</label><input id="screening_ref" name="screening_ref" value="<?= e($in['screening_ref'] ?? '') ?>" placeholder="e.g. tool name + result ID"></div>
      <div class="f"><label for="risk">Risk level</label><select id="risk" name="risk"><?= opts(RISK, $in['risk'] ?? $c['risk']) ?></select></div>
      <div class="f wide"><label for="evidence">Evidence reference</label><input id="evidence" name="evidence" value="<?= e($in['evidence'] ?? '') ?>" placeholder="Where the checked documents are recorded (file names, folder, ticket)"></div>
      <?php if (addr_needs_evidence($cmp['result'])): ?><div class="f wide"><label for="address_evidence">Proof of address used <span class="req">*</span></label><input id="address_evidence" name="address_evidence" value="<?= e($in['address_evidence'] ?? '') ?>" placeholder="e.g. Hydro bill dated 2026-10-02, saved as …"></div><?php endif; ?>
      <div class="f wide"><label for="notes">Decision notes <span class="req">*</span></label><textarea id="notes" name="notes" required minlength="15" placeholder="What you checked and why you decided this"><?= e($in['notes'] ?? '') ?></textarea></div>
      <div class="f"><label for="decision">Decision</label><select id="decision" name="decision" required><option value="">Choose…</option><?php foreach (KYC_DECISIONS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($in['decision'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="f"><label for="code">Your two-step code (confirms it is you)</label><input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="11"></div>
    </div>
    <p class="tag">Reviewer: <strong><?= e(me()['name']) ?></strong> (<?= e(role_label(me()['role'])) ?>) · <?= date('M j, Y g:i a') ?>. The decision, your name and the time are saved permanently.</p>
    <button class="btn gold" style="width:100%">Record decision</button>
  </form>
  <div class="card"><h3 style="margin-bottom:10px">History</h3>
    <div class="list"><?php foreach ($reviews as $r): $ck = json_decode((string)$r['checklist'], true) ?: []; ?>
      <div class="li"><div><strong><?= e(KYC_DECISIONS[$r['decision']]) ?></strong> <span class="tag">#<?= (int)$r['id'] ?> · <?= e($r['case_type']) ?></span>
        <div class="tag"><?= e($r['reviewer']) ?> (<?= e(role_label($r['reviewer_role'])) ?>) · <?= fdatetime($r['decided_at']) ?><?= $r['risk'] ? ' · ' . e($r['risk']) . ' risk' : '' ?><?= !empty($ck['screening_ref']) ? ' · screening ' . e($ck['screening_ref']) : '' ?></div>
        <div style="white-space:pre-wrap"><?= e($r['notes']) ?></div><?php if ($r['evidence']): ?><div class="tag">Evidence: <?= e($r['evidence']) ?></div><?php endif; ?></div></div>
    <?php endforeach; ?><?php if (!$reviews): ?><div class="muted">No decisions yet.</div><?php endif; ?></div>
    <h3 style="margin:14px 0 8px">Documents</h3>
    <div class="list"><?php foreach ($hist as $h): ?><div class="li"><div><strong><?= e($h['doc_type'] ?: 'Document') ?> v<?= (int)$h['version'] ?></strong> <span class="tag"><?= e($h['outcome']) ?> · <?= e($h['source']) ?></span><div class="tag">Expires <?= fdate($h['expires_on']) ?: '—' ?> · added <?= fdatetime($h['created_at']) ?></div></div></div><?php endforeach; ?>
    <?php if (!$hist): ?><div class="muted">None.</div><?php endif; ?></div>
    <?php if ($cases): ?><h3 style="margin:14px 0 8px">KYC refresh cases</h3><div class="list"><?php foreach ($cases as $k): ?><div class="li"><div><strong>#<?= (int)$k['id'] ?> <?= e(str_replace('_', ' ', $k['reason'])) ?></strong><div class="tag"><?= e($k['status']) ?> · due <?= fdate($k['due_date']) ?></div></div></div><?php endforeach; ?></div><?php endif; ?>
    <h3 style="margin:14px 0 8px">Audit trail</h3>
    <div class="list"><?php foreach ($log as $l): ?><div class="li"><div><?= e($l['action']) ?> <span class="tag"><?= e($l['name'] ?? 'system') ?></span></div><div class="tag"><?= fdatetime($l['created_at']) ?></div></div><?php endforeach; ?></div>
  </div>
</div>
</div>
<?php page_foot();
