<?php
/**
 * ERPSMART RC1 T4 — Explicit IRR / Toman display helpers.
 *
 * Ledger / GL amounts remain IRR (scaled cents). Toman is display/input only
 * when company setting selects TOMAN. Factor: 1 Toman = 10 IRR.
 * Never infer display currency; never silently convert inside posting paths.
 */
final class IranLocalizationMoney
{
    public const VERSION = 'rc1-t4-money-1';
    public const FUNCTIONAL_CURRENCY = 'IRR';
    public const DISPLAY_IRR = 'IRR';
    public const DISPLAY_TOMAN = 'TOMAN';
    public const TOMAN_FACTOR = 10;
    public const SCALE = 2;
    /** Max whole IRR units accepted into scaled conversion (matches PurchaseMatchingDomain bound). */
    public const MAX_WHOLE = 10000000000;

    public static function labelFa(string $displayCurrency): string
    {
        $d = strtoupper(trim($displayCurrency));
        if ($d === self::DISPLAY_IRR) {
            return 'ریال';
        }
        if ($d === self::DISPLAY_TOMAN) {
            return 'تومان';
        }
        throw new RuntimeException('واحد نمایش باید IRR یا TOMAN باشد.');
    }

    public static function labelCode(string $displayCurrency): string
    {
        $d = strtoupper(trim($displayCurrency));
        if ($d === self::DISPLAY_IRR || $d === self::DISPLAY_TOMAN) {
            return $d;
        }
        throw new RuntimeException('واحد نمایش باید IRR یا TOMAN باشد.');
    }

    /**
     * Display amount string → IRR scaled integer for storage/posting.
     * Callers MUST pass display_currency explicitly.
     */
    public static function displayToIrrScaled(string $amount, string $displayCurrency): int
    {
        $display = self::labelCode($displayCurrency);
        $units = PurchaseMatchingDomain::scaled(trim($amount), self::SCALE, self::MAX_WHOLE);
        if ($display === self::DISPLAY_IRR) {
            return $units;
        }
        // TOMAN → IRR: multiply by 10 with overflow guard.
        if ($units > intdiv(PHP_INT_MAX, self::TOMAN_FACTOR)) {
            throw new RuntimeException('مبلغ تومان خارج از دامنه مجاز است (سرریز).');
        }
        return $units * self::TOMAN_FACTOR;
    }

    /**
     * IRR scaled integer → display decimal string for the selected display currency.
     * Rejects non-exact Toman conversion (IRR scaled not divisible by 10).
     */
    public static function irrScaledToDisplay(int $irrScaled, string $displayCurrency): string
    {
        $display = self::labelCode($displayCurrency);
        if ($display === self::DISPLAY_IRR) {
            return PurchaseMatchingDomain::fixed($irrScaled, self::SCALE);
        }
        if ($irrScaled % self::TOMAN_FACTOR !== 0) {
            throw new RuntimeException('مبلغ IRR قابل تبدیل دقیق به تومان نمایشی نیست.');
        }
        return PurchaseMatchingDomain::fixed(intdiv($irrScaled, self::TOMAN_FACTOR), self::SCALE);
    }

    /**
     * Format for screens/prints with explicit currency label (no silent unit change).
     */
    public static function formatLabeled(int $irrScaled, string $displayCurrency, bool $persianDigits = false): string
    {
        $amount = self::irrScaledToDisplay($irrScaled, $displayCurrency);
        $label = self::labelFa($displayCurrency);
        $text = $amount . ' ' . $label;
        return $persianDigits ? self::faDigits($text) : $text;
    }

    /**
     * Resolve company display currency; ledger currency is always IRR.
     * Returns metadata only — does not mutate amounts.
     */
    public static function companyDisplayContext(int $wid, int $cid): array
    {
        $profile = BusinessProfileDomain::getCompanyProfile($wid, $cid);
        $display = strtoupper((string)($profile['display_currency'] ?? self::DISPLAY_IRR));
        if (!in_array($display, [self::DISPLAY_IRR, self::DISPLAY_TOMAN], true)) {
            $display = self::DISPLAY_IRR;
        }
        return [
            'workspace_id' => $wid,
            'company_id' => $cid,
            'functional_currency' => self::FUNCTIONAL_CURRENCY,
            'display_currency' => $display,
            'toman_factor' => self::TOMAN_FACTOR,
            'label_fa' => self::labelFa($display),
            'ledger_unaffected' => true,
        ];
    }

    /**
     * Same IRR scaled amount rendered for two companies (A Toman / B IRR) —
     * proves display divergence without GL mutation.
     */
    public static function dualCompanyDisplay(int $irrScaled, string $companyADisplay, string $companyBDisplay): array
    {
        return [
            'ledger_irr_scaled' => $irrScaled,
            'ledger_irr' => PurchaseMatchingDomain::fixed($irrScaled, self::SCALE),
            'company_a' => [
                'display_currency' => self::labelCode($companyADisplay),
                'amount' => self::irrScaledToDisplay($irrScaled, $companyADisplay),
                'labeled' => self::formatLabeled($irrScaled, $companyADisplay),
            ],
            'company_b' => [
                'display_currency' => self::labelCode($companyBDisplay),
                'amount' => self::irrScaledToDisplay($irrScaled, $companyBDisplay),
                'labeled' => self::formatLabeled($irrScaled, $companyBDisplay),
            ],
        ];
    }

    public static function faDigits(string $s): string
    {
        return strtr($s, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    /** Reject float money inputs at the localization boundary. */
    public static function assertNotFloat($raw): void
    {
        if (is_float($raw)) {
            throw new RuntimeException('تبدیل مبلغ اعشاری شناور مجاز نیست؛ از رشته دهدهی استفاده کنید.');
        }
    }
}
