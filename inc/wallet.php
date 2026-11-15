<?php
defined('DX') or exit;
/*
 * Commission wallet and manual withdrawals (no automated payouts).
 *
 * Commission per order (locked when the order is submitted, never recalculated):
 *   commission     = amount sent × the agent's rate for that currency pair, in the destination currency
 *   commission_cad = commission ÷ the CAD→destination mid-market rate of the order day (market_rates)
 * The rate, its date and source are stored on the transfer. Withdrawals only move these locked CAD amounts.
 *
 * Ledger (insert-only, one row per event, unique keys stop double entries):
 *   earn     +CAD when the order is completed (unique per transfer)
 *   reserve  CAD moved from available to reserved when a withdrawal is requested (unique per withdrawal)
 *   release  reserved CAD back to available when a withdrawal is rejected/cancelled (unique per withdrawal)
 *   paid     reserved CAD paid out (unique per withdrawal)
 *   available = earn − reserve + release      reserved = reserve − release − paid
 *
 * Status changes are made only by people (never automatically) and each one is written to withdrawal_events,
 * an insert-only, hash-chained history, and to the tamper-evident activity log.
 */

const WD_STATUSES = ['requested' => 'Requested', 'under_review' => 'Under review', 'approved' => 'Approved', 'processing' => 'Processing',
    'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'failed' => 'Failed'];
const WD_FIRST_MIN_CAD = 500.00;
const WD_NEXT_MIN_CAD = 100.00;

/* ---------- commission lock at order submission ---------- */
/** CAD→$cur mid-market rate for the order day (or the nearest earlier day within 3 days). [rate, date, source] or null. */
function fx_cad_rate(string $cur, string $day): ?array {
    if ($cur === 'CAD') return [1.0, $day, 'same currency'];
    $r = one("SELECT rate, rate_date, source FROM market_rates WHERE send_currency='CAD' AND receive_currency=? AND rate_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND ? ORDER BY rate_date DESC LIMIT 1", [$cur, $day, $day]);
    if ($r && (float)$r['rate'] > 0) return [(float)$r['rate'], $r['rate_date'], 'market ' . $r['source']];
    // the other direction (destination→CAD) if only that was recorded
    $r = one("SELECT rate, rate_date, source FROM market_rates WHERE send_currency=? AND receive_currency='CAD' AND rate_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND ? ORDER BY rate_date DESC LIMIT 1", [$cur, $day, $day]);
    if ($r && (float)$r['rate'] > 0) return [round(1 / (float)$r['rate'], 6), $r['rate_date'], 'market ' . $r['source'] . ' (inverse)'];
    return null;
}
/**
 * Locks the commission of one order: per-unit rate, destination-currency amount and the CAD value at the
 * order-day rate. Runs when the order is submitted; a locked order is never changed again.
 * Returns true when the CAD value is locked (false = no agent rate, or no FX rate for that day yet).
 */
function commission_lock(int $transferId): bool {
    require_once __DIR__ . '/rates.php';
    $t = one('SELECT * FROM transfers WHERE id=?', [$transferId]);
    if (!$t || !$t['agent_id'] || $t['commission_locked_at']) return (bool)($t['commission_locked_at'] ?? false);
    if ($t['commission'] === null) {
        $per = commission_rate_for((int)$t['agent_id'], $t['send_currency'], $t['receive_currency']);
        if ($per === null || $t['send_amount'] === null) return false;
        q('UPDATE transfers SET commission_per_unit=?, commission=?, commission_currency=? WHERE id=? AND commission IS NULL', [$per, round((float)$t['send_amount'] * $per, 2), $t['receive_currency'], $transferId]);
        $t = one('SELECT * FROM transfers WHERE id=?', [$transferId]);
    }
    $fx = fx_cad_rate((string)$t['commission_currency'], (string)$t['transfer_date']);
    if (!$fx) return false; // shown to finance as "waiting for an FX rate"; locked later with that day's rate
    return commission_lock_with($t, $fx[0], $fx[1], $fx[2], null);
}
function commission_lock_with(array $t, float $rate, string $date, string $source, ?int $by): bool {
    if ($rate <= 0) throw new RuntimeException('The FX rate must be above zero.');
    $cad = round((float)$t['commission'] / $rate, 2);
    $n = q('UPDATE transfers SET commission_fx_rate=?, commission_fx_date=?, commission_fx_source=?, commission_cad=?, commission_locked_at=NOW() WHERE id=? AND commission_locked_at IS NULL AND commission IS NOT NULL',
           [round($rate, 6), $date, mb_substr($source, 0, 60), $cad, $t['id']])->rowCount();
    if ($n) audit_write($by, 'commission_locked', 'transfer', $t['id'], $t['ref_no'] . ': ' . $t['commission'] . ' ' . $t['commission_currency'] . ' = ' . number_format($cad, 2, '.', '') . ' CAD at ' . $rate . ' (' . $date . ', ' . $source . ')', $by ? client_ip() : 'system');
    return $n === 1;
}

/* ---------- ledger and balances ---------- */
/** Credits completed orders with a locked CAD commission (safe to run any time: one credit per order). */
function wallet_sync(?int $agentId = null): int {
    // older completed orders: lock with their own order-day rate first
    foreach (all("SELECT id FROM transfers WHERE status='completed' AND agent_id IS NOT NULL AND commission_locked_at IS NULL" . ($agentId ? ' AND agent_id=' . (int)$agentId : '') . ' LIMIT 500') as $r) commission_lock((int)$r['id']);
    return q("INSERT IGNORE INTO commission_ledger (agent_id, entry, amount_cad, transfer_id, note)
              SELECT agent_id, 'earn', commission_cad, id, ref_no FROM transfers WHERE status='completed' AND agent_id IS NOT NULL AND commission_cad IS NOT NULL AND commission_cad > 0" . ($agentId ? ' AND agent_id=' . (int)$agentId : ''))->rowCount();
}
function wallet_balance(int $agentId): array {
    $s = [];
    foreach (all('SELECT entry, SUM(amount_cad) s FROM commission_ledger WHERE agent_id=? GROUP BY entry', [$agentId]) as $r) $s[$r['entry']] = (float)$r['s'];
    $e = fn($k) => $s[$k] ?? 0.0;
    $pending = (float)val("SELECT COALESCE(SUM(commission_cad),0) FROM transfers WHERE agent_id=? AND status='accepted' AND commission_cad IS NOT NULL", [$agentId]);
    return ['earned' => round($e('earn'), 2), 'available' => round($e('earn') - $e('reserve') + $e('release'), 2),
            'reserved' => round($e('reserve') - $e('release') - $e('paid'), 2), 'paid' => round($e('paid'), 2), 'pending' => round($pending, 2)];
}
/** Earnings by destination currency (as earned) and the locked CAD total. */
function wallet_by_currency(int $agentId): array {
    return all("SELECT commission_currency cur, COUNT(*) n, SUM(commission) amount, SUM(commission_cad) cad, SUM(commission_locked_at IS NULL) unlocked
                FROM transfers WHERE agent_id=? AND status='completed' AND commission IS NOT NULL GROUP BY commission_currency ORDER BY commission_currency", [$agentId]);
}
function wd_minimum(int $agentId): float {
    return val("SELECT 1 FROM commission_withdrawals WHERE agent_id=? AND status='paid' LIMIT 1", [$agentId]) ? WD_NEXT_MIN_CAD : WD_FIRST_MIN_CAD;
}

/* ---------- history (insert-only, hash-chained per withdrawal) ---------- */
function wd_event(int $wid, ?string $from, string $to, ?int $by, ?string $reason, array $data = []): void {
    $prev = (string)(val('SELECT row_hash FROM withdrawal_events WHERE withdrawal_id=? ORDER BY id DESC LIMIT 1', [$wid]) ?? '');
    $at = date('Y-m-d H:i:s');
    $json = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
    $h = hash_hmac('sha256', implode('|', [$wid, $from, $to, $by, $reason, $json, $at, $prev]), sec_key('audit'));
    q('INSERT INTO withdrawal_events (withdrawal_id, from_status, to_status, user_id, reason, data, at, prev_hash, row_hash) VALUES (?,?,?,?,?,?,?,?,?)', [$wid, $from, $to, $by, $reason, $json, $at, $prev ?: null, $h]);
    audit_write($by, 'withdrawal_' . $to, 'withdrawal', $wid, ($from ? WD_STATUSES[$from] ?? $from : 'new') . ' → ' . (WD_STATUSES[$to] ?? $to) . ($reason ? ' · ' . mb_substr($reason, 0, 200) : ''), $by ? client_ip() : 'system');
}
/** Checks a withdrawal's history chain. Returns the id of the first broken row, or null when intact. */
function wd_verify(int $wid): ?int {
    $prev = '';
    foreach (all('SELECT * FROM withdrawal_events WHERE withdrawal_id=? ORDER BY id', [$wid]) as $r) {
        $h = hash_hmac('sha256', implode('|', [$r['withdrawal_id'], $r['from_status'], $r['to_status'], $r['user_id'], $r['reason'], $r['data'], $r['at'], $prev]), sec_key('audit'));
        if ((string)$r['prev_hash'] !== $prev || !hash_equals($h, (string)$r['row_hash'])) return (int)$r['id'];
        $prev = $h;
    }
    return null;
}

/* ---------- request (sub-agent) ---------- */
function wd_request(array $agent, string $amount, string $note): int {
    if (($agent['role'] ?? '') !== 'agent' || ($agent['account_status'] ?? '') !== 'active') throw new RuntimeException('Only an active sub-agent can request a withdrawal.');
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', trim($amount))) throw new RuntimeException('Enter the amount in CAD, for example 500 or 150.25.');
    $amt = round((float)$amount, 2);
    if (preg_match('/\d{6,}/', $note)) throw new RuntimeException('Do not write account or card numbers in the note. The office uses the payout details on file.');
    wallet_sync((int)$agent['id']);
    db()->beginTransaction();
    try {
        // one request at a time per agent: the agent row is locked until the reservation is written
        q('SELECT id FROM users WHERE id=? FOR UPDATE', [$agent['id']]);
        $b = wallet_balance((int)$agent['id']);
        $min = wd_minimum((int)$agent['id']);
        if ($amt < $min) throw new RuntimeException('The minimum withdrawal is CAD ' . number_format($min, 2) . ($min === WD_FIRST_MIN_CAD ? ' for the first withdrawal.' : '.'));
        if ($amt > $b['available']) throw new RuntimeException('That is more than your available balance (CAD ' . number_format($b['available'], 2) . ').');
        q("INSERT INTO commission_withdrawals (agent_id, amount_cad, status, note, requested_by, min_applied) VALUES (?,?,'requested',?,?,?)", [$agent['id'], $amt, mb_substr(trim($note), 0, 200) ?: null, $agent['id'], $min]);
        $wid = (int)db()->lastInsertId();
        q("INSERT INTO commission_ledger (agent_id, entry, amount_cad, withdrawal_id, note) VALUES (?, 'reserve', ?, ?, 'Withdrawal requested')", [$agent['id'], $amt, $wid]);
        if (wallet_balance((int)$agent['id'])['available'] < -0.001) throw new RuntimeException('Balance changed. Try again.');
        wd_event($wid, null, 'requested', (int)$agent['id'], 'Requested CAD ' . number_format($amt, 2));
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    notify('Commission withdrawal requested', 'A sub-agent requested a commission withdrawal (#' . $wid . '). Review it under Withdrawals.', 'notice');
    return $wid;
}

/* ---------- status changes (people only) ---------- */
/** Which changes this person may make on this withdrawal (to-status => label). Server-side rules in one place. */
function wd_allowed(array $w, array $u): array {
    if (($u['role'] ?? '') === 'agent' || (int)$u['id'] === (int)$w['agent_id'] || (int)$u['id'] === (int)$w['requested_by']) return [];
    require_once __DIR__ . '/agents.php';
    $agent = one('SELECT * FROM users WHERE id=?', [$w['agent_id']]);
    $review = role_can($u['role'], 'withdrawals.review');
    $team = role_can($u['role'], 'withdrawals.team') && $agent && (int)$agent['manager_id'] === (int)$u['id'];
    $pay = role_can($u['role'], 'withdrawals.pay');
    $limitOk = $review || ($team && $u['approval_limit_cad'] !== null && (float)$w['amount_cad'] <= (float)$u['approval_limit_cad']);
    $rev = $review || $team;
    $map = [
        'requested' => ['under_review' => $rev, 'approved' => $rev && $limitOk, 'rejected' => $rev, 'cancelled' => $rev],
        'under_review' => ['approved' => $rev && $limitOk, 'rejected' => $rev, 'cancelled' => $rev],
        'approved' => ['processing' => $pay, 'cancelled' => $rev || $pay],
        'processing' => ['paid' => $pay, 'failed' => $pay],
        'failed' => ['processing' => $pay && $w['reconcile_outcome'] === 'not_paid', 'cancelled' => ($pay || $review) && $w['reconcile_outcome'] === 'not_paid', 'paid' => $pay && $w['reconcile_outcome'] === 'paid'],
    ];
    return array_keys(array_filter($map[$w['status']] ?? []));
}
/**
 * Moves a withdrawal to a new status. $in: reason (always), and for paid: confirm=1, payment_date, reference, method.
 * Runs in one transaction with a status guard, so two people cannot both move it, and the ledger's unique keys
 * stop a second release or payout.
 */
function wd_transition(int $wid, string $to, array $u, array $in): void {
    $w = one('SELECT * FROM commission_withdrawals WHERE id=?', [$wid]);
    if (!$w) throw new RuntimeException('Withdrawal not found.');
    if (!in_array($to, wd_allowed($w, $u), true)) throw new RuntimeException('You cannot move this withdrawal to ' . (WD_STATUSES[$to] ?? $to) . '.');
    $reason = trim(preg_replace('/\s+/', ' ', (string)($in['reason'] ?? '')));
    if (mb_strlen($reason) < 5) throw new RuntimeException('Give a reason (at least 5 characters). It is kept in the history.');
    $set = ''; $par = []; $data = [];
    if ($to === 'paid') {
        $ref = trim((string)($in['reference'] ?? '')); $pd = (string)($in['payment_date'] ?? '');
        if (($in['confirm'] ?? '') !== '1') throw new RuntimeException('Confirm that the payment was actually sent.');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{3,59}$/', $ref)) throw new RuntimeException('Enter the transaction or reference number (4–60 characters).');
        if (!($pdv = valid_date($pd)) || $pdv > today() || $pdv < substr((string)$w['requested_at'], 0, 10)) throw new RuntimeException('Enter the payment date (not in the future, not before the request).');
        if (val('SELECT 1 FROM commission_withdrawals WHERE paid_reference=? AND id<>?', [$ref, $wid])) throw new RuntimeException('That reference is already recorded on another withdrawal.');
        $set = ', paid_reference=?, paid_date=?, paid_method=?, paid_by=?, paid_at=NOW()';
        $par = [$ref, $pdv, mb_substr(trim((string)($in['method'] ?? '')), 0, 60) ?: null, $u['id']];
        $data = ['reference' => $ref, 'payment_date' => $pdv, 'method' => $in['method'] ?? null];
    }
    if ($to === 'approved') { $set = ', approved_by=?, approved_at=NOW()'; $par = [$u['id']]; }
    if ($to === 'processing' && $w['status'] === 'failed') { $set = ', reconcile_outcome=NULL'; } // a retry needs a new reconciliation if it fails again
    db()->beginTransaction();
    try {
        $n = q("UPDATE commission_withdrawals SET status=?, updated_at=NOW()$set WHERE id=? AND status=?", array_merge([$to], $par, [$wid, $w['status']]))->rowCount();
        if ($n !== 1) throw new RuntimeException('Someone else just changed this withdrawal. Open it again.');
        if (in_array($to, ['rejected', 'cancelled'], true))
            q("INSERT INTO commission_ledger (agent_id, entry, amount_cad, withdrawal_id, note) VALUES (?, 'release', ?, ?, ?)", [$w['agent_id'], $w['amount_cad'], $wid, WD_STATUSES[$to]]);
        if ($to === 'paid')
            q("INSERT INTO commission_ledger (agent_id, entry, amount_cad, withdrawal_id, note) VALUES (?, 'paid', ?, ?, ?)", [$w['agent_id'], $w['amount_cad'], $wid, 'Paid ' . $data['reference']]);
        wd_event($wid, $w['status'], $to, (int)$u['id'], $reason, $data);
        db()->commit();
    } catch (PDOException $e) { if (db()->inTransaction()) db()->rollBack(); throw new RuntimeException('This withdrawal was already released or paid.'); }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    notify_user((int)$w['agent_id'], 'Your DhanXfer commission withdrawal is now ' . WD_STATUSES[$to],
        "Your commission withdrawal #$wid (CAD " . number_format((float)$w['amount_cad'], 2) . ') is now: ' . WD_STATUSES[$to] . ".\n\nSee My commission in the portal for details, or contact the DhanXfer office.", 'withdrawal_status');
}
/** Records the outcome of checking a failed payment with the bank/provider. Required before a retry, release or Paid. */
function wd_reconcile(int $wid, array $u, string $outcome, string $note): void {
    $w = one('SELECT * FROM commission_withdrawals WHERE id=?', [$wid]);
    if (!$w || $w['status'] !== 'failed') throw new RuntimeException('Only a failed payment is reconciled.');
    if (!role_can($u['role'], 'withdrawals.pay') || (int)$u['id'] === (int)$w['agent_id']) throw new RuntimeException('Only finance can reconcile payments.');
    if (!in_array($outcome, ['not_paid', 'paid'], true)) throw new RuntimeException('Choose what the bank or provider confirmed.');
    $note = trim($note);
    if (mb_strlen($note) < 10) throw new RuntimeException('Describe what was checked (at least 10 characters), e.g. the bank statement or provider reply.');
    q('UPDATE commission_withdrawals SET reconcile_outcome=?, reconciled_by=?, reconciled_at=NOW() WHERE id=? AND status=?', [$outcome, $u['id'], $wid, 'failed']);
    wd_event($wid, 'failed', 'failed', (int)$u['id'], 'Reconciled: ' . ($outcome === 'paid' ? 'money did arrive' : 'money was not paid out') . ' · ' . $note, ['reconcile' => $outcome]);
}
/** Finance locks the CAD value of an older order that had no market rate for its day (rate typed with its source). */
function commission_lock_manual(int $transferId, array $u, string $rate, string $source, string $reason): void {
    if (!role_can($u['role'], 'withdrawals.pay')) throw new RuntimeException('Only finance can lock a commission rate.');
    $t = one('SELECT * FROM transfers WHERE id=?', [$transferId]);
    if (!$t || $t['commission_locked_at'] || $t['commission'] === null) throw new RuntimeException('Nothing to lock.');
    if (!is_numeric($rate) || (float)$rate <= 0 || mb_strlen(trim($source)) < 3 || mb_strlen(trim($reason)) < 5) throw new RuntimeException('Enter the CAD rate of the order day, where it came from, and a reason.');
    commission_lock_with($t, (float)$rate, (string)$t['transfer_date'], 'manual: ' . trim($source), (int)$u['id']);
    audit_write((int)$u['id'], 'commission_locked_manual', 'transfer', $transferId, mb_substr(trim($reason), 0, 200), client_ip());
}
