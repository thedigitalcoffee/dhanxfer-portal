<?php
// php tools/backup.php [label]          -> makes an encrypted backup outside the website folder
// php tools/backup.php --list           -> lists backups
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/backup.php';
if (($argv[1] ?? '') === '--list') { foreach (glob(backup_dir() . '/dx-*.bak') ?: [] as $f) echo basename($f), '  ', filesize($f), " bytes\n"; exit; }
$r = backup_create($argv[1] ?? 'nightly');
audit_write(null, 'backup_made', 'backup', null, $r['file'] . ' · ' . $r['tables'] . ' tables', 'cron');
echo date('c'), ' backup ', $r['file'], ' (', $r['bytes'], " bytes)\n";
