<?php
// Secure document upload for a client, from a one-time link sent by DhanXfer staff.
// The client first confirms their date of birth and the last 4 digits of their phone; 5 wrong tries lock the link.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/compliance.php';
require __DIR__ . '/inc/otp.php';

$tok = (string)(get('t') ?: post('t'));
$r = upload_request_find($tok);
$err = []; $done = false;
$verified = $r && !empty($_SESSION['upload_ok'][$r['id']]) && $r['verified_at'];
$client = $r ? one('SELECT * FROM clients WHERE id=?', [$r['client_id']]) : null;
// with an email (or SMS) on file, a one-time code to it is needed too; otherwise the staff-sent link is the second factor
$needOtp = $client && otp_channels($client);
$otpOk = $r && !empty($_SESSION['upload_otp'][$r['id']]);
$otpMsg = null;

if ($r && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('act') === 'verify') {
        if (upload_request_verify($r, post('dob'), preg_replace('/\D/', '', post('last4')))) {
            session_regenerate_id(true); $_SESSION['upload_ok'][$r['id']] = true; $verified = true;
            if ($needOtp) { [$sent, $otpMsg] = otp_send($client, 'upload'); if (!$sent) $err[] = $otpMsg; }
        }
        else { $err[] = 'Those details do not match our records.'; $r = upload_request_find($tok); }
    } elseif (post('act') === 'otp' && $verified && $needOtp) {
        if (otp_verify((int)$client['id'], 'upload', post('code'))) { $_SESSION['upload_otp'][$r['id']] = true; $otpOk = true; }
        else $err[] = 'That code did not work. Check the newest message.';
    } elseif (post('act') === 'otp_resend' && $verified && $needOtp) {
        [, $otpMsg] = otp_send($client, 'upload');
    } elseif (post('act') === 'upload' && $verified && (!$needOtp || $otpOk)) {
        $exp = valid_date(post('id_expiry'));
        if (!in_array(post('id_type'), ID_TYPES, true)) $err[] = 'Choose the ID type.';
        if (trim(post('id_number')) === '') $err[] = 'Enter the ID number.';
        if (!$exp || $exp <= today()) $err[] = 'Enter the expiry date of a current (not expired) ID.';
        if (!$err && id_number_owner(mb_substr(trim(post('id_number')), 0, 60), (int)$r['client_id'])) $err[] = 'We could not accept this ID number online. Please contact DhanXfer on WhatsApp +1 (942)-6600-0600.';
        if (!$err && empty($_FILES['id_file']['name'])) $err[] = 'Add a photo or scan of the ID.';
        $file = null;
        if (!$err) { try { $file = save_id_upload('id_file'); } catch (RuntimeException $ex) { $err[] = $ex->getMessage(); } }
        if (!$err && $file) {
            // single use: only the request that marks the link used may save
            if (q('UPDATE upload_requests SET used_at=NOW() WHERE id=? AND used_at IS NULL', [$r['id']])->rowCount() === 1) try {
                $has = post('id_has_address') === 'yes' ? true : (post('id_has_address') === 'no' ? false : null);
                $addr = $has && post('id_addr_same') === 'no' ? ['line1' => mb_substr(post('id_addr_line1'), 0, 160), 'city' => mb_substr(post('id_addr_city'), 0, 100), 'postal' => mb_substr(post('id_addr_postal'), 0, 20), 'country' => mb_substr(post('id_country'), 0, 80)] : null;
                $vid = doc_add_version((int)$r['client_id'], ['doc_type' => post('id_type'), 'number' => mb_substr(trim(post('id_number')), 0, 60), 'issuing_country' => mb_substr(post('id_country'), 0, 80), 'expires_on' => $exp, 'has_address' => $has, 'address' => $addr, 'file_name' => $file], 'upload_link', null);
                if ($r['rekyc_case_id']) q("UPDATE rekyc_cases SET status='in_progress' WHERE id=? AND status IN ('required','escalated')", [$r['rekyc_case_id']]);
                audit_write(null, 'document_uploaded', 'client', $r['client_id'], 'New ID version #' . $vid . ' through the secure link', client_ip());
                notify('Client uploaded an updated document', 'A client uploaded a document through a secure link. It is waiting for KYC review in the Compliance Center.', 'compliance');
                unset($_SESSION['upload_ok'][$r['id']]);
                $done = true;
            } catch (IdNumberTaken $ex) {
                q('UPDATE upload_requests SET used_at=NULL WHERE id=?', [$r['id']]);
                audit_write(null, 'document_refused', 'client', $r['client_id'], 'ID number already on another client record', client_ip());
                $err[] = 'We could not accept this ID number online. Please contact DhanXfer on WhatsApp +1 (942)-6600-0600.';
            } else $err[] = 'This link was already used.';
        }
    }
}
page_head('Upload your document', true); ?>
<div class="login card" style="max-width:520px">
<?php if ($done): ?>
  <h1>Thank you</h1><p>Your document was received securely. Our team will review it and contact you on WhatsApp if anything else is needed.</p>
<?php elseif (!$r): ?>
  <h1>Link not available</h1><div class="banner bad">This link has expired, was already used, or was locked after too many wrong tries. Please ask DhanXfer for a new link on WhatsApp +1 (942)-6600-0600.</div>
<?php elseif (!$verified): ?>
  <h1>Confirm it's you</h1><p class="tag" style="margin-top:0">Before uploading, confirm two details we have on file.</p>
  <?php foreach ($err as $x): ?><div class="banner bad"><?= e($x) ?></div><?php endforeach; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="verify"><input type="hidden" name="t" value="<?= e($tok) ?>">
    <div class="f" style="margin-bottom:12px"><label for="dob">Date of birth</label><input id="dob" name="dob" type="date" required></div>
    <div class="f" style="margin-bottom:16px"><label for="last4">Last 4 digits of your phone number</label><input id="last4" name="last4" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off"></div>
    <button class="btn gold" style="width:100%">Continue</button></form>
<?php elseif ($needOtp && !$otpOk): ?>
  <h1>Enter your code</h1><p class="tag" style="margin-top:0"><?= e($otpMsg ?? ('We sent a 6-digit code to ' . implode(' / ', otp_channels($client)) . '.')) ?> It works for 10 minutes.</p>
  <?php foreach ($err as $x): ?><div class="banner bad"><?= e($x) ?></div><?php endforeach; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="otp"><input type="hidden" name="t" value="<?= e($tok) ?>">
    <div class="f" style="margin-bottom:14px"><label for="code">6-digit code</label><input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" style="font-size:22px;letter-spacing:.25em;text-align:center"></div>
    <button class="btn gold" style="width:100%">Continue</button></form>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($tok) ?>"><button class="btn ghost" name="act" value="otp_resend" style="width:100%">Send a new code</button></form>
<?php else: ?>
  <h1>Upload your current ID</h1>
  <?php foreach ($err as $x): ?><div class="banner bad"><?= e($x) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="upload"><input type="hidden" name="t" value="<?= e($tok) ?>">
    <div class="fields one">
      <div class="f"><label for="id_type">ID type</label><select id="id_type" name="id_type" required><?= opts(ID_TYPES, post('id_type')) ?></select></div>
      <div class="f"><label for="id_number">ID number</label><input id="id_number" name="id_number" required autocomplete="off" maxlength="60"></div>
      <div class="f"><label for="id_country">Issuing country</label><input id="id_country" name="id_country" list="countries"><?= datalist('countries', COUNTRIES) ?></div>
      <div class="f"><label for="id_expiry">Expiry date</label><input id="id_expiry" name="id_expiry" type="date" required></div>
      <div class="f"><span class="lbl">Does the ID show your address?</span><div class="choice"><label><input type="radio" name="id_has_address" value="yes"><span>Yes</span></label><label><input type="radio" name="id_has_address" value="no"><span>No</span></label></div></div>
      <div class="f" data-show-if="id_has_address=yes"><span class="lbl">Same as your home address?</span><div class="choice"><label><input type="radio" name="id_addr_same" value="yes" checked><span>Yes</span></label><label><input type="radio" name="id_addr_same" value="no"><span>No</span></label></div></div>
      <div class="f" data-show-if="id_has_address=yes&amp;id_addr_same=no"><label for="id_addr_line1">Address on the ID</label><input id="id_addr_line1" name="id_addr_line1"></div>
      <div class="f" data-show-if="id_has_address=yes&amp;id_addr_same=no"><label for="id_addr_city">City</label><input id="id_addr_city" name="id_addr_city"></div>
      <div class="f" data-show-if="id_has_address=yes&amp;id_addr_same=no"><label for="id_addr_postal">Postal code</label><input id="id_addr_postal" name="id_addr_postal"></div>
      <div class="f"><label for="id_file">Photo or scan of the ID</label><input id="id_file" name="id_file" type="file" required accept="image/*,application/pdf" capture="environment"></div>
    </div>
    <button class="btn gold" style="width:100%;margin-top:14px">Send securely</button>
  </form>
<?php endif; ?>
</div>
<?php page_foot();
