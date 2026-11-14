<?php
// Clients check their application with the reference and the last 4 digits of their phone.
// Shows only a plain status: never KYC, risk or internal notes.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$result = null; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ip = client_ip();
    if ((int)val("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND username='status-check' AND at > (NOW() - INTERVAL 15 MINUTE)", [$ip]) >= 10) {
        $err = 'Too many checks from this connection. Please wait 15 minutes.';
    } else {
        q("INSERT INTO login_attempts (ip, username) VALUES (?, 'status-check')", [$ip]);
        $ref = strtoupper(trim(post('ref')));
        $last4 = preg_replace('/\D/', '', post('last4'));
        $s = preg_match('/^DXA-[0-9A-Z-]{4,20}$/', $ref) && strlen($last4) === 4 ? one('SELECT status, phone, created_at, client_id FROM submissions WHERE app_ref=?', [$ref]) : null;
        // A reference stops answering after 5 wrong phone-digit tries in a day, from any connection,
        // so the 4 digits cannot be guessed by spreading tries over many addresses.
        $refKey = 'status-ref:' . substr(hash('sha256', $ref), 0, 32);
        $refLocked = $s && (int)val('SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 1 DAY)', [$refKey]) >= 5;
        $match = $s && !$refLocked && hash_equals(substr(preg_replace('/\D/', '', $s['phone']), -4), $last4);
        if ($s && !$match && !$refLocked) {
            q('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [$ip, $refKey]);
            if ((int)val('SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 1 DAY)', [$refKey]) === 5) audit_write(null, 'status_ref_locked', 'submission', null, 'Status lookups paused for one reference', $ip);
        }
        if ($match) {
            $kycDone = $s['client_id'] ? val('SELECT kyc FROM clients WHERE id=?', [$s['client_id']]) === 'Verified' : false;
            $result = match ($s['status']) {
                'new' => ['Received', 'Our team is reviewing your details. We will contact you on WhatsApp.', 'p-warn'],
                'approved' => $kycDone ? ['Ready', 'Your account is set up. You can send money with DhanXfer.', 'p-ok'] : ['Registered', 'Your details are registered. Please show your original photo ID before your first transfer.', 'p-info'],
                default => ['Not completed', 'We could not complete your registration. Please contact us on WhatsApp.', 'p-bad'],
            };
            $result[] = $s['created_at'];
        } else {
            $err = 'We could not find an application with that reference and phone number. Check both and try again.';
        }
    }
}
page_head('Application status', true); ?>
<div class="login card" style="max-width:460px">
  <h1 style="margin-bottom:6px">Check your application</h1>
  <p class="tag" style="margin-top:0">Use the reference you got after sending the form (it starts with DXA-).</p>
  <?php if ($err): ?><div class="banner bad"><?= e($err) ?></div><?php endif; ?>
  <?php if ($result): ?>
    <div class="card" style="background:var(--panel2)"><span class="pill <?= e($result[2]) ?>"><?= e($result[0]) ?></span><p style="margin-bottom:4px"><?= e($result[1]) ?></p><div class="tag">Sent <?= fdate($result[3]) ?></div></div>
  <?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <div class="f" style="margin-bottom:12px"><label for="ref">Application reference</label><input id="ref" name="ref" required placeholder="DXA-261114-AB12C" style="text-transform:uppercase" value="<?= e(post('ref')) ?>" autocomplete="off"></div>
    <div class="f" style="margin-bottom:16px"><label for="last4">Last 4 digits of your phone number</label><input id="last4" name="last4" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off"></div>
    <button class="btn gold" style="width:100%">Check status</button>
  </form>
  <p class="tag" style="margin-bottom:0">Questions? WhatsApp +1 (942)-6600-0600</p>
</div>
<?php page_foot();
