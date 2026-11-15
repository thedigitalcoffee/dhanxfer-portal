<?php
defined('DX') or exit;
/*
 * Sends a message through an authenticated mailbox (e.g. no-reply@dhanxfer.com on smtp.hostinger.com:465), so
 * mail is signed by the mail provider (DKIM) and passes SPF. PHP's mail() from the web server lands in Junk.
 * Config (dx-config.php): 'smtp' => ['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => 'no-reply@dhanxfer.com', 'pass' => '…']
 * Never logs the password or the message body.
 */
function smtp_message(string $from, string $to, string $subject, string $headers, string $body): string {
    // dot-stuffing and CRLF line ends, as SMTP requires
    $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
    $body = preg_replace('/^\./m', '..', $body);
    return "To: <$to>\r\nSubject: $subject\r\n" . $headers . "\r\n\r\n" . $body . "\r\n.\r\n";
}
function smtp_send(array $c, string $from, string $to, string $subject, string $headers, string $body): bool {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host']]]);
    $s = @stream_socket_client('ssl://' . $c['host'] . ':' . (int)($c['port'] ?? 465), $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) return false;
    stream_set_timeout($s, 15);
    $read = function () use ($s): string { $r = ''; while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (?string $line, string $want) use ($s, $read): bool { if ($line !== null) fwrite($s, $line . "\r\n"); return str_starts_with($read(), $want); };
    $ok = $cmd(null, '220') && $cmd('EHLO ' . (gethostname() ?: 'dhanxfer'), '250') && $cmd('AUTH LOGIN', '334')
        && $cmd(base64_encode($c['user']), '334') && $cmd(base64_encode($c['pass']), '235')
        && $cmd('MAIL FROM:<' . $from . '>', '250') && $cmd('RCPT TO:<' . $to . '>', '25') && $cmd('DATA', '354')
        && $cmd(rtrim(smtp_message($from, $to, $subject, $headers, $body), "\r\n"), '250');
    @fwrite($s, "QUIT\r\n"); @fclose($s);
    return $ok;
}
