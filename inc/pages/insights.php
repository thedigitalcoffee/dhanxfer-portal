<?php
defined('DX') or exit;
// Super admin only: business analytics and team behaviour. Built only from recorded transactions and the activity log.
$days = in_array((int)get('days'), [7, 30, 90], true) ? (int)get('days') : 30;
$from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
$fromDt = $from . ' 00:00:00';

/* ---------- business ---------- */
$apps = (int)val('SELECT COUNT(*) FROM submissions WHERE created_at>=?', [$fromDt]);
$appsOk = (int)val("SELECT COUNT(*) FROM submissions WHERE status='approved' AND created_at>=?", [$fromDt]);
$newClients = (int)val('SELECT COUNT(*) FROM clients WHERE created_at>=?', [$fromDt]);
$kycOk = (int)val("SELECT COUNT(*) FROM clients WHERE kyc='Verified' AND created_at>=?", [$fromDt]);
$transacting = (int)val("SELECT COUNT(DISTINCT c.id) FROM clients c JOIN transfers t ON t.client_id=c.id AND t.status IN ('accepted','completed') WHERE c.created_at>=?", [$fromDt]);
$vol = all("SELECT transfer_date d, send_currency c, SUM(send_amount) s, COUNT(*) n, SUM(fee) f FROM transfers WHERE status IN ('accepted','completed') AND transfer_date>=? GROUP BY d, c ORDER BY d", [$from]);
$byCur = []; $tot = []; $fees = []; $count = 0;
foreach ($vol as $r) { $byCur[$r['c']][$r['d']] = (float)$r['s']; $tot[$r['c']] = ($tot[$r['c']] ?? 0) + (float)$r['s']; $fees[$r['c']] = ($fees[$r['c']] ?? 0) + (float)$r['f']; $count += (int)$r['n']; }
$statusMix = all("SELECT status, COUNT(*) n FROM transfers WHERE transfer_date>=? GROUP BY status", [$from]);
$agents = all("SELECT u.id, u.name, u.ref_code, u.last_login,
   (SELECT COUNT(*) FROM clients c WHERE c.agent_id=u.id AND c.created_at>=?) newc,
   (SELECT COUNT(*) FROM submissions s WHERE s.agent_id=u.id AND s.created_at>=?) apps,
   (SELECT COUNT(*) FROM transfers t WHERE t.agent_id=u.id AND t.status IN ('accepted','completed') AND t.transfer_date>=?) tx,
   (SELECT COUNT(*) FROM transfers t WHERE t.agent_id=u.id AND t.status='rejected' AND t.transfer_date>=?) rej,
   (SELECT COALESCE(SUM(t.send_amount),0) FROM transfers t WHERE t.agent_id=u.id AND t.status IN ('accepted','completed') AND t.transfer_date>=?) vol,
   (SELECT COALESCE(SUM(t.commission),0) FROM transfers t WHERE t.agent_id=u.id AND t.status IN ('accepted','completed') AND t.transfer_date>=?) com,
   (SELECT MAX(a.created_at) FROM audit a WHERE a.user_id=u.id) last_act
  FROM users u WHERE u.role='agent' AND u.active=1 ORDER BY vol DESC", [$fromDt, $fromDt, $from, $from, $from, $from]);

/* ---------- behaviour (from the activity log) ---------- */
$people = all("SELECT u.id, u.name, u.role, u.mfa_enabled, u.last_login,
   SUM(a.action='login') logins,
   SUM(a.action IN ('login_failed','mfa_failed')) fails,
   SUM(a.action='view_pii') pii,
   SUM(a.action LIKE 'export_%') exports,
   SUM(a.action='login' AND (HOUR(a.created_at) < 7 OR HOUR(a.created_at) >= 22)) offhours,
   COUNT(DISTINCT CASE WHEN a.action='login' THEN a.ip END) ips,
   COUNT(a.id) actions
  FROM users u LEFT JOIN audit a ON a.user_id=u.id AND a.created_at>=?
  WHERE u.active=1 GROUP BY u.id, u.name, u.role, u.mfa_enabled, u.last_login ORDER BY actions DESC", [$fromDt]);
$flags = [];
foreach ($people as $p) {
    $why = [];
    if ($p['role'] !== 'agent' && in_array($p['role'], MFA_REQUIRED, true) && !$p['mfa_enabled']) $why[] = ['bad', 'Two-step login not set up'];
    if ((int)$p['fails'] >= 5) $why[] = ['bad', (int)$p['fails'] . ' wrong passwords or codes'];
    if ((int)$p['pii'] >= 10) $why[] = ['warn', (int)$p['pii'] . ' looks at full ID or account numbers'];
    if ((int)$p['exports'] >= 5) $why[] = ['warn', (int)$p['exports'] . ' data exports'];
    if ((int)$p['offhours'] >= 3) $why[] = ['warn', (int)$p['offhours'] . ' logins between 10 pm and 7 am'];
    if ((int)$p['ips'] >= 4) $why[] = ['warn', 'Logged in from ' . (int)$p['ips'] . ' different networks'];
    if ($p['role'] === 'agent' && (!$p['last_login'] || strtotime($p['last_login']) < strtotime('-14 days'))) $why[] = ['info', 'Sub-agent inactive for 14+ days'];
    foreach ($why as $w) $flags[] = ['name' => $p['name'], 'role' => $p['role'], 'id' => $p['id'], 'level' => $w[0], 'text' => $w[1]];
}
foreach ($agents as $a) if ($a['tx'] + $a['rej'] >= 5 && $a['rej'] / max(1, $a['tx'] + $a['rej']) >= 0.3) $flags[] = ['name' => $a['name'], 'role' => 'agent', 'id' => $a['id'], 'level' => 'warn', 'text' => round(100 * $a['rej'] / ($a['tx'] + $a['rej'])) . '% of transfer requests rejected'];
usort($flags, fn($x, $y) => ['bad' => 0, 'warn' => 1, 'info' => 2][$x['level']] <=> ['bad' => 0, 'warn' => 1, 'info' => 2][$y['level']]);
// activity heatmap: weekday x hour
$heat = array_fill(0, 7, array_fill(0, 24, 0)); $hmax = 1;
foreach (all("SELECT WEEKDAY(created_at) wd, HOUR(created_at) h, COUNT(*) n FROM audit WHERE user_id IS NOT NULL AND created_at>=? GROUP BY wd, h", [$fromDt]) as $r) { $heat[(int)$r['wd']][(int)$r['h']] = (int)$r['n']; $hmax = max($hmax, (int)$r['n']); }
$sec = ['fails' => (int)val("SELECT COUNT(*) FROM audit WHERE action IN ('login_failed','mfa_failed') AND created_at>=?", [$fromDt]),
        'pii' => (int)val("SELECT COUNT(*) FROM audit WHERE action='view_pii' AND created_at>=?", [$fromDt]),
        'exports' => (int)val("SELECT COUNT(*) FROM audit WHERE action LIKE 'export_%' AND created_at>=?", [$fromDt]),
        'nomfa' => (int)val("SELECT COUNT(*) FROM users WHERE active=1 AND mfa_enabled=0 AND role IN ('" . implode("','", MFA_REQUIRED) . "')")];

/* ---------- small svg helpers ---------- */
function ins_line(array $series, string $from, int $days): string {
    if (!$series) return '<div class="muted">No accepted transfers in this period.</div>';
    $W = 760; $H = 220; $L = 64; $R = 12; $T = 12; $B = 26; $max = 0;
    foreach ($series as $pts) foreach ($pts as $v) $max = max($max, $v);
    $max = $max ?: 1;
    $x = fn($i) => $L + ($W - $L - $R) * ($days === 1 ? 0 : $i / ($days - 1));
    $y = fn($v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="chart" role="img" aria-label="Daily volume by currency">';
    for ($g = 0; $g <= 4; $g++) { $v = $max * $g / 4; $yy = round($y($v), 1); $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/><text x="' . ($L - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" class="ax">' . e(number_format($v)) . '</text>'; }
    foreach ([0, intdiv($days - 1, 2), $days - 1] as $i) $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 6) . '" text-anchor="middle" class="ax">' . e(date('M j', strtotime("$from +$i days"))) . '</text>';
    $cls = ['CAD' => 'l-s', 'AED' => 'l-b', 'USD' => 'l-m']; $legend = '';
    foreach ($series as $cur => $pts) {
        $d = '';
        for ($i = 0; $i < $days; $i++) { $day = date('Y-m-d', strtotime("$from +$i days")); $d .= ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($pts[$day] ?? 0), 1); }
        $c = $cls[$cur] ?? 'l-m';
        $svg .= '<path d="' . $d . '" class="' . $c . '"/>';
        $legend .= '<span class="k-' . substr($c, 2) . '">' . e($cur) . '</span>';
    }
    return $svg . '</svg><div class="legend">' . $legend . '</div>';
}

page_head('Overview'); ?>
<div class="spread"><div><h1>Owner overview</h1><div class="muted">Business results and how the team uses the portal. Last <?= $days ?> days, from recorded transfers and the activity log.</div></div>
  <div class="row"><?php foreach ([7, 30, 90] as $d): ?><a class="btn sm <?= $days === $d ? 'gold' : '' ?>" href="/?p=insights&days=<?= $d ?>"><?= $d ?> days</a><?php endforeach; ?><a class="btn sm" href="/?p=dashboard&ops=1">Today's operations</a></div></div>

<div class="tiles">
  <div class="tile"><div class="k">Accepted transfers</div><div class="v num"><?= $count ?></div><div class="s"><?= $tot ? e(implode(' · ', array_map(fn($c, $v) => money($v, $c), array_keys($tot), $tot))) : 'No volume yet' ?></div></div>
  <div class="tile"><div class="k">Fees earned</div><div class="v num sm"><?= $fees ? implode('<br>', array_map(fn($c, $v) => e(money($v, $c)), array_keys($fees), $fees)) : '0.00' ?></div></div>
  <div class="tile"><div class="k">Applications → approved</div><div class="v num"><?= $appsOk ?>/<?= $apps ?></div><div class="s"><?= $apps ? round(100 * $appsOk / $apps) . '% approved' : 'No online forms' ?></div></div>
  <div class="tile"><div class="k">New clients → transacting</div><div class="v num"><?= $transacting ?>/<?= $newClients ?></div><div class="s"><?= $kycOk ?> KYC verified</div></div>
  <div class="tile <?= $flags ? 'alert' : '' ?>"><div class="k">Behaviour flags</div><div class="v num"><?= count($flags) ?></div><div class="s">see below</div></div>
  <div class="tile <?= $sec['nomfa'] ? 'alert' : '' ?>"><div class="k">Privileged without two-step</div><div class="v num"><?= $sec['nomfa'] ?></div><div class="s"><?= $sec['fails'] ?> failed logins · <?= $sec['pii'] ?> PII looks</div></div>
</div>

<div class="card"><h2 style="margin-bottom:8px">Daily accepted volume</h2><?= ins_line($byCur, $from, $days) ?></div>

<div class="grid2">
  <div>
    <h2 style="margin-bottom:10px">Sub-agent performance</h2>
    <div class="tablewrap"><table><thead><tr><th>Sub-agent</th><th class="r">Forms</th><th class="r">New clients</th><th class="r">Transfers</th><th class="r">Rejected</th><th class="r">Volume</th><th class="r">Commission</th><th>Last active</th></tr></thead><tbody>
    <?php foreach ($agents as $a): ?><tr><td><a href="/?p=agents&id=<?= (int)$a['id'] ?>" style="color:var(--ink)"><?= e($a['name']) ?></a> <span class="tag"><?= e($a['ref_code']) ?></span></td><td class="r num"><?= (int)$a['apps'] ?></td><td class="r num"><?= (int)$a['newc'] ?></td><td class="r num"><?= (int)$a['tx'] ?></td><td class="r num"><?= (int)$a['rej'] ?></td><td class="r num"><?= e(number_format((float)$a['vol'], 2)) ?></td><td class="r num"><?= e(number_format((float)$a['com'], 2)) ?></td><td class="tag"><?= $a['last_act'] ? fdate($a['last_act']) : 'never' ?></td></tr><?php endforeach; ?>
    <?php if (!$agents): ?><tr><td colspan="8" class="empty">No active sub-agents.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="tag">Volume mixes currencies; open a sub-agent for the per-currency split.</p>
    <h2 style="margin:6px 0 10px">Transfer outcomes</h2>
    <div class="row"><?php foreach ($statusMix as $s): ?><?= tx_pill($s['status']) ?> <span class="num"><?= (int)$s['n'] ?></span>&nbsp;&nbsp;<?php endforeach; ?><?php if (!$statusMix): ?><span class="muted">No transfers in this period.</span><?php endif; ?></div>
  </div>
  <div>
    <h2 style="margin-bottom:10px">Behaviour flags</h2>
    <div class="list"><?php foreach ($flags as $f): ?><div class="li <?= $f['level'] === 'bad' ? 'overdue' : ($f['level'] === 'warn' ? 'due' : '') ?>"><div><strong><?= e($f['name']) ?></strong> <span class="tag"><?= e(role_label($f['role'])) ?></span><div class="tag"><?= e($f['text']) ?></div></div><a class="btn sm" href="/?p=audit&user=<?= (int)$f['id'] ?>">Log</a></div><?php endforeach; ?>
    <?php if (!$flags): ?><div class="muted">Nothing unusual in this period.</div><?php endif; ?></div>
    <p class="tag">Rules: no two-step login on a privileged role; 5+ wrong passwords or codes; 10+ looks at full numbers; 5+ exports; 3+ late-night logins; 4+ networks; sub-agent idle 14 days; 30%+ requests rejected.</p>
  </div>
</div>

<h2 style="margin:6px 0 10px">Team activity by hour</h2>
<div class="card" style="overflow-x:auto">
  <svg viewBox="0 0 760 190" class="chart heat" role="img" aria-label="Actions by weekday and hour">
  <?php $dn = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  for ($d = 0; $d < 7; $d++) { echo '<text x="36" y="' . (24 + $d * 22) . '" text-anchor="end" class="ax">' . $dn[$d] . '</text>';
    for ($h = 0; $h < 24; $h++) { $v = $heat[$d][$h]; $o = $v ? 0.15 + 0.85 * $v / $hmax : 0.04;
      echo '<rect x="' . (44 + $h * 29) . '" y="' . (10 + $d * 22) . '" width="26" height="19" rx="3" fill="#D4AF37" fill-opacity="' . round($o, 2) . '"><title>' . $dn[$d] . ' ' . $h . ':00 · ' . $v . ' actions</title></rect>'; } }
  foreach ([0, 6, 12, 18, 23] as $h) echo '<text x="' . (57 + $h * 29) . '" y="186" text-anchor="middle" class="ax">' . $h . ':00</text>'; ?>
  </svg>
</div>

<h2 style="margin:6px 0 10px">People</h2>
<div class="tablewrap"><table><thead><tr><th>Person</th><th>Role</th><th>Two-step</th><th class="r">Logins</th><th class="r">Failed</th><th class="r">Late-night</th><th class="r">Networks</th><th class="r">Full-number looks</th><th class="r">Exports</th><th class="r">All actions</th></tr></thead><tbody>
<?php foreach ($people as $p): ?><tr><td><a href="/?p=audit&user=<?= (int)$p['id'] ?>" style="color:var(--ink)"><?= e($p['name']) ?></a></td><td><?= e(role_label($p['role'])) ?></td><td><?= $p['mfa_enabled'] ? '<span class="pill p-ok">On</span>' : (in_array($p['role'], MFA_REQUIRED, true) ? '<span class="pill p-bad">Missing</span>' : '<span class="pill p-mute">Off</span>') ?></td>
  <td class="r num"><?= (int)$p['logins'] ?></td><td class="r num"><?= (int)$p['fails'] ?></td><td class="r num"><?= (int)$p['offhours'] ?></td><td class="r num"><?= (int)$p['ips'] ?></td><td class="r num"><?= (int)$p['pii'] ?></td><td class="r num"><?= (int)$p['exports'] ?></td><td class="r num"><?= (int)$p['actions'] ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php page_foot();
