<?php
defined('DX') or exit;
// Compliance Center: KYC queue, document expiry, KYC refresh cases, reminder history and the policy settings.
$manage = can('compliance.manage');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$manage) { flash('Your role can only view the Compliance Center.', 'bad'); redirect('/?p=compliance'); }
    $act = post('act');
    if ($act === 'ack') {
        q("UPDATE reminders SET status='acknowledged', ack_at=NOW(), ack_by=? WHERE id=? AND status IN ('sent','failed')", [me()['id'], (int)post('id')]);
        audit('reminder_ack', 'reminder', (int)post('id'), 'acknowledged');
    } elseif ($act === 'retry') {
        q("UPDATE reminders SET status='pending', attempts=0, next_attempt_at=NOW() WHERE id=? AND status='failed'", [(int)post('id')]);
        audit('reminder_retry', 'reminder', (int)post('id'), 'queued again');
    } elseif ($act === 'run') {
        $n = compliance_daily();
        flash('Checks run: ' . $n['reminders_created'] . ' reminder(s), ' . $n['cases_opened'] . ' case(s) opened, ' . $n['escalated'] . ' escalated, ' . $n['sent'] . ' sent, ' . $n['failed'] . ' failed.');
    } elseif ($act === 'policy') {
        // changing when transfers are blocked needs a fresh two-step code, like a KYC decision
        require_once __DIR__ . '/../mfa.php';
        $fresh = one('SELECT * FROM users WHERE id=?', [me()['id']]);
        if (!$fresh['mfa_enabled'] || mfa_locked((int)$fresh['id']) || !mfa_check($fresh, post('code'))) { if ($fresh['mfa_enabled']) mfa_fail((int)$fresh['id']); flash('Enter a current two-step code to change compliance settings.', 'bad'); redirect('/?p=compliance&v=policy'); }
        $changes = [];
        foreach (array_keys(COMPLIANCE_DEFAULTS) as $k) {
            if (!isset($_POST[$k])) { if (str_starts_with($k, 'block_')) { $v = '0'; } else continue; }
            elseif ($k === 'benef_high_risk_countries') $v = mb_substr(implode(', ', array_filter(array_map('trim', explode(',', post($k))))), 0, 1000);
            else $v = str_starts_with($k, 'block_') ? (post($k) === '1' ? '1' : '0') : (string)max(0, min(3650, (int)post($k)));
            if (setting($k) !== $v) { set_setting($k, $v); $changes[] = "$k=$v"; }
        }
        $offs = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', post('offsets'))), fn($d) => $d >= 0 && $d <= 365)));
        if ($offs) {
            q('UPDATE reminder_rules SET active=0');
            foreach ($offs as $d) foreach (['email', 'staff'] as $ch) q('INSERT INTO reminder_rules (offset_days, channel, active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE active=1', [$d, $ch]);
            $changes[] = 'reminders=' . implode(',', $offs);
        }
        audit('compliance_policy', 'settings', null, $changes ? implode('; ', $changes) : 'no change');
        if ($changes) notify('Compliance settings changed', me()['username'] . ' changed compliance settings: ' . implode('; ', $changes), 'security_alert');
        flash($changes ? 'Compliance settings saved.' : 'Nothing changed.');
    }
    redirect('/?p=compliance&v=' . urlencode(get('v', 'pending')));
}
$views = ['pending' => 'Pending KYC', 'benef' => 'Beneficiaries to review', 'expiring' => 'Expiring soon', 'expired' => 'Expired documents', 'required' => 'KYC refresh required',
          'progress' => 'KYC refresh in progress', 'completed' => 'Completed refresh', 'reminders' => 'Reminder history', 'policy' => 'Settings'];
$v = array_key_exists(get('v'), $views) ? get('v') : 'pending';
[$sw, $sp] = scope_where(me(), 'c');
$count = [
    'pending' => (int)val("SELECT COUNT(*) FROM clients c WHERE $sw AND c.kyc IN ('Pending','Info requested','Escalated')", $sp),
    'expiring' => (int)val("SELECT COUNT(*) FROM clients c JOIN client_documents d ON d.client_id=c.id AND d.doc_kind='identity' AND d.status='current' JOIN document_versions v ON v.id=d.current_version_id WHERE $sw AND v.expires_on >= CURDATE() AND v.expires_on <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)", $sp),
    'expired' => (int)val("SELECT COUNT(*) FROM clients c JOIN client_documents d ON d.client_id=c.id AND d.doc_kind='identity' AND d.status='current' JOIN document_versions v ON v.id=d.current_version_id WHERE $sw AND v.expires_on < CURDATE()", $sp),
    'required' => (int)val("SELECT COUNT(*) FROM rekyc_cases k JOIN clients c ON c.id=k.client_id WHERE $sw AND k.status IN ('required','escalated')", $sp),
    'progress' => (int)val("SELECT COUNT(*) FROM rekyc_cases k JOIN clients c ON c.id=k.client_id WHERE $sw AND k.status='in_progress'", $sp),
    'completed' => (int)val("SELECT COUNT(*) FROM rekyc_cases k JOIN clients c ON c.id=k.client_id WHERE $sw AND k.status='completed'", $sp),
    'reminders' => (int)val("SELECT COUNT(*) FROM reminders r JOIN clients c ON c.id=r.client_id WHERE $sw AND r.status<>'skipped'", $sp),
    'benef' => (int)val("SELECT COUNT(*) FROM beneficiaries b JOIN clients c ON c.id=b.client_id WHERE $sw AND b.status='pending'", $sp),
];
$docJoin = "JOIN client_documents d ON d.client_id=c.id AND d.doc_kind='identity' AND d.status='current' JOIN document_versions v ON v.id=d.current_version_id";
$rows = match ($v) {
    'pending' => all("SELECT c.id, c.client_no, c.name, c.kyc, c.risk, c.office, c.address_match, c.created_at, v.expires_on FROM clients c LEFT JOIN client_documents d ON d.client_id=c.id AND d.doc_kind='identity' AND d.status='current' LEFT JOIN document_versions v ON v.id=d.current_version_id WHERE $sw AND c.kyc IN ('Pending','Info requested','Escalated') ORDER BY FIELD(c.kyc,'Escalated','Pending','Info requested'), c.created_at LIMIT 300", $sp),
    'expiring' => all("SELECT c.id, c.client_no, c.name, c.kyc, c.email, v.expires_on, DATEDIFF(v.expires_on, CURDATE()) days FROM clients c $docJoin WHERE $sw AND v.expires_on >= CURDATE() AND v.expires_on <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY v.expires_on LIMIT 300", $sp),
    'expired' => all("SELECT c.id, c.client_no, c.name, c.kyc, c.email, v.expires_on, DATEDIFF(CURDATE(), v.expires_on) days FROM clients c $docJoin WHERE $sw AND v.expires_on < CURDATE() ORDER BY v.expires_on LIMIT 300", $sp),
    'required', 'progress', 'completed' => all("SELECT k.*, c.client_no, c.name, c.kyc, r.decided_at, u.name reviewer FROM rekyc_cases k JOIN clients c ON c.id=k.client_id LEFT JOIN kyc_reviews r ON r.id=k.closed_review_id LEFT JOIN users u ON u.id=r.reviewer_id WHERE $sw AND k.status IN (" .
        ['required' => "'required','escalated'", 'progress' => "'in_progress'", 'completed' => "'completed'"][$v] . ") ORDER BY " . ($v === 'completed' ? 'k.closed_at DESC' : "k.status='escalated' DESC, k.due_date") . " LIMIT 300", $sp),
    'reminders' => all("SELECT r.*, c.client_no, c.name, (SELECT COUNT(*) FROM notification_attempts n WHERE n.reminder_id=r.id) tries FROM reminders r JOIN clients c ON c.id=r.client_id WHERE $sw AND r.status<>'skipped' ORDER BY r.id DESC LIMIT 300", $sp),
    'benef' => all("SELECT b.id, b.client_id, b.name, b.country, b.bank, b.created_at, b.source, c.client_no, c.name AS cname, c.kyc FROM beneficiaries b JOIN clients c ON c.id=b.client_id WHERE $sw AND b.status='pending' ORDER BY b.created_at LIMIT 300", $sp),
    default => [],
};
$reasons = ['doc_expiring' => 'ID expiring', 'doc_expired' => 'ID expired', 'periodic' => 'Periodic refresh', 'manual' => 'Requested by staff'];
$open = fn($id, $label) => can('kyc.decide') ? '<a href="/?p=kyc_review&id=' . (int)$id . '">' . e($label) . '</a>' : '<a href="/?p=client&id=' . (int)$id . '">' . e($label) . '</a>';
page_head('Compliance Center'); ?>
<div class="spread"><div><h1>Compliance Center</h1><div class="muted">KYC reviews, document expiry and refresh cases. Last daily check: <?= e((string)setting('compliance_last_run', 'not run yet')) ?></div></div>
  <?php if ($manage): ?><form method="post" class="inline"><?= csrf_field() ?><button class="btn sm" name="act" value="run">Run today's checks now</button></form><?php endif; ?></div>
<nav class="subtabs"><?php foreach ($views as $k => $label): ?><a href="/?p=compliance&v=<?= e($k) ?>" class="<?= $v === $k ? 'active' : '' ?>"><?= e($label) ?><?= isset($count[$k]) ? ' <span class="badge' . ($count[$k] ? '' : ' zero') . '">' . $count[$k] . '</span>' : '' ?></a><?php endforeach; ?></nav>

<?php if ($v === 'policy'): $rules = array_column(all("SELECT DISTINCT offset_days FROM reminder_rules WHERE active=1 ORDER BY offset_days DESC"), 'offset_days'); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="policy">
  <p class="banner warn">These are starting values. Have them approved by your compliance adviser (FINTRAC rules) before relying on them.</p>
  <fieldset <?= $manage ? '' : 'disabled' ?>><legend>Reminders before an ID expires</legend><div class="fields">
    <div class="f wide"><label for="offsets">Days before expiry (0 = on the expiry day)</label><input id="offsets" name="offsets" value="<?= e(implode(', ', $rules)) ?>"></div>
    <div class="f"><label for="reminder_max_attempts">Send attempts before giving up</label><input id="reminder_max_attempts" name="reminder_max_attempts" type="number" min="1" max="10" value="<?= e(setting('reminder_max_attempts')) ?>"></div>
    <div class="f"><label for="rekyc_open_days">Open a refresh case this many days before expiry</label><input id="rekyc_open_days" name="rekyc_open_days" type="number" min="0" value="<?= e(setting('rekyc_open_days')) ?>"></div>
    <div class="f"><label for="escalate_after_days">Escalate a refresh case this many days after its due date</label><input id="escalate_after_days" name="escalate_after_days" type="number" min="0" value="<?= e(setting('escalate_after_days')) ?>"></div>
    <div class="f"><label for="upload_link_days">Upload links work for (days)</label><input id="upload_link_days" name="upload_link_days" type="number" min="1" max="30" value="<?= e(setting('upload_link_days')) ?>"></div></div></fieldset>
  <fieldset <?= $manage ? '' : 'disabled' ?>><legend>Periodic KYC refresh (separate from ID expiry)</legend><div class="fields">
    <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $k => $l): ?><div class="f"><label for="rm<?= $k ?>"><?= $l ?> risk: refresh every (months)</label><input id="rm<?= $k ?>" name="refresh_months_<?= $k ?>" type="number" min="1" max="120" value="<?= e(setting('refresh_months_' . $k)) ?>"></div><?php endforeach; ?></div></fieldset>
  <fieldset <?= $manage ? '' : 'disabled' ?>><legend>Beneficiaries</legend><div class="fields">
    <div class="f wide"><label for="benef_high_risk_countries">High-risk countries (flagged for the reviewer, comma separated)</label><input id="benef_high_risk_countries" name="benef_high_risk_countries" value="<?= e(setting('benef_high_risk_countries')) ?>"></div>
    <div class="f"><label for="benef_max_per_client">Most active beneficiaries per client</label><input id="benef_max_per_client" name="benef_max_per_client" type="number" min="1" max="50" value="<?= e(setting('benef_max_per_client')) ?>"></div></div></fieldset>
  <fieldset <?= $manage ? '' : 'disabled' ?>><legend>When transfers are blocked</legend>
    <?php foreach (['block_unverified' => 'KYC not approved', 'block_expired_doc' => 'ID expired (after the grace days below)', 'block_rekyc_overdue' => 'KYC refresh overdue'] as $k => $l): ?><label class="check" style="margin:6px 0"><input type="checkbox" name="<?= $k ?>" value="1" <?= setting($k) === '1' ? 'checked' : '' ?>><span><?= e($l) ?></span></label><?php endforeach; ?>
    <div class="f" style="max-width:260px"><label for="expired_grace_days">Grace days after expiry</label><input id="expired_grace_days" name="expired_grace_days" type="number" min="0" max="90" value="<?= e(setting('expired_grace_days')) ?>"></div></fieldset>
  <?php if ($manage): ?><div class="f" style="max-width:260px;margin-bottom:12px"><label for="pcode">Your two-step code</label><input id="pcode" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="11"></div><button class="btn gold">Save settings</button><?php endif; ?>
</form>
<?php else: ?>
<div class="tablewrap"><table>
<?php if ($v === 'pending'): ?>
  <thead><tr><th>Client</th><th>Status</th><th>Risk</th><th>Address check</th><th>ID expiry</th><th>Waiting since</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= $open($r['id'], $r['name']) ?><div class="tag num"><?= e($r['client_no']) ?></div></td><td><?= kyc_pill($r['kyc']) ?></td><td><?= e($r['risk']) ?></td><td><?= e(addr_result_label($r['address_match'])) ?></td><td class="num"><?= $r['expires_on'] ? fdate($r['expires_on']) : '<span class="tag">no ID</span>' ?></td><td class="num"><?= fdate($r['created_at']) ?></td></tr><?php endforeach; ?>
<?php elseif ($v === 'expiring' || $v === 'expired'): ?>
  <thead><tr><th>Client</th><th>KYC</th><th>ID expiry</th><th class="r"><?= $v === 'expired' ? 'Days since' : 'Days left' ?></th><th>Email on file</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= $open($r['id'], $r['name']) ?><div class="tag num"><?= e($r['client_no']) ?></div></td><td><?= kyc_pill($r['kyc']) ?></td><td class="num"><?= fdate($r['expires_on']) ?></td><td class="r num"><?= (int)$r['days'] ?></td><td><?= $r['email'] ? 'Yes' : '<span class="tag">No: reminders go to staff only</span>' ?></td></tr><?php endforeach; ?>
<?php elseif (in_array($v, ['required', 'progress', 'completed'], true)): ?>
  <thead><tr><th>Client</th><th>Reason</th><th>Status</th><th>Due</th><th><?= $v === 'completed' ? 'Completed' : 'Opened' ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= $open($r['client_id'], $r['name']) ?><div class="tag num"><?= e($r['client_no']) ?> · case #<?= (int)$r['id'] ?></div></td><td><?= e($reasons[$r['reason']] ?? $r['reason']) ?></td><td><?= $r['status'] === 'escalated' ? '<span class="pill p-bad">Escalated</span>' : '<span class="pill p-mute">' . e(str_replace('_', ' ', $r['status'])) . '</span>' ?></td><td class="num"><?= fdate($r['due_date']) ?></td><td class="num"><?= $v === 'completed' ? fdatetime($r['closed_at']) . ($r['reviewer'] ? '<div class="tag">' . e($r['reviewer']) . '</div>' : '') : fdate($r['opened_at']) ?></td></tr><?php endforeach; ?>
<?php elseif ($v === 'benef'): ?>
  <thead><tr><th>Beneficiary</th><th>Client</th><th>Country</th><th>Bank</th><th>Added</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><a href="/?p=beneficiary&client=<?= (int)$r['client_id'] ?>&id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><div class="tag"><?= e($r['source']) ?></div></td><td><?= e($r['cname']) ?><div class="tag num"><?= e($r['client_no']) ?> · KYC <?= e($r['kyc']) ?></div></td><td><?= e($r['country']) ?></td><td><?= e($r['bank']) ?></td><td class="num"><?= fdate($r['created_at']) ?></td></tr><?php endforeach; ?>
<?php elseif ($v === 'reminders'): ?>
  <thead><tr><th>Client</th><th>Event</th><th>Channel</th><th>Status</th><th class="r">Tries</th><th>Last result</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= $open($r['client_id'], $r['name']) ?><div class="tag num"><?= e($r['client_no']) ?></div></td><td><?= e($r['event_key'] === 'expiry' ? 'Expiry day' : str_replace('exp-', '', $r['event_key']) . ' days before') ?><div class="tag"><?= fdate($r['due_on']) ?></div></td><td><?= e($r['channel'] === 'staff' ? 'Staff alert' : 'Client email') ?></td>
    <td><span class="pill <?= ['sent' => 'p-ok', 'failed' => 'p-bad', 'pending' => 'p-warn', 'acknowledged' => 'p-info'][$r['status']] ?? 'p-mute' ?>"><?= e($r['status']) ?></span></td><td class="r num"><?= (int)$r['tries'] ?></td><td class="tag"><?= e($r['last_error'] ?: ($r['sent_at'] ? 'Sent ' . fdatetime($r['sent_at']) : '')) ?></td>
    <td><?php if ($manage && in_array($r['status'], ['sent', 'failed'], true)): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm" name="act" value="ack">Mark handled</button><?php if ($r['status'] === 'failed'): ?> <button class="btn sm" name="act" value="retry">Retry</button><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php endif; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="empty">Nothing here.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
<?php page_foot();
