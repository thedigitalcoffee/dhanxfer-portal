<?php
// Address suggestions for the registration form. Only the typed text and chosen country go to the provider.
// Needs the form's session token, and is limited per connection, so it cannot be used as a free lookup service.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/address.php';
header('Content-Type: application/json; charset=utf-8');
if (!addr_lookup_enabled() || !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) { http_response_code(403); exit('[]'); }
$ip = client_ip();
if ((int)val("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND username='addr-lookup' AND at > (NOW() - INTERVAL 10 MINUTE)", [$ip]) >= 60) { http_response_code(429); exit('[]'); }
q("INSERT INTO login_attempts (ip, username) VALUES (?, 'addr-lookup')", [$ip]);
if (get('id') !== '') {
    // a full address (a paid lookup with Canada Post) only for a suggestion this visitor was just shown, and at most 10 per hour
    $id = (string)get('id');
    if (!in_array($id, $_SESSION['addr_ids'] ?? [], true)) { http_response_code(403); exit('[]'); }
    if ((int)val("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND username='addr-retrieve' AND at > (NOW() - INTERVAL 1 HOUR)", [$ip]) >= 10) { http_response_code(429); exit('[]'); }
    q("INSERT INTO login_attempts (ip, username) VALUES (?, 'addr-retrieve')", [$ip]);
    $_SESSION['addr_ids'] = array_values(array_diff($_SESSION['addr_ids'], [$id]));
    echo json_encode(addr_retrieve($id) ?? [], JSON_UNESCAPED_UNICODE);
    exit;
}
$res = addr_lookup((string)get('q'), (string)get('country'));
$ids = array_values(array_filter(array_column($res, 'id')));
if ($ids) $_SESSION['addr_ids'] = array_slice(array_merge($_SESSION['addr_ids'] ?? [], $ids), -50);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
