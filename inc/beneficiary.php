<?php
defined('DX') or exit;
/*
 * Beneficiaries (the people a client sends money to).
 * - Every new or changed beneficiary waits for review ("pending") before it can receive a transfer.
 * - A reviewer with beneficiaries.review records the sanctions screening reference and how the details were
 *   verified, then approves or rejects. Changing the name, bank, account or country sends it back to review.
 * - Beneficiaries are never deleted once added: they are deactivated, and every step is kept in
 *   beneficiary_events (field names only, never account numbers) and the activity log.
 * - Duplicate checks: the same account twice for one client is refused; the same account under another client
 *   is shown to the reviewer.
 */

const BENEF_STATUSES = ['pending' => 'Waiting for review', 'active' => 'Approved', 'rejected' => 'Rejected', 'deactivated' => 'Deactivated'];
const BENEF_VERIFY_METHODS = ['Confirmed with the client by phone or in person', 'Bank document or statement seen', 'Earlier successful transfer to this account', 'Other (explain in notes)'];
const BENEF_FIELDS = ['name', 'relation', 'country', 'bank', 'account', 'phone'];

/** Duplicate-check key for an account: letters and digits only, upper case, keyed hash (not reversible). */
function benef_account_hash(?string $account): ?string {
    $n = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$account));
    return $n === '' ? null : hash_hmac('sha256', 'benef:' . $n, sec_key('index'));
}
function benef_event(int $bid, int $cid, string $action, ?string $fields, ?string $note, ?int $by): void {
    q('INSERT INTO beneficiary_events (beneficiary_id, client_id, action, fields, note, user_id) VALUES (?,?,?,?,?,?)', [$bid, $cid, $action, $fields, $note ? mb_substr($note, 0, 500) : null, $by]);
    audit_write($by, 'beneficiary_' . $action, 'client', $cid, 'Beneficiary #' . $bid . ($fields ? ' · ' . $fields : ''), $by ? client_ip() : 'system');
}
/** Cleans and checks one beneficiary. Returns [values, errors]. */
function benef_clean(array $in): array {
    $v = [];
    foreach (BENEF_FIELDS as $k) $v[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, $k === 'name' ? 160 : 120);
    $e = [];
    if ($v['name'] === '' || mb_strlen($v['name']) < 2) $e[] = 'Beneficiary full name is required.';
    if ($v['account'] !== '' && !preg_match('/^[A-Za-z0-9 \-]{4,40}$/', $v['account'])) $e[] = 'The account number / IBAN may only hold letters, digits, spaces and dashes (4–40).';
    if ($v['account'] !== '' && $v['bank'] === '') $e[] = 'Enter the bank name for the account.';
    return [$v, $e];
}
/** Adds a beneficiary (status pending). Returns [id|null, errors]. */
function benef_add(int $clientId, array $in, string $source, ?int $by): array {
    [$v, $e] = benef_clean($in);
    if ($e) return [null, $e];
    $max = (int)setting('benef_max_per_client', '10');
    if ((int)val("SELECT COUNT(*) FROM beneficiaries WHERE client_id=? AND status IN ('pending','active')", [$clientId]) >= $max) return [null, ["A client can have at most $max active beneficiaries. Deactivate one first."]];
    $h = benef_account_hash($v['account']);
    if ($h && val("SELECT 1 FROM beneficiaries WHERE client_id=? AND account_hash=? AND status IN ('pending','active')", [$clientId, $h])) return [null, ['This account is already saved for this client.']];
    if ($h === null && val("SELECT 1 FROM beneficiaries WHERE client_id=? AND name=? AND COALESCE(country,'')=? AND status IN ('pending','active')", [$clientId, $v['name'], $v['country']])) return [null, ['A beneficiary with this name and country is already saved for this client.']];
    q("INSERT INTO beneficiaries (client_id, name, relation, country, bank, account, phone, status, account_hash, source, created_by, created_at) VALUES (?,?,?,?,?,?,?,'pending',?,?,?,NOW())",
      [$clientId, $v['name'], $v['relation'] ?: null, $v['country'] ?: null, $v['bank'] ?: null, enc($v['account'] ?: null), $v['phone'] ?: null, $h, $source, $by]);
    $id = (int)db()->lastInsertId();
    benef_event($id, $clientId, 'added', null, 'Source: ' . $source, $by);
    return [$id, []];
}
/** Edits a beneficiary. A change to who or where the money goes sends it back to review. Account left empty = keep. */
function benef_update(array $b, array $in, ?int $by): array {
    if ($b['status'] === 'deactivated') return [false, ['Reactivating is not possible: add the beneficiary again so it is reviewed.']];
    if (trim((string)($in['account'] ?? '')) === '') $in['account'] = (string)dec($b['account']);
    [$v, $e] = benef_clean($in);
    if ($e) return [false, $e];
    $h = benef_account_hash($v['account']);
    if ($h && val("SELECT 1 FROM beneficiaries WHERE client_id=? AND account_hash=? AND id<>? AND status IN ('pending','active')", [$b['client_id'], $h, $b['id']])) return [false, ['This account is already saved for this client.']];
    $old = $b; $old['account'] = (string)dec($b['account']);
    $changed = array_values(array_filter(BENEF_FIELDS, fn($k) => trim((string)($old[$k] ?? '')) !== $v[$k]));
    if (!$changed) return [true, []];
    $material = array_intersect($changed, ['name', 'country', 'bank', 'account']);
    q('UPDATE beneficiaries SET name=?, relation=?, country=?, bank=?, account=?, phone=?, account_hash=?, updated_at=NOW()' . ($material ? ", status='pending', reviewed_by=NULL, reviewed_at=NULL" : '') . ' WHERE id=?',
      [$v['name'], $v['relation'] ?: null, $v['country'] ?: null, $v['bank'] ?: null, enc($v['account'] ?: null), $v['phone'] ?: null, $h, $b['id']]);
    benef_event((int)$b['id'], (int)$b['client_id'], 'edited', implode(',', $changed), $material ? 'Back to review' : null, $by);
    return [true, []];
}
function benef_deactivate(array $b, string $reason, ?int $by): bool {
    if ($b['status'] === 'deactivated') return false;
    q("UPDATE beneficiaries SET status='deactivated', updated_at=NOW() WHERE id=?", [$b['id']]);
    benef_event((int)$b['id'], (int)$b['client_id'], 'deactivated', null, $reason ?: null, $by);
    return true;
}
/** Points the reviewer should look at (no decision is made automatically). */
function benef_flags(array $b): array {
    $f = [];
    if ($b['account_hash']) {
        $n = (int)val("SELECT COUNT(DISTINCT client_id) FROM beneficiaries WHERE account_hash=? AND client_id<>? AND status IN ('pending','active')", [$b['account_hash'], $b['client_id']]);
        if ($n) $f[] = "The same account is saved for $n other client(s).";
    } else $f[] = 'No account number: cash pickup or wallet. Confirm how the money is collected.';
    $hr = array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)setting('benef_high_risk_countries', ''))));
    if ($b['country'] && in_array(mb_strtolower($b['country']), $hr, true)) $f[] = 'Country is on the high-risk list in the compliance settings.';
    $c = one('SELECT name FROM clients WHERE id=?', [$b['client_id']]);
    if ($c && mb_strtolower(trim($c['name'])) === mb_strtolower(trim($b['name'])) && !$b['relation']) $f[] = 'Same name as the client: sending to themselves? Confirm the relationship.';
    $others = (int)val("SELECT COUNT(DISTINCT client_id) FROM beneficiaries WHERE name=? AND client_id<>?", [$b['name'], $b['client_id']]);
    if ($others >= 3) $f[] = "This name receives money from $others other clients.";
    return $f;
}
/** Review decision. $in: decision approve|reject, screening_ref, verification, notes. */
function benef_review(array $b, array $reviewer, array $in): array {
    if (!role_can($reviewer['role'], 'beneficiaries.review')) return [false, ['Your role cannot review beneficiaries.']];
    if ($b['status'] !== 'pending') return [false, ['Only beneficiaries waiting for review can be decided.']];
    $d = (string)($in['decision'] ?? '');
    $notes = trim((string)($in['notes'] ?? ''));
    $e = [];
    if (!in_array($d, ['approve', 'reject'], true)) $e[] = 'Choose approve or reject.';
    if (mb_strlen($notes) < 10) $e[] = 'Write review notes (at least 10 characters).';
    if ($d === 'approve') {
        if (trim((string)($in['screening_ref'] ?? '')) === '') $e[] = 'Enter the sanctions screening reference for this beneficiary.';
        if (!in_array($in['verification'] ?? '', BENEF_VERIFY_METHODS, true)) $e[] = 'Choose how the beneficiary details were verified.';
        $c = one('SELECT kyc FROM clients WHERE id=?', [$b['client_id']]);
        if (!$c || $c['kyc'] === 'Rejected') $e[] = 'The client\'s KYC was rejected.';
    }
    if ($e) return [false, $e];
    // only one reviewer can decide: the row must still be pending when we write
    $ok = q("UPDATE beneficiaries SET status=?, screening_ref=?, verification=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'",
            [$d === 'approve' ? 'active' : 'rejected', mb_substr(trim((string)($in['screening_ref'] ?? '')), 0, 120) ?: null, $d === 'approve' ? $in['verification'] : null, $reviewer['id'], $b['id']])->rowCount();
    if (!$ok) return [false, ['Someone else just decided this beneficiary.']];
    benef_event((int)$b['id'], (int)$b['client_id'], $d === 'approve' ? 'approved' : 'rejected', null, $notes, (int)$reviewer['id']);
    return [true, []];
}
/** Beneficiary can receive a transfer now? Returns [ok, reason]. */
function benef_usable(?array $b, int $clientId): array {
    if (!$b || (int)$b['client_id'] !== $clientId) return [false, 'Choose a beneficiary saved for this client.'];
    if ($b['status'] !== 'active') return [false, 'The beneficiary is ' . strtolower(BENEF_STATUSES[$b['status']] ?? $b['status']) . '.'];
    return [true, ''];
}
/** Reads the repeated beneficiary blocks of a form (b_name[] …). */
function benef_from_post(int $max = 10): array {
    $out = [];
    $names = $_POST['b_name'] ?? [];
    if (!is_array($names)) return [];
    foreach ($names as $i => $n) {
        if (!is_string($n) || trim($n) === '') continue;
        $row = [];
        foreach (BENEF_FIELDS as $k) $row[$k] = is_string($_POST['b_' . $k][$i] ?? null) ? $_POST['b_' . $k][$i] : '';
        $out[] = $row;
        if (count($out) >= $max) break;
    }
    return $out;
}
function benef_pill(string $s): string {
    $c = ['pending' => 'p-warn', 'active' => 'p-ok', 'rejected' => 'p-bad', 'deactivated' => 'p-mute'][$s] ?? 'p-mute';
    return '<span class="pill ' . $c . '">' . e(BENEF_STATUSES[$s] ?? $s) . '</span>';
}
/** One-time upgrade to schema 8. */
function benef_migrate_v8(): void {
    foreach (all("SELECT id, client_id, account FROM beneficiaries WHERE account_hash IS NULL AND account IS NOT NULL AND account<>''") as $b)
        q('UPDATE beneficiaries SET account_hash=? WHERE id=?', [benef_account_hash((string)dec($b['account'])), $b['id']]);
    q("UPDATE beneficiaries SET source=COALESCE(source,'migration'), created_at=COALESCE(created_at,NOW())");
}
