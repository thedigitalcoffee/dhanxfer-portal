<?php
defined('DX') or exit;
require_once __DIR__ . '/../rates.php';
$isAdmin = is_staff();
$canSet = can('rates.set'); $canSettings = can('rates.settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('act');
    $needs = $act === 'backfill' ? ($canSet || $canSettings) : ($act === 'set' ? $canSet : (in_array($act, ['tg_link', 'tg_unlink'], true) ? ($canSet || $canSettings) : ($act === 'tg_test' ? ($canSet || $canSettings) : $canSettings)));
    if (!$needs) { flash('Your role cannot change that.', 'bad'); redirect('/?p=rates'); }
    if ($act === 'set') {
        $rates = [];
        $sugg = null;
        foreach (rate_pairs() as $p) {
            $k = pair_key($p['send_currency'], $p['receive_currency']);
            $b = num_or_null(post('buy_' . $p['id'])); $s = num_or_null(post('sell_' . $p['id']));
            if ($b === null && $s === null) continue;
            if ($b === null || $s === null || $b <= 0 || $s <= 0) { flash('Enter both a buy and a sell rate for ' . pair_label($p['send_currency'], $p['receive_currency']) . '.', 'bad'); redirect('/?p=rates'); }
            $sugg ??= build_suggestions();
            $rates[$k] = ['send' => $p['send_currency'], 'recv' => $p['receive_currency'], 'market' => $sugg[$k]['market'] ?? null, 'buy' => round($b, 4), 'sell' => round($s, 4)];
        }
        if (!$rates) { flash('Type at least one pair of rates.', 'bad'); redirect('/?p=rates'); }
        activate_rates($rates, 'portal', (int)$u['id']);
        foreach (tg_admins() as $chat => $a) tg_send((string)$chat, "✏️ {$u['name']} set new rates in the portal:\n\n" . rates_table_text($rates, false));
        flash('New rates are live for all sub-agents.');
    } elseif ($act === 'settings') {
        $bp = num_or_null(post('buy_pct')); $sp = num_or_null(post('sell_pct'));
        if ($bp === null || $sp === null || abs($bp) > 50 || abs($sp) > 50) { flash('Markups must be numbers between -50 and 50.', 'bad'); redirect('/?p=rates'); }
        $t = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', post('send_time')) ? post('send_time') : '09:00';
        set_setting('buy_pct', (string)$bp); set_setting('sell_pct', (string)$sp); set_setting('send_time', $t); set_setting('rates_on', post('rates_on') === '1' ? '1' : '0');
        audit('rates_settings', 'settings', null, "buy {$bp}%, sell {$sp}%, time $t, daily " . (post('rates_on') === '1' ? 'on' : 'off'));
        flash('Rate settings saved.');
    } elseif ($act === 'pair_add') {
        $s = strtoupper(post('send')); $r = strtoupper(post('recv'));
        if (in_array($s, SEND_CUR, true) && in_array($r, RECV_CUR, true) && $s !== $r) {
            q('INSERT INTO rate_pairs (send_currency, receive_currency, active, sort) VALUES (?,?,1,99) ON DUPLICATE KEY UPDATE active=1', [$s, $r]);
            audit('pair_add', 'rates', null, pair_label($s, $r)); flash(pair_label($s, $r) . ' added.');
        }
    } elseif ($act === 'pair_toggle') {
        q('UPDATE rate_pairs SET active=1-active WHERE id=?', [(int)post('id')]); audit('pair_toggle', 'rates', (int)post('id')); flash('Pair updated.');
    } elseif ($act === 'tg_token') {
        $tok = post('token');
        if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $tok)) { flash('That does not look like a bot token from BotFather. It looks like 123456789:ABC…', 'bad'); redirect('/?p=rates'); }
        set_setting('tg_token', $tok);
        $me = tg_api('getMe');
        if (!($me['ok'] ?? false)) { set_setting('tg_token', null); flash('Telegram did not accept that token. Copy it again from BotFather.', 'bad'); redirect('/?p=rates'); }
        set_setting('tg_bot_username', $me['result']['username'] ?? '');
        $secret = bin2hex(random_bytes(24));
        set_setting('tg_secret', $secret);
        $hook = tg_api('setWebhook', ['url' => rtrim($CONFIG['app_url'], '/') . '/telegram.php', 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]);
        audit('telegram_bot_set', 'settings', null, '@' . ($me['result']['username'] ?? ''));
        flash(($hook['ok'] ?? false) ? 'Bot @' . ($me['result']['username'] ?? '') . ' connected. Now link your Telegram below.' : 'The bot was saved, but Telegram could not reach the portal: ' . ($hook['description'] ?? 'unknown error'), ($hook['ok'] ?? false) ? 'ok' : 'warn');
    } elseif ($act === 'tg_link') {
        set_setting('tg_link', json_encode(['code' => bin2hex(random_bytes(8)), 'user_id' => (int)$u['id'], 'expires' => time() + 900]));
        flash('Open the link below within 15 minutes and press Start in Telegram.');
    } elseif ($act === 'tg_unlink') {
        q('UPDATE users SET telegram_chat_id=NULL WHERE id=?', [(int)post('id')]); audit('telegram_unlinked', 'user', (int)post('id')); flash('Telegram unlinked.');
    } elseif ($act === 'backfill') {
        $n = backfill_market(90);
        audit('market_backfill', 'rates', null, "$n days added");
        flash($n ? "$n past market rates added from European Central Bank data." : 'No past rates were added. They may already be there, or the data source was not reachable.', $n ? 'ok' : 'warn');
    } elseif ($act === 'tg_test') {
        $n = send_daily_proposal();
        flash($n ? "Today's suggested rates were sent to $n admin(s) on Telegram." : 'Nothing was sent. Link your Telegram first, or the market rates could not be fetched.', $n ? 'ok' : 'warn');
    }
    redirect('/?p=rates');
}

$cur = current_rates();
$pairsAll = rate_pairs(false);
$active = rate_pairs();
// tracking pair
$tp = get('pair');
$tpair = null;
foreach ($active as $pp) if (pair_key($pp['send_currency'], $pp['receive_currency']) === $tp) $tpair = $pp;
$tpair ??= $active[0] ?? null;
$days = in_array((int)get('days'), [30, 60, 90, 180, 365], true) ? (int)get('days') : 60;
$track = ($isAdmin && $tpair) ? rate_tracking($tpair['send_currency'], $tpair['receive_currency'], $days) : [];
if ($isAdmin && $tpair && get('export') === 'tracking' && can('export')) {
    audit('export_rates', 'rates', null, pair_label($tpair['send_currency'], $tpair['receive_currency']) . " $days days");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dhanxfer-rates-' . $tpair['send_currency'] . '-' . $tpair['receive_currency'] . '-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Market rate', 'Market source', 'Our buy', 'Our sell', 'Buy vs market %', 'Sell vs market %', 'New rate set that day']);
    foreach ($track as $r) fputcsv($out, [$r['date'], $r['market'], $r['source'], $r['buy'], $r['sell'], $r['buy_pct'] !== null ? round($r['buy_pct'], 2) : '', $r['sell_pct'] !== null ? round($r['sell_pct'], 2) : '', $r['set_today'] ? 'Yes' : 'No']);
    exit;
}
$history = $isAdmin ? [] : all("SELECT * FROM daily_rates WHERE status IN ('active','superseded') ORDER BY id DESC LIMIT 20");
page_head($isAdmin ? 'Rates' : "Today's rates"); ?>
<div class="spread"><div><h1><?= $isAdmin ? 'Daily rates' : "Today's rates" ?></h1>
  <div class="muted"><?= $isAdmin ? 'The rates sub-agents see and use, and how they compare with the market rate each day.' : 'Use the sell rate when you quote a client. Rates are set by DhanXfer admins.' ?></div></div></div>
<div class="tablewrap"><table><thead><tr><th>Currency</th><th class="r">Buy rate</th><th class="r">Sell rate</th><?php if ($isAdmin): ?><th class="r">Market rate</th><?php endif; ?><th>Set</th></tr></thead><tbody>
<?php foreach ($active as $p): $r = $cur[pair_key($p['send_currency'], $p['receive_currency'])] ?? null; ?>
  <tr><td><strong><?= e(pair_label($p['send_currency'], $p['receive_currency'])) ?></strong></td>
    <td class="r num" style="font-size:18px"><?= $r ? e(fmt_rate($r['buy_rate'])) : '—' ?></td><td class="r num" style="font-size:18px;color:var(--gold2)"><?= $r ? e(fmt_rate($r['sell_rate'])) : '—' ?></td>
    <?php if ($isAdmin): ?><td class="r num"><?= $r ? e(fmt_rate($r['market_rate'])) : '' ?></td><?php endif; ?>
    <td class="tag"><?= $r ? fdatetime($r['confirmed_at']) . ($r['rate_date'] !== today() ? ' <span class="pill p-warn">Not updated today</span>' : '') : 'Not set yet' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>

<?php if ($isAdmin && $tpair): ?>
<div class="card">
  <div class="spread" style="margin-bottom:8px"><h2>Rate tracking</h2>
    <form class="row" method="get"><input type="hidden" name="p" value="rates">
      <select name="pair" style="width:auto" onchange="this.form.submit()" aria-label="Currency pair"><?php foreach ($active as $pp): $k = pair_key($pp['send_currency'], $pp['receive_currency']); ?><option value="<?= e($k) ?>"<?= $tpair === $pp ? ' selected' : '' ?>><?= e(pair_label($pp['send_currency'], $pp['receive_currency'])) ?></option><?php endforeach; ?></select>
      <select name="days" style="width:auto" onchange="this.form.submit()" aria-label="Period"><?php foreach ([30, 60, 90, 180, 365] as $dd): ?><option value="<?= $dd ?>"<?= $days === $dd ? ' selected' : '' ?>>Last <?= $dd ?> days</option><?php endforeach; ?></select>
      <?php if (can('export')): ?><a class="btn sm" href="/?p=rates&pair=<?= e(rawurlencode(pair_key($tpair['send_currency'], $tpair['receive_currency']))) ?>&days=<?= $days ?>&export=tracking">Export CSV</a><?php endif; ?>
    </form></div>
  <p class="tag" style="margin-top:0">The market rate is the mid-market rate, the same kind of rate Google shows (it can differ from Google by a small amount). It is saved automatically every morning.</p>
  <?= rate_chart_svg($track) ?>
  <?php if (count($track) < 5 && ($canSet || $canSettings)): ?>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="act" value="backfill"><button class="btn sm">Add the last 90 days of market rates</button> <span class="tag">Uses European Central Bank data.</span></form>
  <?php endif; ?>
</div>
<div class="tablewrap"><table><thead><tr><th>Date</th><th class="r">Market rate</th><th class="r">Our buy</th><th class="r">Buy vs market</th><th class="r">Our sell</th><th class="r">Sell vs market</th><th>New rate</th></tr></thead><tbody>
<?php foreach (array_reverse($track) as $r): ?><tr><td class="num"><?= fdate($r['date']) ?></td><td class="r num"><?= $r['market'] ? e(fmt_rate($r['market'])) : '—' ?></td>
  <td class="r num"><?= $r['buy'] ? e(fmt_rate($r['buy'])) : '—' ?></td><td class="r num"><?= $r['buy_pct'] !== null ? e(sprintf('%+.2f%%', $r['buy_pct'])) : '' ?></td>
  <td class="r num" style="color:var(--gold2)"><?= $r['sell'] ? e(fmt_rate($r['sell'])) : '—' ?></td><td class="r num"><?= $r['sell_pct'] !== null ? e(sprintf('%+.2f%%', $r['sell_pct'])) : '' ?></td>
  <td><?= $r['set_today'] ? '<span class="pill p-ok">Set</span>' : '<span class="tag">Carried over</span>' ?></td></tr><?php endforeach; ?>
<?php if (!$track): ?><tr><td colspan="7" class="empty">No rates recorded yet. The first market rate is saved tomorrow morning, or add past rates above.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php if ($canSet || $canSettings):
  $buyPct = setting('buy_pct', '3'); $sellPct = setting('sell_pct', '6');
  $botUser = setting('tg_bot_username'); $link = json_decode((string)setting('tg_link'), true);
  $linked = all("SELECT id, name FROM users WHERE role<>'agent' AND telegram_chat_id IS NOT NULL"); ?>
<div class="grid2">
  <?php if ($canSet): ?>
  <form method="post" class="card" style="align-self:start"><?= csrf_field() ?><input type="hidden" name="act" value="set">
    <h2 style="margin-bottom:6px">Set rates now</h2>
    <p class="tag" style="margin-top:0">Leave a pair empty to keep its current rate. "Fill" uses the market rate with the markups (buy <?= e(sprintf('%+g', (float)$buyPct)) ?>%, sell <?= e(sprintf('%+g', (float)$sellPct)) ?>%).</p>
    <?php $sugg = get('fill') === '1' ? build_suggestions() : []; if (get('fill') === '1' && !$sugg): ?><div class="banner warn">Market rates could not be fetched right now.</div><?php endif; ?>
    <div class="fields">
    <?php foreach ($active as $p): $k = pair_key($p['send_currency'], $p['receive_currency']); $sg = $sugg[$k] ?? null; ?>
      <div class="f"><label for="buy_<?= (int)$p['id'] ?>"><?= e(pair_label($p['send_currency'], $p['receive_currency'])) ?> buy<?= $sg ? ' · market ' . e(fmt_rate($sg['market'])) : '' ?></label><input id="buy_<?= (int)$p['id'] ?>" name="buy_<?= (int)$p['id'] ?>" type="number" inputmode="decimal" step="0.0001" min="0" class="num" value="<?= $sg ? e(fmt_rate($sg['buy'])) : '' ?>"></div>
      <div class="f"><label for="sell_<?= (int)$p['id'] ?>"><?= e(pair_label($p['send_currency'], $p['receive_currency'])) ?> sell</label><input id="sell_<?= (int)$p['id'] ?>" name="sell_<?= (int)$p['id'] ?>" type="number" inputmode="decimal" step="0.0001" min="0" class="num" value="<?= $sg ? e(fmt_rate($sg['sell'])) : '' ?>"></div>
    <?php endforeach; ?>
    </div>
    <div class="row" style="margin-top:12px"><a class="btn" href="/?p=rates&fill=1">Fill with market + markup</a><button class="btn gold">Make these rates live</button></div>
  </form>
  <?php endif; ?>
  <div>
    <?php if ($canSettings): ?>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="settings">
      <h2 style="margin-bottom:10px">Daily suggestion</h2>
      <div class="fields">
        <div class="f"><label for="buy_pct">Buy rate = market + %</label><input id="buy_pct" name="buy_pct" type="number" step="0.01" value="<?= e($buyPct) ?>"></div>
        <div class="f"><label for="sell_pct">Sell rate = market + %</label><input id="sell_pct" name="sell_pct" type="number" step="0.01" value="<?= e($sellPct) ?>"></div>
        <div class="f"><label for="send_time">Send to Telegram at (Toronto time)</label><input id="send_time" name="send_time" type="time" value="<?= e(setting('send_time', '09:00')) ?>"></div>
        <label class="check f" style="align-self:end"><input type="checkbox" name="rates_on" value="1" <?= setting('rates_on', '1') === '1' ? 'checked' : '' ?>> Send every day</label>
      </div>
      <p class="tag">Use a minus sign for a rate below market, for example -2.5.</p>
      <button class="btn gold">Save</button>
    </form>
    <?php endif; ?>
    <div class="card">
      <h2 style="margin-bottom:10px">Telegram</h2>
      <?php if (!tg_token()): ?>
        <?php if ($canSettings): ?><p class="tag" style="margin-top:0">1. In Telegram, open <strong>@BotFather</strong>, send <strong>/newbot</strong>, and follow the steps. 2. Paste the token it gives you here.</p>
        <?php else: ?><p class="tag">The Telegram bot is not set up yet. Ask the super admin.</p><?php endif; ?>
      <?php else: ?>
        <p style="margin-top:0">Bot: <strong>@<?= e($botUser) ?></strong> <span class="pill p-ok">Connected</span></p>
      <?php endif; ?>
      <?php if ($canSettings): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="act" value="tg_token"><input name="token" placeholder="<?= tg_token() ? 'Paste a new token to replace the bot' : '123456789:ABC…' ?>" style="flex:1;min-width:200px" autocomplete="off"><button class="btn sm">Save bot</button></form><?php endif; ?>
      <?php if (tg_token()): ?>
        <h3 style="margin:16px 0 8px">People who get the daily rates</h3>
        <div class="list"><?php foreach ($linked as $l): ?><div class="li"><span><?= e($l['name']) ?></span><?php if ($canSettings || (int)$l['id'] === (int)$u['id']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="tg_unlink"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn sm danger">Unlink</button></form><?php endif; ?></div><?php endforeach; ?>
        <?php if (!$linked): ?><div class="muted">Nobody yet.</div><?php endif; ?></div>
        <?php if ($canSet && $link && $link['expires'] > time() && (int)$link['user_id'] === (int)$u['id']): $deep = 'https://t.me/' . $botUser . '?start=' . $link['code']; ?>
          <div class="banner ok" style="margin-top:12px">Open <a href="<?= e($deep) ?>" target="_blank" rel="noopener"><?= e($deep) ?></a> on the phone where you use Telegram and press <strong>Start</strong>.</div>
        <?php endif; ?>
        <div class="row" style="margin-top:12px">
          <?php if ($canSet): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="tg_link"><button class="btn sm"><?= $u['telegram_chat_id'] ? 'Link a different Telegram' : 'Link my Telegram' ?></button></form><?php endif; ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="tg_test"><button class="btn sm gold">Send today's suggestion now</button></form>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($canSettings): ?>
    <div class="card"><h2 style="margin-bottom:10px">Currency pairs</h2>
      <div class="list"><?php foreach ($pairsAll as $p): ?><div class="li"><span><?= e(pair_label($p['send_currency'], $p['receive_currency'])) ?> <?= $p['active'] ? '' : '<span class="pill p-mute">Off</span>' ?></span>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="pair_toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm"><?= $p['active'] ? 'Switch off' : 'Switch on' ?></button></form></div><?php endforeach; ?></div>
      <form method="post" class="row" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="act" value="pair_add">
        <select name="send" style="width:auto" aria-label="Send currency"><?= opts(SEND_CUR, 'CAD', '') ?></select><span>→</span><select name="recv" style="width:auto" aria-label="Receive currency"><?= opts(RECV_CUR, 'PKR', '') ?></select><button class="btn sm">Add pair</button></form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
<h2 style="margin:6px 0 10px">Recent rates</h2>
<div class="tablewrap"><table><thead><tr><th>Set on</th><th>Currency</th><th class="r">Buy</th><th class="r">Sell</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td class="num"><?= fdatetime($h['confirmed_at']) ?></td><td><?= e(pair_label($h['send_currency'], $h['receive_currency'])) ?></td><td class="r num"><?= e(fmt_rate($h['buy_rate'])) ?></td><td class="r num"><?= e(fmt_rate($h['sell_rate'])) ?></td></tr><?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="4" class="empty">No rates set yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
<?php page_foot();
