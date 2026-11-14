<?php
// php tools/migrate.php        -> upgrades the database (only with a backup made in the last 2 hours), then checks it
// Live keeps 'auto_migrate' => false so the upgrade never starts from a web request.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
define('DX_NO_AUTO_MIGRATE', true);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/backup.php';
require __DIR__ . '/../inc/verify.php';
$log = function (string $s) { echo $s, "\n"; @file_put_contents(data_dir() . '/migrate-report.txt', $s . "\n", FILE_APPEND); };
$log(date('c') . '  upgrade started');
$recent = array_filter(glob(backup_dir() . '/dx-*.bak') ?: [], fn($f) => filemtime($f) > time() - 7200);
if (!$recent) { $log('STOPPED: no backup from the last 2 hours. Run php tools/backup.php pre-upgrade first.'); exit(2); }
if (!enc_ready()) { $log('STOPPED: no encryption key in the settings file.'); exit(2); }
$before = setting('schema_version') ?? '(none)';
$log('Backup found: ' . basename(end($recent)) . ' · schema before: ' . $before);
try { migrate(); } catch (Throwable $e) { $log('FAILED: ' . get_class($e) . ' — nothing after the failing step was saved. Restore with tools/restore.php if needed.'); exit(1); }
audit_write(null, 'migrated', 'system', null, "schema $before → " . SCHEMA_VERSION, 'cron');
$h = data_health();
$log(health_text($h));
exit($h['problems'] ? 1 : 0);
