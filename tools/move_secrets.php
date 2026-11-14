<?php
// php tools/move_secrets.php
// One-time: moves settings, keys, ID documents and the log checkpoint OUT of the website folder,
// so Git deploys can replace the code without touching them. Safe to run again (does nothing the second time).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
$root = dirname(__DIR__);              // public_html
$home = dirname($root);                // one level above, not reachable from the web
$data = $home . '/dx-data';
foreach (['config.php' => 'dx-config.php', 'config.test.php' => 'dx-config.test.php'] as $in => $out) {
    $src = "$root/inc/$in"; $dst = "$home/$out";
    if (is_file($dst)) { echo "$out already outside\n"; continue; }
    if (!is_file($src)) continue;
    $cfg = require $src;
    if (!is_array($cfg)) { echo "Could not read $in\n"; exit(1); }
    if ($in === 'config.php') $cfg['data_dir'] = $data;
    $php = "<?php\n// Moved out of the website folder by tools/move_secrets.php on " . date('c') . "\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents($dst, $php, LOCK_EX) === false) { echo "Could not write $dst\n"; exit(1); }
    chmod($dst, 0600);
    $check = require $dst;
    if (!is_array($check) || ($check['db_name'] ?? null) !== ($cfg['db_name'] ?? null)) { @unlink($dst); echo "Verification failed for $out\n"; exit(1); }
    echo "$in -> $dst\n";
}
if (!is_dir("$data/ids")) mkdir("$data/ids", 0700, true);
$moved = 0;
foreach (glob("$root/storage/ids/*") ?: [] as $f) {
    if (!is_file($f) || basename($f) === '.keep') continue;
    if (copy($f, "$data/ids/" . basename($f)) && filesize($f) === filesize("$data/ids/" . basename($f))) { unlink($f); $moved++; }
}
foreach (['audit-anchors.log'] as $f) if (is_file("$root/storage/$f") && !is_file("$data/$f")) { copy("$root/storage/$f", "$data/$f"); }
echo "$moved ID files moved to $data/ids\n";
// Inside copies are now only fallbacks; remove the live one so secrets are no longer in the website folder.
if (is_file("$home/dx-config.php") && is_file("$root/inc/config.php")) { unlink("$root/inc/config.php"); echo "Removed inc/config.php from the website folder\n"; }
if (is_file("$home/dx-config.test.php") && is_file("$root/inc/config.test.php")) { unlink("$root/inc/config.test.php"); echo "Removed inc/config.test.php from the website folder\n"; }
echo "Done\n";
