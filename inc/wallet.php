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
/** CAD→$cur mid-market rate saved for exactly the order day (the day the order was submitted). [rate, date, source] or null. */
function fx_cad_rate(string $cur, string $day): ?array {
    if ($cur === 'CAD') return [1.0, $day, 'same currency'];
    $r = one("SELECT rate, rate_date, source FROM market_rates WHERE send_currency='CAD' AND receive_currency=? AND rate_date=?", [$cur, $day]);
    if ($r && (float)$r['rate'] > 0) return [(float)$r['rate'], $r['rate_date'], 'market ' . $r['source']];
    return null;
}
/** Orders submitted before the wallet started were settled outside the panel: they never become withdrawable. */
function wallet_start(): string { return (string)setting('wallet_start', '2999-01-01 00:00:00'); }
/**
 * Locks the commission of one order: per-unit rate, destination-currency amount and the CAD value at the
 * order-day rate. Runs when the order is submitted; a locked order is never changed again.
 * Returns true when the CAD value is locked (false = no agent rate, or no FX rate for that day yet).
 */
function commission_lock(int $transferId): bool {
    require_once __DIR__ . '/rates.php';
    $t = one('SELECT * FROM transfers WHERE id=?', [$transferId]);
    if (!$t || !$t['agent_id'] || $t['commission_locked_at']) return (bool)($t['commission_locked_at'] ?? false);
    if ((string)$t['created_at'] < wallet_start()) return false; // older order: no new calculation, no CAD lock
    if ($t['commission'] === null) {
        $per = commission_rate_for((int)$t['agent_id'], $t['send_currency'], $t['receive_currency']);
        if ($per === null || $t['send_amount'] === null) return false;
        q('UPDATE transfers SET commission_per_unit=?, commission=?, commission_currency=? WHERE id=? AND commission IS NULL', [$per, round((float)$t['send_amount'] * $per, 2), $t['receive_currency'], $transferId]);
        $t = one('SELECT * FROM transfers WHERE id=?', [$transferId]);
    }
    // the FX day is the day the order was submitted (not a date typed on the form)
    $fx = fx_cad_rate((string)$t['commission_currency'], substr((string)$t['created_at'], 0, 10));
    if (!$fx) return false; // shown to finance as "waiting for an FX rate"; locked later with that day's rate
    return commission_lock_with($t, $fx[0], $fx[1], $fx[2], null);
}
function commission_lock_with(array $t, float $rate, string $date, string $source, ?int $by): bool {
    $rate = round($rate, 6);
    if ($rate < 0.0001 || $rate > 1000000) throw new RuntimeException('The FX rate is out of range.');
    $cad = round((float)$t['commission'] / $rate, 2); // worked out from the stored (rounded) rate, so it can be reproduced
    $n = q('UPDATE transfers SET commission_fx_rate=?, commission_fx_date=?, commission_fx_source=?, commission_cad=?, commission_locked_at=NOW() WHERE id=? AND commission_locked_at IS NULL AND commission IS NOT NULL',
           [$rate, $date, mb_substr($source, 0, 60), $cad, $t['id']])->rowCount();
    if ($n) audit_write($by, 'commission_locked', 'transfer', $t['id'], $t['ref_no'] . ': ' . $t['commission'] . ' ' . $t['commission_currency'] . ' = ' . number_format($cad, 2, '.', '') . ' CAD at ' . $rate . ' (' . $date . ', ' . $source . ')', $by ? client_ip() : 'system');
    return $n === 1;
}

/* ---------- ledger and balances ---------- */
/** Credits completed orders with a locked CAD commission (safe to run any time: one credit per order). */
function wallet_sync(?int $agentId = null): int {
    // older completed orders: lock with their own order-day rate first
    // orders submitted after the wallet started whose order-day rate arrived later in the day: lock now with that day's rate
    foreach (all("SELECT id FROM transfers WHERE status IN ('pending','accepted','completed') AND agent_id IS NOT NULL AND commission_locked_at IS NULL AND created_at >= ?" . ($agentId ? ' AND agent_id=' . (int)$agentId : '') . ' LIMIT 500', [wallet_start()]) as $r) commission_lock((int)$r['id']);
    // credited only when completed in the panel (accept, then complete); never orders saved as completed, imported, or older than the wallet
    return q("INSERT IGNORE INTO commission_ledger (agent_id, entry, amount_cad, transfer_id, note)
              SELECT agent_id, 'earn', commission_cad, id, ref_no FROM transfers WHERE status='completed' AND completed_via='panel' AND created_at >= ? AND agent_id IS NOT NULL AND commission_cad IS NOT NULL AND commission_cad > 0" . ($agentId ? ' AND agent_id=' . (int)$agentId : ''), [wallet_start()])->rowCount();
}
function wallet_balance(int $agentId): array {
    $s = [];
    foreach (all('SELECT entry, SUM(amount_cad) s FROM commission_ledger WHERE agent_id=? GROUP BY entry', [$agentId]) as $r) $s[$r['entry']] = (float)$r['s'];
    $e = fn($k) => $s[$k] ?? 0.0;
    $pending = (float)val("SELECT COALESCE(SUM(commission_cad),0) FROM transfers WHERE agent_id=? AND status='accepted' AND commission_cad IS NOT NULL AND created_at >= ?", [$agentId, wallet_start()]);
    return ['earned' => round($e('earn'), 2), 'available' => round($e('earn') - $e('reserve') + $e('release'), 2),
            'reserved' => round($e('reserve') - $e('release') - $e('paid'), 2), 'paid' => round($e('paid'), 2), 'pending' => round($pending, 2)];
}
/** Earnings by destination currency (as earned) and the locked CAD total. */
function wallet_by_currency(int $agentId): array {
    return all("SELECT commission_currency cur, COUNT(*) n, SUM(commission) amount, SUM(commission_cad) cad, SUM(commission_locked_at IS NULL) unlocked
                FROM transfers WHERE agent_id=? AND status='completed' AND completed_via='panel' AND created_at >= ? AND commission IS NOT NULL GROUP BY commission_currency ORDER BY commission_currency", [$agentId, wallet_start()]);
}
function wd_minimum(int $agentId): float {
    return val("SELECT 1 FROM commission_withdrawals WHERE agent_id=? AND status='paid' LIMIT 1", [$agentId]) ? WD_NEXT_MIN_CAD : WD_FIRST_MIN_CAD;
}

/* ---------- history (insert-only, hash-chained per withdrawal) ---------- */
function wd_event(int $wid, ?string $from, string $to, ?int $by, ?string $reason, array $data = []): void {
    // callers hold the withdrawal row lock (SELECT … FOR UPDATE inside their transaction), so the chain cannot fork
    $prev = (string)(val('SELECT last_hash FROM commission_withdrawals WHERE id=?', [$wid]) ?? '');
    $at = date('Y-m-d H:i:s');
    $json = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
    $h = hash_hmac('sha256', implode('|', [$wid, $from, $to, $by, $reason, $json, $at, $prev]), sec_key('audit'));
    q('INSERT INTO withdrawal_events (withdrawal_id, from_status, to_status, user_id, reason, data, at, prev_hash, row_hash) VALUES (?,?,?,?,?,?,?,?,?)', [$wid, $from, $to, $by, $reason, $json, $at, $prev ?: null, $h]);
    q('UPDATE commission_withdrawals SET last_hash=? WHERE id=?', [$h, $wid]);
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
    // removing the newest rows is caught too: the withdrawal row remembers the last hash
    if ($prev !== (string)val('SELECT last_hash FROM commission_withdrawals WHERE id=?', [$wid])) return -1;
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
    // a manager's limit covers everything they approved for this agent in 30 days, so splitting a request does not get around it
    $approved30 = $team ? (float)val("SELECT COALESCE(SUM(amount_cad),0) FROM commission_withdrawals WHERE agent_id=? AND approved_by=? AND approved_at > (NOW() - INTERVAL 30 DAY) AND status NOT IN ('rejected','cancelled') AND id<>?", [$w['agent_id'], $u['id'], $w['id']]) : 0.0;
    $limitOk = $review || ($team && $u['approval_limit_cad'] !== null && (float)$w['amount_cad'] + $approved30 <= (float)$u['approval_limit_cad']);
    $notApprover = (int)($w['approved_by'] ?? 0) !== (int)$u['id'];       // whoever approved cannot also process or pay
    $notReconciler = (int)($w['reconciled_by'] ?? 0) !== (int)$u['id'];   // whoever reconciled cannot also retry or pay
    $rev = $review || $team;
    $map = [
        'requested' => ['under_review' => $rev, 'rejected' => $rev, 'cancelled' => $rev],
        'under_review' => ['approved' => $rev && $limitOk, 'rejected' => $rev, 'cancelled' => $rev],
        'approved' => ['processing' => $pay && $notApprover, 'cancelled' => $rev || $pay],
        'processing' => ['paid' => $pay && $notApprover, 'failed' => $pay],
        'failed' => ['processing' => $pay && $notApprover && $notReconciler && $w['reconcile_outcome'] === 'not_paid', 'cancelled' => ($pay || $review) && $w['reconcile_outcome'] === 'not_paid', 'paid' => $pay && $notApprover && $notReconciler && $w['reconcile_outcome'] === 'paid'],
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
        $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ref));
        if (val('SELECT 1 FROM commission_withdrawals WHERE paid_ref_norm=? AND id<>?', [$norm, $wid])) throw new RuntimeException('That reference is already recorded on another withdrawal.');
        $set = ', paid_reference=?, paid_ref_norm=?, paid_date=?, paid_method=?, paid_by=?, paid_at=NOW()';
        $par = [$ref, $norm, $pdv, mb_substr(trim((string)($in['method'] ?? '')), 0, 60) ?: null, $u['id']];
        $data = ['reference' => $ref, 'payment_date' => $pdv, 'method' => $in['method'] ?? null];
    }
    if ($to === 'approved') { $set = ', approved_by=?, approved_at=NOW()'; $par = [$u['id']]; }
    if ($to === 'processing' && $w['status'] === 'failed') { $set = ', reconcile_outcome=NULL'; } // a retry needs a new reconciliation if it fails again
    db()->beginTransaction();
    try {
        // decide on the row as it is now, under a lock: nobody else can change it (or its reconciliation) meanwhile
        $now = one('SELECT * FROM commission_withdrawals WHERE id=? FOR UPDATE', [$wid]);
        if ($now['status'] !== $w['status'] || $now['reconcile_outcome'] !== $w['reconcile_outcome'] || !in_array($to, wd_allowed($now, $u), true)) throw new RuntimeException('Someone else just changed this withdrawal. Open it again.');
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
    db()->beginTransaction();
    try {
        q('SELECT id FROM commission_withdrawals WHERE id=? FOR UPDATE', [$wid]);
        // one reconciliation per failed attempt: it cannot be flipped afterwards
        if (q('UPDATE commission_withdrawals SET reconcile_outcome=?, reconciled_by=?, reconciled_at=NOW() WHERE id=? AND status=? AND reconcile_outcome IS NULL', [$outcome, $u['id'], $wid, 'failed'])->rowCount() !== 1)
            throw new RuntimeException('This failed payment was already reconciled.');
        wd_event($wid, 'failed', 'failed', (int)$u['id'], 'Reconciled: ' . ($outcome === 'paid' ? 'money did arrive' : 'money was not paid out') . ' · ' . $note, ['reconcile' => $outcome]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
/**
 * When no market rate was saved for an order's day, finance enters that day's CAD rate with its source.
 * Two people: one proposes, a different finance user confirms. Only completed, panel orders after the wallet
 * started, with no market rate that day; and the rate must be within 10% of any market rate of the nearby days.
 */
function commission_lock_manual(int $transferId, array $u, string $rate, string $source, string $reason): string {
    if (!role_can($u['role'], 'withdrawals.pay')) throw new RuntimeException('Only finance can lock a commission rate.');
    $t = one('SELECT * FROM transfers WHERE id=?', [$transferId]);
    if (!$t || $t['commission_locked_at'] || $t['commission'] === null || $t['status'] !== 'completed' || $t['completed_via'] !== 'panel' || (string)$t['created_at'] < wallet_start()) throw new RuntimeException('Nothing to lock for this order.');
    $day = substr((string)$t['created_at'], 0, 10);
    if (fx_cad_rate((string)$t['commission_currency'], $day)) throw new RuntimeException('A market rate exists for that day; it is used automatically.');
    if (!preg_match('/^\d{1,7}(\.\d{1,6})?$/', trim($rate)) || (float)$rate < 0.0001 || mb_strlen(trim($source)) < 3 || mb_strlen(trim($reason)) < 5) throw new RuntimeException('Enter the CAD rate of the order day (a plain number), where it came from, and a reason.');
    $r = round((float)$rate, 6);
    $near = val("SELECT rate FROM market_rates WHERE send_currency='CAD' AND receive_currency=? AND rate_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY) ORDER BY ABS(DATEDIFF(rate_date, ?)) LIMIT 1", [$t['commission_currency'], $day, $day, $day]);
    if ($near !== null && abs($r / (float)$near - 1) > 0.10) throw new RuntimeException('That rate is more than 10% away from the market rate of the nearby days (' . (float)$near . '). Check it.');
    if (!$t['fx_proposed_rate']) {
        q('UPDATE transfers SET fx_proposed_rate=?, fx_proposed_source=?, fx_proposed_by=? WHERE id=? AND fx_proposed_rate IS NULL AND commission_locked_at IS NULL', [$r, mb_substr(trim($source), 0, 60), $u['id'], $transferId]);
        audit_write((int)$u['id'], 'commission_fx_proposed', 'transfer', $transferId, $r . ' (' . trim($source) . ') · ' . mb_substr(trim($reason), 0, 200), client_ip());
        return 'proposed';
    }
    if ((int)$t['fx_proposed_by'] === (int)$u['id']) throw new RuntimeException('A different finance user must confirm the rate you proposed.');
    if (abs((float)$t['fx_proposed_rate'] - $r) > 0.0000005) throw new RuntimeException('The rate does not match the proposed rate (' . (float)$t['fx_proposed_rate'] . '). Clear it with the proposer.');
    commission_lock_with($t, $r, $day, 'manual: ' . $t['fx_proposed_source'] . ' (proposed #' . $t['fx_proposed_by'] . ', confirmed #' . $u['id'] . ')', (int)$u['id']);
    audit_write((int)$u['id'], 'commission_locked_manual', 'transfer', $transferId, mb_substr(trim($reason), 0, 200), client_ip());
    return 'locked';
}
