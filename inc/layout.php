<?php
defined('DX') or exit;

function page_head(string $title, bool $public = false): void {
    $u = me();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#070C15">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icon-192.png">
<link rel="apple-touch-icon" href="/assets/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="DhanXfer">
<title><?= e($title) ?> · DhanXfer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css?v=4">
</head>
<body class="<?= $public ? 'public' : 'app' ?><?= $u && $u['role'] === 'agent' && !$public ? ' agent' : '' ?>">
<?php global $CONFIG; if (($CONFIG['environment'] ?? 'live') === 'staging'): ?><div style="background:#7A2E00;color:#FFE3B8;text-align:center;padding:6px;font-weight:700;font-size:13px">STAGING · test system · not real data</div><?php endif; ?>
<header class="top">
  <a class="brand" href="/"><span class="wordmark">Dhan<b>X</b>fer</span><small><?= $public ? 'Client registration' : ($u && $u['role'] === 'agent' ? 'Sub-agent portal' : 'Team portal') ?></small></a>
  <?php if ($u && !$public): ?>
  <div class="who"><span><?= e($u['name']) ?></span><span class="pill <?= $u['role'] === 'agent' ? 'p-info' : 'p-gold' ?>"><?= $u['role'] === 'agent' ? 'Sub-agent ' . e($u['ref_code']) : e(role_label($u['role'])) ?></span>
    <a href="/?p=account">My account</a>
    <form method="post" action="/logout.php" class="inline"><?= csrf_field() ?><button class="linkbtn">Log out</button></form></div>
  <?php endif; ?>
</header>
<?php if ($u && !$public):
    $cur = get('p', 'dashboard');
    if ($u['role'] === 'agent') {
        $items = ['dashboard' => 'Home', 'rates' => "Today's rates", 'clients' => 'My clients', 'transfers' => 'My transfers', 'commission' => 'My commission', 'audit' => 'My activity', 'client_form' => '+ New client'];
    } else {
        $all = ($u['role'] === 'super_admin' ? ['insights' => ['Overview', '__super']] : []) + ['dashboard' => [$u['role'] === 'super_admin' ? "Today's operations" : 'Today', null], 'transfers' => ['Transfers', null], 'rates' => ['Rates', null], 'clients' => ['Clients', 'clients.all'],
            'submissions' => ['Online forms', 'submissions.review'], 'commission' => ['Commission', 'commission.view'], 'log' => ['Daily log', 'log.use'],
            'agents' => ['Team & sub-agents', ['team.agents', 'team.staff']], 'roles' => ['Roles', '__super'], 'audit' => ['Activity history', null], 'import' => ['Import', 'import']];
        $items = [];
        foreach ($all as $k => [$label, $perm]) {
            $ok = $perm === null || ($perm === '__super' ? $u['role'] === 'super_admin' : (is_array($perm) ? (can($perm[0]) || can($perm[1])) : can($perm)));
            if ($ok) $items[$k] = $label;
        }
    }
    $badges = [];
    if (can('transfers.decide')) $badges['transfers'] = (int)val("SELECT COUNT(*) FROM transfers WHERE status='pending'");
    if (can('submissions.review')) $badges['submissions'] = (int)val("SELECT COUNT(*) FROM submissions WHERE status='new'");
?>
<nav class="tabs">
  <?php foreach ($items as $k => $label): ?>
    <a href="/?p=<?= e($k) . ($k === 'dashboard' && $u['role'] === 'super_admin' ? '&ops=1' : '') ?>" class="<?= ($cur === $k || ($k === 'insights' && $cur === 'dashboard' && $u['role'] === 'super_admin' && get('ops') !== '1' && get('d') === '')) ? 'active' : '' ?>"><?= e($label) ?><?php if (!empty($badges[$k])): ?> <span class="badge"><?= (int)$badges[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($u['role'] === 'agent'): ?>
<nav class="bottombar" aria-label="Quick menu">
  <a href="/?p=dashboard" class="<?= $cur === 'dashboard' ? 'active' : '' ?>"><span aria-hidden="true">⌂</span>Home</a>
  <a href="/?p=rates" class="<?= $cur === 'rates' ? 'active' : '' ?>"><span aria-hidden="true">₹</span>Rates</a>
  <a href="/?p=client_form" class="plus"><span aria-hidden="true">+</span>Client</a>
  <a href="/?p=clients" class="<?= $cur === 'clients' ? 'active' : '' ?>"><span aria-hidden="true">☰</span>Clients</a>
  <a href="/?p=transfers" class="<?= $cur === 'transfers' ? 'active' : '' ?>"><span aria-hidden="true">⇄</span>Transfers</a>
</nav>
<?php endif; ?>
<?php endif; ?>
<main>
<?php foreach (flash() as [$t, $m]): ?>
  <div class="banner <?= e($t) ?>"><?= e($m) ?></div>
<?php endforeach;
}

function page_foot(): void { ?>
</main>
<footer>
  <span>DhanXfer · Transferring Value, Elevating Trust</span>
  <span>WhatsApp: Dhanxfer · +1 (942)-6600-0600</span>
  <span>Info@dhanxfer.com</span>
  <a href="https://www.dhanxfer.com">www.dhanxfer.com</a>
</footer>
<script src="/assets/app.js?v=4"></script>
</body>
</html>
<?php }

function kyc_pill(?string $k): string {
    $m = ['Verified' => 'p-ok', 'Pending' => 'p-warn', 'Rejected' => 'p-bad', 'Expired' => 'p-bad'];
    return '<span class="pill ' . ($m[$k] ?? 'p-mute') . '">' . e($k ?: 'Pending') . '</span>';
}
function tx_pill(string $s): string {
    $m = ['pending' => ['p-warn', 'Waiting for admin'], 'accepted' => ['p-info', 'Accepted'], 'completed' => ['p-ok', 'Completed'], 'rejected' => ['p-bad', 'Rejected'], 'cancelled' => ['p-mute', 'Cancelled']];
    [$c, $l] = $m[$s] ?? ['p-mute', $s];
    return '<span class="pill ' . $c . '">' . e($l) . '</span>';
}
function kv(string $label, $val): string {
    if ($val === null || $val === '') return '';
    return '<dt>' . e($label) . '</dt><dd>' . e($val) . '</dd>';
}

/** Share block for the client registration link: native share on phones, WhatsApp, copy. */
function share_block(string $link, string $refCode = '', string $title = 'Your client registration link'): void {
    $msg = "Register with DhanXfer to send money at great rates. It takes 2 minutes:\n" . $link . ($refCode ? "\nReferral ID: $refCode" : '');
    ?>
    <div class="card share">
      <div class="tag" style="text-transform:uppercase;letter-spacing:.07em"><?= e($title) ?></div>
      <div style="word-break:break-all;margin:6px 0 10px"><?= e($link) ?></div>
      <div class="row">
        <button type="button" class="btn gold" data-share="<?= e($link) ?>" data-share-text="<?= e($msg) ?>">Share form</button>
        <a class="btn" href="https://wa.me/?text=<?= e(rawurlencode($msg)) ?>" target="_blank" rel="noopener">WhatsApp</a>
        <button type="button" class="btn" data-copy="<?= e($link) ?>">Copy link</button>
      </div>
    </div>
    <?php
}
