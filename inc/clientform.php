<?php
defined('DX') or exit;

/** Read and clean the client fields from POST. Returns [fields, beneficiaries, errors]. */
function read_client_post(): array {
    $v = [];
    foreach (CLIENT_FIELDS as $k) $v[$k] = mb_substr(post($k), 0, $k === 'notes' ? 4000 : 250);
    foreach (['dob', 'id_expiry'] as $d) $v[$d] = $v[$d] !== '' ? (valid_date($v[$d]) ?? '') : '';
    if ($v['whatsapp'] === '') $v['whatsapp'] = $v['phone'];
    $err = [];
    if ($v['name'] === '') $err[] = 'Full name is required.';
    if ($v['phone'] === '' || !preg_match('/^[+0-9 ()\-.]{7,25}$/', $v['phone'])) $err[] = 'Enter a valid phone number with country code.';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'The email address does not look right.';
    if ($v['office'] !== '' && !in_array($v['office'], OFFICES, true)) $v['office'] = '';
    $b = [];
    $bn = $_POST['b_name'] ?? [];
    if (is_array($bn)) {
        foreach ($bn as $i => $name) {
            $name = is_string($name) ? trim($name) : '';
            if ($name === '') continue;
            $g = fn($k) => (isset($_POST[$k][$i]) && is_string($_POST[$k][$i])) ? mb_substr(trim($_POST[$k][$i]), 0, 120) : '';
            $b[] = ['name' => mb_substr($name, 0, 160), 'relation' => $g('b_relation'), 'country' => $g('b_country'), 'bank' => $g('b_bank'), 'account' => $g('b_account'), 'phone' => $g('b_phone')];
            if (count($b) >= 10) break;
        }
    }
    return [$v, $b, $err];
}

function save_beneficiaries(int $clientId, array $list): void {
    q('DELETE FROM beneficiaries WHERE client_id=?', [$clientId]);
    foreach ($list as $b) {
        q('INSERT INTO beneficiaries (client_id,name,relation,country,bank,account,phone) VALUES (?,?,?,?,?,?,?)',
            [$clientId, $b['name'], $b['relation'], $b['country'], $b['bank'], $b['account'], $b['phone']]);
    }
}

function nz($v) { return ($v === '' || $v === null) ? null : $v; }

/** Insert a client row. $extra may hold kyc, risk, agent_id, source, consent, id_file, created_by. */
function insert_client(array $v, array $extra): int {
    $cols = array_merge(CLIENT_FIELDS, ['client_no', 'kyc', 'risk', 'agent_id', 'source', 'consent', 'id_file', 'created_by']);
    $vals = [];
    foreach (CLIENT_FIELDS as $k) $vals[] = nz($v[$k] ?? '');
    $vals[] = new_client_no();
    $vals[] = $extra['kyc'] ?? 'Pending';
    $vals[] = $extra['risk'] ?? 'Low';
    $vals[] = $extra['agent_id'] ?? null;
    $vals[] = $extra['source'] ?? 'admin';
    $vals[] = !empty($extra['consent']) ? 1 : 0;
    $vals[] = $extra['id_file'] ?? null;
    $vals[] = $extra['created_by'] ?? null;
    q('INSERT INTO clients (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    return (int)db()->lastInsertId();
}

/**
 * Render the client fields.
 * $mode: 'public' (client fills it), 'agent' (sub-agent), 'admin'.
 */
function client_fields(array $v, array $benefs, string $mode, array $agents = []): void {
    $g = fn($k) => e($v[$k] ?? '');
    $sel = fn($k) => $v[$k] ?? '';
    echo datalist('countries', COUNTRIES);
    ?>
    <fieldset>
      <legend><?= $mode === 'public' ? 'About you' : 'Personal details' ?></legend>
      <div class="fields">
        <div class="f"><label for="name">Full legal name<?= $mode === 'public' ? ' (as on your ID)' : '' ?> <span class="req">*</span></label><input id="name" name="name" required maxlength="160" value="<?= $g('name') ?>" autocomplete="name"></div>
        <div class="f"><label for="dob">Date of birth</label><input id="dob" name="dob" type="date" value="<?= $g('dob') ?>"></div>
        <div class="f"><label for="nationality">Nationality</label><input id="nationality" name="nationality" list="countries" value="<?= $g('nationality') ?>"></div>
        <div class="f"><label for="occupation">Occupation</label><input id="occupation" name="occupation" value="<?= $g('occupation') ?>"></div>
        <div class="f"><label for="employer">Employer / business name</label><input id="employer" name="employer" value="<?= $g('employer') ?>"></div>
        <div class="f"><label for="office"><?= $mode === 'public' ? 'Which DhanXfer office are you dealing with?' : 'Office' ?> <span class="req">*</span></label><select id="office" name="office" required><?= opts(OFFICES, $sel('office')) ?></select></div>
      </div>
    </fieldset>
    <fieldset>
      <legend>Contact details</legend>
      <div class="fields">
        <div class="f"><label for="phone">Phone number <span class="req">*</span></label><input id="phone" name="phone" type="tel" required placeholder="+1 416 555 0123" value="<?= $g('phone') ?>" autocomplete="tel"></div>
        <div class="f"><label for="whatsapp">WhatsApp number (if different)</label><input id="whatsapp" name="whatsapp" type="tel" value="<?= $g('whatsapp') ?>"></div>
        <div class="f"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= $g('email') ?>" autocomplete="email"></div>
        <div class="f wide"><label for="address">Street address</label><input id="address" name="address" value="<?= $g('address') ?>" autocomplete="street-address"></div>
        <div class="f"><label for="city">City</label><input id="city" name="city" value="<?= $g('city') ?>"></div>
        <div class="f"><label for="province">Province / Emirate</label><input id="province" name="province" value="<?= $g('province') ?>"></div>
        <div class="f"><label for="postal">Postal code</label><input id="postal" name="postal" value="<?= $g('postal') ?>"></div>
        <div class="f"><label for="country">Country of residence</label><input id="country" name="country" list="countries" value="<?= $g('country') ?>"></div>
      </div>
    </fieldset>
    <fieldset>
      <legend>Identification</legend>
      <?php if ($mode === 'public'): ?><p class="tag" style="margin-top:0">Required by law for money transfers. We check your original ID before your first transfer.</p><?php endif; ?>
      <div class="fields">
        <div class="f"><label for="id_type">ID type</label><select id="id_type" name="id_type"><?= opts(ID_TYPES, $sel('id_type')) ?></select></div>
        <div class="f"><label for="id_number">ID number</label><input id="id_number" name="id_number" value="<?= $g('id_number') ?>" autocomplete="off"></div>
        <div class="f"><label for="id_country">ID issuing country</label><input id="id_country" name="id_country" list="countries" value="<?= $g('id_country') ?>"></div>
        <div class="f"><label for="id_expiry">ID expiry date</label><input id="id_expiry" name="id_expiry" type="date" value="<?= $g('id_expiry') ?>"></div>
        <div class="f wide"><label for="id_file">Photo or scan of the ID (optional, JPG/PNG/PDF, max 6 MB)<?= !empty($v['id_file']) ? ' · a file is already on record; uploading replaces it' : '' ?></label><input id="id_file" name="id_file" type="file" accept="image/*,application/pdf"></div>
        <?php if ($mode === 'admin'): ?>
        <div class="f"><label for="kyc">KYC status</label><select id="kyc" name="kyc"><?= opts(KYC, $sel('kyc') ?: 'Pending', '') ?></select></div>
        <div class="f"><label for="risk">Risk level</label><select id="risk" name="risk"><?= opts(RISK, $sel('risk') ?: 'Low', '') ?></select></div>
        <?php endif; ?>
      </div>
    </fieldset>
    <fieldset>
      <legend><?= $mode === 'public' ? 'About your transfers' : 'Transfer profile' ?></legend>
      <div class="fields">
        <div class="f"><label for="purpose">Main purpose of transfers</label><select id="purpose" name="purpose"><?= opts(PURPOSES, $sel('purpose')) ?></select></div>
        <div class="f"><label for="source_of_funds">Source of funds</label><select id="source_of_funds" name="source_of_funds"><?= opts(SOF, $sel('source_of_funds')) ?></select></div>
        <div class="f"><label for="monthly_volume">Expected monthly amount</label><select id="monthly_volume" name="monthly_volume"><?= opts(VOLUMES, $sel('monthly_volume')) ?></select></div>
        <div class="f"><label for="destination">Usual destination country</label><input id="destination" name="destination" list="countries" value="<?= $g('destination') ?>"></div>
        <div class="f"><label for="referral">How did <?= $mode === 'public' ? 'you' : 'they' ?> hear about DhanXfer?</label><select id="referral" name="referral"><?= opts(HEARD, $sel('referral')) ?></select></div>
        <div class="f"><label for="referred_by">Referred by (name)</label><input id="referred_by" name="referred_by" value="<?= $g('referred_by') ?>"></div>
        <?php if ($mode === 'public'): ?>
        <div class="f"><label for="ref_code">Sub-agent referral ID</label><input id="ref_code" name="ref_code" placeholder="e.g. SA-1023" value="<?= $g('ref_code') ?>" style="text-transform:uppercase"></div>
        <?php elseif ($mode === 'admin' || $mode === 'staff'): ?>
        <div class="f"><label for="agent_id">Referral sub-agent</label><select id="agent_id" name="agent_id"><option value="">None (direct client)</option>
          <?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (string)($v['agent_id'] ?? '') === (string)$a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ' · ' . $a['ref_code']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
      </div>
    </fieldset>
    <fieldset>
      <legend><?= $mode === 'public' ? 'Who will you send money to? (optional)' : 'Beneficiaries' ?></legend>
      <div id="benefs">
      <?php foreach ($benefs ?: [[]] as $b): $bv = fn($k) => e($b[$k] ?? ''); ?>
        <div class="benef">
          <div class="f"><label>Full name</label><input name="b_name[]" value="<?= $bv('name') ?>"></div>
          <div class="f"><label>Relationship</label><input name="b_relation[]" value="<?= $bv('relation') ?>" placeholder="Mother, brother…"></div>
          <div class="f"><label>Country</label><input name="b_country[]" list="countries" value="<?= $bv('country') ?>"></div>
          <div class="f"><label>Bank name</label><input name="b_bank[]" value="<?= $bv('bank') ?>"></div>
          <div class="f"><label>Account / IBAN</label><input name="b_account[]" value="<?= $bv('account') ?>" autocomplete="off"></div>
          <div class="f"><label>Phone</label><input name="b_phone[]" value="<?= $bv('phone') ?>"></div>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="btn sm" id="addBenef">+ Add another beneficiary</button>
    </fieldset>
    <fieldset>
      <legend><?= $mode === 'public' ? 'Confirm' : 'Notes & consent' ?></legend>
      <div class="f wide"><label for="notes"><?= $mode === 'public' ? 'Anything else we should know?' : 'Notes' ?></label><textarea id="notes" name="notes"><?= $g('notes') ?></textarea></div>
      <label class="check" style="margin-top:12px"><input type="checkbox" name="consent" value="1" <?= !empty($v['consent']) ? 'checked' : '' ?> <?= $mode === 'public' ? 'required' : '' ?>>
        <span><?= $mode === 'public' ? 'I confirm these details are correct and I agree to DhanXfer storing them to provide money transfer services and meet identity-verification rules. <span class="req">*</span>' : 'Client agreed to DhanXfer collecting and storing these details' ?></span></label>
    </fieldset>
    <?php
}

/**
 * Step-by-step version of the client form (public registration and sub-agents on mobile).
 * Same field names as client_fields(), so the same server code reads it.
 * Without JavaScript every step shows at once and the form still works.
 */
function client_wizard(array $v, array $benefs, string $mode): void {
    $pub = $mode === 'public';
    $g = fn($k) => e($v[$k] ?? '');
    $sel = fn($k) => $v[$k] ?? '';
    $you = $pub ? 'your' : "the client's";
    $b = $benefs[0] ?? [];
    $bv = fn($k) => e($b[$k] ?? '');
    echo datalist('countries', COUNTRIES);
    ?>
    <div class="wiz-head" hidden>
      <div class="wiz-bar"><span id="wizFill"></span></div>
      <div class="row" style="justify-content:space-between;margin-top:8px"><strong id="wizTitle"></strong><span class="tag" id="wizCount"></span></div>
    </div>

    <section class="step" data-title="<?= $pub ? "Let's get started" : 'Client basics' ?>">
      <?php if ($pub): ?><p class="tag" style="margin-top:0">Just 3 details to begin. It takes about 2 minutes in total.</p><?php endif; ?>
      <div class="fields one">
        <div class="f"><label for="name">Full legal name<?= $pub ? ' (as on your ID)' : '' ?> <span class="req">*</span></label><input id="name" name="name" required maxlength="160" value="<?= $g('name') ?>" autocomplete="name"></div>
        <div class="f"><label for="phone">Phone number <span class="req">*</span></label><input id="phone" name="phone" type="tel" inputmode="tel" required placeholder="+1 416 555 0123" pattern="[+0-9 ()\-.]{7,25}" value="<?= $g('phone') ?>" autocomplete="tel"></div>
        <div class="f"><label for="whatsapp">WhatsApp number <span class="tag">(leave empty if same as phone)</span></label><input id="whatsapp" name="whatsapp" type="tel" inputmode="tel" value="<?= $g('whatsapp') ?>"></div>
        <div class="f"><span class="lbl"><?= $pub ? 'Which DhanXfer office?' : 'Office' ?> <span class="req">*</span></span>
          <div class="choice"><?php foreach (OFFICES as $o): ?><label><input type="radio" name="office" value="<?= e($o) ?>" required <?= $sel('office') === $o ? 'checked' : '' ?>><span><?= e($o) ?></span></label><?php endforeach; ?></div></div>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'About you' : 'Personal details' ?>">
      <div class="fields">
        <div class="f"><label for="dob">Date of birth</label><input id="dob" name="dob" type="date" value="<?= $g('dob') ?>"></div>
        <div class="f"><label for="email">Email</label><input id="email" name="email" type="email" inputmode="email" value="<?= $g('email') ?>" autocomplete="email"></div>
        <div class="f"><label for="nationality">Nationality</label><input id="nationality" name="nationality" list="countries" value="<?= $g('nationality') ?>"></div>
        <div class="f"><label for="occupation">Occupation</label><input id="occupation" name="occupation" value="<?= $g('occupation') ?>"></div>
        <div class="f wide"><label for="employer">Employer / business name</label><input id="employer" name="employer" value="<?= $g('employer') ?>"></div>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'Your address' : 'Address' ?>">
      <div class="fields">
        <div class="f wide"><label for="address">Street address</label><input id="address" name="address" value="<?= $g('address') ?>" autocomplete="street-address"></div>
        <div class="f"><label for="city">City</label><input id="city" name="city" value="<?= $g('city') ?>" autocomplete="address-level2"></div>
        <div class="f"><label for="province">Province / Emirate</label><input id="province" name="province" value="<?= $g('province') ?>" autocomplete="address-level1"></div>
        <div class="f"><label for="postal">Postal code</label><input id="postal" name="postal" value="<?= $g('postal') ?>" autocomplete="postal-code"></div>
        <div class="f"><label for="country">Country of residence</label><input id="country" name="country" list="countries" value="<?= $g('country') ?>"></div>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'Your photo ID' : 'Photo ID' ?>">
      <p class="tag" style="margin-top:0"><?= $pub ? 'Required by law for money transfers. We check your original ID before your first transfer.' : 'Check the original ID before the first transfer.' ?></p>
      <div class="fields">
        <div class="f"><label for="id_type">ID type</label><select id="id_type" name="id_type"><?= opts(ID_TYPES, $sel('id_type')) ?></select></div>
        <div class="f"><label for="id_number">ID number</label><input id="id_number" name="id_number" value="<?= $g('id_number') ?>" autocomplete="off"></div>
        <div class="f"><label for="id_country">Issuing country</label><input id="id_country" name="id_country" list="countries" value="<?= $g('id_country') ?>"></div>
        <div class="f"><label for="id_expiry">Expiry date</label><input id="id_expiry" name="id_expiry" type="date" value="<?= $g('id_expiry') ?>"></div>
        <div class="f wide"><label for="id_file">Photo of the ID <span class="tag">(optional · JPG, PNG or PDF, up to 6 MB)</span></label><input id="id_file" name="id_file" type="file" accept="image/*,application/pdf" capture="environment"></div>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'About your transfers' : 'Transfer profile' ?>">
      <div class="fields">
        <div class="f"><label for="purpose">Main purpose</label><select id="purpose" name="purpose"><?= opts(PURPOSES, $sel('purpose')) ?></select></div>
        <div class="f"><label for="source_of_funds">Source of funds</label><select id="source_of_funds" name="source_of_funds"><?= opts(SOF, $sel('source_of_funds')) ?></select></div>
        <div class="f"><label for="monthly_volume">Expected monthly amount</label><select id="monthly_volume" name="monthly_volume"><?= opts(VOLUMES, $sel('monthly_volume')) ?></select></div>
        <div class="f"><label for="destination">Usual destination country</label><input id="destination" name="destination" list="countries" value="<?= $g('destination') ?>"></div>
        <div class="f"><label for="referral">How did <?= $pub ? 'you' : 'they' ?> hear about us?</label><select id="referral" name="referral"><?= opts(HEARD, $sel('referral')) ?></select></div>
        <div class="f"><label for="referred_by">Referred by (name)</label><input id="referred_by" name="referred_by" value="<?= $g('referred_by') ?>"></div>
        <?php if ($pub): ?><div class="f"><label for="ref_code">Sub-agent referral ID</label><input id="ref_code" name="ref_code" placeholder="e.g. SA-1023" value="<?= $g('ref_code') ?>" style="text-transform:uppercase" <?= $g('ref_code') !== '' ? 'readonly' : '' ?>></div><?php endif; ?>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'Who will you send money to?' : 'Beneficiary' ?>" data-optional="1">
      <p class="tag" style="margin-top:0">Optional. <?= $pub ? 'You can also tell us later.' : 'More beneficiaries can be added on the client page.' ?></p>
      <div class="fields">
        <div class="f"><label for="b_name0">Full name</label><input id="b_name0" name="b_name[]" value="<?= $bv('name') ?>"></div>
        <div class="f"><label for="b_relation0">Relationship to <?= $pub ? 'you' : 'the client' ?></label><input id="b_relation0" name="b_relation[]" value="<?= $bv('relation') ?>" placeholder="Mother, brother…"></div>
        <div class="f"><label for="b_country0">Country</label><input id="b_country0" name="b_country[]" list="countries" value="<?= $bv('country') ?>"></div>
        <div class="f"><label for="b_bank0">Bank name</label><input id="b_bank0" name="b_bank[]" value="<?= $bv('bank') ?>"></div>
        <div class="f"><label for="b_account0">Account number / IBAN</label><input id="b_account0" name="b_account[]" value="<?= $bv('account') ?>" autocomplete="off"></div>
        <div class="f"><label for="b_phone0">Phone</label><input id="b_phone0" name="b_phone[]" type="tel" value="<?= $bv('phone') ?>"></div>
      </div>
    </section>

    <section class="step" data-title="Check and send" data-review="1">
      <div id="wizReview" class="review"></div>
      <div class="f wide" style="margin-top:12px"><label for="notes"><?= $pub ? 'Anything else we should know?' : 'Notes' ?></label><textarea id="notes" name="notes"><?= $g('notes') ?></textarea></div>
      <label class="check" style="margin-top:12px"><input type="checkbox" name="consent" value="1" <?= !empty($v['consent']) ? 'checked' : '' ?> <?= $pub ? 'required' : '' ?>>
        <span><?= $pub ? 'I confirm these details are correct and I agree to DhanXfer storing them to provide money transfer services and meet identity-verification rules. <span class="req">*</span>' : 'Client agreed to DhanXfer collecting and storing these details' ?></span></label>
    </section>

    <div class="wiz-nav">
      <button type="button" class="btn" id="wizBack" hidden>Back</button>
      <button type="button" class="btn ghost" id="wizSkip" hidden>Skip this step</button>
      <button type="button" class="btn gold" id="wizNext" hidden>Next</button>
      <button type="submit" class="btn gold" id="wizSubmit"><?= $pub ? 'Send my details' : 'Save client' ?></button>
    </div>
    <?php
}
