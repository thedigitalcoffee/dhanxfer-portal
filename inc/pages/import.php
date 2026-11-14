<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
$report = null;

function csv_rows(string $path): array {
    $fh = fopen($path, 'r');
    $first = fgets($fh); rewind($fh);
    if (str_starts_with($first, "\xEF\xBB\xBF")) fread($fh, 3);
    $head = fgetcsv($fh);
    if (!$head) return [];
    $head = array_map(fn($h) => trim((string)$h), $head);
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        if (count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 0) continue;
        $row = [];
        foreach ($head as $i => $h) $row[$h] = trim((string)($r[$i] ?? ''));
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}
function find_agent(string $txt): ?int {
    if ($txt === '') return null;
    $id = val("SELECT id FROM users WHERE role='agent' AND (ref_code=? OR LOWER(name)=LOWER(?) OR username=LOWER(?))", [strtoupper($txt), $txt, $txt]);
    return $id ? (int)$id : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind = post('kind');
    if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) { flash('Choose a CSV file to import.', 'bad'); redirect('/?p=import'); }
    $rows = csv_rows($_FILES['csv']['tmp_name']);
    $added = 0; $skipped = [];
    db()->beginTransaction();
    try {
        if ($kind === 'clients') {
            foreach ($rows as $n => $r) {
                $name = $r['Name'] ?? ''; $phone = $r['Phone'] ?? '';
                if ($name === '' || $phone === '') { $skipped[] = 'Row ' . ($n + 2) . ': no name or phone'; continue; }
                $no = $r['Client no.'] ?? '';
                $digits = substr(preg_replace('/\D/', '', $phone), -10);
                if (($no && val('SELECT 1 FROM clients WHERE client_no=?', [$no])) || val("SELECT 1 FROM clients WHERE RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),10)=?", [$digits])) { $skipped[] = "$name: already in the portal"; continue; }
                $agentTxt = $r['Referral sub-agent'] ?? '';
                $agentId = find_agent($agentTxt);
                $v = ['name' => $name, 'dob' => valid_date($r['DOB'] ?? '') ?? '', 'nationality' => $r['Nationality'] ?? '', 'occupation' => $r['Occupation'] ?? '', 'employer' => $r['Employer'] ?? '',
                    'office' => in_array($r['Office'] ?? '', OFFICES, true) ? $r['Office'] : '', 'phone' => $phone, 'whatsapp' => $r['WhatsApp'] ?? '', 'email' => $r['Email'] ?? '',
                    'address' => $r['Address'] ?? '', 'city' => $r['City'] ?? '', 'province' => $r['Province/Emirate'] ?? '', 'postal' => $r['Postal'] ?? '', 'country' => $r['Country'] ?? '',
                    'id_type' => $r['ID type'] ?? '', 'id_number' => $r['ID number'] ?? '', 'id_country' => $r['ID country'] ?? '', 'id_expiry' => valid_date($r['ID expiry'] ?? '') ?? '',
                    'purpose' => $r['Purpose'] ?? '', 'source_of_funds' => $r['Source of funds'] ?? '', 'monthly_volume' => $r['Monthly volume'] ?? '', 'destination' => $r['Destination'] ?? '',
                    'referral' => $r['Heard via'] ?? '', 'referred_by' => $r['Referred by'] ?? '',
                    'notes' => trim(($r['Notes'] ?? '') . ($agentTxt && !$agentId ? "\nSub-agent (from old panel): $agentTxt" : ''))];
                $cid = insert_client($v, ['kyc' => in_array($r['KYC'] ?? '', KYC, true) ? $r['KYC'] : 'Pending', 'risk' => in_array($r['Risk'] ?? '', RISK, true) ? $r['Risk'] : 'Low',
                    'agent_id' => $agentId, 'source' => 'import', 'consent' => ($r['Consent'] ?? '') === 'Yes', 'created_by' => $u['id']]);
                if ($no && preg_match('/^DX-[A-Z0-9-]{4,20}$/', $no)) q('UPDATE clients SET client_no=? WHERE id=?', [$no, $cid]);
                $b = [];
                foreach (array_filter(explode('|', $r['Beneficiaries'] ?? '')) as $chunk) {
                    $p = array_map('trim', explode(' / ', $chunk));
                    if (($p[0] ?? '') !== '') $b[] = ['name' => $p[0], 'relation' => $p[1] ?? '', 'country' => $p[2] ?? '', 'bank' => $p[3] ?? '', 'account' => $p[4] ?? '', 'phone' => $p[5] ?? ''];
                }
                save_beneficiaries($cid, $b);
                $added++;
            }
        } elseif ($kind === 'log') {
            $map = ['Completed' => 'completed', 'Pending' => 'pending', 'Cancelled' => 'cancelled'];
            foreach ($rows as $n => $r) {
                $date = valid_date($r['Date'] ?? '') ?? today();
                $client = ($r['Client no.'] ?? '') ? one('SELECT id, name, agent_id, office FROM clients WHERE client_no=?', [$r['Client no.']]) : null;
                $cname = $client['name'] ?? ($r['Client'] ?? '');
                if (($r['Type'] ?? '') === 'Transfer' && isset($map[$r['Status'] ?? ''])) {
                    q('INSERT INTO transfers (ref_no,client_id,client_name,agent_id,beneficiary,send_amount,send_currency,rate,receive_amount,receive_currency,fee,payment_method,office,transfer_date,status,receipt_no,notes,requested_by,decided_by,decided_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                        new_transfer_no(), $client['id'] ?? null, $cname ?: 'Unknown', $client['agent_id'] ?? find_agent($r['Referral sub-agent'] ?? ''), nz($r['Beneficiary'] ?? ''),
                        num_or_null($r['Amount sent'] ?? ''), nz($r['Send currency'] ?? ''), num_or_null($r['Rate'] ?? ''), num_or_null($r['Amount received'] ?? ''), nz($r['Receive currency'] ?? ''),
                        num_or_null($r['Fee'] ?? ''), nz($r['Payment'] ?? ''), nz($r['Office'] ?? ''), $date, $map[$r['Status']], nz($r['Reference'] ?? ''), nz($r['Notes'] ?? ''),
                        $u['id'], $map[$r['Status']] !== 'pending' ? $u['id'] : null, $map[$r['Status']] !== 'pending' ? date('Y-m-d H:i:s') : null]);
                    apply_commission((int)db()->lastInsertId());
                } else {
                    $type = in_array($r['Type'] ?? '', ACT_TYPES, true) ? $r['Type'] : (($r['Type'] ?? '') === 'KYC / documents' ? 'KYC / documents' : 'Other');
                    if (($r['Type'] ?? '') === 'Follow-up call') $type = 'Follow-up call';
                    $notes = trim(($r['Notes'] ?? '') . (($r['Type'] ?? '') === 'Transfer' ? ' (quoted transfer ' . ($r['Amount sent'] ?? '') . ' ' . ($r['Send currency'] ?? '') . ')' : ''));
                    $follow = valid_date($r['Follow-up'] ?? '');
                    q('INSERT INTO activities (act_date,type,client_id,client_name,office,status,follow_up,notes,user_id) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$date, $type, $client['id'] ?? null, $cname ?: null, nz($r['Office'] ?? ''), $follow ? 'Open' : 'Done', $follow, $notes ?: null, $u['id']]);
                }
                $added++;
            }
        }
        db()->commit();
        audit('import', $kind, null, "$added added, " . count($skipped) . ' skipped');
        $report = [$added, $skipped];
    } catch (Throwable $ex) {
        db()->rollBack();
        flash('The import stopped and nothing was saved: ' . $ex->getMessage(), 'bad');
        redirect('/?p=import');
    }
}
page_head('Import'); ?>
<div class="spread"><div><h1>Import from the old panel</h1><div class="muted">Bring in the CSV files exported from the claude.ai operations panel. Import clients first, then the daily log.</div></div></div>
<?php if ($report): ?><div class="banner ok"><?= (int)$report[0] ?> rows imported.<?= $report[1] ? ' ' . count($report[1]) . ' skipped.' : '' ?></div>
  <?php if ($report[1]): ?><div class="card"><h3 style="margin-bottom:8px">Skipped</h3><ul style="margin:0"><?php foreach ($report[1] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul></div><?php endif; ?><?php endif; ?>
<div class="grid2">
  <form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="kind" value="clients">
    <h2 style="margin-bottom:8px">1. Clients</h2>
    <p class="tag">In the old panel open Clients → Export CSV, then choose that file here. Clients already in the portal (same client number or phone) are skipped. Sub-agents are matched by name or referral ID, so create sub-agent logins first.</p>
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <div style="margin-top:12px"><button class="btn gold">Import clients</button></div></form>
  <form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="kind" value="log">
    <h2 style="margin-bottom:8px">2. Daily work log</h2>
    <p class="tag">In the old panel open Daily work log, tick "All dates", then Export CSV. Completed and pending transfers become transfers; everything else goes to the daily log.</p>
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <div style="margin-top:12px"><button class="btn gold">Import log</button></div></form>
</div>
<?php page_foot();
