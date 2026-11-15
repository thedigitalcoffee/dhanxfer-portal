<?php
defined('DX') or exit;
require_once __DIR__ . '/kyc.php';
/*
 * Daily compliance work: document expiry reminders (90/60/30/7 days before and on the day, configurable),
 * KYC refresh cases (expiring/expired documents and the periodic refresh policy), escalation of overdue cases,
 * reminder delivery with retries. Notifications never contain document type, number or other ID details.
 */

/** Opens a KYC refresh case once per reason/document (open_key keeps it unique). Returns the case id. */
function rekyc_open(int $clientId, string $reason, ?int $versionId, ?string $due, ?int $by, string $key): int {
    $ins = q('INSERT IGNORE INTO rekyc_cases (client_id, reason, document_version_id, due_date, opened_by, open_key) VALUES (?,?,?,?,?,?)', [$clientId, $reason, $versionId, $due, $by, $key]);
    $id = $ins->rowCount() === 1 ? (int)db()->lastInsertId() : 0;
    if ($id) audit_write($by, 'rekyc_opened', 'client', $clientId, "case #$id · $reason", $by ? client_ip() : 'cron');
    return $id ?: (int)val('SELECT id FROM rekyc_cases WHERE open_key=?', [$key]);
}

/** Runs the daily checks. Safe to run more than once a day: nothing is created twice. Returns counts. */
function compliance_daily(?string $today = null): array {
    $today = $today ?? today();
    $n = ['reminders_created' => 0, 'cases_opened' => 0, 'escalated' => 0, 'sent' => 0, 'failed' => 0];
    $rules = all('SELECT offset_days, channel FROM reminder_rules WHERE active=1 ORDER BY offset_days DESC');
    $offsets = array_values(array_unique(array_map('intval', array_column($rules, 'offset_days'))));
    rsort($offsets);
    $first = $offsets[0] ?? 90;
    $openDays = max((int)setting('rekyc_open_days', '90'), $first);
    // 1. current identity documents with an expiry date
    foreach (all("SELECT v.id, v.client_id, v.expires_on FROM client_documents d JOIN document_versions v ON v.id=d.current_version_id
                  JOIN clients c ON c.id=d.client_id WHERE d.doc_kind='identity' AND d.status='current' AND v.expires_on IS NOT NULL
                  AND v.outcome<>'rejected' AND v.expires_on <= DATE_ADD(?, INTERVAL ? DAY)", [$today, $openDays]) as $v) {
        $days = (int)floor((strtotime($v['expires_on']) - strtotime($today)) / 86400);
        // the reminder stage that applies today: the smallest offset that is >= days left (0 = expiry day and after)
        $stage = null; foreach ($offsets as $o) if ($days <= $o) $stage = $o;
        if ($stage !== null) {
            foreach ($rules as $r) {
                if ((int)$r['offset_days'] < $stage) continue;
                $key = ((int)$r['offset_days'] === 0 ? 'expiry' : 'exp-' . $r['offset_days']);
                // earlier stages that were missed (document added late) are recorded as skipped, never sent late
                $st = (int)$r['offset_days'] === $stage ? 'pending' : 'skipped';
                $ins = q('INSERT IGNORE INTO reminders (client_id, document_version_id, event_key, channel, due_on, status, next_attempt_at) VALUES (?,?,?,?,?,?,NOW())',
                         [$v['client_id'], $v['id'], $key, $r['channel'], date('Y-m-d', strtotime($v['expires_on'] . ' -' . $r['offset_days'] . ' days')), $st]);
                if ($ins->rowCount() && $st === 'pending') $n['reminders_created']++;
            }
        }
        if ($days <= $openDays) {
            $before = (int)val('SELECT COUNT(*) FROM rekyc_cases');
            if ($days < 0) {
                // an open "expiring" case becomes "expired"; otherwise (e.g. it was closed before expiry) a new case opens
                if (!q("UPDATE rekyc_cases SET reason='doc_expired' WHERE open_key=? AND status IN ('required','in_progress','escalated')", ['doc:' . $v['id']])->rowCount()
                    && !val("SELECT 1 FROM rekyc_cases WHERE open_key=? AND status IN ('required','in_progress','escalated')", ['doc:' . $v['id']]))
                    rekyc_open((int)$v['client_id'], 'doc_expired', (int)$v['id'], $v['expires_on'], null, 'docexp:' . $v['id']);
            } else rekyc_open((int)$v['client_id'], 'doc_expiring', (int)$v['id'], $v['expires_on'], null, 'doc:' . $v['id']);
            if ((int)val('SELECT COUNT(*) FROM rekyc_cases') > $before) $n['cases_opened']++;
        }
    }
    // 2. periodic KYC refresh, separate from document expiry (months by risk level, set in the Compliance Center)
    foreach (all("SELECT id, kyc_next_review FROM clients WHERE kyc='Verified' AND kyc_next_review IS NOT NULL AND kyc_next_review <= DATE_ADD(?, INTERVAL 30 DAY)", [$today]) as $c) {
        $before = (int)val('SELECT COUNT(*) FROM rekyc_cases');
        rekyc_open((int)$c['id'], 'periodic', null, $c['kyc_next_review'], null, 'periodic:' . $c['id'] . ':' . $c['kyc_next_review']);
        if ((int)val('SELECT COUNT(*) FROM rekyc_cases') > $before) $n['cases_opened']++;
    }
    // 3. escalate overdue cases
    $esc = (int)setting('escalate_after_days', '7');
    foreach (all("SELECT id, client_id FROM rekyc_cases WHERE status IN ('required','in_progress') AND due_date IS NOT NULL AND due_date < DATE_SUB(?, INTERVAL ? DAY)", [$today, $esc]) as $c) {
        q("UPDATE rekyc_cases SET status='escalated', escalated_at=NOW() WHERE id=? AND status IN ('required','in_progress')", [$c['id']]);
        audit_write(null, 'rekyc_escalated', 'client', $c['client_id'], 'case #' . $c['id'] . ' overdue', 'cron');
        $n['escalated']++;
    }
    if ($n['escalated']) notify('Compliance: ' . $n['escalated'] . ' KYC refresh case(s) overdue', 'Overdue KYC refresh cases were escalated. Open the Compliance Center to act on them.', 'compliance');
    // 4. deliver what is due (and retry failures)
    $r = reminders_send();
    $n['sent'] = $r['sent']; $n['failed'] = $r['failed'];
    // saved registration drafts hold personal details: removed a day after they expire or are used
    q('DELETE FROM intake_drafts WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) OR used_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    // rate-limit records older than a week are no longer needed by any limit (longest window: 1 day)
    q('DELETE FROM login_attempts WHERE at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    q('DELETE FROM upload_requests WHERE expires_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
    set_setting('compliance_last_run', $today . ' ' . date('H:i'));
    return $n;
}

/** Sends pending reminders whose time has come. Failures retry after 1, 2, 4 hours… up to the attempt limit. */
function reminders_send(?callable $transport = null): array {
    $transport = $transport ?? 'send_mail';
    $max = max(1, (int)setting('reminder_max_attempts', '3'));
    $out = ['sent' => 0, 'failed' => 0, 'retry' => 0];
    $staff = [];
    foreach (all("SELECT r.*, c.email, c.name FROM reminders r JOIN clients c ON c.id=r.client_id WHERE r.status='pending' AND (r.next_attempt_at IS NULL OR r.next_attempt_at <= NOW()) ORDER BY r.id LIMIT 200") as $r) {
        // claim the row so two runs at once cannot both send it
        if (!q("UPDATE reminders SET attempts=attempts+1, next_attempt_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id=? AND status='pending' AND attempts=?", [$r['id'], $r['attempts']])->rowCount()) continue;
        $attempt = (int)$r['attempts'] + 1; $ok = false; $err = null;
        if ($r['channel'] === 'staff') { $staff[] = $r; $ok = true; }
        elseif (!filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL)) { $err = 'No email address on file'; }
        else {
            $expired = $r['event_key'] === 'expiry';
            $body = 'Hello ' . $r['name'] . ",\n\n" . ($expired
                ? 'A document we hold for your DhanXfer account has now expired.'
                : 'A document we hold for your DhanXfer account will expire soon.')
                . "\nPlease contact DhanXfer on WhatsApp (+1 (942)-6600-0600) so we can update your file. We will send you a secure link if you need to upload anything.\n\nWe never ask for documents or codes by email.";
            $ok = (bool)$transport((string)$r['email'], 'DhanXfer: please update your details', $body, 'expiry_reminder');
            if (!$ok) $err = 'The mail server did not accept the message';
        }
        q('INSERT INTO notification_attempts (reminder_id, attempt_no, channel, ok, error) VALUES (?,?,?,?,?)', [$r['id'], $attempt, $r['channel'], $ok ? 1 : 0, $err]);
        if ($ok) { q("UPDATE reminders SET status='sent', sent_at=NOW(), last_error=NULL, next_attempt_at=NULL WHERE id=?", [$r['id']]); $out['sent']++; }
        elseif ($attempt >= $max || $err === 'No email address on file') { q("UPDATE reminders SET status='failed', last_error=?, next_attempt_at=NULL WHERE id=?", [$err, $r['id']]); $out['failed']++; }
        else { q('UPDATE reminders SET last_error=?, next_attempt_at=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?', [$err, 2 ** ($attempt - 1), $r['id']]); $out['retry']++; }
    }
    if ($staff) notify('Compliance: ' . count($staff) . ' document expiry alert(s)', count($staff) . " client document(s) reached a reminder date today. Open the Compliance Center for the list.\n(No client details are sent by email.)", 'compliance');
    return $out;
}

/* ---------- secure upload links for updated documents ---------- */

/** Creates a one-time upload link for a client (and a KYC refresh case if none is open). */
function upload_request_create(int $clientId, int $by): string {
    $case = val("SELECT id FROM rekyc_cases WHERE client_id=? AND status IN ('required','in_progress','escalated') ORDER BY id LIMIT 1", [$clientId]);
    if (!$case) $case = rekyc_open($clientId, 'manual', null, date('Y-m-d', strtotime(today() . ' +30 days')), $by, 'manual:' . $clientId . ':' . date('YmdHis'));
    q("UPDATE rekyc_cases SET status='in_progress' WHERE id=? AND status='required'", [$case]);
    q('UPDATE upload_requests SET used_at=NOW() WHERE client_id=? AND used_at IS NULL', [$clientId]); // older links stop working
    $tok = bin2hex(random_bytes(24));
    q('INSERT INTO upload_requests (client_id, rekyc_case_id, token_hash, expires_at, created_by) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? DAY),?)',
      [$clientId, $case, hash('sha256', $tok), (int)setting('upload_link_days', '7'), $by]);
    audit_write($by, 'upload_link_made', 'client', $clientId, 'Secure document upload link for case #' . $case, client_ip());
    return app_link('/upload?t=' . $tok);
}
function upload_request_find(string $tok): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
    $r = one('SELECT u.*, c.dob, c.phone, c.phone_e164, c.name, c.client_no FROM upload_requests u JOIN clients c ON c.id=u.client_id WHERE u.token_hash=?', [hash('sha256', $tok)]);
    return ($r && !$r['used_at'] && strtotime($r['expires_at']) > time() && (int)$r['verify_fails'] < 5) ? $r : null;
}
/** The client proves it is them: date of birth and the last 4 digits of the phone number on file. */
function upload_request_verify(array $r, string $dob, string $last4): bool {
    $digits = preg_replace('/\D/', '', (string)($r['phone_e164'] ?: $r['phone']));
    $ok = $r['dob'] && valid_date($dob) === $r['dob'] && strlen($last4) === 4 && hash_equals(substr($digits, -4), $last4);
    if ($ok) q('UPDATE upload_requests SET verified_at=NOW() WHERE id=?', [$r['id']]);
    else q('UPDATE upload_requests SET verify_fails=verify_fails+1 WHERE id=?', [$r['id']]);
    return $ok;
}
