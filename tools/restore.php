<?php
// php tools/restore.php <backup-file> --yes-replace-everything
// Replaces the whole database with the backup. A safety backup of the current state is made first.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/backup.php';
$file = $argv[1] ?? '';
if (!$file || ($argv[2] ?? '') !== '--yes-replace-everything') { echo "Usage: php tools/restore.php <backup-file> --yes-replace-everything\n"; exit(2); }
$safety = backup_create('before-restore');
echo "Safety backup: {$safety['file']}\n";
$r = backup_restore($file);
audit_write(null, 'backup_restored', 'backup', null, basename($file) . ($r['match'] ? ' · verified identical' : ' · MISMATCH'), 'cron');
echo $r['match'] ? "Restored {$r['tables']} tables from {$r['made']}. Fingerprint matches.\n" : "Restored, but the fingerprint does NOT match. Check before using the system.\n";
exit($r['match'] ? 0 : 1);
