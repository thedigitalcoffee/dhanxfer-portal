<?php
// Short referral link: /r/{agent link name} -> the client registration form.
// The name only finds the agent; what is remembered is the agent's internal id and the link id, checked again
// when the form is sent. Unknown, disabled, retired or suspended links all lead to the same plain form.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/agents.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$ip = client_ip();
$slug = mb_substr((string)($_GET['s'] ?? ''), 0, 60);
$limited = (int)val("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND username='ref-link' AND at > (NOW() - INTERVAL 10 MINUTE)", [$ip]) >= 120;
if ($limited) { header('Location: /register', true, 302); exit; } // too many look-ups: no new referral, the earlier one is left as it was
q("INSERT INTO login_attempts (ip, username) VALUES (?, 'ref-link')", [$ip]);
$hit = ref_resolve($slug);
if ($hit) {
    $_SESSION['ref_agent'] = (int)$hit['id'];
    $_SESSION['ref_link'] = (int)$hit['link_id'];
    unset($_SESSION['ref_hint']);
} else {
    // an inactive or unknown link never credits anyone, and never leaves an earlier referral in place either
    unset($_SESSION['ref_agent'], $_SESSION['ref_link'], $_SESSION['ref_hint']);
}
header('Location: /register', true, 302);
exit;
