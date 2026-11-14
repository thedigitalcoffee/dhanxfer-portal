<?php
// Serves an uploaded ID document to logged-in staff who may see that client.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$name = basename(get('f'));
if (!preg_match('/^\d{8}_[a-f0-9]{24}\.(jpg|png|webp|pdf|heic)$/', $name)) { http_response_code(404); exit('Not found'); }

$allowed = false;
$c = one('SELECT id, agent_id FROM clients WHERE id_file=?', [$name]);
$mayViewIds = can('pii.reveal') || can('kyc.decide');
if ($c) $allowed = $mayViewIds && can_see_client($c);
if (!$allowed && $mayViewIds && val('SELECT 1 FROM submissions WHERE id_file=?', [$name])) $allowed = true;
if (!$allowed) { http_response_code(403); exit('You do not have access to this file.'); }

$path = ids_dir() . '/' . $name;
if (!is_file($path)) { http_response_code(404); exit('Not found'); }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'heic' => 'image/heic'];
$ext = pathinfo($name, PATHINFO_EXTENSION);
audit('view_id_file', 'client', $c['id'] ?? null, $name);
header('Content-Type: ' . $types[$ext]);
header('Content-Disposition: inline; filename="id-document.' . $ext . '"');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
