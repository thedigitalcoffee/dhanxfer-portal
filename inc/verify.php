<?php
defined('DX') or exit;
/**
 * Checks the stored data after an upgrade. Returns counts only: never values, names or numbers.
 * problems = list of short text lines; empty means everything checked out.
 */
function data_health(): array {
    $r = ['schema' => (string)setting('schema_version'), 'counts' => [], 'encrypted' => [], 'problems' => []];
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $r['counts'][$t] = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    if ($r['schema'] !== SCHEMA_VERSION) $r['problems'][] = 'schema version is ' . $r['schema'] . ', expected ' . SCHEMA_VERSION;
    $cols = [['clients', 'id_number'], ['beneficiaries', 'account'], ['submissions', 'data'], ['users', 'mfa_secret']];
    foreach ($cols as [$t, $c]) {
        $tot = 0; $sealed = 0; $plain = 0; $bad = 0;
        foreach (db()->query("SELECT id, `$c` AS v FROM `$t` WHERE `$c` IS NOT NULL AND `$c`<>''") as $row) {
            $tot++;
            if (!str_starts_with((string)$row['v'], 'enc:v1:')) { $plain++; continue; }
            $sealed++;
            if (dec((string)$row['v']) === null) $bad++;
        }
        $r['encrypted']["$t.$c"] = compact('tot', 'sealed', 'plain', 'bad');
        if ($plain) $r['problems'][] = "$t.$c: $plain value(s) still readable";
        if ($bad) $r['problems'][] = "$t.$c: $bad value(s) do not decrypt";
    }
    $mis = 0;
    foreach (db()->query("SELECT id_number, id_number_hash FROM clients WHERE id_number LIKE 'enc:%'") as $row)
        if (!hash_equals((string)$row['id_number_hash'], (string)id_hash(dec((string)$row['id_number'])))) $mis++;
    if ($mis) $r['problems'][] = "clients: $mis ID search key(s) do not match";
    $badJson = 0;
    foreach (db()->query("SELECT data FROM submissions") as $row) if (!is_array(json_decode((string)dec((string)$row['data']), true))) $badJson++;
    if ($badJson) $r['problems'][] = "submissions: $badJson form record(s) are not readable";
    foreach ([['clients', 'id_number'], ['beneficiaries', 'account'], ['users', 'mfa_secret']] as [$t, $c]) {
        $n = (int)val('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$t, $c]);
        if ($n < 255) $r['problems'][] = "$t.$c is only $n characters wide";
    }
    $plainFiles = 0; $files = 0;
    foreach (glob(ids_dir() . '/*') ?: [] as $f) { if (!is_file($f) || str_starts_with(basename($f), '.')) continue; $files++; if (!id_file_encrypted(basename($f))) $plainFiles++; }
    $r['id_files'] = ['total' => $files, 'not_encrypted' => $plainFiles];
    if ($plainFiles) $r['problems'][] = "$plainFiles ID image(s) are not encrypted";
    if (val("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_versions'")) {
        $r['documents'] = (int)val('SELECT COUNT(*) FROM document_versions');
        $missing = (int)val("SELECT COUNT(*) FROM clients c WHERE ((c.id_number IS NOT NULL AND c.id_number<>'') OR c.id_file IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM client_documents d WHERE d.client_id=c.id)");
        if ($missing) $r['problems'][] = "$missing client(s) with ID details have no document history";
    }
    $noSuper = !(int)val("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1");
    $r['super_admins'] = (int)val("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1 AND mfa_enabled=1");
    $r['mfa_pending'] = (int)val("SELECT COUNT(*) FROM users WHERE active=1 AND mfa_enabled=0 AND role IN ('" . implode("','", MFA_REQUIRED) . "')");
    $r['mfa_pending_not_link_only'] = (int)val("SELECT COUNT(*) FROM users WHERE active=1 AND mfa_enabled=0 AND mfa_token_only=0 AND role IN ('" . implode("','", MFA_REQUIRED) . "')");
    if ($r['mfa_pending_not_link_only']) $r['problems'][] = $r['mfa_pending_not_link_only'] . ' admin-level account(s) without two-step login could set it up with a password alone';
    [$n, $broken, $legacy, $anchor] = audit_verify();
    $r['audit'] = ['rows' => $n, 'legacy' => $legacy, 'broken_at' => $broken, 'anchor' => $anchor];
    if ($broken) $r['problems'][] = 'activity log chain breaks at row #' . $broken;
    if ($anchor) $r['problems'][] = 'activity log checkpoint: ' . $anchor;
    $r['note'] = $noSuper ? 'No super admin yet: the owner claims it with install.php after the upgrade.' : '';
    return $r;
}
function health_text(array $h): string {
    $o = "Schema version: {$h['schema']}\nRows: " . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($h['counts']), $h['counts'])) . "\n";
    foreach ($h['encrypted'] as $k => $v) $o .= sprintf("  %-22s %d stored, %d encrypted, %d readable, %d unreadable\n", $k, $v['tot'], $v['sealed'], $v['plain'], $v['bad']);
    $o .= 'ID images: ' . ($h['id_files']['total'] ?? 0) . ' (' . ($h['id_files']['not_encrypted'] ?? 0) . " not encrypted) · document versions: " . ($h['documents'] ?? 0) . "\n";
    $o .= "Activity log: {$h['audit']['rows']} rows ({$h['audit']['legacy']} from before the chain)\n";
    $o .= "Super admins with two-step: {$h['super_admins']} · admin-level accounts still to set up two-step (link only): {$h['mfa_pending']}\n";
    if ($h['note']) $o .= $h['note'] . "\n";
    $o .= $h['problems'] ? "PROBLEMS:\n  - " . implode("\n  - ", $h['problems']) . "\n" : "RESULT: all checks passed\n";
    return $o;
}
