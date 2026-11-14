<?php
// Emergency owner recovery, only from the server (hPanel cron or SSH) — whoever controls the hosting account.
// php tools/recover_admin.php <login-id>
// Clears that super admin's two-step login, ends their sessions and prints a one-time link (24 hours) to set
// a new password and two-step login. Logged and emailed to the owner inbox.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
// php tools/recover_admin.php --clear-claim
// Clears a half-finished "claim super admin" (owner_claim) when no super admin with two-step login exists,
// so the real owner can claim again with the install key. Promotes nobody.
if (($argv[1] ?? '') === '--clear-claim') {
    if ((int)val("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1 AND mfa_enabled=1")) { echo "A working super admin exists: use their account, or recover it with this tool and their login ID.\n"; exit(2); }
    q("DELETE FROM settings WHERE k='owner_claim'");
    audit_write(null, 'break_glass', 'settings', null, 'Unfinished super admin claim cleared from the server', 'cron');
    notify('Security: unfinished owner claim cleared', 'The server recovery tool cleared an unfinished super admin claim at ' . date('Y-m-d H:i') . '.', 'security_alert');
    echo "Cleared. The owner can claim super admin again at /install.php with the install key.\n"; exit(0);
}
$login = mb_strtolower((string)($argv[1] ?? ''));
$u = $login !== '' ? one("SELECT * FROM users WHERE username=? AND role='super_admin'", [$login]) : null;
if (!$u) { echo "No super admin with that login ID.\n"; exit(2); }
q("UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL", [$u['id']]);
q("UPDATE users SET active=1, mfa_enabled=0, mfa_secret=NULL, mfa_recovery=NULL, mfa_token_only=1, password_hash=?, session_version=session_version+1 WHERE id=?", [password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $u['id']]);
q("DELETE FROM login_attempts WHERE username IN (?, ?)", [$u['username'], 'mfa:' . $u['id']]);
$link = make_reset_link((int)$u['id'], 0, 'reset');
audit_write(null, 'break_glass', 'user', $u['id'], 'Super admin ' . $u['username'] . ' recovered from the server', 'cron');
notify('Security: emergency owner recovery used', 'The server recovery tool was used for ' . $u['username'] . ' at ' . date('Y-m-d H:i') . '. If this was not you, someone has access to the hosting account.', 'security_alert');
echo "One-time link for {$u['username']} (24 hours). Open it, set a new password, then scan the new two-step code:\n$link\n";
