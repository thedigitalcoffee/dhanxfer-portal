<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/clientform.php';
require __DIR__ . '/inc/otp.php';

/*
 * Public client registration.
 * - Referral: only a signed sub-agent link (…/register?a=ID&s=… or the older ?ref=SA-1001&s=…) credits an agent.
 *   A code the client types is kept as "typed, not verified" and never credited automatically.
 * - Drafts: "Save and finish later" stores the answers encrypted; they come back only with the private link
 *   AND the last 4 digits of the phone number entered (5 wrong tries lock the draft).
 * - Returning clients prove who they are (client number, date of birth, phone digits) and only send what changed.
 *   Nothing on file is shown to them except masked hints.
 */
$errors = []; $done = null; $draftLink = null; $view = 'form';
$ip = client_ip();

if (get('a') !== '' || get('ref') !== '') {
    $ag = agent_from_link(get('a'), mb_substr(get('ref'), 0, 30), get('s'));
    $_SESSION['ref_agent'] = $ag ? (int)$ag['id'] : null;
    if (!$ag) $_SESSION['ref_hint'] = mb_substr(get('ref') ?: '', 0, 30);
}
$agent = !empty($_SESSION['ref_agent']) ? one("SELECT id, username, name FROM users WHERE id=? AND role='agent' AND active=1", [$_SESSION['ref_agent']]) : null;
$v = ['ref_code' => $agent['username'] ?? ($_SESSION['ref_hint'] ?? ''), 'ref_label' => $agent ? $agent['username'] : ''];
$benefs = [];
$draftKey = fn(string $tok, string $last4) => hash_hmac('sha256', 'draft:' . $tok . ':' . $last4, sec_key('draft'));
$findDraft = fn(string $tok) => preg_match('/^[a-f0-9]{48}$/', $tok) ? one('SELECT * FROM intake_drafts WHERE token_hash=? AND used_at IS NULL AND expires_at > NOW() AND fails < 5', [hash('sha256', $tok)]) : null;

if (get('returning') === '1') $view = 'returning';
if (get('draft') !== '') { $view = $findDraft(get('draft')) ? 'resume' : 'draft_gone'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    if (post('website') !== '') { $done = 'DXA-' . strtoupper(bin2hex(random_bytes(4))); } // bot: pretend success
    elseif ($act === 'draft') {
        // save what is filled in so far (no files), behind a private link + phone digits
        $last4 = substr(preg_replace('/\D/', '', post('phone')), -4);
        if (strlen($last4) < 4) $errors[] = 'Enter your phone number first: you will need its last 4 digits to open your saved form.';
        elseif ((int)val('SELECT COUNT(*) FROM intake_drafts WHERE ip=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$ip]) >= 10) $errors[] = 'Too many saved forms from this connection. Try again later.';
        else {
            $keep = [];
            foreach (array_merge(CLIENT_FIELDS, ['wa_same', 'id_has_address', 'id_addr_same', 'id_addr_line1', 'id_addr_city', 'id_addr_postal', 'purpose_other', 'address_source', 'ref_code']) as $k) $keep[$k] = mb_substr(post($k), 0, $k === 'notes' ? 4000 : 250);
            foreach (['b_name', 'b_relation', 'b_country', 'b_bank', 'b_account', 'b_phone'] as $k) $keep[$k] = is_array($_POST[$k] ?? null) ? array_map(fn($x) => mb_substr((string)$x, 0, 160), array_slice($_POST[$k], 0, 5)) : [];
            $tok = bin2hex(random_bytes(24));
            q('INSERT INTO intake_drafts (token_hash, data, phone_last4_hash, agent_id, ip, expires_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 7 DAY))',
              [hash('sha256', $tok), enc(json_encode($keep, JSON_UNESCAPED_UNICODE)), $draftKey($tok, $last4), $agent['id'] ?? null, $ip]);
            if (!empty($_SESSION['draft_tok'])) q('UPDATE intake_drafts SET used_at=NOW() WHERE token_hash=?', [hash('sha256', $_SESSION['draft_tok'])]);
            $_SESSION['draft_tok'] = $tok;
            $draftLink = app_link('/register?draft=' . $tok);
            $view = 'saved';
        }
        if ($errors) { [$v] = read_client_post(); $v['ref_label'] = $agent['username'] ?? ''; }
    } elseif ($act === 'resume') {
        $tok = post('draft'); $d = $findDraft($tok);
        if (!$d) { $view = 'draft_gone'; }
        elseif (!hash_equals($d['phone_last4_hash'], $draftKey($tok, substr(preg_replace('/\D/', '', post('last4')), -4)))) {
            q('UPDATE intake_drafts SET fails=fails+1 WHERE id=?', [$d['id']]);
            $errors[] = 'Those digits do not match the phone number in the saved form.'; $view = 'resume';
        } else {
            $data = json_decode((string)dec($d['data']), true) ?: [];
            $v = array_merge($v, $data);
            $benefs = [];
            foreach (($data['b_name'] ?? []) as $i => $bn) { if (trim((string)$bn) === '') continue; $row = []; foreach (BENEF_FIELDS as $k) $row[$k] = (string)($data['b_' . $k][$i] ?? ''); $benefs[] = $row; }
            if ($d['agent_id'] && !$agent) { $_SESSION['ref_agent'] = (int)$d['agent_id']; $agent = one("SELECT id, username, name FROM users WHERE id=? AND role='agent' AND active=1", [$d['agent_id']]); }
            $v['ref_label'] = $agent['username'] ?? '';
            $_SESSION['draft_tok'] = $tok;
            $view = 'form';
        }
    } elseif ($act === 'returning') {
        $view = 'returning';
        if ((int)val("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND username='returning' AND at > (NOW() - INTERVAL 1 HOUR)", [$ip]) >= 5) $errors[] = 'Too many tries. Please wait an hour or contact us on WhatsApp.';
        else {
            $cno = strtoupper(trim(post('client_no')));
            $ckey = 'returning:' . substr(hash('sha256', $cno), 0, 40);
            // per client as well as per connection: the client number is printed on receipts, so it is not a secret
            $clientLocked = (int)val('SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 1 DAY)', [$ckey]) >= 5;
            $c = $clientLocked ? null : one("SELECT * FROM clients WHERE client_no=? AND kyc='Verified'", [$cno]);
            $digits = $c ? preg_replace('/\D/', '', (string)($c['phone_e164'] ?: $c['phone'])) : '';
            $last4 = preg_replace('/\D/', '', post('last4'));
            if ($c && $c['dob'] && valid_date(post('dob')) === $c['dob'] && strlen($last4) === 4 && hash_equals(substr($digits, -4), $last4)) {
                // second step: a one-time code to the email (or phone) already on the verified record
                session_regenerate_id(true);
                [$sent, $otpMsg] = otp_send($c, 'returning');
                if ($sent) { $_SESSION['returning_pending'] = ['id' => (int)$c['id'], 'at' => time()]; $view = 'returning_otp'; }
                else $errors[] = $otpMsg;
            } else {
                q("INSERT INTO login_attempts (ip, username) VALUES (?, 'returning')", [$ip]);
                if (!$clientLocked) { q('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [$ip, $ckey]);
                    if ((int)val('SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 1 DAY)', [$ckey]) === 5) { $cc = one('SELECT id FROM clients WHERE client_no=?', [$cno]); if ($cc) audit_write(null, 'returning_locked', 'client', $cc['id'], 'Returning-client check locked for 24 hours after 5 wrong tries', $ip); notify('Security: returning-client check locked', 'Five wrong identity checks for one client number in a day. The check is locked for 24 hours.', 'security_alert'); } }
                $errors[] = 'We could not match those details. Check your client number (on your receipts), date of birth and phone digits.';
            }
        }
    } elseif ($act === 'returning_otp' || $act === 'returning_resend') {
        $pend = $_SESSION['returning_pending'] ?? null;
        if (!$pend || time() - $pend['at'] > 900) { $errors[] = 'Your session ran out. Please confirm your details again.'; $view = 'returning'; }
        elseif ($act === 'returning_resend') { [, $otpMsg] = otp_send(one('SELECT * FROM clients WHERE id=?', [$pend['id']]), 'returning'); $view = 'returning_otp'; }
        elseif (otp_verify((int)$pend['id'], 'returning', post('code'))) {
            unset($_SESSION['returning_pending']); session_regenerate_id(true);
            $_SESSION['returning'] = ['id' => (int)$pend['id'], 'at' => time()];
            $view = 'returning_form';
        } else { $errors[] = 'That code did not work. Check the newest message, or ask for a new code.'; $view = 'returning_otp'; }
    } elseif ($act === 'returning_update') {
        $r = $_SESSION['returning'] ?? null;
        if (!$r || time() - $r['at'] > 900) { $errors[] = 'Your session ran out. Please confirm your details again.'; $view = 'returning'; }
        else {
            $c = one('SELECT * FROM clients WHERE id=?', [$r['id']]);
            $chg = array_values(array_intersect(['address', 'phone', 'id', 'beneficiary', 'other'], (array)($_POST['changes'] ?? [])));
            $upd = ['changes' => $chg, 'consent' => post('consent') === '1'];
            foreach (['address', 'city', 'province', 'postal', 'phone', 'id_type', 'id_number', 'id_expiry', 'notes'] as $k) $upd[$k] = mb_substr(trim(post($k)), 0, 250);
            if (in_array('phone', $chg, true)) { $ph = phone_check($upd['phone'], phone_region($c['country'])); if (!$ph['ok']) $errors[] = 'Phone: ' . $ph['error']; else $upd['phone_e164'] = $ph['e164']; }
            $upd['beneficiaries'] = in_array('beneficiary', $chg, true) ? benef_from_post(5) : [];
            if (in_array('beneficiary', $chg, true)) foreach ($upd['beneficiaries'] as $nb) { [, $be] = benef_clean($nb); foreach ($be as $x) $errors[] = $x; }
            if (!$chg) $errors[] = 'Tick what has changed.';
            if (!$upd['consent']) $errors[] = 'Please tick the consent box.';
            $file = null;
            if (!$errors) { try { $file = save_id_upload('id_file'); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); } }
            if (!$errors) {
                do { $ref = 'DXA-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); } while (val('SELECT 1 FROM submissions WHERE app_ref=?', [$ref]));
                q('INSERT INTO submissions (data,name,phone,agent_id,id_file,ip,app_ref,client_id_returning) VALUES (?,?,?,?,?,?,?,?)',
                  [enc(json_encode(['returning_update' => $upd], JSON_UNESCAPED_UNICODE)), $c['name'], $c['phone'], $c['agent_id'], $file, $ip, $ref, $c['id']]);
                audit_write(null, 'returning_update', 'client', $c['id'], $ref . ' · ' . implode(',', $chg), $ip);
                notify('Returning client update ' . $ref, "A returning client sent changes (" . implode(', ', $chg) . ").\n\nReview it in the portal: " . app_link('/?p=submissions'), 'new_registration');
                unset($_SESSION['returning']);
                $done = $ref;
            } else $view = 'returning_form';
        }
    } elseif ((int)val('SELECT COUNT(*) FROM submissions WHERE ip=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$ip]) >= 6) {
        $errors[] = 'Too many forms were sent from this connection. Please try again in an hour, or contact us on WhatsApp.';
    } else {
        [$v, $benefs, $errors] = read_client_post();
        $benefs = array_slice($benefs, 0, 5);
        foreach ($benefs as $nb) { [, $be] = benef_clean($nb); foreach ($be as $x) $errors[] = 'Beneficiary ' . ($nb['name'] ?: '') . ': ' . $x; }
        $typed = mb_substr(trim(post('ref_code')), 0, 60);
        $v['ref_code'] = $agent ? $agent['username'] : $typed;
        $v['ref_label'] = $agent['username'] ?? '';
        $v['consent'] = post('consent') === '1';
        if (!$v['consent']) $errors[] = 'Please tick the consent box to continue.';
        if ($v['country'] === '') $errors[] = 'Choose the country where you live.';
        $file = null;
        if (!$errors) { try { $file = save_id_upload('id_file'); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); } }
        if (!$errors) {
            do { $ref = 'DXA-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); } while (val('SELECT 1 FROM submissions WHERE app_ref=?', [$ref]));
            $data = $v; $data['beneficiaries'] = $benefs;
            if (!$agent && $typed !== '' && ($sug = agent_by_code($typed))) $data['typed_code_matches_agent'] = (int)$sug['id']; // shown to staff, never credited automatically
            q('INSERT INTO submissions (data,name,phone,ref_code,ref_verified,agent_id,id_file,ip,app_ref) VALUES (?,?,?,?,?,?,?,?,?)',
                [enc(json_encode($data, JSON_UNESCAPED_UNICODE)), $v['name'], $v['phone'], $v['ref_code'] ?: null, $agent ? 1 : 0, $agent['id'] ?? null, $file, $ip, $ref]);
            audit_write(null, 'public_registration', 'submission', (int)db()->lastInsertId(), $ref . ($v['ref_code'] ? ' · referral ' . $v['ref_code'] . ($agent ? ' (verified link, agent #' . $agent['id'] . ')' : ' (typed, not verified)') : ''), $ip);
            notify('New client registration ' . $ref, "A new client registration was submitted.\n\nReference: $ref\nOffice: {$v['office']}\n\nReview it in the portal: " . app_link('/?p=submissions'), 'new_registration');
            if (!empty($_SESSION['draft_tok'])) { q('UPDATE intake_drafts SET used_at=NOW() WHERE token_hash=?', [hash('sha256', $_SESSION['draft_tok'])]); unset($_SESSION['draft_tok']); }
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
<?php elseif ($view === 'saved'): ?>
  <div class="card" style="max-width:520px;margin:0 auto">
    <h1>Saved for later</h1>
    <p>Open this private link within 7 days to continue. You will need the <strong>last 4 digits of your phone number</strong>. Photos are not saved: add your ID photo when you finish.</p>
    <div class="secret" style="word-break:break-all"><?= e($draftLink) ?></div>
    <div class="row" style="margin-top:10px"><button type="button" class="btn gold" data-copy="<?= e($draftLink) ?>">Copy link</button><button type="button" class="btn" data-share="<?= e($draftLink) ?>" data-share-text="My DhanXfer registration (private)">Send to myself</button></div>
    <p class="tag">Keep this link private. Anyone with it and your phone digits could see what you entered.</p>
  </div>
<?php elseif ($view === 'resume' || $view === 'draft_gone'): ?>
  <div class="login card" style="max-width:460px">
    <h1>Continue your registration</h1>
    <?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
    <?php if ($view === 'draft_gone'): ?><div class="banner bad">This saved form has expired, was already sent, or was locked after too many wrong tries.</div><a class="btn gold" href="/register">Start again</a>
    <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="resume"><input type="hidden" name="draft" value="<?= e(get('draft') ?: post('draft')) ?>">
      <div class="f" style="margin-bottom:14px"><label for="last4">Last 4 digits of the phone number you entered</label><input id="last4" name="last4" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off"></div>
      <button class="btn gold" style="width:100%">Continue</button></form><?php endif; ?>
  </div>
<?php elseif ($view === 'returning'): ?>
  <div class="login card" style="max-width:460px">
    <h1>Already a DhanXfer client?</h1>
    <p class="tag" style="margin-top:0">Confirm it is you, then tell us only what has changed.</p>
    <?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="returning">
      <div class="f" style="margin-bottom:12px"><label for="client_no">Client number <span class="tag">(on your receipts, starts with DX-)</span></label><input id="client_no" name="client_no" required autocomplete="off" style="text-transform:uppercase"></div>
      <div class="f" style="margin-bottom:12px"><label for="rdob">Date of birth</label><input id="rdob" name="dob" type="date" required></div>
      <div class="f" style="margin-bottom:16px"><label for="rlast4">Last 4 digits of your phone number on file</label><input id="rlast4" name="last4" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off"></div>
      <button class="btn gold" style="width:100%">Continue</button></form>
    <p class="tag">New to DhanXfer? <a href="/register">Register here</a>.</p>
  </div>
<?php elseif ($view === 'returning_otp'): $pc = one('SELECT * FROM clients WHERE id=?', [$_SESSION['returning_pending']['id'] ?? 0]); ?>
  <div class="login card" style="max-width:460px">
    <h1>Enter your code</h1>
    <p class="tag" style="margin-top:0"><?= e($otpMsg ?? ('We sent a 6-digit code to ' . implode(' / ', $pc ? otp_channels($pc) : []) . '.')) ?> It works for 10 minutes.</p>
    <?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="returning_otp">
      <div class="f" style="margin-bottom:14px"><label for="otp">6-digit code</label><input id="otp" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]{6,7}" style="font-size:22px;letter-spacing:.25em;text-align:center"></div>
      <button class="btn gold" style="width:100%">Continue</button></form>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><button class="btn ghost" name="act" value="returning_resend" style="width:100%">Send a new code</button></form>
  </div>
<?php elseif ($view === 'returning_form'): $c = one('SELECT * FROM clients WHERE id=?', [$_SESSION['returning']['id']]); $dv = doc_current((int)$c['id']); ?>
  <div class="card" style="max-width:620px;margin:0 auto">
    <h1>Welcome back, <?= e(explode(' ', $c['name'])[0]) ?></h1>
    <p class="tag" style="margin-top:0">We have your details on file (phone ending <?= e(substr(preg_replace('/\D/', '', (string)$c['phone']), -4)) ?>). You don't need to enter them again: just tell us what changed.</p>
    <?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="returning_update">
      <span class="lbl">What has changed?</span>
      <?php foreach (['address' => 'I moved (new address)', 'phone' => 'New phone number', 'id' => 'New or renewed ID', 'beneficiary' => 'New person to send money to', 'other' => 'Something else'] as $k => $l): ?><label class="check" style="margin:6px 0"><input type="checkbox" name="changes[]" value="<?= $k ?>"><span><?= e($l) ?></span></label><?php endforeach; ?>
      <div class="fields" style="margin-top:10px">
        <div class="f wide" data-show-if="changes[]=address"><label for="raddr">New street address</label><input id="raddr" name="address"></div>
        <div class="f" data-show-if="changes[]=address"><label for="rcity">City</label><input id="rcity" name="city"></div>
        <div class="f" data-show-if="changes[]=address"><label for="rpostal">Postal code</label><input id="rpostal" name="postal"></div>
        <div class="f" data-show-if="changes[]=phone"><label for="rphone">New phone number</label><input id="rphone" name="phone" type="tel" inputmode="tel"></div>
        <div class="f" data-show-if="changes[]=id"><label for="rid_type">ID type</label><select id="rid_type" name="id_type"><?= opts(ID_TYPES, '') ?></select></div>
        <div class="f" data-show-if="changes[]=id"><label for="rid_number">ID number</label><input id="rid_number" name="id_number" autocomplete="off"></div>
        <div class="f" data-show-if="changes[]=id"><label for="rid_expiry">Expiry date</label><input id="rid_expiry" name="id_expiry" type="date"></div>
        <div class="f wide" data-show-if="changes[]=id"><label for="rid_file">Photo of the new ID</label><input id="rid_file" name="id_file" type="file" accept="image/*,application/pdf" capture="environment"></div>
        <div class="f wide" data-show-if="changes[]=beneficiary"><div id="benefs"><div class="benef card" style="padding:12px;margin-bottom:8px"><div class="fields">
          <div class="f"><label>Their full name</label><input name="b_name[]"></div><div class="f"><label>Relationship</label><input name="b_relation[]"></div>
          <div class="f"><label>Country</label><input name="b_country[]"></div><div class="f"><label>Bank</label><input name="b_bank[]"></div>
          <div class="f"><label>Account / IBAN</label><input name="b_account[]" autocomplete="off"></div><div class="f"><label>Phone</label><input name="b_phone[]" type="tel"></div></div></div></div>
          <button type="button" class="btn sm" id="addBenef" data-max="5">+ Add another person</button><p class="tag">Each new person is checked by our team before money can be sent to them.</p></div>
        <div class="f wide"><label for="rnotes">Anything else?</label><textarea id="rnotes" name="notes"></textarea></div>
      </div>
      <label class="check" style="margin-top:12px"><input type="checkbox" name="consent" value="1" required><span>These details are correct and DhanXfer may store them. <span class="req">*</span></span></label>
      <button class="btn gold" style="width:100%;margin-top:12px">Send update</button>
    </form>
  </div>
<?php else: ?>
  <div class="hero">
    <h1>Register with DhanXfer</h1>
    <p>A few quick steps and our team will set up your account before your first transfer. Your information is kept private and used only to provide money transfer services.</p>
    <?php if ($agent): ?><p class="tag">You were referred by DhanXfer agent <strong><?= e($agent['username']) ?></strong>.</p><?php endif; ?>
    <p class="tag"><a href="/register?returning=1">Already a client? Update your details</a></p>
  </div>
  <?php foreach ($errors as $er): ?><div class="banner bad"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="wizard" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
    <?php client_wizard($v, $benefs, $agent ? 'public_locked' : 'public'); ?>
  </form>
  <p class="tag" style="text-align:center">Already applied? <a href="/status">Check your application status</a>.</p>
<?php endif;
page_foot();
