<?php
defined('DX') or exit;
/*
 * Re-seals stored values and ID files with the active key ring key (after a new data key is activated).
 * Safe to stop and run again: each value is re-sealed, read back and compared before it is written, and only
 * rows still sealed with another key are touched. Search keys (ID-number and account look-ups) do not change.
 */
function reseal_run(bool $write, int $batch = 200, ?callable $log = null): array {
    $active = keyring_active();
    $r = ['active' => $active, 'columns' => [], 'files' => ['total' => 0, 'other_key' => 0, 'resealed' => 0, 'failed' => 0], 'failed' => 0];
    foreach (ENC_COLUMNS as [$t, $c]) {
        $st = ['other_key' => 0, 'resealed' => 0, 'failed' => 0];
        $exists = (int)val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$t, $c]);
        if (!$exists) { $r['columns']["$t.$c"] = $st; continue; }
        $last = 0;
        do {
            $rows = all("SELECT id, `$c` AS v FROM `$t` WHERE id > ? AND `$c` LIKE 'enc:%' ORDER BY id LIMIT " . (int)$batch, [$last]);
            foreach ($rows as $row) {
                $last = (int)$row['id'];
                if (enc_kid($row['v']) === $active) continue;
                $st['other_key']++;
                $plain = dec($row['v']);
                if ($plain === null) { $st['failed']++; continue; }
                if (!$write) continue;
                $new = enc($plain);
                if (dec($new) !== $plain) { $st['failed']++; continue; }
                // only if nobody changed the row meanwhile
                if (q("UPDATE `$t` SET `$c`=? WHERE id=? AND `$c`=?", [$new, $row['id'], $row['v']])->rowCount() === 1
                    && dec((string)val("SELECT `$c` FROM `$t` WHERE id=?", [$row['id']])) === $plain) $st['resealed']++;
                else $st['failed']++;
            }
        } while (count($rows) === $batch);
        $r['columns']["$t.$c"] = $st; $r['failed'] += $st['failed'];
        if ($log) $log("$t.$c: " . json_encode($st));
    }
    foreach (glob(ids_dir() . '/*') ?: [] as $f) {
        if (!is_file($f) || str_starts_with(basename($f), '.')) continue;
        $n = basename($f); $r['files']['total']++;
        $kid = id_file_kid($n);
        if ($kid === $active) continue;
        $r['files']['other_key']++;
        $plain = id_file_read($n);
        if ($plain === null) { $r['files']['failed']++; continue; }
        if (!$write) continue;
        if (id_file_write($n, $plain) && id_file_kid($n) === $active && id_file_read($n) === $plain) $r['files']['resealed']++;
        else $r['files']['failed']++;
    }
    $r['failed'] += $r['files']['failed'];
    if ($write) audit_write(null, 'keys_resealed', 'system', null, 'Re-sealed with ' . $active . ': ' . array_sum(array_column($r['columns'], 'resealed')) . ' values, ' . $r['files']['resealed'] . ' files, ' . $r['failed'] . ' failed', 'cli');
    return $r;
}
