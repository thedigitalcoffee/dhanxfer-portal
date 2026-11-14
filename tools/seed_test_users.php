<?php
// STAGING ONLY: creates one test login per role, from the test_users list in the staging config.
// php tools/seed_test_users.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/../inc/bootstrap.php';
if (($CONFIG['environment'] ?? 'live') !== 'staging' || empty($CONFIG['test_users']) || !str_contains((string)$CONFIG['app_url'], 'staging.') || !str_contains((string)$CONFIG['db_name'], 'staging')) { echo "Refused: test users can only be made on staging.\n"; exit(2); }
foreach ($CONFIG['test_users'] as $t) if (($t['role'] ?? '') === 'super_admin') { echo "Refused: test users may not be super admins.\n"; exit(2); }
$made = 0;
foreach ($CONFIG['test_users'] as $t) {
    if (val('SELECT 1 FROM users WHERE username=?', [$t['username']])) continue;
    q('INSERT INTO users (role, username, name, ref_code, office, password_hash, must_change_password, mfa_secret, mfa_enabled, mfa_token_only) VALUES (?,?,?,?,?,?,0,?,?,0)', [
        $t['role'], $t['username'], $t['name'], $t['ref_code'] ?? null, 'Canada', password_hash($t['password'], PASSWORD_DEFAULT),
        !empty($t['mfa_secret']) ? enc($t['mfa_secret']) : null, !empty($t['mfa_secret']) ? 1 : 0]);
    audit_write(null, 'create_user', 'user', (int)db()->lastInsertId(), 'staging test user ' . $t['role'] . ' ' . $t['username'], 'cron');
    $made++;
}
echo date('c') . " $made test users created\n";
