<?php
defined('DX') or exit;
// Time-based one-time codes (RFC 6238), the 6-digit codes from Google Authenticator, Microsoft Authenticator, Authy, etc.

function b32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function b32_decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function totp_code(string $secretB32, int $step): string {
    $h = hash_hmac('sha1', pack('J', $step), b32_decode($secretB32), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Returns the matching time step (one step of clock drift either way), or null. */
function totp_match(string $secretB32, string $code, ?int $lastStep = null, ?int $now = null): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $t = intdiv($now ?? time(), 30);
    foreach ([0, -1, 1] as $d) {
        $step = $t + $d;
        if ($lastStep !== null && $step <= $lastStep) continue; // a code can be used once
        if (hash_equals(totp_code($secretB32, $step), $code)) return $step;
    }
    return null;
}
function mfa_new_secret(): string { return b32_encode(random_bytes(20)); }
function mfa_recovery_codes(): array {
    $c = [];
    for ($i = 0; $i < 8; $i++) $c[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5));
    return $c;
}
/** Checks a login code or a recovery code for a user row; updates the replay guard. */
function mfa_check(array $user, string $input): bool {
    $secret = dec($user['mfa_secret']);
    if (!$secret) return false;
    $step = totp_match($secret, $input, $user['mfa_last_step'] !== null ? (int)$user['mfa_last_step'] : null);
    if ($step !== null) {
        // Only one request can claim this step: the update succeeds once.
        return q('UPDATE users SET mfa_last_step=? WHERE id=? AND (mfa_last_step IS NULL OR mfa_last_step < ?)', [$step, $user['id'], $step])->rowCount() === 1;
    }
    $codes = json_decode((string)$user['mfa_recovery'], true) ?: [];
    $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
    foreach ($codes as $i => $hash) {
        if (password_verify($norm, $hash)) {
            unset($codes[$i]);
            // Only succeeds if nobody used a code in the meantime, so one code cannot work twice at once.
            if (q('UPDATE users SET mfa_recovery=? WHERE id=? AND mfa_recovery=?', [json_encode(array_values($codes)), $user['id'], (string)$user['mfa_recovery']])->rowCount() !== 1) return false;
            audit_write((int)$user['id'], 'mfa_recovery_used', 'user', $user['id'], count($codes) . ' recovery codes left', client_ip());
            return true;
        }
    }
    return false;
}

/** Wrong-code limits: 5 per 15 minutes, 15 per day. Returns a message when locked. Alerts the admins once a day is reached. */
function mfa_locked(int $uid): ?string {
    $key = 'mfa:' . $uid;
    if ((int)val('SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 1 DAY)', [$key]) >= 15) return 'Two-step login is locked for 24 hours after too many wrong codes. Ask the super admin to check your account.';
    if ((int)val('SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 15 MINUTE)', [$key]) >= 5) return 'Too many wrong codes. Wait 15 minutes and log in again.';
    return null;
}
function mfa_fail(int $uid): void {
    $key = 'mfa:' . $uid;
    q('INSERT INTO login_attempts (ip, username) VALUES (?,?)', [client_ip(), $key]);
    audit_write($uid, 'mfa_failed', 'user', $uid, 'Wrong two-step code', client_ip());
    $n = (int)val('SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 1 DAY)', [$key]);
    if ($n === 5 || $n === 15) {
        notify('Security alert: wrong two-step codes', "User #$uid has entered $n wrong two-step codes in the last 24 hours, which suggests someone else knows their password. Check the Activity history and consider resetting their password.", 'security_alert');
        notify_user($uid, 'Security alert: wrong two-step codes on your DhanXfer account', "Someone entered the right password for your DhanXfer portal account but $n wrong two-step codes in the last 24 hours. If this was not you, your password is known to someone else: ask the super admin to reset it now." . ($n === 15 ? "\n\nTwo-step login on your account is now locked for 24 hours." : ''));
    }
}
/** Strict browser rules for the pages that show or take two-step codes: no outside scripts at all. */
function strict_csp(): void {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}
