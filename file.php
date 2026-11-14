<?php
// Serves an ID document to logged-in staff who may see that client. Files are stored encrypted and decrypted here.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$name = basename(get('f'));
if (!preg_match('/^\d{8}_[a-f0-9]{24}\.(jpg|png|webp|pdf|heic)$/', $name)) { http_response_code(404); exit('Not found'); }

$mayViewIds = can('pii.reveal') || can('kyc.decide');
$allowed = false; $clientId = null;
$owner = one('SELECT c.id, c.agent_id FROM document_versions v JOIN clients c ON c.id=v.client_id WHERE v.file_name=? LIMIT 1', [$name])
      ?? one('SELECT id, agent_id FROM clients WHERE id_file=?', [$name]);
if ($owner) { $allowed = $mayViewIds && can_see_client($owner); $clientId = $owner['id']; }
elseif ($mayViewIds && val('SELECT 1 FROM submissions WHERE id_file=?', [$name])) $allowed = true;
if (!$allowed) { http_response_code(403); exit('You do not have access to this file.'); }

$data = id_file_read($name);
if ($data === null) { http_response_code(404); exit('Not found'); }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'heic' => 'image/heic'];
$ext = pathinfo($name, PATHINFO_EXTENSION);
audit('view_id_file', 'client', $clientId, $name);
header('Content-Type: ' . $types[$ext]);
header('Content-Disposition: inline; filename="id-document.' . $ext . '"');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Content-Length: ' . strlen($data));
echo $data;
