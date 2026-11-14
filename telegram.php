<?php
// Telegram webhook: admins confirm or type the day's rates from Telegram.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/rates.php';

$secret = setting('tg_secret');
if (!$secret || !hash_equals($secret, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) { http_response_code(403); exit; }
$upd = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($upd)) exit;
http_response_code(200);

function state_get(string $chat): ?array {
    $r = one('SELECT * FROM telegram_state WHERE chat_id=?', [$chat]);
    if (!$r || strtotime($r['updated_at']) < time() - 6 * 3600) return null;
    return ['state' => $r['state'], 'data' => json_decode((string)$r['data'], true) ?: []];
}
function state_set(string $chat, string $state, array $data): void {
    q('REPLACE INTO telegram_state (chat_id, state, data, updated_at) VALUES (?,?,?,NOW())', [$chat, $state, json_encode($data)]);
}
function state_clear(string $chat): void { q('DELETE FROM telegram_state WHERE chat_id=?', [$chat]); }

function start_manual(string $chat): void {
    $pairs = rate_pairs();
    if (!$pairs) { tg_send($chat, 'No currency pairs are switched on. Add them in the portal under Rates.'); return; }
    $prop = current_proposal();
    $keys = array_map(fn($p) => pair_key($p['send_currency'], $p['receive_currency']), $pairs);
    state_set($chat, 'enter', ['keys' => $keys, 'i' => 0, 'step' => 'buy', 'rates' => [], 'prop' => $prop]);
    tg_send($chat, "OK, send today's rates one at a time. Send /cancel to stop.");
    ask_next($chat);
}
function ask_next(string $chat): void {
    $s = state_get($chat); if (!$s) return;
    $d = $s['data'];
    $key = $d['keys'][$d['i']];
    [$send, $recv] = explode('>', $key);
    $p = $d['prop'][$key] ?? null;
    $hint = $p ? "\nMarket " . fmt_rate($p['market']) . ', suggested ' . fmt_rate($p[$d['step']]) : '';
    tg_send($chat, '(' . ($d['i'] + 1) . '/' . count($d['keys']) . ') <b>' . pair_label($send, $recv) . '</b>: type the <b>' . strtoupper($d['step']) . "</b> rate." . $hint);
}
function confirm_keyboard(): array {
    return [[['text' => '✅ Confirm these rates', 'callback_data' => 'save']], [['text' => '🔁 Start again', 'callback_data' => 'redo'], ['text' => '✖ Cancel', 'callback_data' => 'cancel']]];
}
function notify_others(string $exceptChat, string $text): void {
    foreach (tg_admins() as $chat => $u) if ((string)$chat !== $exceptChat) tg_send((string)$chat, $text);
}

/* ---------- button presses ---------- */
if (isset($upd['callback_query'])) {
    $cb = $upd['callback_query'];
    $chat = (string)($cb['message']['chat']['id'] ?? '');
    $admins = tg_admins();
    tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
    if (!isset($admins[$chat])) { tg_send($chat, 'This bot is private to DhanXfer admins.'); exit; }
    $u = $admins[$chat];
    $data = (string)($cb['data'] ?? '');
    $msgId = $cb['message']['message_id'] ?? null;
    if ($msgId) tg_api('editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => $msgId, 'reply_markup' => ['inline_keyboard' => []]]);

    if ($data === 'ok') {
        $prop = current_proposal();
        if (!$prop) { tg_send($chat, "These rates were already handled. Send /rates to see today's live rates."); exit; }
        activate_rates($prop, 'auto', (int)$u['id']);
        tg_send($chat, "✅ Rates confirmed and live in the portal for all sub-agents.\n\n" . rates_table_text($prop, false));
        notify_others($chat, "✅ {$u['name']} confirmed today's rates.\n\n" . rates_table_text($prop, false));
    } elseif ($data === 'own') {
        start_manual($chat);
    } elseif ($data === 'save') {
        $s = state_get($chat);
        if (!$s || $s['state'] !== 'review') { tg_send($chat, 'Nothing to save. Send /update to enter rates.'); exit; }
        activate_rates($s['data']['rates'], 'telegram', (int)$u['id']);
        state_clear($chat);
        tg_send($chat, "✅ Your rates are live in the portal for all sub-agents.\n\n" . rates_table_text($s['data']['rates'], false));
        notify_others($chat, "✏️ {$u['name']} set today's rates:\n\n" . rates_table_text($s['data']['rates'], false));
    } elseif ($data === 'redo') {
        start_manual($chat);
    } elseif ($data === 'cancel') {
        state_clear($chat);
        tg_send($chat, 'Cancelled. Nothing was changed.' . (current_proposal() ? ' Send /today to see the suggested rates again.' : ''));
    }
    exit;
}

/* ---------- text messages ---------- */
$msg = $upd['message'] ?? null;
if (!$msg || !isset($msg['text'])) exit;
$chat = (string)$msg['chat']['id'];
$text = trim((string)$msg['text']);

if (preg_match('#^/start(?:\s+(\S+))?#', $text, $m)) {
    $code = $m[1] ?? '';
    $link = json_decode((string)setting('tg_link'), true);
    if ($code && $link && hash_equals((string)$link['code'], $code) && $link['expires'] > time()) {
        $usr = one("SELECT * FROM users WHERE id=? AND role<>'agent' AND active=1", [(int)$link['user_id']]);
        if ($usr && role_can($usr['role'], 'rates.set')) {
            q('UPDATE users SET telegram_chat_id=NULL WHERE telegram_chat_id=?', [$chat]);
            q('UPDATE users SET telegram_chat_id=? WHERE id=?', [$chat, $usr['id']]);
            set_setting('tg_link', null);
            audit_as((int)$usr['id'], 'telegram_linked', 'user', $usr['id']);
            tg_send($chat, "👋 Hi {$usr['name']}, this chat is now linked to the DhanXfer portal.\nYou will get the daily rates here every morning.\n\nCommands:\n/rates – today's live rates\n/today – the suggested rates again\n/update – type new rates now\n/cancel – stop typing rates");
            exit;
        }
    }
    if (!isset(tg_admins()[$chat])) { tg_send($chat, 'This bot is private to DhanXfer admins. Open the portal → Rates → Link my Telegram to connect.'); exit; }
}

$admins = tg_admins();
if (!isset($admins[$chat])) { tg_send($chat, 'This bot is private to DhanXfer admins.'); exit; }
$u = $admins[$chat];

if ($text === '/rates') {
    $cur = current_rates();
    $rows = array_map(fn($r) => ['send' => $r['send_currency'], 'recv' => $r['receive_currency'], 'market' => $r['market_rate'], 'buy' => $r['buy_rate'], 'sell' => $r['sell_rate']], $cur);
    tg_send($chat, $rows ? "📈 <b>Live rates</b>\n\n" . rates_table_text($rows) : 'No rates are set yet. Send /update.');
    exit;
}
if ($text === '/today') {
    $prop = current_proposal();
    if (!$prop) { $prop = build_suggestions(); if ($prop) save_proposal($prop); }
    tg_send($chat, $prop ? "📊 <b>Suggested rates</b>\n\n" . rates_table_text($prop) . "\n\nConfirm these rates?" : 'Market rates could not be fetched right now. Send /update to type them.', $prop ? proposal_keyboard() : null);
    exit;
}
if ($text === '/update') { start_manual($chat); exit; }
if ($text === '/cancel') { state_clear($chat); tg_send($chat, 'Stopped. Nothing was changed.'); exit; }

$s = state_get($chat);
if ($s && $s['state'] === 'enter') {
    $num = str_replace([',', ' '], ['.', ''], $text);
    if (!is_numeric($num) || (float)$num <= 0) { tg_send($chat, 'Please type a number, for example 62.85. Send /cancel to stop.'); exit; }
    $d = $s['data'];
    $key = $d['keys'][$d['i']];
    [$send, $recv] = explode('>', $key);
    $d['rates'][$key] = ($d['rates'][$key] ?? ['send' => $send, 'recv' => $recv, 'market' => $d['prop'][$key]['market'] ?? null]);
    $d['rates'][$key][$d['step']] = round((float)$num, 4);
    if ($d['step'] === 'buy') { $d['step'] = 'sell'; }
    else { $d['step'] = 'buy'; $d['i']++; }
    if ($d['i'] >= count($d['keys'])) {
        state_set($chat, 'review', $d);
        tg_send($chat, "Please check today's new rates:\n\n" . rates_table_text($d['rates']) . "\n\nSave and make them live?", confirm_keyboard());
    } else {
        state_set($chat, 'enter', $d);
        ask_next($chat);
    }
    exit;
}
tg_send($chat, "Commands:\n/rates – today's live rates\n/today – suggested rates to confirm\n/update – type new rates\n/cancel – stop");
