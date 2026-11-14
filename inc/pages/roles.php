<?php
defined('DX') or exit;
$editable = ['admin', 'staff', 'accountant'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $changes = [];
    db()->beginTransaction();
    foreach ($editable as $role) {
        $before = role_perms($role);
        $after = [];
        foreach (array_keys(PERMS) as $perm) if (!empty($_POST['perm'][$role][$perm])) $after[] = $perm;
        q('DELETE FROM role_permissions WHERE role=?', [$role]);
        foreach ($after as $perm) q('INSERT INTO role_permissions (role, perm) VALUES (?, ?)', [$role, $perm]);
        $added = array_diff($after, $before); $removed = array_diff($before, $after);
        if ($added || $removed) $changes[] = role_label($role) . ': ' . ($added ? '+' . implode(' +', $added) : '') . ($removed ? ' -' . implode(' -', $removed) : '');
    }
    db()->commit();
    if ($changes) {
        q('UPDATE users SET session_version=session_version+1 WHERE role IN (' . implode(',', array_fill(0, count($editable), '?')) . ')', $editable);
        audit('roles_changed', 'roles', null, implode(' | ', $changes));
        flash('Role permissions saved. People in changed roles need to log in again.');
    } else flash('Nothing changed.');
    redirect('/?p=roles');
}

$counts = [];
foreach (all("SELECT role, COUNT(*) n FROM users WHERE active=1 GROUP BY role") as $r) $counts[$r['role']] = (int)$r['n'];
page_head('Roles'); ?>
<div class="spread"><div><h1>Job roles and permissions</h1>
  <div class="muted">Choose what each job role can do. The super admin can always do everything. Sub-agents only ever see their own clients, transfers and commission.</div></div></div>
<form method="post"><?= csrf_field() ?>
<div class="tablewrap"><table class="matrix"><thead><tr><th>Permission</th>
  <th class="c">Super admin<div class="tag"><?= $counts['super_admin'] ?? 0 ?> people</div></th>
  <?php foreach ($editable as $role): ?><th class="c"><?= e(role_label($role)) ?><div class="tag"><?= $counts[$role] ?? 0 ?> people</div></th><?php endforeach; ?>
  <th class="c">Sub-agent<div class="tag"><?= $counts['agent'] ?? 0 ?> people</div></th></tr></thead><tbody>
<?php foreach (PERMS as $perm => $label): ?>
  <tr><td><?= e($label) ?></td><td class="c"><span aria-label="Always">✓</span></td>
  <?php foreach ($editable as $role): ?><td class="c"><input type="checkbox" name="perm[<?= e($role) ?>][<?= e($perm) ?>]" value="1" aria-label="<?= e(role_label($role) . ': ' . $label) ?>" <?= role_can($role, $perm) ? 'checked' : '' ?> style="width:20px;height:20px"></td><?php endforeach; ?>
  <td class="c tag"><?= ['clients.edit' => 'Own clients', 'transfers.create' => 'Requests only', 'commission.view' => 'Own only', 'audit.all' => 'Own only'][$perm] ?? '—' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="formbar"><button class="btn gold">Save permissions</button></div>
</form>
<p class="tag">All permissions are stored in the database table <code>role_permissions</code>, and every change is recorded in the activity history.</p>
<?php page_foot();
