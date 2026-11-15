<?php
defined('DX') or exit;
require_once __DIR__ . '/address.php';
require_once __DIR__ . '/phone.php';
/*
 * KYC: identity documents with full version history, the structured review (approve / request information /
 * reject / escalate) and the transfer-eligibility policy. The only way a client becomes "Verified" is
 * kyc_decide() with every prerequisite met; the checks are here on the server, not only on the screen.
 */

/** Checklist the reviewer must complete before approving. key => text. */
const KYC_CHECKLIST = [
    'id_original' => 'I saw the original ID (in person or a clear, complete image of every page with details)',
    'name_match' => 'Name on the ID matches the client record',
    'dob_match' => 'Date of birth on the ID matches the client record',
    'photo_match' => 'Photo on the ID matches the client (in person or live video)',
    'doc_valid' => 'ID is genuine-looking, not altered, and not expired',
    'address_checked' => 'Home address checked (matches the ID, or proof of address seen)',
    'screening' => 'Sanctions / PEP screening done (reference below)',
    'purpose_ok' => 'Purpose, source of funds and expected amounts are reasonable for this client',
];
const KYC_DECISIONS = ['approve' => 'Approve', 'request_info' => 'Request more information', 'reject' => 'Reject', 'escalate' => 'Escalate'];
const DOC_TYPES_NO_EXPIRY = []; // every accepted ID type has an expiry date; leave empty unless compliance approves an exception

/* ---------- documents ---------- */

/** Current identity document version for a client, or null. */
function doc_current(int $clientId): ?array {
    return one("SELECT v.*, d.doc_kind FROM client_documents d JOIN document_versions v ON v.id=d.current_version_id
                WHERE d.client_id=? AND d.doc_kind='identity' AND d.status='current' ORDER BY v.id DESC LIMIT 1", [$clientId]);
}
function doc_history(int $clientId): array {
    return all('SELECT v.*, d.doc_kind, u.name AS uploaded_by_name FROM document_versions v JOIN client_documents d ON d.id=v.document_id
                LEFT JOIN users u ON u.id=v.uploaded_by WHERE v.client_id=? ORDER BY v.id DESC', [$clientId]);
}
/**
 * Adds a new version of the client's identity document (the old versions stay as history).
 * $d: doc_type, number (plain), issuing_country, expires_on, has_address (bool|null), address (array|null), file_name.
 */
/** Raised when an identity document number already belongs to another client (one KYC identity per sender). */
class IdNumberTaken extends RuntimeException {}
/** The client (other than $clientId) that already holds this ID number, now or in its document history. */
function id_number_owner(string $num, int $clientId): ?array {
    $h = trim($num) === '' ? null : id_hash(trim($num));
    if (!$h) return null;
    return one('SELECT id, client_no, name, agent_id FROM clients WHERE id<>? AND (id_number_hash=? OR id IN (SELECT client_id FROM document_versions WHERE number_hash=?)) ORDER BY id LIMIT 1', [$clientId, $h, $h]);
}
/** Runs $fn while holding the ID-number lock, so two saves of the same number at the same moment cannot both pass the check. */
function with_id_lock(callable $fn) {
    $l = lock_name('dx_idnum');
    if ((int)val('SELECT GET_LOCK(?, 15)', [$l]) !== 1) throw new RuntimeException('Another identity is being saved. Try again in a moment.');
    try { return $fn(); } finally { val('SELECT RELEASE_LOCK(?)', [$l]); }
}
function doc_add_version(int $clientId, array $d, string $source, ?int $by, string $kind = 'identity'): int {
    $num = trim((string)($d['number'] ?? ''));
    if ($kind === 'identity' && $num !== '') return with_id_lock(function () use ($clientId, $d, $source, $by, $kind, $num) {
        if (id_number_owner($num, $clientId)) throw new IdNumberTaken('This ID number already belongs to another client record. Every sender needs their own identity.');
        return doc_add_version_locked($clientId, $d, $source, $by, $kind);
    });
    return doc_add_version_locked($clientId, $d, $source, $by, $kind);
}
function doc_add_version_locked(int $clientId, array $d, string $source, ?int $by, string $kind): int {
    $doc = one("SELECT * FROM client_documents WHERE client_id=? AND doc_kind=? AND status='current' ORDER BY id DESC LIMIT 1", [$clientId, $kind]);
    if (!$doc) { q('INSERT INTO client_documents (client_id, doc_kind, doc_type) VALUES (?,?,?)', [$clientId, $kind, $d['doc_type'] ?? null]); $docId = (int)db()->lastInsertId(); $ver = 1; }
    else { $docId = (int)$doc['id']; $ver = (int)val('SELECT COALESCE(MAX(version),0)+1 FROM document_versions WHERE document_id=?', [$docId]); }
    $num = trim((string)($d['number'] ?? ''));
    $file = $d['file_name'] ?? null;
    if (!$file && $doc && $num !== '') { // same document, details corrected: keep its image
        $prev = one('SELECT file_name, number_hash FROM document_versions WHERE id=?', [$doc['current_version_id']]);
        if ($prev && $prev['number_hash'] === id_hash($num)) $file = $prev['file_name'];
    }
    q('INSERT INTO document_versions (document_id, client_id, version, doc_type, number_enc, number_hash, issuing_country, expires_on, has_address, address_json, file_name, file_sha256, source, uploaded_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$docId, $clientId, $ver, $d['doc_type'] ?? null, $num !== '' ? enc($num) : null, $num !== '' ? id_hash($num) : null, $d['issuing_country'] ?: null,
       ($d['expires_on'] ?? '') ?: null, isset($d['has_address']) && $d['has_address'] !== null ? (int)(bool)$d['has_address'] : null,
       !empty($d['address']) ? json_encode($d['address'], JSON_UNESCAPED_UNICODE) : null, $file,
       $file ? hash('sha256', (string)id_file_read($file)) : null, $source, $by]);
    $vid = (int)db()->lastInsertId();
    q('UPDATE client_documents SET current_version_id=?, doc_type=? WHERE id=?', [$vid, $d['doc_type'] ?? null, $docId]);
    if ($kind === 'identity') doc_sync_client($clientId);
    return $vid;
}
/** Keeps the summary columns on the client row (lists, search, masking) in step with the current ID version. */
function doc_sync_client(int $clientId): void {
    $v = doc_current($clientId);
    if (!$v) return;
    $c = one('SELECT address, city, province, postal, country FROM clients WHERE id=?', [$clientId]);
    $idAddr = $v['address_json'] ? json_decode($v['address_json'], true) : null;
    $home = ['line1' => $c['address'] ?? '', 'city' => $c['city'] ?? '', 'province' => $c['province'] ?? '', 'postal' => $c['postal'] ?? '', 'country' => $c['country'] ?? ''];
    $match = $v['has_address'] === null ? null : addr_compare($home, (int)$v['has_address'] === 1 ? ($idAddr ?: $home) : null)['result'];
    q('UPDATE clients SET id_type=?, id_number=?, id_number_hash=?, id_country=?, id_expiry=?, id_file=COALESCE(?, id_file), id_has_address=?, id_address_json=?, address_match=?, updated_at=NOW() WHERE id=?',
      [$v['doc_type'], $v['number_enc'], $v['number_hash'], $v['issuing_country'], $v['expires_on'], $v['file_name'], $v['has_address'], $v['address_json'], $match, $clientId]);
}

/** Identity details of a verified client changed (name, date of birth, address, phone…): it needs a new review. */
function kyc_identity_changed(int $clientId, string $what, ?int $by): void {
    if (q("UPDATE clients SET kyc='Pending', updated_at=NOW() WHERE id=? AND kyc='Verified'", [$clientId])->rowCount())
        audit_write($by, 'kyc_reopened', 'client', $clientId, 'Identity details changed (' . $what . '): needs a new KYC review', $by ? client_ip() : 'system');
    doc_sync_client($clientId);
}

/* ---------- transfer eligibility (backend policy) ---------- */

/** Returns [allowed, reasons]. Used when a transfer is requested and again when it is accepted. */
function transfer_eligibility(array $c): array {
    $r = [];
    if ($c['kyc'] === 'Rejected') $r[] = 'KYC was rejected.';
    elseif (setting('block_unverified', '1') === '1' && $c['kyc'] !== 'Verified') $r[] = 'KYC is not approved yet (' . $c['kyc'] . ').';
    $v = doc_current((int)$c['id']);
    // the ID in use must itself have been approved: a newer, unreviewed upload does not count
    if (setting('block_unverified', '1') === '1' && $c['kyc'] === 'Verified' && (!$v || $v['outcome'] !== 'verified')) $r[] = 'The current ID has not been reviewed yet.';
    $exp = $v['expires_on'] ?? ($c['id_expiry'] ?? null);
    if (setting('block_expired_doc', '1') === '1') {
        if (!$exp) $r[] = 'No ID expiry date on file.';
        elseif (strtotime($exp . ' +' . (int)setting('expired_grace_days', '0') . ' days') < strtotime(today())) $r[] = 'The ID on file has expired.';
    }
    if (setting('block_rekyc_overdue', '1') === '1' && val("SELECT 1 FROM rekyc_cases WHERE client_id=? AND status IN ('required','in_progress','escalated') AND due_date < CURDATE() LIMIT 1", [$c['id']]))
        $r[] = 'A KYC refresh is overdue.';
    return [!$r, $r];
}

/* ---------- the review decision ---------- */

/**
 * Records a KYC decision. $in: decision, checklist (keys ticked), risk, notes, evidence, address_evidence, screening_ref, code (two-step code).
 * Every rule is enforced here. Returns [ok, errors, reviewId].
 */
function kyc_decide(array $client, array $reviewer, array $in): array {
    require_once __DIR__ . '/mfa.php';
    $e = [];
    $decision = (string)($in['decision'] ?? '');
    if (!isset(KYC_DECISIONS[$decision])) return [false, ['Choose a decision.'], null];
    if (!role_can($reviewer['role'], 'kyc.decide')) return [false, ['Your role cannot make KYC decisions.'], null];
    if (!can_see_client($client)) return [false, ['You cannot open this client.'], null];
    // explicit authorization: the reviewer confirms with a fresh two-step code
    $fresh = one('SELECT * FROM users WHERE id=? AND active=1', [$reviewer['id']]);
    if (!$fresh || !(int)$fresh['mfa_enabled']) return [false, ['Two-step login must be on for KYC decisions.'], null];
    if ($lock = mfa_locked((int)$fresh['id'])) return [false, [$lock], null];
    if (!mfa_check($fresh, (string)($in['code'] ?? ''))) { mfa_fail((int)$fresh['id']); return [false, ['The two-step code did not match. Use the newest code from your app.'], null]; }

    $notes = trim((string)($in['notes'] ?? ''));
    $evidence = trim((string)($in['evidence'] ?? ''));
    $risk = in_array($in['risk'] ?? '', RISK, true) ? $in['risk'] : null;
    $ticked = array_values(array_intersect(array_keys(KYC_CHECKLIST), (array)($in['checklist'] ?? [])));
    if (mb_strlen($notes) < 15) $e[] = 'Write decision notes (at least 15 characters).';
    $ver = doc_current((int)$client['id']);
    $addrRes = $client['address_match'] ?? null;
    if ($decision === 'approve') {
        if ($client['kyc'] === 'Verified' && !val("SELECT 1 FROM rekyc_cases WHERE client_id=? AND status IN ('required','in_progress','escalated')", [$client['id']])) $e[] = 'This client is already verified and has no open KYC refresh.';
        if (!$ver) $e[] = 'No identity document on file.';
        else {
            if (!$ver['number_enc']) $e[] = 'The ID number is missing.';
            if (!$ver['file_name'] || id_file_read((string)$ver['file_name']) === null) $e[] = 'No readable image or scan of the ID is on file.';
            if (!$ver['expires_on'] && !in_array($ver['doc_type'], DOC_TYPES_NO_EXPIRY, true)) $e[] = 'The ID expiry date is missing.';
            elseif ($ver['expires_on'] && $ver['expires_on'] <= today()) $e[] = 'The ID has expired. Ask for a current ID.';
            if ($ver['outcome'] === 'rejected') $e[] = 'This document version was rejected earlier. Ask for a new one.';
        }
        if (!$client['dob']) $e[] = 'Date of birth is missing.';
        if ($missing = array_diff(array_keys(KYC_CHECKLIST), $ticked)) $e[] = 'Complete every checklist item (' . count($missing) . ' left).';
        if (!$risk) $e[] = 'Choose a risk level.';
        if (trim((string)($in['screening_ref'] ?? '')) === '') $e[] = 'Enter the sanctions / PEP screening reference.';
        if ($evidence === '') $e[] = 'Enter the evidence reference (where the checked documents are recorded).';
        if (addr_needs_evidence($addrRes) && trim((string)($in['address_evidence'] ?? '')) === '') $e[] = 'The address on the ID ' . ($addrRes === 'no_id_address' ? 'is missing' : 'does not match') . ': record the proof of address used.';
    }
    if ($e) return [false, $e, null];

    $status = ['approve' => 'Verified', 'request_info' => 'Info requested', 'reject' => 'Rejected', 'escalate' => 'Escalated'][$decision];
    $check = [];
    foreach (array_keys(KYC_CHECKLIST) as $k) $check[$k] = in_array($k, $ticked, true);
    $check['screening_ref'] = mb_substr(trim((string)($in['screening_ref'] ?? '')), 0, 120);
    $check['address_evidence'] = mb_substr(trim((string)($in['address_evidence'] ?? '')), 0, 200);
    db()->beginTransaction();
    try {
        // only one decision at a time for a client: the row lock makes a second reviewer wait and re-check
        $now = one('SELECT kyc FROM clients WHERE id=? FOR UPDATE', [$client['id']]);
        $case = one("SELECT * FROM rekyc_cases WHERE client_id=? AND status IN ('required','in_progress','escalated') ORDER BY id LIMIT 1", [$client['id']]);
        if ($decision === 'approve' && $now['kyc'] === 'Verified' && !$case) throw new RuntimeException('Another reviewer just approved this client.');
        q('INSERT INTO kyc_reviews (client_id, case_type, rekyc_case_id, document_version_id, decision, checklist, risk, address_result, notes, evidence, reviewer_id, reviewer_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
          [$client['id'], $case ? 'rekyc' : 'initial', $case['id'] ?? null, $ver['id'] ?? null, $decision, json_encode($check), $risk, $addrRes, mb_substr($notes, 0, 4000), mb_substr($evidence, 0, 500) ?: null, $reviewer['id'], $reviewer['role']]);
        $rid = (int)db()->lastInsertId();
        $next = null;
        if ($decision === 'approve') {
            $months = (int)setting('refresh_months_' . strtolower((string)$risk), '24');
            $next = date('Y-m-d', strtotime(today() . " +$months months"));
            if ($ver) q("UPDATE document_versions SET outcome='verified', outcome_by=?, outcome_at=NOW() WHERE id=?", [$reviewer['id'], $ver['id']]);
            q("UPDATE rekyc_cases SET status='completed', closed_at=NOW(), closed_review_id=? WHERE client_id=? AND status IN ('required','in_progress','escalated')", [$rid, $client['id']]);
        } elseif ($decision === 'reject') {
            if ($ver) q("UPDATE document_versions SET outcome='rejected', outcome_by=?, outcome_at=NOW() WHERE id=?", [$reviewer['id'], $ver['id']]);
        } elseif ($decision === 'escalate' && $case) {
            q("UPDATE rekyc_cases SET status='escalated', escalated_at=NOW() WHERE id=?", [$case['id']]);
        } elseif ($decision === 'request_info' && $case && $case['status'] === 'required') {
            q("UPDATE rekyc_cases SET status='in_progress' WHERE id=?", [$case['id']]);
        }
        q('UPDATE clients SET kyc=?, risk=COALESCE(?, risk), kyc_reviewed_at=NOW(), kyc_reviewed_by=?, kyc_next_review=COALESCE(?, kyc_next_review), updated_at=NOW() WHERE id=?',
          [$status, $risk, $reviewer['id'], $next, $client['id']]);
        db()->commit();
    } catch (Throwable $ex) { if (db()->inTransaction()) db()->rollBack(); return [false, [$ex instanceof RuntimeException ? $ex->getMessage() : 'The decision could not be saved.'], null]; }
    audit_write((int)$reviewer['id'], 'kyc_' . $decision, 'client', $client['id'], $client['client_no'] . ' · review #' . $rid . ' · ' . $reviewer['role'] . ' confirmed with two-step code', client_ip());
    if ($decision === 'escalate') notify('KYC case escalated', 'A KYC review was escalated (review #' . $rid . '). Open the Compliance Center for details.', 'compliance');
    return [true, [], $rid];
}

/* ---------- sub-agent referral codes ----------
 * The readable code is the sub-agent's login ID. Attribution is stored by the agent's internal ID, so renaming
 * never moves past clients. Every code ever used stays reserved for that agent (agent_codes), so an old code
 * cannot be handed to someone else. Signed links carry the internal ID.
 */
function agent_code_register(int $agentId, string $code): void {
    $code = mb_strtolower(trim($code));
    $owner = val('SELECT agent_id FROM agent_codes WHERE code=?', [$code]);
    if ($owner !== null && (int)$owner !== $agentId) throw new RuntimeException('That referral code belongs to another sub-agent.');
    q('UPDATE agent_codes SET is_current=0, retired_at=COALESCE(retired_at, NOW()) WHERE agent_id=? AND code<>?', [$agentId, $code]);
    q('INSERT INTO agent_codes (agent_id, code, is_current) VALUES (?,?,1) ON DUPLICATE KEY UPDATE is_current=1, retired_at=NULL', [$agentId, $code]);
}
/** Sub-agent for a typed code (current or old), or null. */
function agent_by_code(string $code): ?array {
    $code = mb_strtolower(trim($code));
    if ($code === '') return null;
    $id = val('SELECT agent_id FROM agent_codes WHERE code=?', [$code]);
    return $id ? one("SELECT * FROM users WHERE id=? AND role='agent'", [(int)$id]) : null;
}
/** Signature of the older long link. Regenerating an agent's link raises ref_epoch, so every older long link stops. */
function agent_sig(int $agentId, ?int $epoch = null): string {
    $epoch ??= (int)val('SELECT ref_epoch FROM users WHERE id=?', [$agentId]);
    return substr(hash_hmac('sha256', 'agent:' . $agentId . ($epoch ? ':' . $epoch : ''), sec_key('ref')), 0, 16);
}
/** The agent's link to share: the short /r/{name} link while it is active, otherwise null. */
function agent_link(array $agent): ?string {
    require_once __DIR__ . '/agents.php';
    $l = ref_primary((int)$agent['id']);
    return $l && $l['status'] === 'active' ? ref_short_url($l['slug']) : null;
}
/** Agent for a signed link: new form ?a=ID&s=… or the older ?ref=SA-1001&s=…; null when the signature is wrong. */
function agent_from_link(string $a, string $ref, string $sig): ?array {
    if ($sig === '') return null;
    require_once __DIR__ . '/agents.php';
    $ag = null;
    if ($a !== '' && ctype_digit($a) && hash_equals(agent_sig((int)$a), $sig)) $ag = one("SELECT * FROM users WHERE id=? AND role='agent'", [(int)$a]);
    elseif ($ref !== '' && ref_valid(strtoupper($ref), $sig)) {
        $ag = one("SELECT * FROM users WHERE ref_code=? AND role='agent'", [strtoupper($ref)]);
        if ($ag && (int)$ag['ref_epoch'] > 0) $ag = null; // regenerated: the old SA-#### links stop too
    }
    // older long links follow the agent's status and main short link
    return $ag && ref_still_valid((int)$ag['id'], null) ? $ag : null;
}
/** Changes a sub-agent's login ID (and so their referral code). Old code keeps pointing to the same agent. */
function agent_rename(array $agent, string $new, int $by): void {
    $new = mb_strtolower(trim($new));
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $new)) throw new RuntimeException('Login ID: 3–40 letters, numbers, dot, dash or underscore.');
    if ($new === $agent['username']) return;
    if (val('SELECT 1 FROM users WHERE username=? AND id<>?', [$new, $agent['id']])) throw new RuntimeException('That login ID is taken.');
    $owner = val('SELECT agent_id FROM agent_codes WHERE code=?', [$new]);
    if ($owner !== null && (int)$owner !== (int)$agent['id']) throw new RuntimeException('That name was used by another sub-agent before and stays reserved for them.');
    db()->beginTransaction();
    try {
        agent_code_register((int)$agent['id'], $new);
        q('UPDATE users SET username=? WHERE id=?', [$new, $agent['id']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    audit_write($by, 'agent_renamed', 'user', $agent['id'], $agent['username'] . ' → ' . $new . ' (old code still credits this sub-agent)', client_ip());
}

/* ---------- one-time upgrade to schema 7 ---------- */
function kyc_migrate_v7(): void {
    // ID details already on clients become version 1 of their identity document
    foreach (all("SELECT * FROM clients c WHERE ((c.id_number IS NOT NULL AND c.id_number<>'') OR c.id_file IS NOT NULL OR c.id_expiry IS NOT NULL)
                  AND NOT EXISTS (SELECT 1 FROM client_documents d WHERE d.client_id=c.id)") as $c) {
        q("INSERT INTO client_documents (client_id, doc_kind, doc_type) VALUES (?, 'identity', ?)", [$c['id'], $c['id_type']]);
        $did = (int)db()->lastInsertId();
        q("INSERT INTO document_versions (document_id, client_id, version, doc_type, number_enc, number_hash, issuing_country, expires_on, file_name, source, outcome)
           VALUES (?,?,1,?,?,?,?,?,?, 'migration', ?)", [$did, $c['id'], $c['id_type'], $c['id_number'] ?: null, $c['id_number_hash'], $c['id_country'], $c['id_expiry'], $c['id_file'], $c['kyc'] === 'Verified' ? 'verified' : 'unverified']);
        q('UPDATE client_documents SET current_version_id=? WHERE id=?', [(int)db()->lastInsertId(), $did]);
    }
    // phone numbers in international form where they can be read safely
    foreach (all('SELECT id, phone, country, office FROM clients WHERE phone_e164 IS NULL') as $c) {
        $p = phone_check((string)$c['phone'], phone_region($c['country']) ?? ($c['office'] === 'Dubai' ? 'AE' : 'CA'));
        if ($p['ok']) q('UPDATE clients SET phone_e164=?, phone_region=? WHERE id=?', [$p['e164'], $p['region'], $c['id']]);
    }
    // referral codes: login ID is the current code, the old SA-#### code stays reserved for the same agent
    $agents = all("SELECT id, username, ref_code FROM users WHERE role='agent' ORDER BY id");
    foreach ($agents as $a) q('INSERT IGNORE INTO agent_codes (agent_id, code, is_current) VALUES (?,?,1)', [$a['id'], mb_strtolower($a['username'])]);
    foreach ($agents as $a) if ($a['ref_code']) {
        $own = val('SELECT agent_id FROM agent_codes WHERE code=?', [mb_strtolower($a['ref_code'])]);
        if ($own === null) q('INSERT INTO agent_codes (agent_id, code, is_current, retired_at) VALUES (?,?,0,NOW())', [$a['id'], mb_strtolower($a['ref_code'])]);
        elseif ((int)$own !== (int)$a['id']) audit_write(null, 'agent_code_conflict', 'user', $a['id'], 'Old code ' . $a['ref_code'] . ' equals another agent\'s login ID: not reserved', 'system');
    }
    // clients verified under the old one-click process get a KYC refresh within 30 days
    q("UPDATE clients SET kyc_next_review=DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE kyc='Verified' AND kyc_next_review IS NULL");
    // ID images saved before encryption are encrypted in place
    foreach (glob(ids_dir() . '/*') ?: [] as $f) {
        $n = basename($f);
        if (is_file($f) && preg_match('/^\d{8}_[a-f0-9]{24}\.(jpg|png|webp|pdf|heic)$/', $n) && !id_file_encrypted($n)) {
            $raw = (string)file_get_contents($f);
            if ($raw !== '' && id_file_write($n, $raw) && id_file_read($n) !== $raw) throw new RuntimeException('ID file encryption check failed.');
        }
    }
}
