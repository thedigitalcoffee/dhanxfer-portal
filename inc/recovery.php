<?php
defined('DX') or exit;
/* Emergency owner recovery. Only reachable from the server (tools/recover_admin.php), i.e. by whoever controls the hosting account. */

/** Clears a super admin's two-step login and password, ends their sessions, returns a one-time link (24 hours). */
function recover_super(string $login, bool $reactivate = false): ?string {
    $u = one("SELECT * FROM users WHERE username=? AND role='super_admin'", [mb_strtolower($login)]);
    if (!$u || (!$u['active'] && !$reactivate)) return null;
    $link = make_reset_link((int)$u['id'], 0, 'reset'); // first: if this fails, nothing has changed
    q("UPDATE users SET active=1, account_status='active', mfa_enabled=0, mfa_secret=NULL, mfa_recovery=NULL, mfa_token_only=1, password_hash=?, session_version=session_version+1 WHERE id=?", [password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $u['id']]);
    q('DELETE FROM login_attempts WHERE username IN (?, ?)', [$u['username'], 'mfa:' . $u['id']]);
    audit_write(null, 'break_glass', 'user', $u['id'], 'Super admin ' . $u['username'] . ' recovered from the server', 'cron');
    notify('Security: emergency owner recovery used', 'The server recovery tool was used for ' . $u['username'] . ' at ' . date('Y-m-d H:i') . '. If this was not you, someone has access to the hosting account.', 'security_alert');
    return $link;
}
/** Clears an unfinished "claim super admin" when no working super admin exists. Promotes nobody. */
function clear_owner_claim(): bool {
    if ((int)val("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1 AND account_status='active' AND mfa_enabled=1")) return false;
    q("DELETE FROM settings WHERE k='owner_claim'");
    audit_write(null, 'break_glass', 'settings', null, 'Unfinished super admin claim cleared from the server', 'cron');
    notify('Security: unfinished owner claim cleared', 'The server recovery tool cleared an unfinished super admin claim at ' . date('Y-m-d H:i') . '.', 'security_alert');
    return true;
}
