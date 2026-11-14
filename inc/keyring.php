<?php
defined('DX') or exit;
/*
 * Key ring: envelope encryption for client data.
 *
 * Today (default, nothing configured): one master key "k0" in dx-config.php ('enc_key'). Behaviour is unchanged.
 *
 * With a key ring file ($CONFIG['keyring_file'], outside the website folder):
 *   {"active": "k1", "stable": "k0",
 *    "keys": {"k1": {"provider": "gcpkms", "kms_key": "projects/P/locations/L/keyRings/R/cryptoKeys/C", "wrapped": "<base64>", "created": "…"},
 *             "k0": {"provider": "config"}}}
 * - Each data key (32 random bytes) is stored only "wrapped" (encrypted) by a key-encryption key that never leaves
 *   Google Cloud KMS (provider gcpkms) or, for tests and offline escrow, by a local key (provider local).
 * - "active": new values and files are sealed with this key and say which key they use (enc:v2:<kid>:…, DXF2, DXB2).
 * - "stable": the key for search keys and signatures (ID-number search, duplicate checks, codes, audit chain,
 *   signed links). These must not change when data keys rotate, or look-ups would stop matching.
 * - Older values (enc:v1:, DXF1, DXB1) keep opening with k0 until tools/rotate_data.php re-seals them.
 *
 * Cloud KMS is never called unless $CONFIG['kms_enabled'] is true and a service-account file is configured.
 */

/** Every column that holds sealed values (enc:…). The re-seal tool and the data check walk these. */
const ENC_COLUMNS = [['clients', 'id_number'], ['beneficiaries', 'account'], ['submissions', 'data'], ['users', 'mfa_secret'], ['document_versions', 'number_enc'], ['intake_drafts', 'data']];

/** Test hook: replaces the HTTPS transport (fn(string $url, array $headers, string $body): array [status, body]). */
$GLOBALS['KMS_HTTP'] = $GLOBALS['KMS_HTTP'] ?? null;

function keyring_file(): ?string {
    global $CONFIG;
    $f = $CONFIG['keyring_file'] ?? null;
    return $f && is_file($f) ? $f : null;
}
/** The key ring as configured (cached for the request). Without a file: only k0 from the config. */
function keyring(bool $reload = false): array {
    static $ring = null;
    if ($ring !== null && !$reload) return $ring;
    $f = keyring_file();
    $r = $f ? json_decode((string)file_get_contents($f), true) : null;
    if ($f && !is_array($r)) throw new RuntimeException('The key ring file cannot be read.');
    $r = is_array($r) ? $r : [];
    $r['keys'] = ($r['keys'] ?? []) + ['k0' => ['provider' => 'config']];
    $r['active'] = $r['active'] ?? 'k0';
    $r['stable'] = $r['stable'] ?? 'k0';
    foreach (['active', 'stable'] as $k) if (!isset($r['keys'][$r[$k]])) throw new RuntimeException("The key ring names a missing $k key.");
    return $ring = $r;
}
function keyring_save(array $r): void {
    global $CONFIG;
    $f = $CONFIG['keyring_file'] ?? null;
    if (!$f) throw new RuntimeException('Set keyring_file in dx-config.php first (a path outside the website folder).');
    $tmp = $f . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new RuntimeException('The key ring could not be written.');
    @chmod($tmp, 0600);
    if (!rename($tmp, $f)) throw new RuntimeException('The key ring could not be replaced.');
    keyring(true); keyring_key('', true);
}
/** The 32-byte key for a key id, unwrapped once per request. */
function keyring_key(string $kid, bool $flush = false): string {
    static $cache = [];
    if ($flush) { $cache = []; return ''; }
    if (isset($cache[$kid])) return $cache[$kid];
    $k = keyring()['keys'][$kid] ?? null;
    if (!$k) throw new RuntimeException('Unknown encryption key ' . preg_replace('/[^a-z0-9_-]/i', '', $kid) . '.');
    $raw = match ($k['provider'] ?? '') {
        'config' => master_key(),
        'local'  => kr_local_unwrap($kid, (string)$k['wrapped']),
        'gcpkms' => kms_unwrap($kid, $k),
        default  => throw new RuntimeException('Unknown key provider.'),
    };
    if (strlen($raw) !== 32) throw new RuntimeException('A data key has the wrong length.');
    return $cache[$kid] = $raw;
}
function keyring_active(): string { return keyring()['active']; }
function keyring_stable(): string { return keyring()['stable']; }

/* ---- local provider: data key wrapped with a key-encryption key from the config (tests, offline escrow) ---- */
function kr_local_kek(): string {
    global $CONFIG;
    $k = base64_decode((string)($CONFIG['local_kek'] ?? ''), true);
    if ($k === false || strlen($k) !== 32) throw new RuntimeException('local_kek is missing from the config.');
    return $k;
}
function kr_local_wrap(string $kid, string $dek): string { return base64_encode(aead_seal($dek, kr_local_kek(), 'dhanxfer-dek:' . $kid)); }
function kr_local_unwrap(string $kid, string $wrapped): string {
    $p = aead_open((string)base64_decode($wrapped, true), kr_local_kek(), 'dhanxfer-dek:' . $kid);
    if ($p === null) throw new RuntimeException('The data key could not be unwrapped (wrong key or damaged key ring).');
    return $p;
}

/* ---- Google Cloud KMS provider (symmetric ENCRYPT_DECRYPT key; only wrap/unwrap calls, never data) ---- */
function kms_enabled(): bool { global $CONFIG; return !empty($CONFIG['kms_enabled']) && !empty($CONFIG['kms_sa_file']); }
function kms_http(string $url, array $headers, string $body): array {
    if (is_callable($GLOBALS['KMS_HTTP'])) return ($GLOBALS['KMS_HTTP'])($url, $headers, $body);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, $res === false ? '' : (string)$res];
}
function kms_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
/** Short-lived OAuth token from the service account (signed JWT, scope cloudkms only). Cached for the request. */
function kms_token(): string {
    static $tok = null, $exp = 0;
    if ($tok && time() < $exp - 60) return $tok;
    global $CONFIG;
    if (!kms_enabled()) throw new RuntimeException('Cloud KMS is not switched on (kms_enabled, kms_sa_file).');
    $sa = json_decode((string)@file_get_contents($CONFIG['kms_sa_file']), true);
    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) throw new RuntimeException('The KMS service-account file cannot be read.');
    $now = time();
    $jwt = kms_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . kms_b64url(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/cloudkms', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 600]));
    if (!openssl_sign($jwt, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('The KMS sign-in could not be signed.');
    [$code, $body] = kms_http('https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . kms_b64url($sig)]));
    $j = json_decode($body, true);
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('Cloud KMS sign-in failed (HTTP ' . $code . ').');
    $tok = $j['access_token']; $exp = $now + (int)($j['expires_in'] ?? 600);
    return $tok;
}
function kms_call(string $name, string $verb, array $payload): array {
    if (!preg_match('#^projects/[a-z0-9-]+/locations/[a-z0-9-]+/keyRings/[A-Za-z0-9_-]+/cryptoKeys/[A-Za-z0-9_-]+$#', $name)) throw new RuntimeException('The KMS key name is not valid.');
    [$code, $body] = kms_http("https://cloudkms.googleapis.com/v1/$name:$verb", ['Authorization: Bearer ' . kms_token(), 'Content-Type: application/json'], json_encode($payload));
    $j = json_decode($body, true);
    if ($code !== 200 || !is_array($j)) throw new RuntimeException("Cloud KMS $verb failed (HTTP $code).");
    return $j;
}
/** Wraps a data key with the KMS key's primary version. The key id is bound in (AAD), so wrapped keys cannot be swapped. */
function kms_wrap(string $kid, string $kmsKey, string $dek): string {
    $j = kms_call($kmsKey, 'encrypt', ['plaintext' => base64_encode($dek), 'additionalAuthenticatedData' => base64_encode('dhanxfer-dek:' . $kid)]);
    if (empty($j['ciphertext'])) throw new RuntimeException('Cloud KMS returned no ciphertext.');
    return (string)$j['ciphertext'];
}
function kms_unwrap(string $kid, array $k): string {
    $j = kms_call((string)$k['kms_key'], 'decrypt', ['ciphertext' => (string)$k['wrapped'], 'additionalAuthenticatedData' => base64_encode('dhanxfer-dek:' . $kid)]);
    $p = base64_decode((string)($j['plaintext'] ?? ''), true);
    if ($p === false) throw new RuntimeException('Cloud KMS returned no key.');
    return $p;
}

/* ---- key ring changes (run from tools/keyring.php on the server, never from a web page) ---- */
/** Makes a new random data key, wrapped by the given provider. Not active until keyring_activate(). */
function keyring_new(string $kid, string $provider, ?string $kmsKey = null): array {
    if (!preg_match('/^k[0-9]{1,4}$/', $kid) || $kid === 'k0') throw new RuntimeException('Key ids look like k1, k2 …');
    $r = keyring(true);
    if (isset($r['keys'][$kid])) throw new RuntimeException("Key $kid already exists; key ids are never reused.");
    $dek = random_bytes(32);
    $entry = match ($provider) {
        'local'  => ['provider' => 'local', 'wrapped' => kr_local_wrap($kid, $dek)],
        'gcpkms' => ['provider' => 'gcpkms', 'kms_key' => (string)$kmsKey, 'wrapped' => kms_wrap($kid, (string)$kmsKey, $dek)],
        default  => throw new RuntimeException('Provider must be local or gcpkms.'),
    };
    $entry['created'] = date('c');
    $entry['check'] = substr(hash_hmac('sha256', 'dhanxfer-key-check', $dek), 0, 16); // detects a wrong key on unwrap, reveals nothing
    $r['keys'][$kid] = $entry;
    keyring_save($r);
    if (!hash_equals($entry['check'], substr(hash_hmac('sha256', 'dhanxfer-key-check', keyring_key($kid)), 0, 16))) throw new RuntimeException('The new key did not unwrap to the same value.');
    return $entry;
}
function keyring_activate(string $kid): void {
    $r = keyring(true);
    if (!isset($r['keys'][$kid])) throw new RuntimeException("No key $kid.");
    $k = keyring_key($kid);
    if (isset($r['keys'][$kid]['check']) && !hash_equals($r['keys'][$kid]['check'], substr(hash_hmac('sha256', 'dhanxfer-key-check', $k), 0, 16))) throw new RuntimeException('Key check failed: not activated.');
    $r['active'] = $kid; keyring_save($r);
}
/** KMS key rotation: unwraps a data key and wraps it again with the KMS key's current primary version (data is not touched). */
function keyring_rewrap(string $kid): void {
    $r = keyring(true); $e = $r['keys'][$kid] ?? null;
    if (!$e || ($e['provider'] ?? '') !== 'gcpkms') throw new RuntimeException('Only Cloud KMS keys are rewrapped.');
    $dek = keyring_key($kid);
    $r['keys'][$kid]['wrapped'] = kms_wrap($kid, (string)$e['kms_key'], $dek);
    $r['keys'][$kid]['rewrapped'] = date('c');
    keyring_save($r);
    keyring_key('', true);
    if (keyring_key($kid) !== $dek) throw new RuntimeException('Rewrap check failed.');
}
/** Status for the panel and tools: ids, providers, which is active. Never key material. */
function keyring_status(): array {
    $r = keyring(true); $out = [];
    foreach ($r['keys'] as $kid => $e) $out[$kid] = ['provider' => $e['provider'] ?? '?', 'created' => $e['created'] ?? null, 'active' => $kid === $r['active'], 'stable' => $kid === $r['stable'], 'kms_key' => $e['kms_key'] ?? null];
    return $out;
}
