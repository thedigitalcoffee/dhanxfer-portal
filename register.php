<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/clientform.php';

/*
 * Referral attribution: only a signed link from a sub-agent (…/register?ref=SA-1001&s=…) locks the referral.
 * A code the client types is kept as "typed by client, not verified" and is never credited automatically.
 */
if (get('ref') !== '') {
    $code = strtoupper(mb_substr(get('ref'), 0, 30));
    $_SESSION['ref_lock'] = ref_valid($code, get('s')) ? $code : null;
    if (!$_SESSION['ref_lock']) $_SESSION['ref_hint'] = $code;
}
$lock = $_SESSION['ref_lock'] ?? null;
$v = ['ref_code' => $lock ?? ($_SESSION['ref_hint'] ?? '')];
$benefs = [];
$errors = [];
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('website') !== '') { $done = 'DXA-' . strtoupper(bin2hex(random_bytes(4))); } // bot: pretend success
    elseif ((int)val('SELECT COUNT(*) FROM submissions WHERE ip=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [client_ip()]) >= 6) {
        $errors[] = 'Too many forms were sent from this connection. Please try again in an hour, or contact us on WhatsApp.';
    } else {
        [$v, $benefs, $errors] = read_client_post();
        $typed = strtoupper(mb_substr(post('ref_code'), 0, 30));
        $v['ref_code'] = $lock ?? $typed;
        $v['consent'] = post('consent') === '1';
        if (!$v['consent']) $errors[] = 'Please tick the consent box to continue.';
        if ($v['office'] === '') $errors[] = 'Choose the DhanXfer office you are dealing with.';
        $agent = $lock ? one("SELECT id FROM users WHERE ref_code=? AND role='agent' AND active=1", [$lock]) : null;
        $file = null;
        if (!$errors) { try { $file = save_id_upload('id_file'); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); } }
        if (!$errors) {
            do { $ref = 'DXA-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); } while (val('SELECT 1 FROM submissions WHERE app_ref=?', [$ref]));
            $data = $v; $data['beneficiaries'] = $benefs;
            q('INSERT INTO submissions (data,name,phone,ref_code,ref_verified,agent_id,id_file,ip,app_ref) VALUES (?,?,?,?,?,?,?,?,?)',
                [enc(json_encode($data, JSON_UNESCAPED_UNICODE)), $v['name'], $v['phone'], $v['ref_code'] ?: null, $agent ? 1 : 0, $agent['id'] ?? null, $file, client_ip(), $ref]);
            audit_write(null, 'public_registration', 'submission', (int)db()->lastInsertId(), $ref . ($v['ref_code'] ? ' · referral ' . $v['ref_code'] . ($agent ? ' (verified link)' : ' (typed, not verified)') : ''), client_ip());
            notify('New client registration ' . $ref, "A new client registration was submitted.\n\nReference: $ref\nOffice: {$v['office']}\n\nReview it in the portal: " . app_link('/?p=submissions'), 'new_registration');
            unset($_SESSION['ref_hint']);
            $done = $ref;
        }
    }
}

page_head('Client registration', true);
if ($done): ?>
  <div class="card" style="text-align:center;padding:36px 22px">
    <h1>Thank you!</h1>
    <p class="muted" style="max-width:52ch;margin:12px auto 16px">Your details have reached the DhanXfer team. We will contact you on WhatsApp shortly. Please keep your photo ID ready for your first transfer.</p>
    <div class="tag" style="text-transform:uppercase;letter-spacing:.07em">Your application reference</div>
    <div class="secret" style="display:inline-block;margin:8px 0 6px;font-size:20px"><?= e($done) ?></div>
    <p class="tag">Save it. You can check your application at <a href="/status"><?= e(preg_replace('#^https?://#', '', app_link('/status'))) ?></a> with this reference and your phone number.</p>
    <p>WhatsApp: <strong>+1 (942)-6600-0600</strong> · <a href="https://www.dhanxfer.com">www.dhanxfer.com</a></p>
  </div>
<?php else: ?>
  <div class="hero">
    <h1>Register with DhanXfer</h1>
    <p>A few quick steps and our team will set up your account before your first transfer. Your information is kept private and used only to provide money transfer services.</p>
    <?php if ($lock): ?><p class="tag">You were referred by DhanXfer agent <strong><?= e($lock) ?></strong>.</p><?php endif; ?>
  </div>
  <?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="wizard" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
    <?php client_wizard($v, $benefs, $lock ? 'public_locked' : 'public'); ?>
  </form>
  <p class="tag" style="text-align:center">Already applied? <a href="/status">Check your application status</a>.</p>
<?php endif;
page_foot();
