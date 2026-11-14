<?php
defined('DX') or exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('act');
    if ($act === 'add') {
        $date = valid_date(post('act_date')) ?? today();
        $type = in_array(post('type'), ACT_TYPES, true) ? post('type') : 'Other';
        $cid = (int)post('client_id') ?: null;
        $cname = $cid ? val('SELECT name FROM clients WHERE id=?', [$cid]) : null;
        if (!$cname) { $cid = null; $cname = mb_substr(post('client_name'), 0, 160) ?: null; }
        $follow = valid_date(post('follow_up'));
        q('INSERT INTO activities (act_date,type,client_id,client_name,office,status,follow_up,notes,user_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$date, $type, $cid, $cname, in_array(post('office'), OFFICES, true) ? post('office') : null, $follow ? 'Open' : 'Done', $follow, mb_substr(post('notes'), 0, 2000) ?: null, $u['id']]);
        audit('add_activity', 'activity', (int)db()->lastInsertId(), $type . ($cname ? ' · ' . $cname : ''));
        flash('Entry saved.');
        redirect('/?p=log&d=' . $date);
    }
    if ($act === 'done') { q("UPDATE activities SET status='Done' WHERE id=?", [(int)post('id')]); audit('activity_done', 'activity', (int)post('id')); flash('Follow-up marked done.'); }
    if ($act === 'delete') { q('DELETE FROM activities WHERE id=?', [(int)post('id')]); audit('delete_activity', 'activity', (int)post('id')); flash('Entry deleted.'); }
    redirect('/?p=log&d=' . (valid_date(post('d')) ?? today()));
}

$d = valid_date(get('d')) ?? today();
$all = get('all') === '1';
$rows = $all ? all('SELECT a.*, u.name by_name FROM activities a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.act_date DESC, a.id DESC LIMIT 500')
             : all('SELECT a.*, u.name by_name FROM activities a LEFT JOIN users u ON u.id=a.user_id WHERE a.act_date=? ORDER BY a.id DESC', [$d]);
$txDay = all('SELECT ref_no, client_name, send_amount, send_currency, status FROM transfers WHERE transfer_date=? ORDER BY id DESC', [$d]);
$clients = all('SELECT id, name, client_no FROM clients ORDER BY name LIMIT 5000');
$pre = (int)get('client');
$prev = date('Y-m-d', strtotime("$d -1 day")); $next = date('Y-m-d', strtotime("$d +1 day"));
page_head('Daily log'); ?>
<div class="spread"><div><h1>Daily work log</h1><div class="muted">Enquiries, KYC checks, calls and visits. Transfers are recorded under Transfers.</div></div></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="add">
  <div class="fields">
    <div class="f"><label for="act_date">Date</label><input id="act_date" type="date" name="act_date" value="<?= e($d) ?>"></div>
    <div class="f"><label for="type">Type</label><select id="type" name="type"><?= opts(ACT_TYPES, 'Rate enquiry', '') ?></select></div>
    <div class="f"><label for="client_id">Registered client</label><select id="client_id" name="client_id"><option value="">Not registered / no client</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $pre === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name'] . ' · ' . $c['client_no']) ?></option><?php endforeach; ?></select></div>
    <div class="f"><label for="client_name">…or name of person</label><input id="client_name" name="client_name" placeholder="Walk-in enquiry name"></div>
    <div class="f"><label for="office">Office</label><select id="office" name="office"><?= opts(OFFICES, '') ?></select></div>
    <div class="f"><label for="follow_up">Follow-up date</label><input id="follow_up" type="date" name="follow_up"></div>
    <div class="f wide"><label for="notes">What happened?</label><textarea id="notes" name="notes" placeholder="e.g. Asked for the AED rate, will come back Monday with ID"></textarea></div>
  </div>
  <div class="formbar" style="margin-top:12px"><button class="btn gold">Save entry</button></div>
</form>
<div class="spread"><h2><?= $all ? 'All entries' : fdate($d) ?></h2>
  <form class="row" method="get"><input type="hidden" name="p" value="log">
    <a class="btn sm" href="/?p=log&d=<?= $prev ?>">◀</a><input type="date" name="d" value="<?= e($d) ?>" style="width:auto" onchange="this.form.submit()"><a class="btn sm" href="/?p=log&d=<?= $next ?>">▶</a>
    <a class="btn sm <?= $all ? 'gold' : '' ?>" href="/?p=log&all=1">All dates</a></form></div>
<div class="tablewrap"><table><thead><tr><th>Date</th><th>Type</th><th>Client</th><th>Notes</th><th>Follow-up</th><th>By</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr><td class="num"><?= fdate($a['act_date']) ?></td><td><?= e($a['type']) ?></td><td><?= $a['client_id'] ? '<a href="/?p=client&id=' . (int)$a['client_id'] . '">' . e($a['client_name']) . '</a>' : e($a['client_name']) ?></td><td><?= e($a['notes']) ?></td>
  <td><?= $a['follow_up'] ? ($a['status'] === 'Open' ? '<span class="pill ' . ($a['follow_up'] < today() ? 'p-bad' : 'p-warn') . '">' . fdate($a['follow_up']) . '</span>' : '<span class="pill p-ok">Done</span>') : '' ?></td><td class="tag"><?= e($a['by_name']) ?></td>
  <td style="white-space:nowrap"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="d" value="<?= e($d) ?>"><?php if ($a['status'] === 'Open'): ?><button class="btn sm ok" name="act" value="done">Done</button> <?php endif; ?><button class="btn sm danger" name="act" value="delete" data-confirm="Delete this entry?">✕</button></form></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="empty">No entries<?= $all ? '' : ' on this day' ?>.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if (!$all && $txDay): ?>
<h2 style="margin-bottom:10px">Transfers on this day</h2>
<div class="tablewrap"><table><thead><tr><th>Ref</th><th>Client</th><th class="r">Sent</th><th>Status</th></tr></thead><tbody>
<?php foreach ($txDay as $t): ?><tr><td><?= e($t['ref_no']) ?></td><td><?= e($t['client_name']) ?></td><td class="r num"><?= e(money($t['send_amount'], $t['send_currency'] ?? '')) ?></td><td><?= tx_pill($t['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php page_foot();
