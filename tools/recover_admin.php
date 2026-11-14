<?php
// Emergency owner recovery, only from the server (hPanel cron or SSH) — whoever controls the hosting account.
//   php tools/recover_admin.php <login-id>   clears that super admin's lost two-step login and prints a one-time link
//   php tools/recover_admin.php --clear-claim clears a half-finished super admin claim (only if no working super admin)
// Both are logged and emailed to the owner inbox. Nobody is promoted.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/recovery.php';
$arg = (string)($argv[1] ?? '');
if ($arg === '--clear-claim') { echo clear_owner_claim() ? "Cleared. The owner can claim super admin again at /install.php with the install key.\n" : "A working super admin exists: recover that account instead.\n"; exit; }
$link = $arg !== '' ? recover_super($arg) : null;
echo $link ? "One-time link for $arg (24 hours). Open it, set a new password, then scan the new two-step code:\n$link\n" : "No super admin with that login ID.\n";
