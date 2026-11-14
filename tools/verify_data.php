<?php
// php tools/verify_data.php   -> checks encryption, ID search keys, form records and the activity log. Counts only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
define('DX_NO_AUTO_MIGRATE', true);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/verify.php';
$h = data_health();
$out = date('c') . "  data check\n" . health_text($h);
echo $out; @file_put_contents(data_dir() . '/verify-report.txt', $out);
exit($h['problems'] ? 1 : 0);
