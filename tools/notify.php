<?php
// php tools/notify.php "Message"  -> sends a short progress note to the admins linked on Telegram.
// Used for development milestones. Never put client data in the message.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/rates.php';
$msg = trim(implode(' ', array_slice($argv, 1)));
if ($msg === '' || !tg_token()) { echo "Nothing sent (no message or no Telegram bot).\n"; exit(0); }
$env = ($CONFIG['environment'] ?? 'live') === 'staging' ? '[STAGING] ' : '';
$n = 0;
foreach (tg_admins() as $chat => $u) { tg_send((string)$chat, '🛠 ' . $env . e(mb_substr($msg, 0, 900))); $n++; }
echo date('c') . " sent to $n\n";
