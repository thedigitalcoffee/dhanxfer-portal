<?php
defined('DX') or exit;

/* ================= Rates ================= */

function rate_pairs(bool $activeOnly = true): array {
    return all('SELECT * FROM rate_pairs' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY sort, id');
}
function pair_key(string $s, string $r): string { return $s . '>' . $r; }
function pair_label(string $s, string $r): string { return $s . ' → ' . $r; }

/** Latest active rate for each pair, keyed "CAD>INR". */
function current_rates(): array {
    $rows = all("SELECT d.* FROM daily_rates d JOIN (SELECT send_currency, receive_currency, MAX(id) mid FROM daily_rates WHERE status='active' GROUP BY send_currency, receive_currency) m ON m.mid=d.id");
    $out = [];
    foreach ($rows as $r) $out[pair_key($r['send_currency'], $r['receive_currency'])] = $r;
    return $out;
}
function fmt_rate($r): string {
    if ($r === null || $r === '') return '';
    $s = number_format((float)$r, 4, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

function http_get_json(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'DhanXfer-Portal/1.0']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) return null;
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
        if ($body === false) return null;
    }
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

/**
 * Mid-market rate (the same kind of rate Google shows), from open.er-api.com.
 * Returns receive-currency units per 1 send-currency unit, or null if unavailable.
 */
function market_rate(string $send, string $recv): ?float {
    static $cache = [];
    if (!isset($cache[$send])) {
        $j = http_get_json('https://open.er-api.com/v6/latest/' . rawurlencode($send));
        $cache[$send] = ($j && ($j['result'] ?? '') === 'success') ? ($j['rates'] ?? []) : [];
    }
    $r = $cache[$send][$recv] ?? null;
    if ($r) record_market(today(), $send, $recv, (float)$r, 'open.er-api.com');
    return $r ? (float)$r : null;
}

/** Keeps one market rate per pair per day (latest fetch wins). */
function record_market(string $date, string $send, string $recv, float $rate, string $source): void {
    q('INSERT INTO market_rates (rate_date, send_currency, receive_currency, rate, source) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate), source=VALUES(source), fetched_at=NOW()',
        [$date, $send, $recv, round($rate, 6), $source]);
}
/** Records today's market rate for every active pair. Returns how many were saved. */
function record_market_today(): int {
    $n = 0;
    foreach (rate_pairs() as $p) if (market_rate($p['send_currency'], $p['receive_currency']) !== null) $n++;
    return $n;
}
/**
 * Fills in past market rates (European Central Bank data via frankfurter.app) for days with no record.
 * AED is pegged to USD at 3.6725, so AED pairs are worked out from the USD rate.
 */
function backfill_market(int $days = 90): int {
    $from = date('Y-m-d', strtotime("-$days days")); $to = date('Y-m-d', strtotime('-1 day'));
    $saved = 0;
    foreach (rate_pairs() as $p) {
        $s = $p['send_currency']; $r = $p['receive_currency']; $div = 1.0;
        if ($s === 'AED') { $s = 'USD'; $div = 3.6725; }
        if ($r === 'AED') continue;
        $j = http_get_json("https://api.frankfurter.app/$from..$to?from=" . rawurlencode($s) . '&to=' . rawurlencode($r));
        foreach (($j['rates'] ?? []) as $day => $vals) {
            if (!isset($vals[$r])) continue;
            if (val('SELECT 1 FROM market_rates WHERE rate_date=? AND send_currency=? AND receive_currency=?', [$day, $p['send_currency'], $r])) continue;
            record_market($day, $p['send_currency'], $r, (float)$vals[$r] / $div, 'ECB via frankfurter.app');
            $saved++;
        }
    }
    return $saved;
}
/** Day-by-day tracking rows for one pair: market rate and the buy/sell rate that was live that day. */
function rate_tracking(string $send, string $recv, int $days = 60): array {
    $from = date('Y-m-d', strtotime("-$days days"));
    $market = [];
    foreach (all('SELECT rate_date, rate, source FROM market_rates WHERE send_currency=? AND receive_currency=? AND rate_date>=? ORDER BY rate_date', [$send, $recv, $from]) as $m) $market[$m['rate_date']] = $m;
    $ours = [];
    foreach (all("SELECT d.rate_date, d.buy_rate, d.sell_rate FROM daily_rates d JOIN (SELECT rate_date, MAX(id) mid FROM daily_rates WHERE status IN ('active','superseded') AND send_currency=? AND receive_currency=? GROUP BY rate_date) x ON x.mid=d.id ORDER BY d.rate_date", [$send, $recv]) as $o) $ours[$o['rate_date']] = $o;
    $rows = []; $live = null;
    foreach ($ours as $d => $o) if ($d < $from) $live = $o;
    for ($t = strtotime($from); $t <= time(); $t += 86400) {
        $d = date('Y-m-d', $t);
        if (isset($ours[$d])) $live = $ours[$d];
        if (!isset($market[$d]) && !isset($ours[$d])) continue;
        $m = isset($market[$d]) ? (float)$market[$d]['rate'] : null;
        $b = $live ? (float)$live['buy_rate'] : null; $s = $live ? (float)$live['sell_rate'] : null;
        $rows[] = ['date' => $d, 'market' => $m, 'source' => $market[$d]['source'] ?? '', 'buy' => $b, 'sell' => $s, 'set_today' => isset($ours[$d]),
            'buy_pct' => ($m && $b) ? ($b / $m - 1) * 100 : null, 'sell_pct' => ($m && $s) ? ($s / $m - 1) * 100 : null];
    }
    return $rows;
}
/** Small inline SVG line chart: market, buy and sell. */
function rate_chart_svg(array $rows): string {
    $pts = array_values(array_filter($rows, fn($r) => $r['market'] || $r['buy'] || $r['sell']));
    if (count($pts) < 2) return '<div class="muted">The chart appears after two days of rates.</div>';
    $vals = [];
    foreach ($pts as $r) foreach (['market', 'buy', 'sell'] as $k) if ($r[$k]) $vals[] = $r[$k];
    $min = min($vals); $max = max($vals); $pad = ($max - $min) * 0.1 ?: 0.5; $min -= $pad; $max += $pad;
    $W = 760; $H = 240; $L = 56; $R = 12; $T = 12; $B = 28; $n = count($pts);
    $x = fn($i) => $L + ($W - $L - $R) * ($n === 1 ? 0 : $i / ($n - 1));
    $y = fn($v) => $T + ($H - $T - $B) * (1 - ($v - $min) / ($max - $min));
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="chart" role="img" aria-label="Market, buy and sell rates over time">';
    for ($g = 0; $g <= 4; $g++) { $v = $min + ($max - $min) * $g / 4; $yy = round($y($v), 1);
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/><text x="' . ($L - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" class="ax">' . e(fmt_rate(round($v, 2))) . '</text>'; }
    foreach ([0, intdiv($n - 1, 2), $n - 1] as $i) $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle" class="ax">' . e(date('M j', strtotime($pts[$i]['date']))) . '</text>';
    foreach (['market' => 'l-m', 'buy' => 'l-b', 'sell' => 'l-s'] as $k => $cls) {
        $d = ''; $prev = false;
        foreach ($pts as $i => $r) { if (!$r[$k]) { $prev = false; continue; } $d .= ($prev ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($r[$k]), 1); $prev = true; }
        if ($d) $svg .= '<path d="' . $d . '" class="' . $cls . '"/>';
        $last = null; foreach ($pts as $i => $r) if ($r[$k]) $last = $i;
        if ($last !== null) $svg .= '<circle cx="' . round($x($last), 1) . '" cy="' . round($y($pts[$last][$k]), 1) . '" r="3.5" class="' . $cls . ' dot"/>';
    }
    return $svg . '</svg><div class="legend"><span class="k-m">Market rate</span><span class="k-b">Our buy</span><span class="k-s">Our sell</span></div>';
}

function apply_pct(float $base, float $pct): float { return round($base * (1 + $pct / 100), 4); }

/** Builds today's suggested rates from the market rate. Returns [pairKey => row] (only pairs with a market rate). */
function build_suggestions(): array {
    $buyPct = (float)setting('buy_pct', '3');
    $sellPct = (float)setting('sell_pct', '6');
    $out = [];
    foreach (rate_pairs() as $p) {
        $m = market_rate($p['send_currency'], $p['receive_currency']);
        if ($m === null) continue;
        $out[pair_key($p['send_currency'], $p['receive_currency'])] = [
            'send' => $p['send_currency'], 'recv' => $p['receive_currency'],
            'market' => round($m, 6), 'buy' => apply_pct($m, $buyPct), 'sell' => apply_pct($m, $sellPct),
        ];
    }
    return $out;
}

/** Saves a proposal for today (replacing any earlier undecided proposal). */
function save_proposal(array $sugg): void {
    q("UPDATE daily_rates SET status='discarded' WHERE status='proposed'");
    foreach ($sugg as $r) {
        q("INSERT INTO daily_rates (rate_date, send_currency, receive_currency, market_rate, buy_rate, sell_rate, status, source) VALUES (?,?,?,?,?,?,'proposed','auto')",
            [today(), $r['send'], $r['recv'], $r['market'], $r['buy'], $r['sell']]);
    }
}
function current_proposal(): array {
    $out = [];
    foreach (all("SELECT * FROM daily_rates WHERE status='proposed' ORDER BY id") as $r)
        $out[pair_key($r['send_currency'], $r['receive_currency'])] = ['send' => $r['send_currency'], 'recv' => $r['receive_currency'], 'market' => $r['market_rate'] !== null ? (float)$r['market_rate'] : null, 'buy' => (float)$r['buy_rate'], 'sell' => (float)$r['sell_rate']];
    return $out;
}

/**
 * Makes a set of rates live for everyone. $rates: [pairKey => ['send','recv','market','buy','sell']].
 * $source: auto (suggested rates confirmed), telegram (typed in Telegram), portal (typed in the portal).
 */
function activate_rates(array $rates, string $source, ?int $userId): void {
    db()->beginTransaction();
    try {
        foreach ($rates as $r) {
            q("UPDATE daily_rates SET status='superseded' WHERE status='active' AND send_currency=? AND receive_currency=?", [$r['send'], $r['recv']]);
            q("INSERT INTO daily_rates (rate_date, send_currency, receive_currency, market_rate, buy_rate, sell_rate, status, source, set_by, confirmed_at) VALUES (?,?,?,?,?,?,'active',?,?,NOW())",
                [today(), $r['send'], $r['recv'], $r['market'] ?? null, $r['buy'], $r['sell'], $source, $userId]);
        }
        q("UPDATE daily_rates SET status='discarded' WHERE status='proposed'");
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    $summary = implode('; ', array_map(fn($r) => pair_label($r['send'], $r['recv']) . ' buy ' . fmt_rate($r['buy']) . ' sell ' . fmt_rate($r['sell']), $rates));
    audit_as($userId, 'rates_set', 'rates', null, "[$source] $summary");
}

/** Like audit(), but for a known user id (Telegram and cron have no web session). */
function audit_as(?int $userId, string $action, string $entity = '', $entityId = null, string $detail = ''): void {
    audit_write($userId, $action, $entity, $entityId, $detail, PHP_SAPI === 'cli' ? 'cron' : client_ip());
}

/* ================= Commission ================= */

function commission_rate_for(?int $agentId, ?string $send, ?string $recv): ?float {
    if (!$agentId || !$send || !$recv) return null;
    $v = val('SELECT per_unit FROM agent_commissions WHERE agent_id=? AND send_currency=? AND receive_currency=?', [$agentId, $send, $recv]);
    return $v === null ? null : (float)$v;
}
/** Works out and stores the commission on an accepted/completed transfer; clears it otherwise. */
function apply_commission(int $transferId): void {
    $t = one('SELECT * FROM transfers WHERE id=?', [$transferId]);
    if (!$t || $t['commission_locked_at']) return; // locked at order submission (with its CAD value): never changed
    if (!in_array($t['status'], ['accepted', 'completed'], true) || !$t['agent_id']) {
        q('UPDATE transfers SET commission=NULL, commission_per_unit=NULL, commission_currency=NULL WHERE id=?', [$transferId]);
        return;
    }
    if ($t['commission'] !== null) return; // keep the commission fixed once worked out
    $per = commission_rate_for((int)$t['agent_id'], $t['send_currency'], $t['receive_currency']);
    if ($per === null || $t['send_amount'] === null) return;
    q('UPDATE transfers SET commission_per_unit=?, commission=?, commission_currency=? WHERE id=?',
        [$per, round((float)$t['send_amount'] * $per, 2), $t['receive_currency'], $transferId]);
}

/* ================= Telegram ================= */

function tg_token(): ?string { $t = setting('tg_token'); return $t ?: null; }

function tg_api(string $method, array $params = []): ?array {
    $tok = tg_token();
    if (!$tok) return null;
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($params)]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}
function tg_send(string $chatId, string $text, ?array $buttons = null): void {
    $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($buttons) $p['reply_markup'] = ['inline_keyboard' => $buttons];
    tg_api('sendMessage', $p);
}
/** Admins who linked Telegram: [chat_id => user row]. */
function tg_admins(): array {
    $out = [];
    foreach (all("SELECT * FROM users WHERE role<>'agent' AND active=1 AND telegram_chat_id IS NOT NULL") as $u) if (role_can($u['role'], 'rates.set')) $out[$u['telegram_chat_id']] = $u;
    return $out;
}
function rates_table_text(array $rates, bool $withMarket = true): string {
    $lines = [];
    foreach ($rates as $r) {
        $lines[] = '<b>' . pair_label($r['send'], $r['recv']) . '</b>' . ($withMarket && !empty($r['market']) ? "\n  Market: " . fmt_rate($r['market']) : '')
            . "\n  Buy: <b>" . fmt_rate($r['buy']) . "</b>\n  Sell: <b>" . fmt_rate($r['sell']) . '</b>';
    }
    return implode("\n\n", $lines);
}
function proposal_keyboard(): array {
    return [[['text' => '✅ Yes, confirm', 'callback_data' => 'ok'], ['text' => '✏️ No, wait for my rate', 'callback_data' => 'own']]];
}
function send_daily_proposal(): int {
    $sugg = build_suggestions();
    if (!$sugg) {
        foreach (tg_admins() as $chat => $u) tg_send((string)$chat, "⚠️ DhanXfer: today's market rates could not be fetched. Send /update to type today's rates yourself.");
        return 0;
    }
    save_proposal($sugg);
    $text = "📊 <b>DhanXfer rates for " . date('D, M j') . "</b>\nMarket rate from open.er-api.com. Buy = market " . sprintf('%+g', (float)setting('buy_pct', '3')) . "%, Sell = market " . sprintf('%+g', (float)setting('sell_pct', '6')) . "%.\n\n" . rates_table_text($sugg) . "\n\nConfirm these rates for today?";
    $n = 0;
    foreach (tg_admins() as $chat => $u) { tg_send((string)$chat, $text, proposal_keyboard()); $n++; }
    audit_as(null, 'rates_proposed', 'rates', null, count($sugg) . ' pairs sent to ' . $n . ' admin(s)');
    return $n;
}
