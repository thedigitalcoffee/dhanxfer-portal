<?php
defined('DX') or exit;
/*
 * Beneficiaries (the people a client sends money to).
 * - Every new or changed beneficiary waits for review ("pending") before it can receive a transfer.
 * - A reviewer with beneficiaries.review records the sanctions screening reference and how the details were
 *   verified, then approves or rejects. Approved details are never changed in place: an edit makes a new version
 *   that is reviewed again, so every transfer keeps pointing at the exact details that were approved.
 * - Four-eyes: whoever added or last changed a beneficiary cannot approve it (setting benef_four_eyes).
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
/** Cleans and checks one beneficiary. Returns [values, errors]. $keptAccount: the account on file is kept as it is (older records may not match today's format). */
function benef_clean(array $in, bool $keptAccount = false): array {
    $v = [];
    foreach (BENEF_FIELDS as $k) $v[$k] = $k === 'account' && $keptAccount ? (string)($in[$k] ?? '') : mb_substr(trim((string)($in[$k] ?? '')), 0, $k === 'name' ? 160 : 120);
    $e = [];
    if ($v['name'] === '' || mb_strlen($v['name']) < 2) $e[] = 'Beneficiary full name is required.';
    if (!$keptAccount && $v['account'] !== '' && !preg_match('/^[A-Za-z0-9 \-]{4,40}$/', $v['account'])) $e[] = 'The account number / IBAN may only hold letters, digits, spaces and dashes (4–40).';
    if ($v['account'] !== '' && $v['bank'] === '') $e[] = 'Enter the bank name for the account.';
    return [$v, $e];
}
/** Adds a beneficiary (status pending). Returns [id|null, errors]. The check and insert run under one lock per client. */
function benef_add(int $clientId, array $in, string $source, ?int $by, ?int $replaces = null): array {
    [$v, $e] = benef_clean($in, $replaces !== null && !empty($in['_kept']));
    if ($e) return [null, $e];
    $lock = lock_name('dx_benef_' . $clientId);
    if ((int)val('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) return [null, ['Another change to this client is being saved. Try again.']];
    try {
        $max = max(1, (int)setting('benef_max_per_client', '10'));
        $count = (int)val("SELECT COUNT(*) FROM beneficiaries WHERE client_id=? AND status IN ('pending','active')" . ($replaces ? ' AND id<>' . (int)$replaces : ''), [$clientId]);
        if ($count >= $max) return [null, ["A client can have at most $max active beneficiaries. Deactivate one first."]];
        $h = benef_account_hash($v['account']);
        $not = $replaces ? ' AND id<>' . (int)$replaces : '';
        if ($h && val("SELECT 1 FROM beneficiaries WHERE client_id=? AND account_hash=? AND status IN ('pending','active')$not", [$clientId, $h])) return [null, ['This account is already saved for this client.']];
        if ($h === null && val("SELECT 1 FROM beneficiaries WHERE client_id=? AND name=? AND COALESCE(country,'')=? AND status IN ('pending','active')$not", [$clientId, $v['name'], $v['country']])) return [null, ['A beneficiary with this name and country is already saved for this client.']];
        q("INSERT INTO beneficiaries (client_id, name, relation, country, bank, account, phone, status, account_hash, source, created_by, created_at) VALUES (?,?,?,?,?,?,?,'pending',?,?,?,NOW())",
          [$clientId, $v['name'], $v['relation'] ?: null, $v['country'] ?: null, $v['bank'] ?: null, enc($v['account'] ?: null), $v['phone'] ?: null, $h, $source, $by]);
        $id = (int)db()->lastInsertId();
    } finally { val('SELECT RELEASE_LOCK(?)', [$lock]); }
    benef_event($id, $clientId, 'added', null, $replaces ? 'Changed details of #' . $replaces : 'Source: ' . $source, $by);
    return [$id, []];
}
/**
 * Edits a beneficiary. Returns [id of the current version|false, errors].
 * - Waiting for review: changed in place (the version counter goes up, so a reviewer cannot approve details they did not see).
 * - Approved: the reviewed details are never overwritten, because transfers already point to them. Any change makes a new
 *   version that waits for review; the old one is deactivated and points to the new one.
 * - Rejected or deactivated: cannot be edited (add it again so it is screened again).
 */
function benef_update(array $b, array $in, ?int $by): array {
    if ($b['status'] === 'deactivated') return [false, ['Reactivating is not possible: add the beneficiary again so it is reviewed.']];
    if ($b['status'] === 'rejected') return [false, ['A rejected beneficiary cannot be edited. Add it again if the details were wrong: it will be screened again and the reviewer sees the earlier rejection.']];
    $kept = trim((string)($in['account'] ?? '')) === '';
    if ($kept) {
        $acct = dec($b['account']);
        if ($acct === null && (string)$b['account'] !== '') return [false, ['The account number on file cannot be opened right now (encryption keys unavailable). Nothing was changed.']];
        $in['account'] = (string)$acct;
    }
    [$v, $e] = benef_clean($in, $kept);
    if ($e) return [false, $e];
    $old = $b; $old['account'] = (string)(dec($b['account']) ?? '');
    $changed = array_values(array_filter(BENEF_FIELDS, fn($k) => trim((string)($old[$k] ?? '')) !== trim($v[$k])));
    if (!$changed) return [(int)$b['id'], []];
    if ($b['status'] === 'active') {
        [$nid, $e] = benef_add((int)$b['client_id'], array_merge($v, ['_kept' => $kept]), (string)($b['source'] ?: 'admin'), $by, (int)$b['id']);
        if (!$nid) return [false, $e];
        if (!q("UPDATE beneficiaries SET status='deactivated', replaced_by=?, updated_by=?, updated_at=NOW() WHERE id=? AND status='active'", [$nid, $by, $b['id']])->rowCount()) {
            if (q("DELETE FROM beneficiaries WHERE id=? AND status='pending'", [$nid])->rowCount()) q('DELETE FROM beneficiary_events WHERE beneficiary_id=?', [$nid]);
            return [false, ['This beneficiary was changed by someone else just now. Open it again.']];
        }
        benef_event((int)$b['id'], (int)$b['client_id'], 'replaced', implode(',', $changed), 'New version #' . $nid . ' waits for review', $by);
        benef_event($nid, (int)$b['client_id'], 'edited', implode(',', $changed), 'Changed from #' . $b['id'], $by);
        return [$nid, []];
    }
    $h = benef_account_hash($v['account']);
    if ($h && val("SELECT 1 FROM beneficiaries WHERE client_id=? AND account_hash=? AND id<>? AND status IN ('pending','active')", [$b['client_id'], $h, $b['id']])) return [false, ['This account is already saved for this client.']];
    $n = q("UPDATE beneficiaries SET name=?, relation=?, country=?, bank=?, account=?, phone=?, account_hash=?, rev=rev+1, updated_by=?, updated_at=NOW() WHERE id=? AND status='pending' AND rev=?",
      [$v['name'], $v['relation'] ?: null, $v['country'] ?: null, $v['bank'] ?: null, enc($v['account'] ?: null), $v['phone'] ?: null, $h, $by, $b['id'], (int)$b['rev']])->rowCount();
    if (!$n) return [false, ['This beneficiary was decided or changed by someone else just now. Open it again.']];
    benef_event((int)$b['id'], (int)$b['client_id'], 'edited', implode(',', $changed), null, $by);
    return [(int)$b['id'], []];
}
function benef_deactivate(array $b, string $reason, ?int $by): bool {
    if ($b['status'] === 'deactivated') return false;
    if (!q("UPDATE beneficiaries SET status='deactivated', updated_by=?, updated_at=NOW() WHERE id=? AND status<>'deactivated'", [$by, $b['id']])->rowCount()) return false;
    benef_event((int)$b['id'], (int)$b['client_id'], 'deactivated', null, $reason ?: null, $by);
    return true;
}
/** Points the reviewer should look at (no decision is made automatically). */
function benef_flags(array $b): array {
    $f = [];
    if ($b['account_hash']) {
        $n = (int)val("SELECT COUNT(DISTINCT client_id) FROM beneficiaries WHERE account_hash=? AND client_id<>? AND status IN ('pending','active')", [$b['account_hash'], $b['client_id']]);
        if ($n) $f[] = "The same account is saved for $n other client(s).";
        $r = (int)val("SELECT COUNT(*) FROM beneficiaries WHERE account_hash=? AND id<>? AND status='rejected'", [$b['account_hash'], $b['id']]);
        if ($r) $f[] = "This account was rejected before ($r earlier review(s)). Read those notes before deciding.";
    } else $f[] = 'No account number: cash pickup or wallet. Confirm how the money is collected and who holds the phone.';
    $rn = (int)val("SELECT COUNT(*) FROM beneficiaries WHERE name=? AND id<>? AND status='rejected'", [$b['name'], $b['id']]);
    if ($rn) $f[] = "A beneficiary with this name was rejected before ($rn time(s)).";
    $hr = array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)setting('benef_high_risk_countries', ''))));
    if ($b['country'] && in_array(mb_strtolower($b['country']), $hr, true)) $f[] = 'Country is on the high-risk list in the compliance settings.';
    $c = one('SELECT name FROM clients WHERE id=?', [$b['client_id']]);
    if ($c && mb_strtolower(trim($c['name'])) === mb_strtolower(trim($b['name'])) && !$b['relation']) $f[] = 'Same name as the client: sending to themselves? Confirm the relationship.';
    $others = (int)val("SELECT COUNT(DISTINCT client_id) FROM beneficiaries WHERE name=? AND client_id<>?", [$b['name'], $b['client_id']]);
    if ($others >= 3) $f[] = "This name receives money from $others other clients.";
    return $f;
}
/** Review decision. $in: decision approve|reject, screening_ref, verification, notes, rev (the version the reviewer saw). */
function benef_review(array $b, array $reviewer, array $in): array {
    if (!role_can($reviewer['role'], 'beneficiaries.review')) return [false, ['Your role cannot review beneficiaries.']];
    if ($b['status'] !== 'pending') return [false, ['Only beneficiaries waiting for review can be decided.']];
    if (!isset($in['rev']) || (string)$in['rev'] !== (string)$b['rev']) return [false, ['The details changed while you were reviewing. Check them again before deciding.']];
    if (setting('benef_four_eyes', '1') === '1' && in_array((int)$reviewer['id'], benef_editors($b), true))
        return [false, ['You added or changed this beneficiary, so another reviewer must decide it (four-eyes rule in the compliance settings).']];
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
    // only one decision, and only on the exact version the reviewer saw
    $ok = q("UPDATE beneficiaries SET status=?, screening_ref=?, verification=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending' AND rev=?",
            [$d === 'approve' ? 'active' : 'rejected', mb_substr(trim((string)($in['screening_ref'] ?? '')), 0, 120) ?: null, $d === 'approve' ? $in['verification'] : null, $reviewer['id'], $b['id'], (int)$b['rev']])->rowCount();
    if (!$ok) return [false, ['Someone else just changed or decided this beneficiary. Open it again.']];
    benef_event((int)$b['id'], (int)$b['client_id'], $d === 'approve' ? 'approved' : 'rejected', null, $notes, (int)$reviewer['id']);
    return [true, []];
}
/** Everyone who added or changed this version (four-eyes: none of them may decide it). */
function benef_editors(array $b): array {
    $ids = array_map('intval', array_filter([$b['created_by'] ?? null, $b['updated_by'] ?? null]));
    foreach (all("SELECT DISTINCT user_id FROM beneficiary_events WHERE beneficiary_id=? AND action IN ('added','edited') AND user_id IS NOT NULL", [$b['id']]) as $r) $ids[] = (int)$r['user_id'];
    return array_values(array_unique($ids));
}
/** Beneficiary can receive a transfer now? Returns [ok, reason]. */
function benef_usable(?array $b, int $clientId): array {
    if (!$b || (int)$b['client_id'] !== $clientId) return [false, 'Choose a beneficiary saved for this client.'];
    if ($b['status'] !== 'active') return [false, 'The beneficiary is ' . strtolower(BENEF_STATUSES[$b['status']] ?? $b['status']) . ($b['replaced_by'] ?? null ? ' (its details were changed: new version #' . (int)$b['replaced_by'] . ')' : '') . '.'];
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
