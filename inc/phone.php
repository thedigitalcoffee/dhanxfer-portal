<?php
defined('DX') or exit;
/*
 * Phone numbers: checked with libphonenumber (Google's rules, vendored in vendor/libphonenumber).
 * This only says a number is possible and well-formed for its country. It does NOT prove the client
 * owns it: that needs a one-time code (OTP), which is a separate step.
 */
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'libphonenumber\\')) {
        $f = __DIR__ . '/../vendor/libphonenumber/' . str_replace('\\', '/', substr($c, 15)) . '.php';
        if (is_file($f)) require $f;
    }
});
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;

/** Country names used in the forms, mapped to ISO codes for the phone rules. */
const PHONE_COUNTRIES = [
    'Canada' => 'CA', 'United States' => 'US', 'United Arab Emirates' => 'AE', 'India' => 'IN', 'Pakistan' => 'PK',
    'Philippines' => 'PH', 'Bangladesh' => 'BD', 'Nepal' => 'NP', 'Sri Lanka' => 'LK', 'United Kingdom' => 'GB',
];
/** ISO code for a country name or code; null when unknown. */
function phone_region(?string $country): ?string {
    $c = trim((string)$country);
    if ($c === '') return null;
    if (isset(PHONE_COUNTRIES[$c])) return PHONE_COUNTRIES[$c];
    $up = strtoupper($c);
    return (strlen($up) === 2 && in_array($up, PhoneNumberUtil::getInstance()->getSupportedRegions(), true)) ? $up : null;
}
/** Dialling code shown next to the phone field, e.g. "+1" for Canada. */
function phone_dial_code(?string $region): string {
    return $region ? '+' . PhoneNumberUtil::getInstance()->getCountryCodeForRegion($region) : '';
}
/**
 * Checks a typed number. Spaces, dashes, dots and brackets are ignored. A number starting with + or 00 is read
 * as international; otherwise it is read for $region (the country the client chose, never guessed from the IP).
 * Returns ['ok' => bool, 'e164' => '+14165550123', 'region' => 'CA', 'national' => '(416) 555-0123', 'error' => text].
 */
function phone_check(string $typed, ?string $region): array {
    $raw = trim($typed);
    if ($raw === '') return ['ok' => false, 'error' => 'Enter a phone number.'];
    if (preg_match('/[^0-9+\s().\-\/]/', $raw)) return ['ok' => false, 'error' => 'Use digits only (spaces, dashes and brackets are fine).'];
    $digits = preg_replace('/\D/', '', $raw);
    if (str_starts_with($raw, '00')) { $raw = '+' . substr($digits, 2); }
    $u = PhoneNumberUtil::getInstance();
    if (!str_starts_with($raw, '+') && !$region) return ['ok' => false, 'error' => 'Choose the country of this number, or start it with + and the country code.'];
    // Canada/US: exactly 10 digits nationally (a leading 1 is the country code, not part of the number)
    if (!str_starts_with($raw, '+') && in_array($region, ['CA', 'US'], true)) {
        $nat = strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;
        if (strlen($nat) !== 10) return ['ok' => false, 'error' => 'A Canadian or US number has 10 digits, e.g. 416 555 0123.'];
    }
    try { $p = $u->parse($raw, $region ?: 'ZZ'); }
    catch (NumberParseException $e) { return ['ok' => false, 'error' => 'That does not look like a phone number.']; }
    if (!$u->isValidNumber($p)) return ['ok' => false, 'error' => 'That number is not valid for ' . ($region ? (array_search($region, PHONE_COUNTRIES, true) ?: $region) : 'that country') . '. Check the digits and the country.'];
    return ['ok' => true, 'e164' => $u->format($p, PhoneNumberFormat::E164), 'region' => $u->getRegionCodeForNumber($p),
            'national' => $u->format($p, PhoneNumberFormat::NATIONAL), 'international' => $u->format($p, PhoneNumberFormat::INTERNATIONAL), 'error' => ''];
}
