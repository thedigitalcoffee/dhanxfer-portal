<?php
defined('DX') or exit;
$isAdmin = is_staff();
$mode = !$isAdmin ? 'agent' : (can('kyc.decide') ? 'admin' : 'staff');
$id = (int)get('id');
$c = null;
if ($id) {
    $c = one('SELECT * FROM clients WHERE id=?', [$id]);
    if (!$c || !can_see_client($c)) { flash('Client not found.', 'bad'); redirect('/?p=clients'); }
}
$agents = $isAdmin ? all("SELECT id,name,ref_code FROM users WHERE role='agent' AND active=1 ORDER BY name") : [];
$v = $c ?? ['kyc' => 'Pending', 'risk' => 'Low'];
$benefs = $c ? all('SELECT * FROM beneficiaries WHERE client_id=?', [$c['id']]) : [];
$reveal = can('pii.reveal');
if ($c) {
    $plainId = dec($c['id_number']);
    $v['id_number'] = $reveal ? $plainId : '';
    $v['id_masked'] = $reveal ? '' : mask($plainId);
    foreach ($benefs as &$bb) {
        $pa = dec($bb['account']);
        $bb['account_masked'] = $reveal ? '' : mask($pa);
        $bb['account'] = $reveal ? $pa : '';
    }
    unset($bb);
}
$errors = [];
$dupe = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$nv, $benefs, $errors] = read_client_post($c ? (int)$c['id'] : 0);
    if ($nv['office'] === '') $errors[] = 'Choose the office.';
    $consent = post('consent') === '1';
    $agentId = $isAdmin ? ((int)post('agent_id') ?: null) : ($c ? $c['agent_id'] : (int)$u['id']);
    if ($isAdmin && $agentId && !val("SELECT 1 FROM users WHERE id=? AND role='agent'", [$agentId])) $agentId = null;
    // KYC status and risk never change here: only the KYC review screen decides them
    $kyc = $c['kyc'] ?? 'Pending';
    $risk = $c['risk'] ?? 'Low';
    if (!$errors && !empty($nv['phone_e164']) && post('dupe_ok') !== '1') {
        $dupe = one('SELECT id, client_no, name, agent_id FROM clients WHERE phone_e164=? AND id<>?', [$nv['phone_e164'], $c['id'] ?? 0]);
        if ($dupe) $errors[] = 'A client with this phone number already exists' . (can_see_client($dupe) ? ': ' . $dupe['name'] . ' (' . $dupe['client_no'] . ')' : '') . '. Tick "Save anyway" below if this is a different person.';
    }
    // one KYC identity per person: the same ID document cannot belong to two client records
    if (!$errors && trim($nv['id_number']) !== '') {
        $idDupe = one('SELECT id, client_no, name FROM clients WHERE id_number_hash=? AND id<>?', [id_hash($nv['id_number']), $c['id'] ?? 0]);
        if ($idDupe) $errors[] = 'This ID number already belongs to another client' . (can_see_client($idDupe) ? ': ' . $idDupe['name'] . ' (' . $idDupe['client_no'] . ')' : '') . '. Every sender needs their own identity: use that client record instead.';
    }
    $file = null;
    if (!$errors) { try { $file = save_id_upload('id_file'); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); } }
    if (!$errors) {
        if ($c) {
            $sets = []; $par = [];
            $idKeys = ['id_type', 'id_number', 'id_country', 'id_expiry'];
            foreach (CLIENT_FIELDS as $k) { if (in_array($k, $idKeys, true)) continue; $sets[] = "$k=?"; $par[] = nz($nv[$k]); }
            foreach (['phone_e164', 'phone_region'] as $k) { $sets[] = "$k=?"; $par[] = $nv[$k] ?? null; }
            $sets[] = 'agent_id=?'; $par[] = $agentId; $sets[] = 'consent=?'; $par[] = $consent ? 1 : 0;
            $sets[] = 'updated_at=NOW()';
            $par[] = $c['id'];
            q('UPDATE clients SET ' . implode(',', $sets) . ' WHERE id=?', $par);
            // identity details of a verified client changed: back to review (a typo fix is quick to re-approve)
            $idChanged = [];
            foreach (['name' => 'name', 'dob' => 'date of birth', 'nationality' => 'nationality', 'address' => 'address', 'city' => 'address', 'province' => 'address', 'postal' => 'address', 'country' => 'address'] as $k => $label)
                if (trim((string)$c[$k]) !== trim((string)nz($nv[$k]))) $idChanged[$label] = true;
            if (($nv['phone_e164'] ?? null) && $nv['phone_e164'] !== $c['phone_e164']) $idChanged['phone'] = true;
            if ($idChanged) {
                kyc_identity_changed((int)$c['id'], implode(', ', array_keys($idChanged)), (int)$u['id']);
                if ($c['kyc'] === 'Verified') flash('Identity details changed (' . implode(', ', array_keys($idChanged)) . '), so this client needs a new KYC review before more transfers.', 'warn');
            }
            // ID changes become a new document version (history kept); a verified client goes back to review
            $old = ['id_type' => (string)$c['id_type'], 'id_number' => (string)dec($c['id_number']), 'id_country' => (string)$c['id_country'], 'id_expiry' => (string)$c['id_expiry']];
            $new = ['id_type' => $nv['id_type'], 'id_number' => $nv['id_number'] === '' && !$reveal ? $old['id_number'] : $nv['id_number'], 'id_country' => $nv['id_country'], 'id_expiry' => $nv['id_expiry']];
            $hasNew = $nv['id_has_address'] === '' ? ($c['id_has_address'] === null ? '' : ((int)$c['id_has_address'] ? 'yes' : 'no')) : $nv['id_has_address'];
            $oldHas = $c['id_has_address'] === null ? '' : ((int)$c['id_has_address'] ? 'yes' : 'no');
            if ($file || $new != $old || $hasNew !== $oldHas || $nv['id_addr_line1'] !== '') {
                client_save_document((int)$c['id'], array_merge($nv, $new, ['id_has_address' => $hasNew]), $file, $isAdmin ? 'admin' : 'agent', (int)$u['id']);
                if ($c['kyc'] === 'Verified') { q("UPDATE clients SET kyc='Pending' WHERE id=?", [$c['id']]); flash('The ID details changed, so this client needs a new KYC review.', 'warn'); }
            }
            audit('edit_client', 'client', $c['id'], $c['client_no']);
            flash('Client details saved.');
            redirect('/?p=client&id=' . $c['id']);
        } else {
            $newId = insert_client($nv, ['kyc' => $kyc, 'risk' => $risk, 'agent_id' => $agentId, 'source' => $isAdmin ? 'admin' : 'agent', 'consent' => $consent, 'id_file' => $file, 'created_by' => $u['id']]);
            foreach (save_beneficiaries($newId, $benefs, $isAdmin ? 'admin' : 'agent', (int)$u['id']) as $be) flash('Beneficiary not added: ' . $be, 'warn');
            audit('create_client', 'client', $newId, (string)val('SELECT client_no FROM clients WHERE id=?', [$newId]));
            flash('Client saved as ' . val('SELECT client_no FROM clients WHERE id=?', [$newId]) . '.');
            redirect('/?p=client&id=' . $newId);
        }
    }
    $v = array_merge($v, $nv, ['kyc' => $kyc, 'risk' => $risk, 'agent_id' => $agentId, 'consent' => $consent]);
}

page_head($c ? 'Edit client' : 'New client'); ?>
<div class="spread"><div><h1><?= $c ? 'Edit ' . e($c['name']) : 'New client' ?></h1>
  <div class="muted"><?= $isAdmin ? 'Fields marked * are required.' : 'This client will be saved under your referral code ' . e($u['username']) . '. An admin checks the ID before the first transfer.' ?></div></div></div>
<?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
<?php $wiz = $mode === 'agent' && !$c; ?>
<form method="post" enctype="multipart/form-data"<?= $wiz ? ' class="wizard" novalidate' : '' ?>>
  <?= csrf_field() ?>
  <?php if ($dupe): ?><label class="check banner warn"><input type="checkbox" name="dupe_ok" value="1"> Save anyway, this is a different person</label><?php endif; ?>
  <?php if ($wiz): client_wizard($v, $benefs, 'agent'); else: client_fields($v, $benefs, $mode, $agents); ?>
  <div class="formbar"><a class="btn" href="<?= $c ? '/?p=client&id=' . (int)$c['id'] : '/?p=clients' ?>">Cancel</a><button class="btn gold">Save client</button></div>
  <?php endif; ?>
</form>
<?php page_foot();
