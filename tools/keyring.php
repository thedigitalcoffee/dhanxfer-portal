<?php
// Key ring management on the server (never from a web page). Prints ids and providers only, never key material.
//   php tools/keyring.php status
//   php tools/keyring.php new k1 local                       (staging/test: data key wrapped by local_kek)
//   php tools/keyring.php new k1 gcpkms projects/P/locations/L/keyRings/R/cryptoKeys/C   (needs kms_enabled)
//   php tools/keyring.php activate k1                        (new values and files use k1)
//   php tools/keyring.php rewrap k1                          (after the KMS key rotated: wrap again with its new primary)
//   php tools/keyring.php reseal [--write]                   (re-seal older values/files with the active key; dry run without --write)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cli';
define('DX_NO_AUTO_MIGRATE', true);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/reseal.php';
$a = array_slice($argv, 1);
try {
    switch ($a[0] ?? 'status') {
        case 'status': foreach (keyring_status() as $kid => $s) echo $kid, '  ', $s['provider'], $s['active'] ? '  ACTIVE' : '', $s['stable'] ? '  stable (search keys)' : '', $s['kms_key'] ? '  ' . $s['kms_key'] : '', "\n"; break;
        case 'new': keyring_new((string)($a[1] ?? ''), (string)($a[2] ?? ''), $a[3] ?? null); audit_write(null, 'key_created', 'system', null, ($a[1] ?? '') . ' · ' . ($a[2] ?? ''), 'cli'); echo "created ", $a[1], " (not active yet)\n"; break;
        case 'activate': keyring_activate((string)($a[1] ?? '')); audit_write(null, 'key_activated', 'system', null, (string)$a[1], 'cli'); echo "active: ", $a[1], "\n"; break;
        case 'rewrap': keyring_rewrap((string)($a[1] ?? '')); audit_write(null, 'key_rewrapped', 'system', null, (string)$a[1], 'cli'); echo "rewrapped ", $a[1], "\n"; break;
        case 'reseal': $r = reseal_run(in_array('--write', $a, true), 200, fn($l) => print("$l\n")); echo json_encode($r['files']), "\nfailed: ", $r['failed'], "\n"; exit($r['failed'] ? 1 : 0);
        default: echo "unknown command\n"; exit(2);
    }
} catch (Throwable $e) { fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n"); exit(1); }
