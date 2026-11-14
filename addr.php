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
echo json_encode(get('id') !== '' ? (addr_retrieve((string)get('id')) ?? []) : addr_lookup((string)get('q'), (string)get('country')), JSON_UNESCAPED_UNICODE);
