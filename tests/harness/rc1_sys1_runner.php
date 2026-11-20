<?php
/**
 * SYS-1 smoke: service/retail profile on shared canonical identities without manufacturing config.
 * Reuses AP harness stubs + BusinessProfileDomain against disposable MySQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/ap_e2e_bootstrap.php';
require_once $ROOT . '/app/Core/BusinessProfileDomain.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $args = json_decode($argv[2], true) ?: [];
}

function rc1_sys_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
}

try {
    if ($scenario !== 'service_retail_profile_smoke') {
        rc1_sys_out(['ok' => false, 'error' => 'unknown_scenario', 'scenario' => $scenario]);
        exit(1);
    }

    ap_e2e_reset_schema();
    pdo()->exec('DROP TABLE IF EXISTS erp_company_business_profiles');
    BusinessProfileDomain::migrate(pdo());

    // Ensure company 2 exists for multi-company smoke
    pdo()->exec('INSERT IGNORE INTO companies (id, workspace_id, active) VALUES (2, 10, 1)');

    Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
    BusinessProfileDomain::setCompanyProfile(10, 1, 2, 'service', 'IRR');
    $svc = BusinessProfileDomain::getCompanyProfile(10, 1);
    $mods = $svc['definition']['modules'] ?? [];
    if (in_array('production', $mods, true) || in_array('trade', $mods, true)) {
        throw new RuntimeException('service_profile_must_not_require_production_or_trade');
    }

    BusinessProfileDomain::setCompanyProfile(10, 1, 2, 'retail', 'IRR');
    $ret = BusinessProfileDomain::getCompanyProfile(10, 1);
    $rmods = $ret['definition']['modules'] ?? [];
    if (in_array('production', $rmods, true)) {
        throw new RuntimeException('retail_profile_must_not_require_production');
    }

    $parties = (int)pdo()->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acc_parties'"
    )->fetchColumn();
    $items = (int)pdo()->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acc_items'"
    )->fetchColumn();

    BusinessProfileDomain::setCompanyProfile(10, 2, 2, 'service', 'TOMAN');
    $other = BusinessProfileDomain::getCompanyProfile(10, 2);
    if ($other['profile_code'] !== 'service' || $other['display_currency'] !== 'TOMAN') {
        throw new RuntimeException('company2_profile_not_isolated');
    }
    if ($ret['profile_code'] !== 'retail') {
        throw new RuntimeException('company1_profile_clobbered');
    }

    rc1_sys_out([
        'ok' => true,
        'service_initial' => [
            'profile_code' => 'service',
            'modules_exclude_production' => true,
        ],
        'retail' => [
            'profile_code' => $ret['profile_code'],
            'display_currency' => $ret['display_currency'],
            'modules' => $rmods,
        ],
        'company2' => [
            'profile_code' => $other['profile_code'],
            'display_currency' => $other['display_currency'],
        ],
        'canonical_tables' => ['acc_parties' => $parties > 0, 'acc_items' => $items > 0],
        'functional_currency' => BusinessProfileDomain::FUNCTIONAL_CURRENCY,
        'note' => 'SYS-1 profile smoke only; full browser login deferred to T6/OPS browser walkthrough.',
    ]);
} catch (Throwable $e) {
    rc1_sys_out(['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
}
