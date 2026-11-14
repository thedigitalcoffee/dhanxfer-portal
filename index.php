<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/clientform.php';

$u = require_login();
csrf_check();

// page => permissions that open it (any one is enough). null = every logged-in user.
$pages = [
    'dashboard' => null, 'clients' => null, 'client' => null, 'transfers' => null, 'rates' => null, 'audit' => null, 'account' => null,
    'client_form' => ['clients.edit'], 'transfer_form' => ['transfers.create'],
    'commission' => ['commission.view'], 'submissions' => ['submissions.review'],
    'agents' => ['team.agents', 'team.staff'], 'log' => ['log.use'], 'import' => ['import'], 'roles' => ['__super'], 'mfa' => null,
];
$p = get('p', 'dashboard');
if (!array_key_exists($p, $pages)) $p = 'dashboard';
require_once __DIR__ . '/inc/mfa.php';
if ($u['must_change_password'] && $p !== 'account') redirect('/?p=account&first=1');
if (mfa_required($u) && !$u['mfa_enabled'] && $p !== 'mfa' && !$u['must_change_password']) { flash('Your role needs two-step login. Set it up to continue.', 'warn'); redirect('/?p=mfa'); }
$need = $pages[$p];
if ($need !== null) {
    $isAgentPage = $u['role'] === 'agent' && in_array($p, ['client_form', 'transfer_form', 'commission'], true);
    if ($need === ['__super']) { if ($u['role'] !== 'super_admin') { flash('Only the super admin can change roles.', 'bad'); redirect('/'); } }
    elseif (!$isAgentPage) require_perm(...$need);
}
require __DIR__ . '/inc/pages/' . $p . '.php';
