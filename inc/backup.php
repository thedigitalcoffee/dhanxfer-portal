<?php
defined('DX') or exit;
// Encrypted, self-checking database backups kept OUTSIDE the website folder.
// Format: gzip(JSON) encrypted with a key derived from the server key. Includes uploaded ID files.

function backup_dir(): string {
    global $CONFIG;
    $d = $CONFIG['backup_dir'] ?? (dirname(__DIR__, 2) . '/dx-backups' . (defined('DX_TEST') ? '-test' : ''));
    if (!is_dir($d)) mkdir($d, 0700, true);
    $real = realpath($d) ?: $d;
    $docroot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    if (str_contains($real, '/public_html') || ($docroot !== '' && str_starts_with($real, $docroot)) || str_starts_with($real, realpath(dirname(__DIR__)) ?: dirname(__DIR__)))
        throw new RuntimeException('Refusing to keep backups inside the website folder: ' . $real);
    return $d;
}
/** Per-table row count + hash of every row, to prove a restore is identical. */
function db_fingerprint(): array {
    $fp = [];
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $h = hash_init('sha256'); $n = 0;
        foreach (db()->query("SELECT * FROM `$t` ORDER BY 1") as $r) { hash_update($h, json_encode(array_values($r))); $n++; }
        $fp[$t] = $n . ':' . hash_final($h);
    }
    ksort($fp);
    return $fp;
}
function backup_create(string $label = 'nightly'): array {
    $data = ['made' => date('c'), 'schema' => setting('schema_version'), 'tables' => [], 'files' => [], 'fingerprint' => db_fingerprint()];
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = db()->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $data['tables'][$t] = ['create' => $create, 'rows' => db()->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC)];
    }
    foreach (glob(ids_dir() . '/*') ?: [] as $f) if (is_file($f)) $data['files'][basename($f)] = base64_encode((string)file_get_contents($f));
    $plain = gzencode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 6);
    $blob = 'DXB1' . aead_seal($plain, sec_key('backup'), 'dhanxfer-backup');
    $name = 'dx-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]/', '', $label) . '.bak';
    $path = backup_dir() . '/' . $name;
    file_put_contents($path, $blob, LOCK_EX);
    chmod($path, 0600);
    // keep the newest 30
    $all = glob(backup_dir() . '/dx-*.bak') ?: []; rsort($all);
    foreach (array_slice($all, 30) as $old) @unlink($old);
    return ['file' => $name, 'bytes' => strlen($blob), 'tables' => count($data['tables']), 'files' => count($data['files'])];
}
function backup_read(string $name): array {
    $path = backup_dir() . '/' . basename($name);
    $blob = (string)@file_get_contents($path);
    if (!str_starts_with($blob, 'DXB1')) throw new RuntimeException('Not a DhanXfer backup file.');
    $plain = aead_open(substr($blob, 4), sec_key('backup'), 'dhanxfer-backup');
    if ($plain === null) throw new RuntimeException('The backup could not be decrypted (wrong key or damaged file).');
    $data = json_decode((string)gzdecode($plain), true);
    if (!is_array($data)) throw new RuntimeException('The backup is damaged.');
    return $data;
}
/** Replaces the whole database (and uploaded IDs) with the backup, then proves it matches. */
function backup_restore(string $name): array {
    $data = backup_read($name);
    db()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) db()->exec("DROP TABLE `$t`");
    foreach ($data['tables'] as $t => $tbl) {
        db()->exec($tbl['create']);
        foreach (array_chunk($tbl['rows'], 200) as $chunk) {
            if (!$chunk) continue;
            $cols = array_keys($chunk[0]);
            $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $par = [];
            foreach ($chunk as $r) foreach ($cols as $c) $par[] = $r[$c];
            q("INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES " . implode(',', array_fill(0, count($chunk), $ph)), $par);
        }
    }
    db()->exec('SET FOREIGN_KEY_CHECKS=1');
    $ids = ids_dir();
    if (!is_dir($ids)) mkdir($ids, 0750, true);
    foreach (glob($ids . '/*') ?: [] as $f) if (is_file($f) && !isset($data['files'][basename($f)])) @unlink($f); // files added after the backup
    foreach ($data['files'] as $f => $b64) file_put_contents($ids . '/' . basename($f), base64_decode($b64));
    // Note: after a restore the activity-log checkpoint check will report rows written after the backup as missing. Expected.
    $now = db_fingerprint();
    return ['match' => $now == $data['fingerprint'], 'tables' => count($data['tables']), 'made' => $data['made']];
}
