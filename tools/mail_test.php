<?php
// STAGING ONLY: php tools/mail_test.php <address>
// Sends one of each email the portal sends. The activation/reset samples use a dummy account whose links are
// cancelled straight after, so nothing in them can be used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
if (($CONFIG['environment'] ?? '') !== 'staging') { echo "REFUSED: staging only\n"; exit(2); }
$to = (string)($argv[1] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { echo "Give an email address\n"; exit(2); }
q("DELETE FROM users WHERE username='mail.sample'");
q("INSERT INTO users (role, username, name, email, password_hash, active) VALUES ('agent','mail.sample','Mail Sample',?,?,0)", [$to, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
$id = (int)db()->lastInsertId();
$tag = date('H:i:s');
$res = [];
$l = make_reset_link($id, 0, 'activate');
$res['activation'] = send_mail($to, "Activate your DhanXfer portal account ($tag)", "Welcome to DhanXfer, Mail Sample! Open this link to set your password and activate your portal account. It works once and expires in 72 hours:\n$l\nYour login ID: mail.sample\n\n(Test message: this link has already been cancelled.)", 'activation_link');
$l = make_reset_link($id, 0, 'reset');
$res['password_reset'] = send_mail($to, "Your DhanXfer password reset link ($tag)", "Hi Mail Sample, use this link to set a new password for the DhanXfer portal. It works once and expires in 24 hours:\n$l\nYour login ID: mail.sample\n\n(Test message: this link has already been cancelled.)", 'reset_link');
q('UPDATE password_resets SET used_at=NOW() WHERE user_id=?', [$id]);
$res['security_alert'] = send_mail($to, "Security alert: wrong two-step codes on your DhanXfer account ($tag)", "Someone entered the right password for your DhanXfer portal account but 5 wrong two-step codes in the last 24 hours. If this was not you, your password is known to someone else: ask the super admin to reset it now.\n\n(Test message.)", 'security_alert');
$line = 'DXAUDIT ' . date('Y-m-d') . ' rows=0 last=0 hash=TEST-ONLY-NOT-A-REAL-CHECKPOINT';
$res['audit_checkpoint'] = send_mail($to, 'DhanXfer activity log checkpoint ' . date('Y-m-d') . " ($tag)", "Keep this email. It lets the portal prove later that no activity-log rows were removed.\n\n$line\n\n(Test message.)", 'audit_checkpoint');
q('DELETE FROM password_resets WHERE user_id=?', [$id]); q('DELETE FROM users WHERE id=?', [$id]);
$out = date('c') . "  mail test ($tag) handed to the mail server:\n";
foreach ($res as $k => $ok) $out .= sprintf("  %-17s %s\n", $k, $ok ? 'accepted' : 'REFUSED');
echo $out; @file_put_contents(data_dir() . '/mail-report.txt', $out);
