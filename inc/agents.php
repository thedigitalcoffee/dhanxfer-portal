<?php
defined('DX') or exit;
/*
 * Sub-agent control: account status, manager scope, short referral links (/r/{username}).
 *
 * - Attribution always uses the agent's internal id (users.id), never the name in the link. Clients,
 *   submissions, transfers and commissions keep the agent id they were credited to, whatever happens later
 *   to the username, the link or the account.
 * - A link credits a referral only while the link is active AND the agent account is active. This is checked
 *   when the link is opened and again when the form is sent.
 * - Short names are lower case, unique and never reused: a retired link name stays reserved for the agent it
 *   belonged to, and system words (admin, login, …) can never be taken.
 * - Who may control an agent: roles with team.agents (all agents) or agents.assigned (only agents whose
 *   manager_id is them). Nobody can change their own account from here, and managers never touch staff accounts.
 */

const AGENT_STATUSES = ['active' => 'Active', 'pending' => 'Pending activation', 'suspended' => 'Suspended', 'deactivated' => 'Deactivated'];
const LINK_STATUSES = ['active' => 'Active', 'disabled' => 'Disabled', 'retired' => 'Retired'];
const RESERVED_SLUGS = ['admin', 'administrator', 'root', 'system', 'support', 'help', 'info', 'contact', 'dhanxfer', 'dhan', 'xfer', 'staff',
    'manager', 'compliance', 'super', 'superadmin', 'owner', 'login', 'logout', 'register', 'signup', 'signin', 'status', 'upload', 'reset', 'install',
    'mfa', 'api', 'www', 'mail', 'email', 'r', 'ref', 'referral', 'agent', 'agents', 'subagent', 'portal', 'app', 'assets', 'static', 'test', 'null',
    'undefined', 'security', 'official', 'office', 'billing', 'payments', 'pay', 'bank', 'kyc', 'fintrac', 'abuse', 'noreply', 'no-reply', 'team'];

/* ---------- scope ---------- */
/** 'all' (team.agents), 'assigned' (agents.assigned: only agents assigned to me) or null. */
function agent_scope(?array $u = null): ?string {
    $u ??= me();
    if (!$u || $u['role'] === 'agent') return null;
    if (role_can($u['role'], 'team.agents')) return 'all';
    if (role_can($u['role'], 'agents.assigned')) return 'assigned';
    return null;
}
/** May the current user control this account? Only sub-agents, never yourself, managers only their own agents. */
function can_control_agent(?array $agent, ?array $u = null): bool {
    $u ??= me();
    if (!$agent || !$u || ($agent['role'] ?? '') !== 'agent' || (int)$agent['id'] === (int)$u['id']) return false;
    $s = agent_scope($u);
    return $s === 'all' || ($s === 'assigned' && (int)($agent['manager_id'] ?? 0) === (int)$u['id']);
}
/** SQL condition for "agents I may control" on alias $a. */
function agent_scope_sql(string $a = 'u', ?array $u = null): array {
    $u ??= me();
    $s = agent_scope($u);
    if ($s === 'all') return ["$a.role='agent'", []];
    if ($s === 'assigned') return ["$a.role='agent' AND $a.manager_id=?", [(int)$u['id']]];
    return ['1=0', []];
}
function agent_event(int $agentId, string $action, ?string $reason, ?int $by, ?string $detail = null): void {
    q('INSERT INTO agent_events (agent_id, action, reason, detail, user_id) VALUES (?,?,?,?,?)', [$agentId, $action, $reason ? mb_substr($reason, 0, 255) : null, $detail ? mb_substr($detail, 0, 255) : null, $by]);
    audit_write($by, 'agent_' . $action, 'user', $agentId, ($detail ? $detail . ' · ' : '') . ($reason ? 'Reason: ' . mb_substr($reason, 0, 200) : ''), $by ? client_ip() : 'system');
}
function agent_reason(string $r): string {
    $r = trim(preg_replace('/\s+/', ' ', $r));
    if (mb_strlen($r) < 5) throw new RuntimeException('Give a reason (at least 5 characters). It is kept in the history.');
    return mb_substr($r, 0, 255);
}

/* ---------- account status ---------- */
/**
 * Changes a sub-agent's status. Suspending or deactivating ends every session at once (session version),
 * cancels unused activation/reset links, and stops all referral attribution. Returns the new status.
 * Who: suspend/reactivate/deactivate for anyone who controls the agent; bringing back a deactivated agent
 * needs team.agents (not a manager).
 */
function agent_set_status(array $agent, string $to, string $reason, array $by): string {
    if (!can_control_agent($agent, $by)) throw new RuntimeException('You cannot change this account.');
    $reason = agent_reason($reason);
    $from = (string)$agent['account_status'];
    $allowed = [
        'suspended' => ['active', 'pending'],
        'deactivated' => ['active', 'pending', 'suspended'],
        'active' => ['suspended', 'deactivated'],
    ];
    if (!isset($allowed[$to]) || !in_array($from, $allowed[$to], true)) throw new RuntimeException('That status change is not possible from ' . (AGENT_STATUSES[$from] ?? $from) . '.');
    if ($to === 'active' && $from === 'deactivated' && agent_scope($by) !== 'all') throw new RuntimeException('Only an admin can bring back a deactivated sub-agent.');
    // an agent who never set a password goes back to "pending activation", not "active"
    $new = $to === 'active' && !$agent['last_login'] ? 'pending' : $to;
    $n = q('UPDATE users SET account_status=?, active=?, session_version=session_version+' . ($to === 'active' ? '0' : '1') . ', status_reason=?, status_changed_at=NOW(), status_changed_by=? WHERE id=? AND role=? AND account_status=?',
           [$new, $to === 'active' ? 1 : 0, $reason, $by['id'], $agent['id'], 'agent', $from])->rowCount();
    if (!$n) throw new RuntimeException('The account changed meanwhile. Open it again.');
    if ($to !== 'active') q('UPDATE password_resets SET used_at=COALESCE(used_at, NOW()) WHERE user_id=?', [$agent['id']]);
    agent_event((int)$agent['id'], $to === 'active' ? 'reactivated' : $to, $reason, (int)$by['id'], AGENT_STATUSES[$from] . ' → ' . AGENT_STATUSES[$new]);
    if ($to !== 'active') notify_user((int)$agent['id'], 'Your DhanXfer portal access was ' . ($to === 'suspended' ? 'paused' : 'closed'), "Hi {$agent['name']},\n\nYour DhanXfer portal access (" . $agent['username'] . ") was " . ($to === 'suspended' ? 'paused' : 'closed') . " by the DhanXfer office. Your referral link no longer accepts new clients. Please contact the office if you have questions.");
    return $new;
}
/** Per-agent restrictions: may add clients / may request transfers. */
function agent_set_limits(array $agent, bool $clients, bool $transfers, string $reason, array $by): void {
    if (!can_control_agent($agent, $by)) throw new RuntimeException('You cannot change this account.');
    $reason = agent_reason($reason);
    q('UPDATE users SET agent_can_clients=?, agent_can_transfers=? WHERE id=? AND role=?', [(int)$clients, (int)$transfers, $agent['id'], 'agent']);
    agent_event((int)$agent['id'], 'limits', $reason, (int)$by['id'], 'Add clients: ' . ($clients ? 'yes' : 'no') . ', request transfers: ' . ($transfers ? 'yes' : 'no'));
}
function agent_assign_manager(array $agent, ?int $managerId, string $reason, array $by): void {
    if (agent_scope($by) !== 'all' || ($agent['role'] ?? '') !== 'agent') throw new RuntimeException('Only an admin can assign sub-agents to a manager.');
    $reason = agent_reason($reason);
    if ($managerId && !val("SELECT 1 FROM users WHERE id=? AND role<>'agent' AND active=1 AND id<>?", [$managerId, $agent['id']])) throw new RuntimeException('Choose an active manager.');
    if ($managerId && !role_can((string)val('SELECT role FROM users WHERE id=?', [$managerId]), 'agents.assigned')) throw new RuntimeException('That person\'s role cannot manage sub-agents.');
    q('UPDATE users SET manager_id=? WHERE id=?', [$managerId ?: null, $agent['id']]);
    agent_event((int)$agent['id'], 'assigned', $reason, (int)$by['id'], 'Manager: ' . ($managerId ? (string)val('SELECT username FROM users WHERE id=?', [$managerId]) : 'none'));
}

/* ---------- short referral links ---------- */
function ref_base_url(): string { global $CONFIG; return rtrim((string)($CONFIG['ref_base_url'] ?? $CONFIG['app_url']), '/'); }
function ref_short_url(string $slug): string { return ref_base_url() . '/r/' . rawurlencode($slug); }
function slug_clean(string $s): string { return mb_strtolower(trim($s)); }
/** Why a short name cannot be used by this agent (null = free). Names are never reused by another agent. */
function slug_problem(string $slug, int $agentId): ?string {
    if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,28}[a-z0-9])$/', $slug)) return 'Link name: 3–30 letters or numbers (dot, dash or underscore allowed inside).';
    if (in_array($slug, RESERVED_SLUGS, true) || preg_match('/^(admin|dhanxfer|support|official|staff)/', $slug)) return 'That name is reserved.';
    $owner = val('SELECT agent_id FROM referral_links WHERE slug=?', [$slug]);
    if ($owner !== null && (int)$owner !== $agentId) return 'That link name belongs to another sub-agent (names are never reused).';
    if ($owner !== null) return 'That link name was used before by this sub-agent. Choose a new one.';
    if (val('SELECT 1 FROM users WHERE username=? AND id<>?', [$slug, $agentId])) return 'That name is another person\'s login ID.';
    $code = val('SELECT agent_id FROM agent_codes WHERE code=?', [$slug]);
    if ($code !== null && (int)$code !== $agentId) return 'That name was a referral code of another sub-agent.';
    return null;
}
function ref_primary(int $agentId): ?array { return one("SELECT * FROM referral_links WHERE agent_id=? AND is_primary=1 ORDER BY id DESC LIMIT 1", [$agentId]); }
/** Creates the agent's new main link (the earlier main link stays as it is unless $retireOld). */
function ref_link_create(array $agent, string $slug, ?int $by, ?string $reason = null, bool $retireOld = false): int {
    $slug = slug_clean($slug);
    if ($p = slug_problem($slug, (int)$agent['id'])) throw new RuntimeException($p);
    $lock = lock_name('dx_slug');
    if ((int)val('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) throw new RuntimeException('Busy, try again.');
    try {
        if ($p = slug_problem($slug, (int)$agent['id'])) throw new RuntimeException($p);
        if ($retireOld) q("UPDATE referral_links SET status='retired', changed_by=?, changed_at=NOW(), reason=? WHERE agent_id=? AND status<>'retired'", [$by, $reason, $agent['id']]);
        q('UPDATE referral_links SET is_primary=0 WHERE agent_id=?', [$agent['id']]);
        q("INSERT INTO referral_links (agent_id, slug, status, is_primary, created_by) VALUES (?,?,'active',1,?)", [$agent['id'], $slug, $by]);
        $id = (int)db()->lastInsertId();
    } finally { val('SELECT RELEASE_LOCK(?)', [$lock]); }
    return $id;
}
/** Disable / enable one link, or regenerate (retire every older link and old signed links, issue a new name). */
function ref_link_set(array $agent, array $link, string $to, string $reason, array $by): void {
    if (!can_control_agent($agent, $by) || (int)$link['agent_id'] !== (int)$agent['id']) throw new RuntimeException('You cannot change this link.');
    $reason = agent_reason($reason);
    if ($link['status'] === 'retired') throw new RuntimeException('A retired link stays retired. Make a new link instead.');
    if (!in_array($to, ['active', 'disabled'], true) || $link['status'] === $to) throw new RuntimeException('Nothing to change.');
    q('UPDATE referral_links SET status=?, reason=?, changed_by=?, changed_at=NOW() WHERE id=? AND status=?', [$to, $reason, $by['id'], $link['id'], $link['status']]);
    agent_event((int)$agent['id'], $to === 'active' ? 'link_enabled' : 'link_disabled', $reason, (int)$by['id'], '/r/' . $link['slug']);
}
function ref_link_regenerate(array $agent, string $slug, string $reason, array $by): int {
    if (!can_control_agent($agent, $by)) throw new RuntimeException('You cannot change this link.');
    $reason = agent_reason($reason);
    $id = ref_link_create($agent, $slug, (int)$by['id'], $reason, true);
    q('UPDATE users SET ref_epoch=ref_epoch+1 WHERE id=?', [$agent['id']]); // old long signed links stop as well
    agent_event((int)$agent['id'], 'link_regenerated', $reason, (int)$by['id'], 'New link /r/' . slug_clean($slug) . '; all older links retired');
    return $id;
}
/** Suggests a free link name for an agent (their login ID first). */
function slug_suggest(array $agent): string {
    $base = preg_replace('/[^a-z0-9._-]/', '', slug_clean((string)$agent['username']));
    $base = trim($base, '._-') ?: 'agent' . (int)$agent['id'];
    if (strlen($base) < 3) $base .= (int)$agent['id'];
    if (!slug_problem($base, (int)$agent['id'])) return $base;
    for ($i = 2; $i < 200; $i++) { $s = substr($base, 0, 26) . $i; if (!slug_problem($s, (int)$agent['id'])) return $s; }
    return 'agent' . (int)$agent['id'] . random_int(100, 999);
}
/**
 * The agent and link behind a short name, only if both are active right now. Anything else gives null,
 * whatever the reason (unknown, disabled, retired, suspended): the visitor learns nothing more.
 */
function ref_resolve(string $slug): ?array {
    $slug = slug_clean($slug);
    if (!preg_match('/^[a-z0-9._-]{1,40}$/', $slug)) return null;
    $r = one("SELECT l.id link_id, l.slug, u.id, u.username, u.name FROM referral_links l JOIN users u ON u.id=l.agent_id
              WHERE l.slug=? AND l.status='active' AND u.role='agent' AND u.account_status='active' AND u.active=1", [$slug]);
    return $r ?: null;
}
/** Re-checks a remembered referral when the form is sent: same agent, link still active, agent still active. */
function ref_still_valid(?int $agentId, ?int $linkId): bool {
    if (!$agentId) return false;
    if (!(int)val("SELECT 1 FROM users WHERE id=? AND role='agent' AND account_status='active' AND active=1", [$agentId])) return false;
    if ($linkId) return (bool)val("SELECT 1 FROM referral_links WHERE id=? AND agent_id=? AND status='active'", [$linkId, $agentId]);
    // older long signed link: valid while the agent still has an active main link
    return (bool)val("SELECT 1 FROM referral_links WHERE agent_id=? AND is_primary=1 AND status='active'", [$agentId]);
}

/* ---------- one-time upgrade to schema 10 ---------- */
function agents_migrate_v10(): void {
    // switched-off accounts become "deactivated"; everyone else keeps working as before
    q("UPDATE users SET account_status='deactivated' WHERE active=0 AND account_status='active'");
    foreach (all("SELECT * FROM users WHERE role='agent' AND id NOT IN (SELECT agent_id FROM referral_links)") as $a) {
        $slug = slug_suggest($a);
        q("INSERT INTO referral_links (agent_id, slug, status, is_primary, created_by) VALUES (?,?,'active',1,NULL)", [$a['id'], $slug]);
    }
}
