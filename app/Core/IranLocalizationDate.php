<?php
/**
 * ERPSMART RC1 T4 — Jalali ↔ Gregorian date helpers.
 *
 * Canonical storage: Gregorian DATE (Y-m-d). Jalali is presentation/input only.
 * No timezone shifting for pure calendar dates; no ambiguous free-text parsing.
 */
final class IranLocalizationDate
{
    public const VERSION = 'rc1-t4-date-1';
    public const STORE_FORMAT = 'Y-m-d';

    /** Explicit roles so transaction vs posting dates stay distinguishable. */
    public const ROLE_TRANSACTION = 'transaction_date';
    public const ROLE_POSTING = 'posting_date';
    public const ROLE_FISCAL_PERIOD = 'fiscal_period';
    public const ROLE_PRODUCTION_SCHEDULE = 'production_schedule';
    public const ROLE_INVOICE = 'invoice_date';
    public const ROLE_PURCHASE = 'purchase_date';

    /**
     * Parse Jalali input → Gregorian DATE string for storage, or null if invalid.
     * Accepts Persian/Arabic digits; separators / - . space; years 1300–1499.
     */
    public static function jalaliToGregorianDate(string $jalali): ?string
    {
        $normalized = self::normalizeJalaliInput($jalali);
        if ($normalized === null) {
            return null;
        }
        [$jy, $jm, $jd] = $normalized;
        if (!self::isValidJalali($jy, $jm, $jd)) {
            return null;
        }
        [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, $jd);
        if (!self::isValidGregorian($gy, $gm, $gd)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * Format stored Gregorian DATE (Y-m-d only) → Jalali Y/m/d.
     * Rejects datetime strings with time components to avoid timezone ambiguity.
     */
    public static function gregorianDateToJalali(string $gregorianDate): ?string
    {
        $parts = self::parseGregorianDateOnly($gregorianDate);
        if ($parts === null) {
            return null;
        }
        [$gy, $gm, $gd] = $parts;
        if (!self::isValidGregorian($gy, $gm, $gd)) {
            return null;
        }
        [$jy, $jm, $jd] = Jalali::toJalali($gy, $gm, $gd);
        if (!self::isValidJalali($jy, $jm, $jd)) {
            return null;
        }
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /**
     * Round-trip: Jalali → Gregorian store → Jalali. Returns false on any failure or mismatch.
     */
    public static function roundTripJalali(string $jalali): bool
    {
        $normalized = self::normalizeJalaliInput($jalali);
        if ($normalized === null) {
            return false;
        }
        $canonical = sprintf('%04d/%02d/%02d', $normalized[0], $normalized[1], $normalized[2]);
        $g = self::jalaliToGregorianDate($canonical);
        if ($g === null) {
            return false;
        }
        $back = self::gregorianDateToJalali($g);
        return $back === $canonical;
    }

    /**
     * Round-trip: Gregorian DATE → Jalali → Gregorian DATE.
     */
    public static function roundTripGregorian(string $gregorianDate): bool
    {
        $j = self::gregorianDateToJalali($gregorianDate);
        if ($j === null) {
            return false;
        }
        $back = self::jalaliToGregorianDate($j);
        return $back === $gregorianDate;
    }

    public static function isLeapJalaliYear(int $jy): bool
    {
        return Jalali::isLeap($jy);
    }

    public static function jalaliMonthLength(int $jy, int $jm): int
    {
        if ($jm < 1 || $jm > 12) {
            return 0;
        }
        return Jalali::monthLength($jy, $jm);
    }

    /**
     * Build a typed date payload for docs/UI (store = Gregorian, display = Jalali).
     */
    public static function datedField(string $role, string $jalaliInput): array
    {
        $store = self::jalaliToGregorianDate($jalaliInput);
        if ($store === null) {
            throw new RuntimeException('تاریخ شمسی نامعتبر است (' . $role . ').');
        }
        return [
            'role' => $role,
            'store_gregorian' => $store,
            'display_jalali' => self::gregorianDateToJalali($store),
            'store_format' => self::STORE_FORMAT,
        ];
    }

    /** @return array{0:int,1:int,2:int}|null */
    public static function normalizeJalaliInput(string $raw): ?array
    {
        $s = trim(str_replace(['-', '.', ' '], '/', Jalali::enDigits($raw)));
        if ($s === '') {
            return null;
        }
        // Strict: no free-text; year must be 13xx/14xx.
        if (!preg_match('/^(13|14)\d{2}\/(\d{1,2})\/(\d{1,2})$/D', $s)) {
            return null;
        }
        $parts = array_map('intval', explode('/', $s));
        return [$parts[0], $parts[1], $parts[2]];
    }

    /** @return array{0:int,1:int,2:int}|null */
    public static function parseGregorianDateOnly(string $raw): ?array
    {
        $s = trim(Jalali::enDigits($raw));
        // Date-only: reject time / timezone suffixes that would invite strtotime shifts.
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $s, $m)) {
            return null;
        }
        return [(int)$m[1], (int)$m[2], (int)$m[3]];
    }

    public static function isValidJalali(int $jy, int $jm, int $jd): bool
    {
        if ($jy < 1300 || $jy > 1499) {
            return false;
        }
        if ($jm < 1 || $jm > 12) {
            return false;
        }
        $len = Jalali::monthLength($jy, $jm);
        return $jd >= 1 && $jd <= $len;
    }

    public static function isValidGregorian(int $gy, int $gm, int $gd): bool
    {
        if ($gy < 1900 || $gy > 2200) {
            return false;
        }
        return checkdate($gm, $gd, $gy);
    }
}
