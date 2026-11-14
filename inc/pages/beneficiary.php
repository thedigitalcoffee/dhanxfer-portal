<?php
defined('DX') or exit;
// Add, edit, deactivate and review one beneficiary of a client. ?p=beneficiary&client=ID[&id=BID]
$c = one('SELECT * FROM clients WHERE id=?', [(int)get('client')]);
if (!$c || !can_see_client($c)) { flash('Client not found.', 'bad'); redirect('/?p=clients'); }
$isAgent = $u['role'] === 'agent';
$canManage = $isAgent || can('beneficiaries.manage');
$canReview = can('beneficiaries.review');
$b = get('id') !== '' ? one('SELECT * FROM beneficiaries WHERE id=? AND client_id=?', [(int)get('id'), $c['id']]) : null;
if (get('id') !== '' && !$b) { flash('Beneficiary not found.', 'bad'); redirect('/?p=client&id=' . $c['id']); }
$errors = []; $form = [];
$back = '/?p=client&id=' . $c['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('act');
    $form = []; foreach (BENEF_FIELDS as $k) $form[$k] = post($k);
    if (in_array($act, ['add', 'edit', 'deactivate'], true) && !$canManage) { flash('Your role cannot change beneficiaries.', 'bad'); redirect($back); }
    if ($act === 'add') {
        [$id, $errors] = benef_add((int)$c['id'], $form, $isAgent ? 'agent' : 'admin', (int)$u['id']);
        if ($id) { flash('Beneficiary added. It can receive money after review.'); redirect($back); }
    } elseif ($act === 'edit' && $b) {
        [$nid, $errors] = benef_update($b, $form, (int)$u['id']);
        if ($nid) { flash($nid !== (int)$b['id'] ? 'Saved as a new version. It needs review before the next transfer; the approved details stay in the history.' : 'Beneficiary saved.'); redirect('/?p=beneficiary&client=' . $c['id'] . '&id=' . $nid); }
    } elseif ($act === 'deactivate' && $b) {
        if (trim(post('reason')) === '') $errors[] = 'Give a reason for deactivating.';
        else { benef_deactivate($b, mb_substr(trim(post('reason')), 0, 200), (int)$u['id']); flash('Beneficiary deactivated. It stays in the history.'); redirect($back); }
    } elseif ($act === 'review' && $b) {
        if (!$canReview) { flash('Your role cannot review beneficiaries.', 'bad'); redirect($back); }
        [$ok, $errors] = benef_review($b, $u, ['decision' => post('decision'), 'screening_ref' => post('screening_ref'), 'verification' => post('verification'), 'notes' => post('notes'), 'rev' => post('rev')]);
        if ($ok) { flash(post('decision') === 'approve' ? 'Beneficiary approved.' : 'Beneficiary rejected.'); redirect($back); }
    }
}
$reveal = $b && can('pii.reveal') && $_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'reveal';
if ($b) audit($reveal ? 'view_pii' : 'view_beneficiary', 'client', $c['id'], 'Beneficiary #' . $b['id'] . ($reveal ? ' · full account shown' : ''));
$acct = $b ? (string)dec($b['account']) : '';
$val = fn($k) => e($form[$k] ?? ($b && $k !== 'account' ? ($b[$k] ?? '') : ''));
$events = $b ? all('SELECT e.*, x.name FROM beneficiary_events e LEFT JOIN users x ON x.id=e.user_id WHERE e.beneficiary_id=? ORDER BY e.id DESC', [$b['id']]) : [];
$flags = $b ? benef_flags($b) : [];
$used = $b ? (int)val("SELECT COUNT(*) FROM transfers WHERE beneficiary_id=?", [$b['id']]) : 0;
page_head($b ? 'Beneficiary · ' . $b['name'] : 'Add beneficiary'); ?>
<div class="spread"><div><div class="tag"><a href="<?= e($back) ?>"><?= e($c['name']) ?> · <?= e($c['client_no']) ?></a></div>
  <h1><?= $b ? e($b['name']) : 'Add a beneficiary' ?></h1><?php if ($b): ?><div class="row" style="margin-top:6px"><?= benef_pill($b['status']) ?><span class="tag"><?= $used ?> transfer(s)</span></div><?php endif; ?></div>
  <a class="btn" href="<?= e($back) ?>">Back to client</a></div>
<?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
<div class="grid2"><div>
<?php if ($b && $b['replaced_by']): ?><div class="banner warn">These details were changed. <a href="/?p=beneficiary&client=<?= (int)$c['id'] ?>&id=<?= (int)$b['replaced_by'] ?>">Open the new version #<?= (int)$b['replaced_by'] ?></a>. This version is kept because transfers may point to it.</div><?php endif; ?>
<?php if ($canManage && (!$b || in_array($b['status'], ['pending', 'active'], true))): ?>
  <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="<?= $b ? 'edit' : 'add' ?>">
    <h3 style="margin-bottom:10px"><?= $b ? 'Details' : 'Who will receive the money?' ?></h3>
    <div class="fields">
      <div class="f wide"><label for="name">Full name (as on their bank account) <span class="req">*</span></label><input id="name" name="name" required value="<?= $val('name') ?>"></div>
      <div class="f"><label for="relation">Relationship to the client</label><input id="relation" name="relation" value="<?= $val('relation') ?>" placeholder="Mother, brother…"></div>
      <div class="f"><label for="country">Country</label><input id="country" name="country" list="countries" value="<?= $val('country') ?>"><?= datalist('countries', COUNTRIES) ?></div>
      <div class="f"><label for="bank">Bank name</label><input id="bank" name="bank" value="<?= $val('bank') ?>"></div>
      <div class="f"><label for="account">Account number / IBAN<?= $b && $acct !== '' ? ' · on file ' . e($reveal ? $acct : mask($acct)) . ' (leave empty to keep)' : '' ?></label><input id="account" name="account" autocomplete="off" value="<?= e($form['account'] ?? '') ?>"></div>
      <div class="f"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?= $val('phone') ?>"></div>
    </div>
    <?php if ($b && $b['status'] === 'active'): ?><p class="tag">Any change is saved as a new version that waits for review. The approved details stay unchanged for past transfers.</p><?php endif; ?>
    <button class="btn gold"><?= $b ? 'Save changes' : 'Add beneficiary' ?></button>
  </form>
<?php elseif ($b): ?>
  <div class="card"><dl class="kv"><?= kv('Name', $b['name']) . kv('Relationship', $b['relation']) . kv('Country', $b['country']) . kv('Bank', $b['bank']) . kv('Account', $reveal ? $acct : mask($acct)) . kv('Phone', $b['phone']) ?></dl></div>
<?php endif; ?>
<?php if ($b && can('pii.reveal') && !$reveal && $acct !== ''): ?><form method="post" class="inline"><?= csrf_field() ?><button class="linkbtn" name="act" value="reveal">Show full account number</button> <span class="tag">(logged)</span></form><?php endif; ?>
<?php if ($b && $canManage && in_array($b['status'], ['pending', 'active'], true)): ?>
  <form method="post" class="card row" style="padding:12px"><?= csrf_field() ?><input type="hidden" name="act" value="deactivate">
    <div class="f" style="flex:1;min-width:200px"><label for="reason">Deactivate: reason</label><input id="reason" name="reason" placeholder="e.g. client no longer sends to this person"></div>
    <button class="btn danger">Deactivate</button></form>
<?php endif; ?>
</div><div>
<?php if ($b && $b['status'] === 'pending' && $canReview): ?>
  <form method="post" class="card" style="border-color:var(--gold)"><?= csrf_field() ?><input type="hidden" name="act" value="review"><input type="hidden" name="rev" value="<?= (int)$b['rev'] ?>">
    <h3 style="margin-bottom:10px">Review</h3>
    <?php foreach ($flags as $f): ?><div class="banner warn" style="margin:0 0 8px"><?= e($f) ?></div><?php endforeach; ?>
    <?php if (!$flags): ?><p class="tag" style="margin-top:0">No automatic flags. Still screen the name.</p><?php endif; ?>
    <?php if (setting('benef_four_eyes', '1') === '1' && in_array((int)$u['id'], benef_editors($b), true)): ?><div class="banner warn" style="margin:0 0 8px">You added or changed this beneficiary: another reviewer must decide it.</div><?php endif; ?>
    <div class="fields">
      <div class="f wide"><label for="screening_ref">Sanctions screening reference</label><input id="screening_ref" name="screening_ref" value="<?= e(post('screening_ref')) ?>" placeholder="tool + result ID"></div>
      <div class="f wide"><label for="verification">How were the details verified?</label><select id="verification" name="verification"><?= opts(BENEF_VERIFY_METHODS, post('verification')) ?></select></div>
      <div class="f wide"><label for="notes">Notes <span class="req">*</span></label><textarea id="notes" name="notes"><?= e(post('notes')) ?></textarea></div>
      <div class="f"><label for="decision">Decision</label><select id="decision" name="decision"><option value="">Choose…</option><option value="approve">Approve</option><option value="reject">Reject</option></select></div>
    </div>
    <p class="tag">Reviewer: <?= e($u['name']) ?> · <?= date('M j, Y g:i a') ?></p>
    <button class="btn gold" style="width:100%">Record decision</button></form>
<?php elseif ($b && $b['reviewed_at']): ?>
  <div class="card"><h3 style="margin-bottom:10px">Review</h3><dl class="kv"><?= kv('Decided', fdatetime($b['reviewed_at']) . ' by ' . (string)val('SELECT name FROM users WHERE id=?', [$b['reviewed_by']])) . kv('Screening', $b['screening_ref']) . kv('Verified by', $b['verification']) ?></dl></div>
<?php endif; ?>
<?php if ($b): ?>
  <div class="card"><h3 style="margin-bottom:10px">History</h3><div class="list">
  <?php foreach ($events as $ev): ?><div class="li"><div><strong><?= e($ev['action']) ?></strong><?= $ev['fields'] ? ' <span class="tag">' . e($ev['fields']) . '</span>' : '' ?><div class="tag"><?= e($ev['name'] ?? 'system') ?><?= $ev['note'] ? ' · ' . e($ev['note']) : '' ?></div></div><div class="tag"><?= fdatetime($ev['at']) ?></div></div><?php endforeach; ?>
  </div></div>
<?php endif; ?>
</div></div>
<?php page_foot();
