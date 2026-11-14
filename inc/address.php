<?php
defined('DX') or exit;
/*
 * Addresses: compare the home address with the address printed on the ID, and (optionally) suggest addresses
 * while the client types. A suggestion picked from the lookup is only a convenience: it is NEVER treated as a
 * verified address. Only a reviewer, with evidence, can confirm an address.
 */

const ADDRESS_FIELDS = ['line1', 'line2', 'city', 'province', 'postal', 'country'];
const PROVINCE_CODES = [
    'ontario' => 'on', 'quebec' => 'qc', 'british columbia' => 'bc', 'alberta' => 'ab', 'manitoba' => 'mb', 'saskatchewan' => 'sk',
    'nova scotia' => 'ns', 'new brunswick' => 'nb', 'newfoundland and labrador' => 'nl', 'newfoundland' => 'nl', 'prince edward island' => 'pe',
    'northwest territories' => 'nt', 'nunavut' => 'nu', 'yukon' => 'yt', 'dubai' => 'du', 'abu dhabi' => 'az', 'sharjah' => 'sh', 'ajman' => 'aj',
];
const STREET_WORDS = [
    'st' => 'street', 'str' => 'street', 'ave' => 'avenue', 'av' => 'avenue', 'rd' => 'road', 'dr' => 'drive', 'blvd' => 'boulevard',
    'cres' => 'crescent', 'cr' => 'crescent', 'ct' => 'court', 'crt' => 'court', 'pl' => 'place', 'ln' => 'lane', 'hwy' => 'highway',
    'pkwy' => 'parkway', 'sq' => 'square', 'ter' => 'terrace', 'terr' => 'terrace', 'cir' => 'circle', 'trl' => 'trail', 'gdns' => 'gardens',
    'n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west', 'ne' => 'northeast', 'nw' => 'northwest', 'se' => 'southeast', 'sw' => 'southwest',
    'apt' => 'unit', 'apartment' => 'unit', 'suite' => 'unit', 'ste' => 'unit', 'no' => 'unit', 'flat' => 'unit',
];
const COUNTRY_ALIASES = ['ca' => 'canada', 'can' => 'canada', 'uae' => 'united arab emirates', 'ae' => 'united arab emirates', 'us' => 'united states', 'usa' => 'united states', 'uk' => 'united kingdom', 'gb' => 'united kingdom', 'in' => 'india'];

/** Lowercase, no accents or punctuation, common abbreviations written out. */
function addr_norm_text(?string $s): string {
    $s = mb_strtolower(trim((string)$s));
    if (function_exists('transliterator_transliterate')) $s = (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
    $s = preg_replace('/[#.,\/\\\\\'"()-]+/', ' ', $s);
    $words = preg_split('/\s+/', trim((string)$s)) ?: [];
    return implode(' ', array_map(fn($w) => STREET_WORDS[$w] ?? $w, array_filter($words, fn($w) => $w !== '')));
}
/** Normalised components for comparing. */
function addr_normalize(array $a): array {
    $line = addr_norm_text(trim(($a['line1'] ?? '') . ' ' . ($a['line2'] ?? '')));
    // unit numbers: "unit 5 12 main street" and "5 12 main street" and "12 main street unit 5" all read the same
    preg_match_all('/\d+[a-z]?/', $line, $m);
    $nums = $m[0];
    $words = trim(preg_replace('/\b(unit)\b|\d+[a-z]?/', ' ', $line));
    $prov = addr_norm_text($a['province'] ?? '');
    $country = addr_norm_text($a['country'] ?? '');
    return [
        'numbers' => $nums, 'street' => preg_replace('/\s+/', ' ', $words),
        'city' => addr_norm_text($a['city'] ?? ''),
        'province' => PROVINCE_CODES[$prov] ?? $prov,
        'postal' => strtoupper(preg_replace('/[^a-z0-9]/i', '', (string)($a['postal'] ?? ''))),
        'country' => COUNTRY_ALIASES[$country] ?? $country,
    ];
}
/** 0..1, 1 = identical. Small spelling slips score high. */
function addr_similar(string $a, string $b): float {
    if ($a === $b) return 1.0;
    if ($a === '' || $b === '') return 0.0;
    $max = max(strlen($a), strlen($b));
    return 1 - levenshtein(substr($a, 0, 255), substr($b, 0, 255)) / $max;
}
/**
 * Compares the home address with the address on the ID.
 * Returns ['result' => match|minor|mismatch|no_id_address|incomplete, 'notes' => [...]].
 * - match: same place. - minor: same place with small spelling or format differences (fine, noted for the reviewer).
 * - mismatch / no_id_address / incomplete: needs manual review or proof of address. Never an automatic rejection.
 */
function addr_compare(array $home, ?array $onId): array {
    if (!$onId || !array_filter(array_map('trim', array_intersect_key($onId, array_flip(ADDRESS_FIELDS))))) return ['result' => 'no_id_address', 'notes' => ['The ID shows no address: ask for proof of address or check it another way.']];
    $h = addr_normalize($home); $i = addr_normalize($onId);
    if ($h['street'] === '' || $i['street'] === '' || $h['city'] === '' || $i['city'] === '') return ['result' => 'incomplete', 'notes' => ['One of the addresses is incomplete.']];
    $notes = [];
    $numsSame = $h['numbers'] && $i['numbers'] && !array_diff($h['numbers'], $i['numbers']) && !array_diff($i['numbers'], $h['numbers']);
    $houseSame = $h['numbers'] && $i['numbers'] && (in_array($i['numbers'][0], $h['numbers'], true) || in_array($h['numbers'][0], $i['numbers'], true));
    $street = addr_similar($h['street'], $i['street']);
    $city = addr_similar($h['city'], $i['city']);
    $postalSame = $h['postal'] !== '' && $h['postal'] === $i['postal'];
    $postalNear = $h['postal'] !== '' && $i['postal'] !== '' && substr($h['postal'], 0, 3) === substr($i['postal'], 0, 3);
    $countrySame = $h['country'] === '' || $i['country'] === '' || $h['country'] === $i['country'];
    if (!$countrySame) return ['result' => 'mismatch', 'notes' => ['Different countries.']];
    if ($numsSame && $street >= 0.999 && $city >= 0.999 && ($postalSame || $h['postal'] === '' || $i['postal'] === '')) return ['result' => 'match', 'notes' => []];
    if ($houseSame && $street >= 0.8 && $city >= 0.8 && ($postalSame || $postalNear || $h['postal'] === '' || $i['postal'] === '')) {
        if (!$numsSame) $notes[] = 'Unit or suite number differs or is missing on one of them.';
        if ($street < 0.999) $notes[] = 'Street name spelled slightly differently.';
        if ($city < 0.999) $notes[] = 'City spelled slightly differently.';
        if (!$postalSame && $h['postal'] !== '' && $i['postal'] !== '') $notes[] = 'Postal code differs in the last characters.';
        return ['result' => 'minor', 'notes' => $notes];
    }
    return ['result' => 'mismatch', 'notes' => ['The home address and the ID address are different places (the client may have moved).']];
}
function addr_result_label(?string $r): string {
    return ['match' => 'Matches the ID', 'minor' => 'Matches with small differences', 'mismatch' => 'Different from the ID: needs review',
            'no_id_address' => 'ID has no address: needs proof of address', 'incomplete' => 'Incomplete: needs review'][$r] ?? 'Not compared';
}
/** Address needs a reviewer decision with evidence before approval. */
function addr_needs_evidence(?string $r): bool { return !in_array($r, ['match', 'minor'], true); }

/* ---------- optional address lookup (suggestions while typing) ----------
 * Off unless 'address_provider' is set in the settings file. Supported: 'geoapify' (needs 'address_api_key').
 * 'mock' returns fixed sample addresses and only works on staging/test, for testing the screens.
 * The search text goes to the provider; nothing else about the client is sent.
 */
function addr_lookup_enabled(): bool {
    global $CONFIG;
    $p = $CONFIG['address_provider'] ?? '';
    if ($p === 'mock') return ($CONFIG['environment'] ?? 'live') !== 'live';
    return in_array($p, ['geoapify', 'canadapost'], true) && !empty($CONFIG['address_api_key']);
}
/** Returns up to 5 suggestions: [['label' => ..., 'line1', 'city', 'province', 'postal', 'country'], ...] */
function addr_lookup(string $q, string $country = ''): array {
    global $CONFIG;
    $q = trim(mb_substr($q, 0, 120));
    if (mb_strlen($q) < 4 || !addr_lookup_enabled()) return [];
    if (($CONFIG['address_provider'] ?? '') === 'mock') {
        $all = [
            ['line1' => '100 Queen Street West', 'city' => 'Toronto', 'province' => 'ON', 'postal' => 'M5H 2N2', 'country' => 'Canada'],
            ['line1' => '100 Queen Street', 'city' => 'Ottawa', 'province' => 'ON', 'postal' => 'K1P 1J9', 'country' => 'Canada'],
            ['line1' => '1 Sheikh Mohammed bin Rashid Boulevard', 'city' => 'Dubai', 'province' => 'Dubai', 'postal' => '', 'country' => 'United Arab Emirates'],
        ];
        $hit = array_values(array_filter($all, fn($a) => stripos($a['line1'] . ' ' . $a['city'], explode(' ', $q)[0]) !== false));
        return array_map(fn($a) => $a + ['label' => $a['line1'] . ', ' . $a['city'] . ' ' . $a['province'] . ' ' . $a['postal']], $hit);
    }
    $codes = ['Canada' => 'ca', 'United States' => 'us', 'United Arab Emirates' => 'ae', 'United Kingdom' => 'gb', 'India' => 'in'];
    if (($CONFIG['address_provider'] ?? '') === 'canadapost') {
        // Canada Post AddressComplete: Find is free; only Retrieve (when the client picks an address) is a paid lookup
        $res = addr_http('https://ws1.postescanada-canadapost.ca/AddressComplete/Interactive/Find/v2.10/json3.ws?' . http_build_query([
            'Key' => $CONFIG['address_api_key'], 'SearchTerm' => $q, 'Country' => strtoupper($codes[$country] ?? 'ca'), 'MaxSuggestions' => 6, 'LanguagePreference' => 'en']));
        $out = [];
        foreach (($res['Items'] ?? []) as $it) if (!empty($it['Id']) && ($it['Next'] ?? '') === 'Retrieve') $out[] = ['label' => trim(($it['Text'] ?? '') . ', ' . ($it['Description'] ?? ''), ', '), 'id' => (string)$it['Id']];
        return array_slice($out, 0, 5);
    }
    $url = 'https://api.geoapify.com/v1/geocode/autocomplete?' . http_build_query(array_filter([
        'text' => $q, 'type' => 'street', 'limit' => 5, 'format' => 'json', 'apiKey' => $CONFIG['address_api_key'],
        'filter' => isset($codes[$country]) ? 'countrycode:' . $codes[$country] : null]));
    $out = [];
    foreach ((addr_http($url)['results'] ?? []) as $r) {
        $line1 = trim(($r['housenumber'] ?? '') . ' ' . ($r['street'] ?? ''));
        if ($line1 === '') continue;
        $a = ['line1' => $line1, 'city' => (string)($r['city'] ?? ''), 'province' => (string)($r['state_code'] ?? $r['state'] ?? ''), 'postal' => (string)($r['postcode'] ?? ''), 'country' => (string)($r['country'] ?? '')];
        $out[] = $a + ['label' => (string)($r['formatted'] ?? $line1)];
    }
    return array_slice($out, 0, 5);
}

/** Full address for a suggestion that needs a second step (Canada Post Retrieve). */
function addr_retrieve(string $id): ?array {
    global $CONFIG;
    if (!addr_lookup_enabled() || ($CONFIG['address_provider'] ?? '') !== 'canadapost' || !preg_match('/^[A-Za-z0-9|\-]{3,80}$/', $id)) return null;
    $res = addr_http('https://ws1.postescanada-canadapost.ca/AddressComplete/Interactive/Retrieve/v2.11/json3.ws?' . http_build_query(['Key' => $CONFIG['address_api_key'], 'Id' => $id]));
    foreach (($res['Items'] ?? []) as $it) {
        if (($it['Language'] ?? 'ENG') !== 'ENG') continue;
        return ['line1' => trim(($it['SubBuilding'] ?? '') !== '' ? $it['SubBuilding'] . '-' . ($it['BuildingNumber'] ?? '') . ' ' . ($it['Street'] ?? '') : ($it['Line1'] ?? '')),
                'city' => (string)($it['City'] ?? ''), 'province' => (string)($it['ProvinceCode'] ?? ''), 'postal' => (string)($it['PostalCode'] ?? ''), 'country' => (string)($it['CountryName'] ?? '')];
    }
    return null;
}
function addr_http(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code === 200 && is_string($raw)) ? (json_decode($raw, true) ?: []) : [];
}
