<?php
defined('DX') or exit;
/*
 * One-time codes for client self-service (returning-client updates, document uploads).
 * A code is sent to a channel already on the client's verified record — email today; SMS when a provider is
 * approved ('sms_provider' in the settings file). It proves the person controls that channel, on top of the
 * knowledge check (date of birth + phone digits). Codes: 6 digits, 10 minutes, 5 tries, 3 sends per hour,
 * stored only as a keyed hash.
 */
function otp_channels(array $client): array {
    global $CONFIG;
    $ch = [];
    if (filter_var((string)$client['email'], FILTER_VALIDATE_EMAIL)) $ch['email'] = otp_mask_email((string)$client['email']);
    if (!empty($CONFIG['sms_provider']) && $client['phone_e164']) $ch['sms'] = '•••• ' . substr((string)$client['phone_e164'], -4);
    return $ch;
}
function otp_mask_email(string $e): string {
    [$u, $d] = explode('@', $e, 2) + ['', ''];
    return mb_substr($u, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($u) - 1))) . '@' . $d;
}
function otp_hash(int $clientId, string $purpose, string $code): string { return hash_hmac('sha256', "otp:$clientId:$purpose:$code", sec_key('otp')); }
/** Sends a new code. Returns [ok, message]. Older unused codes for the same purpose stop working. */
function otp_send(array $client, string $purpose, string $channel = 'email'): array {
    $chs = otp_channels($client);
    if (!isset($chs[$channel])) return [false, 'There is no verified ' . ($channel === 'sms' ? 'mobile number' : 'email address') . ' on your file. Please contact DhanXfer on WhatsApp +1 (942)-6600-0600.'];
    if ((int)val('SELECT COUNT(*) FROM client_otps WHERE client_id=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$client['id']]) >= 3) return [false, 'Too many codes were sent in the last hour. Please wait and try again.'];
    // longer cap: about 6 codes (30 guesses) a day per client, and staff are told when it is reached
    if ((int)val('SELECT COUNT(*) FROM client_otps WHERE client_id=? AND created_at > (NOW() - INTERVAL 1 DAY)', [$client['id']]) >= 6) {
        if (!val("SELECT 1 FROM audit WHERE action='otp_daily_cap' AND entity_id=? AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1", [$client['id']])) {
            audit_write(null, 'otp_daily_cap', 'client', $client['id'], $purpose, client_ip());
            notify('Security codes paused for a client', 'A client reached the daily limit of security codes. Check the activity log if they did not ask for them.', 'compliance');
        }
        return [false, 'Too many codes were sent today. Please contact DhanXfer on WhatsApp +1 (942)-6600-0600.'];
    }
    q('UPDATE client_otps SET used_at=NOW() WHERE client_id=? AND purpose=? AND used_at IS NULL', [$client['id'], $purpose]);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('INSERT INTO client_otps (client_id, purpose, channel, code_hash, expires_at, ip) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),?)', [$client['id'], $purpose, $channel, otp_hash((int)$client['id'], $purpose, $code), client_ip()]);
    $ok = $channel === 'email'
        ? send_mail((string)$client['email'], 'Your DhanXfer security code', "Your DhanXfer security code is: $code\n\nIt works for 10 minutes. Never share it with anyone: DhanXfer staff will never ask for it.\nIf you did not ask for this code, contact DhanXfer on WhatsApp.", 'otp')
        : sms_send((string)$client['phone_e164'], "DhanXfer code: $code (10 min). Never share it.");
    audit_write(null, $ok ? 'otp_sent' : 'otp_failed', 'client', $client['id'], $purpose . ' · ' . $channel, client_ip());
    return $ok ? [true, 'We sent a 6-digit code to ' . $chs[$channel] . '.'] : [false, 'The code could not be sent. Please try again later or contact DhanXfer.'];
}
/** Checks a code. 5 wrong tries end the code. */
function otp_verify(int $clientId, string $purpose, string $code): bool {
    $r = one('SELECT * FROM client_otps WHERE client_id=? AND purpose=? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1', [$clientId, $purpose]);
    if (!$r) return false;
    // count the try first and atomically: parallel guesses cannot go past 5
    if (q('UPDATE client_otps SET attempts=attempts+1 WHERE id=? AND attempts<5 AND used_at IS NULL', [$r['id']])->rowCount() !== 1) return false;
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) === 6 && hash_equals($r['code_hash'], otp_hash($clientId, $purpose, $code))) {
        // single use, even if two requests arrive together
        $ok = q('UPDATE client_otps SET used_at=NOW() WHERE id=? AND used_at IS NULL', [$r['id']])->rowCount() === 1;
        if ($ok) audit_write(null, 'otp_verified', 'client', $clientId, $purpose, client_ip());
        return $ok;
    }
    return false;
}
/** SMS adapter: off until a provider is approved and configured. */
function sms_send(string $to, string $text): bool {
    global $CONFIG;
    return false; // no provider connected yet (see 'sms_provider'); the channel is never offered while it is off
}
