<?php
defined('DX') or exit;
$isAdmin = is_staff();
$qs = get('q'); $kyc = get('kyc'); $office = get('office'); $agent = get('agent');
$where = []; $par = [];
[$scSql, $scPar] = scope_where($u, 'c'); $where[] = $scSql; $par = array_merge($par, $scPar);
if (!$isAdmin) {}
elseif ($agent === 'none') { $where[] = 'c.agent_id IS NULL'; }
elseif ($agent !== '') { $where[] = 'c.agent_id=?'; $par[] = (int)$agent; }
if ($qs !== '') { $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.client_no LIKE ? OR c.email LIKE ? OR c.id_number_hash = ?)'; $like = '%' . $qs . '%'; array_push($par, $like, $like, $like, $like, (string)id_hash($qs)); }
if (in_array($kyc, KYC, true)) { $where[] = 'c.kyc=?'; $par[] = $kyc; }
if (in_array($office, OFFICES, true)) { $where[] = 'c.office=?'; $par[] = $office; }
$sql = "SELECT c.*, a.name agent_name, a.ref_code,
  (SELECT COUNT(*) FROM transfers t WHERE t.client_id=c.id AND t.status IN ('accepted','completed')) ntx,
  (SELECT MAX(transfer_date) FROM transfers t WHERE t.client_id=c.id) last_tx
  FROM clients c LEFT JOIN users a ON a.id=c.agent_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.id DESC LIMIT 500';
$rows = all($sql, $par);
$agents = $isAdmin ? all("SELECT id,name,ref_code FROM users WHERE role='agent' ORDER BY name") : [];

if (get('export') === 'csv' && can('export')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dhanxfer-clients-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Client no.', 'Name', 'Phone', 'WhatsApp', 'Email', 'Office', 'DOB', 'Nationality', 'Occupation', 'Employer', 'Address', 'City', 'Province/Emirate', 'Postal', 'Country', 'ID type', 'ID number', 'ID country', 'ID expiry', 'KYC', 'Risk', 'Purpose', 'Source of funds', 'Monthly volume', 'Destination', 'Heard via', 'Referred by', 'Referral sub-agent', 'Registered via', 'Consent', 'Notes', 'Registered']);
    foreach ($rows as $c) fputcsv($out, [$c['client_no'], $c['name'], $c['phone'], $c['whatsapp'], $c['email'], $c['office'], $c['dob'], $c['nationality'], $c['occupation'], $c['employer'], $c['address'], $c['city'], $c['province'], $c['postal'], $c['country'], $c['id_type'], can('pii.reveal') ? dec($c['id_number']) : mask(dec($c['id_number'])), $c['id_country'], $c['id_expiry'], $c['kyc'], $c['risk'], $c['purpose'], $c['source_of_funds'], $c['monthly_volume'], $c['destination'], $c['referral'], $c['referred_by'], $c['ref_code'], $c['source'], $c['consent'] ? 'Yes' : 'No', $c['notes'], $c['created_at']]);
    audit('export_clients', 'client', null, count($rows) . ' rows');
    exit;
}

page_head($isAdmin ? 'Clients' : 'My clients'); ?>
<div class="spread">
  <div><h1><?= $isAdmin ? 'Clients' : 'My clients' ?></h1><div class="muted"><?= count($rows) ?> shown</div></div>
  <div class="row"><?php if (can('export')): ?><a class="btn sm" href="/?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a><?php endif; ?><?php if (!$isAdmin || can('clients.edit')): ?><a class="btn sm gold" href="/?p=client_form">+ New client</a><?php endif; ?></div>
</div>
<form class="row card" method="get" style="padding:12px">
  <input type="hidden" name="p" value="clients">
  <input name="q" value="<?= e($qs) ?>" placeholder="Search name, phone, client no., ID" style="flex:1;min-width:220px">
  <select name="kyc" style="width:auto"><?= opts(KYC, $kyc, 'All KYC') ?></select>
  <select name="office" style="width:auto"><?= opts(OFFICES, $office, 'All offices') ?></select>
  <?php if ($isAdmin): ?><select name="agent" style="width:auto"><option value="">All sub-agents</option><option value="none"<?= $agent === 'none' ? ' selected' : '' ?>>Direct clients</option>
    <?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $agent === (string)$a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ' · ' . $a['ref_code']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn sm">Filter</button>
</form>
<div class="tablewrap"><table>
  <thead><tr><th>Client no.</th><th>Name</th><th>Phone</th><th>Office</th><?php if ($isAdmin): ?><th>Sub-agent</th><?php endif; ?><th>KYC</th><th>ID expiry</th><th class="r">Transfers</th><th>Last transfer</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c):
      $exp = $c['id_expiry']; $expHtml = '';
      if ($exp) { $expHtml = $exp < today() ? '<span class="pill p-bad">Expired ' . fdate($exp) . '</span>' : ($exp <= date('Y-m-d', strtotime('+30 days')) ? '<span class="pill p-warn">' . fdate($exp) . '</span>' : fdate($exp)); } ?>
    <tr><td class="num"><a href="/?p=client&id=<?= (int)$c['id'] ?>"><?= e($c['client_no']) ?></a></td>
      <td><a href="/?p=client&id=<?= (int)$c['id'] ?>" style="color:var(--ink)"><strong><?= e($c['name']) ?></strong></a><?= $c['risk'] === 'High' ? ' <span class="pill p-bad">High risk</span>' : '' ?></td>
      <td class="num"><?= e($c['phone']) ?></td><td><?= e($c['office']) ?></td>
      <?php if ($isAdmin): ?><td><?= e($c['ref_code'] ? $c['agent_name'] . ' · ' . $c['ref_code'] : '') ?></td><?php endif; ?>
      <td><?= kyc_pill($c['kyc']) ?></td><td><?= $expHtml ?></td><td class="r num"><?= (int)$c['ntx'] ?></td><td><?= fdate($c['last_tx']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">No clients found.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php page_foot();
