<?php
// Key ring management on the server (never from a web page). Prints ids and providers only, never key material.
//   php tools/keyring.php init                             (once: empty key ring file where keyring_file points)
//   php tools/keyring.php status
//   php tools/keyring.php new k1 local                       (staging/test: data key wrapped by local_kek)
//   php tools/keyring.php new k1 gcpkms projects/P/locations/L/keyRings/R/cryptoKeys/C   (needs kms_enabled)
//   php tools/keyring.php activate k1                        (new values and files use k1)
//   php tools/keyring.php rewrap k1                          (after the KMS key rotated: wrap again with its new primary)
//   DX_ESCROW_PASS='…' php tools/keyring.php escrow-export k1 /path/escrow-k1.json   (offline copy for the owner)
//   DX_ESCROW_PASS='…' php tools/keyring.php escrow-import /path/escrow-k1.json gcpkms projects/…/cryptoKeys/C
//   php tools/keyring.php reseal [--write]                   (re-seal older values/files with the active key; dry run without --write)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cli';
define('DX_NO_AUTO_MIGRATE', true);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/reseal.php';
$a = array_slice($argv, 1);
try {
    switch ($a[0] ?? 'status') {
        case 'init': keyring_init(); echo "key ring created (still using k0)\n"; break;
        case 'status': foreach (keyring_status() as $kid => $s) echo $kid, '  ', $s['provider'], $s['active'] ? '  ACTIVE' : '', $s['stable'] ? '  stable (search keys)' : '', $s['kms_key'] ? '  ' . $s['kms_key'] : '', "\n"; break;
        case 'new': keyring_new((string)($a[1] ?? ''), (string)($a[2] ?? ''), $a[3] ?? null); audit_write(null, 'key_created', 'system', null, ($a[1] ?? '') . ' · ' . ($a[2] ?? ''), 'cli'); echo "created ", $a[1], " (not active yet)\n"; break;
        case 'activate': keyring_activate((string)($a[1] ?? '')); audit_write(null, 'key_activated', 'system', null, (string)$a[1], 'cli'); echo "active: ", $a[1], "\n"; break;
        case 'rewrap': keyring_rewrap((string)($a[1] ?? '')); audit_write(null, 'key_rewrapped', 'system', null, (string)$a[1], 'cli'); echo "rewrapped ", $a[1], "\n"; break;
        case 'reseal': $r = reseal_run(in_array('--write', $a, true), 200, fn($l) => print("$l\n")); echo json_encode($r['files']), "\nfailed: ", $r['failed'], "\n"; exit($r['failed'] ? 1 : 0);
        case 'escrow-export': // DX_ESCROW_PASS must be set in the environment for this one command; the file goes to the owner, then is deleted here
            $pass = (string)getenv('DX_ESCROW_PASS'); $out = (string)($a[2] ?? '');
            if ($out === '' || file_exists($out)) throw new RuntimeException('Give a new output file path.');
            file_put_contents($out, keyring_escrow_export((string)($a[1] ?? ''), $pass)); chmod($out, 0600);
            audit_write(null, 'key_escrow_exported', 'system', null, (string)$a[1], 'cli'); echo "escrow file written: $out (move it off the server now)\n"; break;
        case 'escrow-import': // php tools/keyring.php escrow-import FILE local|gcpkms [KMS key name]
            $kid = keyring_escrow_import((string)file_get_contents((string)($a[1] ?? '')), (string)getenv('DX_ESCROW_PASS'), (string)($a[2] ?? ''), $a[3] ?? null);
            audit_write(null, 'key_escrow_imported', 'system', null, $kid, 'cli'); echo "restored $kid from escrow\n"; break;
        default: echo "unknown command\n"; exit(2);
    }
} catch (Throwable $e) { fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n"); exit(1); }
