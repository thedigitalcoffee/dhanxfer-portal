<?php
defined('DX') or exit;
require_once __DIR__ . '/kyc.php';
require_once __DIR__ . '/beneficiary.php';

/** Read and clean the client fields from POST. Returns [fields, beneficiaries, errors]. */
function read_client_post(int $clientId = 0): array {
    $v = [];
    foreach (CLIENT_FIELDS as $k) $v[$k] = mb_substr(post($k), 0, $k === 'notes' ? 4000 : 250);
    foreach (['dob', 'id_expiry'] as $d) $v[$d] = $v[$d] !== '' ? (valid_date($v[$d]) ?? '') : '';
    $err = [];
    // questions that were hidden because they did not apply are ignored even if a value came in
    $v['wa_same'] = post('wa_same') === 'no' ? 'no' : 'yes';
    if ($v['wa_same'] === 'yes') $v['whatsapp'] = '';
    if (!in_array($v['occupation'], ['Employed', 'Self-employed'], true) && in_array($v['occupation'], OCCUPATIONS, true)) $v['employer'] = '';
    if ($v['purpose'] === 'Other' && trim(post('purpose_other')) !== '') $v['purpose'] = 'Other: ' . mb_substr(trim(post('purpose_other')), 0, 70);
    $v['purpose_other'] = mb_substr(trim(post('purpose_other')), 0, 80);
    $v['address_source'] = post('address_source') === 'lookup' ? 'lookup' : 'manual';
    $v['id_has_address'] = in_array(post('id_has_address'), ['yes', 'no'], true) ? post('id_has_address') : '';
    $v['id_addr_same'] = post('id_addr_same') === 'no' ? 'no' : 'yes';
    foreach (['id_addr_line1', 'id_addr_city', 'id_addr_postal'] as $k) $v[$k] = $v['id_has_address'] === 'yes' && $v['id_addr_same'] === 'no' ? mb_substr(trim(post($k)), 0, 160) : '';
    if ($v['name'] === '') $err[] = 'Full name is required.';
    // phone: checked for the country the client chose (not guessed from the connection)
    $region = phone_region($v['country']) ?? (str_starts_with(trim($v['phone']), '+') ? null : ($v['office'] === 'Dubai' ? 'AE' : null));
    $ph = phone_check($v['phone'], $region);
    if (!$ph['ok']) $err[] = 'Phone: ' . $ph['error'];
    else { $v['phone_e164'] = $ph['e164']; $v['phone_region'] = $ph['region']; $v['phone'] = $ph['international']; }
    if ($v['whatsapp'] !== '') { $wa = phone_check($v['whatsapp'], $region); if (!$wa['ok']) $err[] = 'WhatsApp: ' . $wa['error']; else $v['whatsapp'] = $wa['international']; }
    else $v['whatsapp'] = $v['phone'];
    if ($v['office'] === '' && $v['country'] !== '') $v['office'] = $v['country'] === 'United Arab Emirates' ? 'Dubai' : 'Canada';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'The email address does not look right.';
    if ($v['office'] !== '' && !in_array($v['office'], OFFICES, true)) $v['office'] = '';
    if ($v['id_expiry'] !== '' && $v['id_expiry'] < today()) $err[] = 'The ID has already expired. Please use a current ID.';
    $b = [];
    $bn = $_POST['b_name'] ?? [];
    if (is_array($bn)) {
        foreach ($bn as $i => $name) {
            $name = is_string($name) ? trim($name) : '';
            if ($name === '') continue;
            $g = fn($k) => (isset($_POST[$k][$i]) && is_string($_POST[$k][$i])) ? mb_substr(trim($_POST[$k][$i]), 0, 120) : '';
            $acct = $g('b_account');
            $keepId = (int)($_POST['b_keep_id'][$i] ?? 0);
            if ($acct === '' && $keepId && $clientId) $acct = (string)dec((string)val('SELECT account FROM beneficiaries WHERE id=? AND client_id=?', [$keepId, $clientId]));
            $b[] = ['name' => mb_substr($name, 0, 160), 'relation' => $g('b_relation'), 'country' => $g('b_country'), 'bank' => $g('b_bank'), 'account' => $acct, 'phone' => $g('b_phone')];
            if (count($b) >= 10) break;
        }
    }
    return [$v, $b, $err];
}

/** Adds the beneficiaries from a new-client form. Each waits for review. Returns the problems (if any). */
function save_beneficiaries(int $clientId, array $list, string $source = 'admin', ?int $by = null): array {
    require_once __DIR__ . '/beneficiary.php';
    $errs = [];
    foreach ($list as $b) { [, $e] = benef_add($clientId, $b, $source, $by); foreach ($e as $x) $errs[] = ($b['name'] ?? '') . ': ' . $x; }
    return $errs;
}

function nz($v) { return ($v === '' || $v === null) ? null : $v; }

/** Insert a client row. $extra may hold kyc, risk, agent_id, source, consent, id_file, created_by. */
function insert_client(array $v, array $extra): int {
    $cols = array_merge(CLIENT_FIELDS, ['client_no', 'kyc', 'risk', 'agent_id', 'source', 'consent', 'id_file', 'created_by', 'id_number_hash']);
    $vals = [];
    foreach (CLIENT_FIELDS as $k) $vals[] = $k === 'id_number' ? enc(nz($v[$k] ?? '')) : nz($v[$k] ?? '');
    $vals[] = new_client_no();
    $vals[] = $extra['kyc'] ?? 'Pending';
    $vals[] = $extra['risk'] ?? 'Low';
    $vals[] = $extra['agent_id'] ?? null;
    $vals[] = $extra['source'] ?? 'admin';
    $vals[] = !empty($extra['consent']) ? 1 : 0;
    $vals[] = $extra['id_file'] ?? null;
    $vals[] = $extra['created_by'] ?? null;
    $vals[] = id_hash($v['id_number'] ?? '');
    $cols = array_merge($cols, ['phone_e164', 'phone_region', 'address_source']);
    array_push($vals, $v['phone_e164'] ?? null, $v['phone_region'] ?? null, $v['address_source'] ?? null);
    // the ID number and file live in the document history; the client row only keeps a summary (set by doc_add_version)
    $idx = array_search('id_number', $cols, true); $vals[$idx] = null; $vals[array_search('id_number_hash', $cols, true)] = null; $vals[array_search('id_file', $cols, true)] = null;
    q('INSERT INTO clients (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    $id = (int)db()->lastInsertId();
    client_save_document($id, $v, $extra['id_file'] ?? null, (string)($extra['source'] ?? 'admin'), $extra['created_by'] ?? null);
    return $id;
}
/** Records the ID details from a form as a new document version (only when something about the ID was given). */
function client_save_document(int $clientId, array $v, ?string $file, string $source, ?int $by): ?int {
    require_once __DIR__ . '/kyc.php';
    $num = trim((string)($v['id_number'] ?? ''));
    $has = ($v['id_has_address'] ?? '') === 'yes' ? true : (($v['id_has_address'] ?? '') === 'no' ? false : null);
    if ($num === '' && !$file && ($v['id_expiry'] ?? '') === '' && ($v['id_type'] ?? '') === '') return null;
    $addr = null;
    if ($has && ($v['id_addr_same'] ?? 'yes') === 'no')
        $addr = ['line1' => $v['id_addr_line1'] ?? '', 'city' => $v['id_addr_city'] ?? '', 'postal' => $v['id_addr_postal'] ?? '', 'country' => $v['id_country'] ?? ''];
    $src = in_array($source, ['admin', 'agent', 'online', 'upload_link', 'migration'], true) ? $source : 'admin';
    return doc_add_version($clientId, ['doc_type' => $v['id_type'] ?? null, 'number' => $num, 'issuing_country' => $v['id_country'] ?? null,
        'expires_on' => $v['id_expiry'] ?? null, 'has_address' => $has, 'address' => $addr, 'file_name' => $file], $src, $by);
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
        <div class="f"><label for="id_number">ID number<?= !empty($v['id_masked']) ? ' · on file: ' . e($v['id_masked']) . ' (leave empty to keep)' : '' ?></label><input id="id_number" name="id_number" value="<?= $g('id_number') ?>" autocomplete="off"></div>
        <div class="f"><label for="id_country">ID issuing country</label><input id="id_country" name="id_country" list="countries" value="<?= $g('id_country') ?>"></div>
        <div class="f"><label for="id_expiry">ID expiry date</label><input id="id_expiry" name="id_expiry" type="date" value="<?= $g('id_expiry') ?>"></div>
        <div class="f wide"><label for="id_file">Photo or scan of the ID (optional, JPG/PNG/PDF, max 6 MB)<?= !empty($v['id_file']) ? ' · a file is already on record; uploading replaces it' : '' ?></label><input id="id_file" name="id_file" type="file" accept="image/*,application/pdf"></div>
        <div class="f"><label for="id_has_address">Does the ID show an address?</label><select id="id_has_address" name="id_has_address"><option value="">Not known</option><option value="yes"<?= ($v['id_has_address'] ?? '') === 'yes' || ($v['id_has_address'] ?? '') === '1' ? ' selected' : '' ?>>Yes</option><option value="no"<?= ($v['id_has_address'] ?? '') === 'no' || ($v['id_has_address'] ?? '') === '0' ? ' selected' : '' ?>>No</option></select></div>
        <div class="f"><label for="id_addr_same">Address on ID</label><select id="id_addr_same" name="id_addr_same"><option value="yes">Same as home address</option><option value="no"<?= ($v['id_addr_same'] ?? '') === 'no' ? ' selected' : '' ?>>Different (enter below)</option></select></div>
        <div class="f wide"><label for="id_addr_line1">Street address on the ID (if different)</label><input id="id_addr_line1" name="id_addr_line1" value="<?= $g('id_addr_line1') ?>"></div>
        <div class="f"><label for="id_addr_city">City on the ID</label><input id="id_addr_city" name="id_addr_city" value="<?= $g('id_addr_city') ?>"></div>
        <div class="f"><label for="id_addr_postal">Postal code on the ID</label><input id="id_addr_postal" name="id_addr_postal" value="<?= $g('id_addr_postal') ?>"></div>
        <div class="f wide"><p class="tag" style="margin:0">KYC status and risk are set on the KYC review screen, not here.</p></div>
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
      <?php if (array_filter(array_column($benefs, 'id'))): ?>
      <p class="tag" style="margin:0">Beneficiaries are added, changed, reviewed and deactivated on the client page (each change is reviewed and kept in the history).</p>
      <?php else: ?>
      <div id="benefs">
      <?php foreach ($benefs ?: [[]] as $b): $bv = fn($k) => e($b[$k] ?? ''); ?>
        <div class="benef">
          <div class="f"><label>Full name</label><input name="b_name[]" value="<?= $bv('name') ?>"></div>
          <div class="f"><label>Relationship</label><input name="b_relation[]" value="<?= $bv('relation') ?>" placeholder="Mother, brother…"></div>
          <div class="f"><label>Country</label><input name="b_country[]" list="countries" value="<?= $bv('country') ?>"></div>
          <div class="f"><label>Bank name</label><input name="b_bank[]" value="<?= $bv('bank') ?>"></div>
          <div class="f"><label>Account / IBAN<?= !empty($b['account_masked']) ? ' · on file ' . e($b['account_masked']) : '' ?></label><input name="b_account[]" value="<?= $bv('account') ?>" autocomplete="off" placeholder="<?= !empty($b['account_masked']) ? 'Leave empty to keep' : '' ?>"><input type="hidden" name="b_keep_id[]" value="<?= (int)($b['id'] ?? 0) ?>"></div>
          <div class="f"><label>Phone</label><input name="b_phone[]" value="<?= $bv('phone') ?>"></div>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="btn sm" id="addBenef">+ Add another beneficiary</button>
      <?php endif; ?>
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
 * Step-by-step client form (public registration and sub-agents). Mobile-first and short:
 * questions that do not apply stay hidden (data-show-if), the office follows the country, and the phone
 * field shows the country code for the chosen country. Without JavaScript every step shows and still works.
 */
function client_wizard(array $v, array $benefs, string $mode): void {
    $locked = $mode === 'public_locked';
    $pub = $mode === 'public' || $locked;
    $g = fn($k) => e($v[$k] ?? '');
    $sel = fn($k) => (string)($v[$k] ?? '');
    $yes = fn($k, $val) => ($v[$k] ?? '') === $val ? 'checked' : '';
    $b = $benefs[0] ?? [];
    $bv = fn($k) => e($b[$k] ?? '');
    $you = $pub ? 'you' : 'the client';
    $your = $pub ? 'your' : "the client's";
    $dial = [];
    foreach (COUNTRIES as $cn) $dial[$cn] = phone_dial_code(phone_region($cn));
    echo datalist('countries', COUNTRIES);
    ?>
    <script type="application/json" id="dialCodes"><?= json_encode($dial, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
    <input type="hidden" name="address_source" id="address_source" value="<?= $g('address_source') ?: 'manual' ?>">
    <div class="wiz-head" hidden>
      <div class="wiz-bar"><span id="wizFill"></span></div>
      <div class="row" style="justify-content:space-between;margin-top:8px"><strong id="wizTitle"></strong><span class="tag" id="wizCount"></span></div>
    </div>

    <section class="step" data-title="<?= $pub ? "Let's get started" : 'Client basics' ?>">
      <?php if ($pub): ?><p class="tag" style="margin-top:0">About 2 minutes. You can save and finish later.</p><?php endif; ?>
      <div class="fields one">
        <div class="f"><label for="name">Full legal name<?= $pub ? ' (as on your ID)' : '' ?> <span class="req">*</span></label><input id="name" name="name" required maxlength="160" value="<?= $g('name') ?>" autocomplete="name"></div>
        <div class="f"><label for="country">Country where <?= $pub ? 'you live' : 'the client lives' ?> <span class="req">*</span></label><select id="country" name="country" required autocomplete="country-name"><?= opts(array_merge(COUNTRIES, ['Other']), $sel('country')) ?></select></div>
        <div class="f"><label for="phone">Mobile number <span class="req">*</span></label>
          <div class="phone-row"><span class="dial" id="dialShow" aria-hidden="true"><?= e($dial[$sel('country')] ?? '+') ?></span><input id="phone" name="phone" type="tel" inputmode="tel" required value="<?= $g('phone') ?>" autocomplete="tel-national" placeholder="416 555 0123" maxlength="25"></div>
          <div class="tag" id="phoneHint">Spaces and dashes are fine. For another country, start with + and its code.</div></div>
        <div class="f"><span class="lbl">Is this number on WhatsApp?</span>
          <div class="choice"><label><input type="radio" name="wa_same" value="yes" <?= ($v['wa_same'] ?? 'yes') === 'yes' ? 'checked' : '' ?>><span>Yes</span></label><label><input type="radio" name="wa_same" value="no" <?= $yes('wa_same', 'no') ?>><span>No, another number</span></label></div></div>
        <div class="f" data-show-if="wa_same=no"><label for="whatsapp">WhatsApp number</label><input id="whatsapp" name="whatsapp" type="tel" inputmode="tel" value="<?= $g('whatsapp') ?>"></div>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'Where do you live?' : 'Home address' ?>">
      <div class="fields">
        <div class="f wide suggest"><label for="address">Street address <span class="tag">(with unit or apartment)</span></label><input id="address" name="address" value="<?= $g('address') ?>" autocomplete="<?= addr_lookup_enabled() ? 'off' : 'street-address' ?>" data-lookup="<?= addr_lookup_enabled() ? '1' : '' ?>" placeholder="<?= addr_lookup_enabled() ? 'Start typing, then pick your address' : '' ?>"><div class="suggest-list" id="addrList" hidden></div></div>
        <div class="f"><label for="city">City</label><input id="city" name="city" value="<?= $g('city') ?>" autocomplete="address-level2"></div>
        <div class="f"><label for="province">Province / State / Emirate</label><input id="province" name="province" value="<?= $g('province') ?>" autocomplete="address-level1"></div>
        <div class="f"><label for="postal">Postal code</label><input id="postal" name="postal" value="<?= $g('postal') ?>" autocomplete="postal-code" style="text-transform:uppercase"></div>
      </div>
      <?php if (addr_lookup_enabled()): ?><p class="tag">Can't find it? Just type it in the boxes.</p><?php endif; ?>
    </section>

    <section class="step" data-title="<?= $pub ? 'Your photo ID' : 'Photo ID' ?>">
      <p class="tag" style="margin-top:0"><?= $pub ? 'Required by law for money transfers. We check your original ID before your first transfer.' : 'Check the original ID before the first transfer.' ?></p>
      <div class="fields">
        <div class="f"><label for="id_type">ID type</label><select id="id_type" name="id_type"><?= opts(ID_TYPES, $sel('id_type')) ?></select></div>
        <div class="f"><label for="id_number">ID number</label><input id="id_number" name="id_number" value="<?= $g('id_number') ?>" autocomplete="off" maxlength="60"></div>
        <div class="f"><label for="id_country">Issuing country</label><input id="id_country" name="id_country" list="countries" value="<?= $g('id_country') ?>"></div>
        <div class="f"><label for="id_expiry">Expiry date</label><input id="id_expiry" name="id_expiry" type="date" value="<?= $g('id_expiry') ?>"></div>
        <div class="f wide"><span class="lbl">Does the ID show <?= $your ?> address?</span>
          <div class="choice"><label><input type="radio" name="id_has_address" value="yes" <?= $yes('id_has_address', 'yes') ?>><span>Yes</span></label><label><input type="radio" name="id_has_address" value="no" <?= $yes('id_has_address', 'no') ?>><span>No</span></label></div></div>
        <div class="f wide" data-show-if="id_has_address=yes"><span class="lbl">Is it the same as the home address?</span>
          <div class="choice"><label><input type="radio" name="id_addr_same" value="yes" <?= ($v['id_addr_same'] ?? '') !== 'no' ? 'checked' : '' ?>><span>Yes, same</span></label><label><input type="radio" name="id_addr_same" value="no" <?= $yes('id_addr_same', 'no') ?>><span>No, it's different</span></label></div></div>
        <div class="f wide" data-show-if="id_has_address=yes&amp;id_addr_same=no"><label for="id_addr_line1">Address on the ID</label><input id="id_addr_line1" name="id_addr_line1" value="<?= $g('id_addr_line1') ?>"></div>
        <div class="f" data-show-if="id_has_address=yes&amp;id_addr_same=no"><label for="id_addr_city">City on the ID</label><input id="id_addr_city" name="id_addr_city" value="<?= $g('id_addr_city') ?>"></div>
        <div class="f" data-show-if="id_has_address=yes&amp;id_addr_same=no"><label for="id_addr_postal">Postal code on the ID</label><input id="id_addr_postal" name="id_addr_postal" value="<?= $g('id_addr_postal') ?>" style="text-transform:uppercase"></div>
        <div class="f wide"><label for="id_file">Photo of the ID <span class="tag">(JPG, PNG or PDF, up to 6 MB)</span></label><input id="id_file" name="id_file" type="file" accept="image/*,application/pdf" capture="environment"></div>
      </div>
      <p class="tag" data-show-if="id_has_address=no">No problem: we may ask for a bill or bank statement showing <?= $your ?> address.</p>
    </section>

    <section class="step" data-title="<?= $pub ? 'About you' : 'Personal details' ?>">
      <div class="fields">
        <div class="f"><label for="dob">Date of birth</label><input id="dob" name="dob" type="date" value="<?= $g('dob') ?>" autocomplete="bday"></div>
        <div class="f"><label for="email">Email <span class="tag">(for reminders)</span></label><input id="email" name="email" type="email" inputmode="email" value="<?= $g('email') ?>" autocomplete="email"></div>
        <div class="f"><label for="nationality">Nationality</label><input id="nationality" name="nationality" list="countries" value="<?= $g('nationality') ?>"></div>
        <div class="f"><label for="occupation">Work</label><select id="occupation" name="occupation"><?= opts(OCCUPATIONS, $sel('occupation')) ?></select></div>
        <div class="f wide" data-show-if="occupation=Employed|Self-employed"><label for="employer">Employer or business name</label><input id="employer" name="employer" value="<?= $g('employer') ?>"></div>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'About your transfers' : 'Transfer profile' ?>">
      <div class="fields">
        <div class="f"><label for="purpose">Main purpose</label><select id="purpose" name="purpose"><?= opts(PURPOSES, $sel('purpose')) ?></select></div>
        <div class="f" data-show-if="purpose=Other"><label for="purpose_other">Please describe</label><input id="purpose_other" name="purpose_other" value="<?= $g('purpose_other') ?>" maxlength="80"></div>
        <div class="f"><label for="source_of_funds">Where the money comes from</label><select id="source_of_funds" name="source_of_funds"><?= opts(SOF, $sel('source_of_funds')) ?></select></div>
        <div class="f"><label for="monthly_volume">Expected monthly amount</label><select id="monthly_volume" name="monthly_volume"><?= opts(VOLUMES, $sel('monthly_volume')) ?></select></div>
        <div class="f"><label for="destination">Usual destination country</label><input id="destination" name="destination" list="countries" value="<?= $g('destination') ?>"></div>
        <?php if ($locked): ?><div class="f"><span class="lbl">DhanXfer agent</span><div class="secret" style="font-size:15px;padding:9px 10px"><?= $g('ref_label') ?: $g('ref_code') ?></div></div>
        <?php elseif ($pub): ?>
        <div class="f"><label for="referral">How did you hear about us?</label><select id="referral" name="referral"><?= opts(HEARD, $sel('referral')) ?></select></div>
        <div class="f" data-show-if="referral=Referral"><label for="ref_code">Agent's code or name, if an agent helped you</label><input id="ref_code" name="ref_code" value="<?= $g('ref_code') ?>" autocapitalize="none"></div>
        <?php endif; ?>
      </div>
    </section>

    <section class="step" data-title="<?= $pub ? 'Who will you send money to?' : 'Beneficiaries' ?>" data-optional="1">
      <p class="tag" style="margin-top:0">Optional. Add everyone <?= $you ?> plan<?= $pub ? '' : 's' ?> to send to (up to 5). Each one is checked by our team before the first transfer.</p>
      <div id="benefs">
      <?php foreach ($benefs ?: [[]] as $bi => $b): $bv = fn($k) => e($b[$k] ?? ''); $sfx = $bi ? '' : '0'; ?>
        <div class="benef card" style="padding:12px;margin-bottom:10px">
          <div class="fields">
            <div class="f"><label<?= $sfx !== '' ? ' for="b_name0"' : '' ?>>Full name</label><input<?= $sfx !== '' ? ' id="b_name0"' : '' ?> name="b_name[]" value="<?= $bv('name') ?>"></div>
            <div class="f"><label<?= $sfx !== '' ? ' for="b_relation0"' : '' ?>>Relationship to <?= $you ?></label><input<?= $sfx !== '' ? ' id="b_relation0"' : '' ?> name="b_relation[]" value="<?= $bv('relation') ?>" placeholder="Mother, brother…"></div>
            <div class="f"><label<?= $sfx !== '' ? ' for="b_country0"' : '' ?>>Country</label><input<?= $sfx !== '' ? ' id="b_country0"' : '' ?> name="b_country[]" list="countries" value="<?= $bv('country') ?>"></div>
            <div class="f"><label<?= $sfx !== '' ? ' for="b_bank0"' : '' ?>>Bank name</label><input<?= $sfx !== '' ? ' id="b_bank0"' : '' ?> name="b_bank[]" value="<?= $bv('bank') ?>"></div>
            <div class="f"><label<?= $sfx !== '' ? ' for="b_account0"' : '' ?>>Account number / IBAN</label><input<?= $sfx !== '' ? ' id="b_account0"' : '' ?> name="b_account[]" value="<?= $bv('account') ?>" autocomplete="off"></div>
            <div class="f"><label<?= $sfx !== '' ? ' for="b_phone0"' : '' ?>>Phone</label><input<?= $sfx !== '' ? ' id="b_phone0"' : '' ?> name="b_phone[]" type="tel" value="<?= $bv('phone') ?>"></div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="btn sm" id="addBenef" data-max="5">+ Add another person</button>
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
    <?php if ($pub): ?><div class="draftbar"><button type="submit" class="btn ghost" name="act" value="draft" formnovalidate id="saveDraft">Save and finish later</button></div><?php endif; ?>
    <?php
}
