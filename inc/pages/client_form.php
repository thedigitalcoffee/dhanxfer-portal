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
        $bb['account_keep'] = $reveal ? '' : (string)$bb['account'];
        $bb['account_masked'] = $reveal ? '' : mask($pa);
        $bb['account'] = $reveal ? $pa : '';
    }
    unset($bb);
}
$errors = [];
$dupe = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$nv, $benefs, $errors] = read_client_post();
    if ($nv['office'] === '') $errors[] = 'Choose the office.';
    $consent = post('consent') === '1';
    $agentId = $isAdmin ? ((int)post('agent_id') ?: null) : ($c ? $c['agent_id'] : (int)$u['id']);
    if ($isAdmin && $agentId && !val("SELECT 1 FROM users WHERE id=? AND role='agent'", [$agentId])) $agentId = null;
    $kyc = can('kyc.decide') && in_array(post('kyc'), KYC, true) ? post('kyc') : ($c['kyc'] ?? 'Pending');
    $risk = can('kyc.decide') && in_array(post('risk'), RISK, true) ? post('risk') : ($c['risk'] ?? 'Low');
    $digits = substr(preg_replace('/\D/', '', $nv['phone']), -10);
    if (!$errors && $digits !== '' && post('dupe_ok') !== '1') {
        $dupe = one("SELECT id, client_no, name, agent_id FROM clients WHERE RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),10)=? AND id<>?", [$digits, $c['id'] ?? 0]);
        if ($dupe) $errors[] = 'A client with this phone number already exists' . (can_see_client($dupe) ? ': ' . $dupe['name'] . ' (' . $dupe['client_no'] . ')' : '') . '. Tick "Save anyway" below if this is a different person.';
    }
    $file = null;
    if (!$errors) { try { $file = save_id_upload('id_file'); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); } }
    if (!$errors) {
        if ($c) {
            $sets = []; $par = [];
            foreach (CLIENT_FIELDS as $k) {
                if ($k === 'id_number') {
                    if ($nv['id_number'] === '' && !$reveal) continue; // keep the number on file
                    $sets[] = 'id_number=?'; $par[] = enc(nz($nv['id_number'])); $sets[] = 'id_number_hash=?'; $par[] = id_hash($nv['id_number']);
                    continue;
                }
                $sets[] = "$k=?"; $par[] = nz($nv[$k]);
            }
            $sets[] = 'kyc=?'; $par[] = $kyc; $sets[] = 'risk=?'; $par[] = $risk;
            $sets[] = 'agent_id=?'; $par[] = $agentId; $sets[] = 'consent=?'; $par[] = $consent ? 1 : 0;
            if ($file) { $sets[] = 'id_file=?'; $par[] = $file; }
            $sets[] = 'updated_at=NOW()';
            $par[] = $c['id'];
            q('UPDATE clients SET ' . implode(',', $sets) . ' WHERE id=?', $par);
            save_beneficiaries((int)$c['id'], $benefs);
            audit('edit_client', 'client', $c['id'], $c['client_no']);
            flash('Client details saved.');
            redirect('/?p=client&id=' . $c['id']);
        } else {
            $newId = insert_client($nv, ['kyc' => $kyc, 'risk' => $risk, 'agent_id' => $agentId, 'source' => $isAdmin ? 'admin' : 'agent', 'consent' => $consent, 'id_file' => $file, 'created_by' => $u['id']]);
            save_beneficiaries($newId, $benefs);
            audit('create_client', 'client', $newId, (string)val('SELECT client_no FROM clients WHERE id=?', [$newId]));
            flash('Client saved as ' . val('SELECT client_no FROM clients WHERE id=?', [$newId]) . '.');
            redirect('/?p=client&id=' . $newId);
        }
    }
    $v = array_merge($v, $nv, ['kyc' => $kyc, 'risk' => $risk, 'agent_id' => $agentId, 'consent' => $consent]);
}

page_head($c ? 'Edit client' : 'New client'); ?>
<div class="spread"><div><h1><?= $c ? 'Edit ' . e($c['name']) : 'New client' ?></h1>
  <div class="muted"><?= $isAdmin ? 'Fields marked * are required.' : 'This client will be saved under your referral ID ' . e($u['ref_code']) . '. An admin checks the ID before the first transfer.' ?></div></div></div>
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
