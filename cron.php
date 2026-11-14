<?php
// Runs every hour from the Hostinger cron. Sends the daily rate proposal once a day after the chosen time.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/rates.php';
if (!empty($CONFIG['maintenance']) || !schema_current()) { echo date('c') . " skipped: maintenance or upgrade pending\n"; exit; }

// Keep a daily record of the market rate, whether or not Telegram is set up.
if (setting('last_market_date') !== today()) {
    $n = record_market_today();
    if ($n) set_setting('last_market_date', today());
    echo date('c') . " market rates recorded for $n pair(s)\n";
}
// Daily activity-log checkpoint kept outside the database (file + email), so cut-off rows are detected.
if (setting('last_anchor_date') !== today()) {
    set_setting('last_anchor_date', today());
    if ($line = audit_write_anchor()) notify('DhanXfer activity log checkpoint ' . today(), "Keep this email. It lets the portal prove later that no activity-log rows were removed.\n\n$line", 'audit_checkpoint');
}
// Daily compliance checks: document expiry reminders, KYC refresh cases, escalation. Pending reminders retry every run.
require_once __DIR__ . '/inc/compliance.php';
if (setting('last_compliance_date') !== today() && (int)date('G') >= 7) {
    $c = compliance_daily();
    set_setting('last_compliance_date', today()); // only after it worked, so a failed run is tried again next hour
    echo date('c') . ' compliance: ' . json_encode($c) . "\n";
} else { $r = reminders_send(); if ($r['sent'] || $r['failed']) echo date('c') . ' reminder retries: ' . json_encode($r) . "\n"; }
if (setting('rates_on', '1') !== '1' || !tg_token()) exit(0);
$sendAt = setting('send_time', '09:00');
if (date('H:i') < $sendAt) exit(0);
if (setting('last_proposal_date') === today()) exit(0);
set_setting('last_proposal_date', today());
$n = send_daily_proposal();
echo date('c') . " proposal sent to $n admin(s)\n";
