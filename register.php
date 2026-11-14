<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/clientform.php';

$v = ['ref_code' => strtoupper(get('ref'))];
$benefs = [];
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Spam guards: hidden honeypot field and a per-IP limit.
    if (post('website') !== '') { $done = true; }
    elseif ((int)val('SELECT COUNT(*) FROM submissions WHERE ip=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [client_ip()]) >= 6) {
        $errors[] = 'Too many forms were sent from this connection. Please try again in an hour, or contact us on WhatsApp.';
    } else {
        [$v, $benefs, $errors] = read_client_post();
        $v['ref_code'] = strtoupper(mb_substr(post('ref_code'), 0, 30));
        $v['consent'] = post('consent') === '1';
        if (!$v['consent']) $errors[] = 'Please tick the consent box to continue.';
        if ($v['office'] === '') $errors[] = 'Choose the DhanXfer office you are dealing with.';
        $agent = null;
        if ($v['ref_code'] !== '') {
            $agent = one("SELECT id,name FROM users WHERE ref_code=? AND role='agent' AND active=1", [$v['ref_code']]);
        }
        $file = null;
        if (!$errors) {
            try { $file = save_id_upload('id_file'); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
        }
        if (!$errors) {
            $data = $v; $data['beneficiaries'] = $benefs;
            q('INSERT INTO submissions (data,name,phone,ref_code,agent_id,id_file,ip) VALUES (?,?,?,?,?,?,?)',
                [json_encode($data, JSON_UNESCAPED_UNICODE), $v['name'], $v['phone'], $v['ref_code'] ?: null, $agent['id'] ?? null, $file, client_ip()]);
            q('INSERT INTO audit (user_id, action, entity, entity_id, detail, ip) VALUES (NULL,?,?,?,?,?)', ['public_registration', 'submission', (int)db()->lastInsertId(), $v['name'] . ($v['ref_code'] ? ' · ref ' . $v['ref_code'] : ''), client_ip()]);
            notify('New client registration: ' . $v['name'], "A new client registration was submitted.\n\nName: {$v['name']}\nPhone: {$v['phone']}\nOffice: {$v['office']}\nReferral ID: " . ($v['ref_code'] ?: '-') . "\n\nReview it in the portal: https://portal.dhanxfer.com/?p=submissions");
            $done = true;
        }
    }
}

page_head('Client registration', true);
if ($done): ?>
  <div class="card" style="text-align:center;padding:40px 24px">
    <h1>Thank you!</h1>
    <p class="muted" style="max-width:52ch;margin:12px auto 20px">Your details have reached the DhanXfer team. We will contact you on WhatsApp shortly. Please keep your photo ID ready for your first transfer.</p>
    <p>WhatsApp: <strong>+1 (942)-6600-0600</strong> · <a href="https://www.dhanxfer.com">www.dhanxfer.com</a></p>
  </div>
<?php else: ?>
  <div class="hero">
    <h1>Register with DhanXfer</h1>
    <p>A few quick steps and our team will set up your account before your first transfer. Your information is kept private and used only to provide money transfer services.</p>
  </div>
  <?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="wizard" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
    <?php client_wizard($v, $benefs, 'public'); ?>
  </form>
<?php endif;
page_foot();
