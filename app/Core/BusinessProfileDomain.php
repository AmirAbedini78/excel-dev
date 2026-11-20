<?php
/**
 * ERPSMART 10.21.0 — Iran-first business profiles over shared canonical entities.
 *
 * Profiles guide module defaults and menus. They do NOT create parallel ledgers,
 * counterparties, or industry-specific GL charts.
 */
final class BusinessProfileDomain
{
    public const VERSION = '10.21.0-profiles';
    public const FUNCTIONAL_CURRENCY = 'IRR';
    public const DISPLAY_CURRENCY_OPTIONS = ['IRR', 'TOMAN'];

    public const PROFILES = [
        'trading' => [
            'label_fa' => 'بازرگانی / عمده / واردات-صادرات',
            'modules' => ['accounting', 'treasury', 'inventory', 'purchase', 'sales', 'trade', 'p2p', 'crm'],
            'priority_cycles' => ['domestic_p2p', 'o2c', 'foreign_trade_landed_cost', 'treasury'],
        ],
        'manufacturing' => [
            'label_fa' => 'تولید / صنعتی',
            'modules' => ['accounting', 'treasury', 'inventory', 'purchase', 'sales', 'production', 'p2p'],
            'priority_cycles' => ['bom_mrp', 'production_order', 'material_issue', 'finished_goods_costing'],
        ],
        'retail' => [
            'label_fa' => 'خرده / توزیع',
            'modules' => ['accounting', 'treasury', 'inventory', 'sales', 'crm'],
            'priority_cycles' => ['o2c', 'pos_settlement', 'inventory'],
        ],
        'service' => [
            'label_fa' => 'خدماتی / پیمانکاری',
            'modules' => ['accounting', 'treasury', 'crm', 'projects', 'contracts'],
            'priority_cycles' => ['contract_billing', 'expense_allocation', 'treasury'],
        ],
        'hybrid' => [
            'label_fa' => 'ترکیبی',
            'modules' => ['accounting', 'treasury', 'inventory', 'purchase', 'sales', 'trade', 'production', 'crm'],
            'priority_cycles' => ['o2c', 'p2p', 'selective_trade_or_mfg'],
        ],
    ];

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_company_business_profiles (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            profile_code VARCHAR(40) NOT NULL,
            display_currency VARCHAR(10) NOT NULL DEFAULT 'IRR',
            toman_factor INT NOT NULL DEFAULT 10,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function listProfiles(): array
    {
        return self::PROFILES;
    }

    public static function getCompanyProfile(int $wid, int $cid): array
    {
        $q = pdo()->prepare('SELECT * FROM erp_company_business_profiles WHERE workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$wid, $cid]);
        $row = $q->fetch();
        if (!$row) {
            return [
                'profile_code' => 'hybrid',
                'display_currency' => 'IRR',
                'toman_factor' => 10,
                'functional_currency' => self::FUNCTIONAL_CURRENCY,
                'configured' => false,
                'definition' => self::PROFILES['hybrid'],
            ];
        }
        $code = (string)$row['profile_code'];
        if (!isset(self::PROFILES[$code])) {
            $code = 'hybrid';
        }
        return [
            'profile_code' => $code,
            'display_currency' => (string)$row['display_currency'],
            'toman_factor' => (int)$row['toman_factor'],
            'functional_currency' => self::FUNCTIONAL_CURRENCY,
            'configured' => true,
            'definition' => self::PROFILES[$code],
        ];
    }

    public static function setCompanyProfile(int $wid, int $cid, int $uid, string $profileCode, string $displayCurrency = 'IRR'): void
    {
        Tenant::requirePermission('accounting.settings.manage');
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت/محیط کاری نامعتبر است.');
        }
        if (!isset(self::PROFILES[$profileCode])) {
            throw new RuntimeException('پروفایل کسب‌وکار نامعتبر است.');
        }
        $display = strtoupper(trim($displayCurrency));
        if (!in_array($display, self::DISPLAY_CURRENCY_OPTIONS, true)) {
            throw new RuntimeException('واحد نمایش باید IRR یا TOMAN باشد.');
        }
        // Functional ledger remains IRR. Toman is display-only (factor 10). Never silent convert in posting.
        $factor = 10;
        pdo()->prepare('INSERT INTO erp_company_business_profiles (workspace_id,company_id,profile_code,display_currency,toman_factor,updated_by,updated_at)
            VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE profile_code=VALUES(profile_code),display_currency=VALUES(display_currency),toman_factor=VALUES(toman_factor),updated_by=VALUES(updated_by),updated_at=NOW()')
            ->execute([$wid, $cid, $profileCode, $display, $factor, $uid]);
        Audit::log('biz.profile.set', 'erp_company_business_profiles', $cid, 'تنظیم پروفایل کسب‌وکار ' . $profileCode);
    }

    /**
     * Convert display amount string to IRR scaled cents for storage/posting.
     * Explicit only — callers must pass display_currency; never infer silently.
     */
    public static function displayToIrrScaled(string $amount, string $displayCurrency): int
    {
        $display = strtoupper(trim($displayCurrency));
        $units = PurchaseMatchingDomain::scaled(trim($amount), 2, 10000000000);
        if ($display === 'IRR') {
            return $units;
        }
        if ($display === 'TOMAN') {
            // 1 Toman display = 10 IRR
            return $units * 10;
        }
        throw new RuntimeException('واحد نمایش نامعتبر است.');
    }

    public static function irrScaledToDisplay(int $irrScaled, string $displayCurrency): string
    {
        $display = strtoupper(trim($displayCurrency));
        if ($display === 'IRR') {
            return PurchaseMatchingDomain::fixed($irrScaled, 2);
        }
        if ($display === 'TOMAN') {
            if ($irrScaled % 10 !== 0) {
                throw new RuntimeException('مبلغ IRR قابل تبدیل دقیق به تومان نمایشی نیست.');
            }
            return PurchaseMatchingDomain::fixed(intdiv($irrScaled, 10), 2);
        }
        throw new RuntimeException('واحد نمایش نامعتبر است.');
    }
}
