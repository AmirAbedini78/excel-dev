<?php
/**
 * Shared RC1 cumulative migrate steps (bootstrap order).
 * Used by disposable rehearsal AND operator staging CLI — no duplicated schema logic.
 * CLI-oriented helpers only; never invoked as a web front controller.
 */
declare(strict_types=1);

/**
 * Autoload the same Core domain set used by offline migrate runners.
 */
function rc1_cumulative_migrate_autoload(string $ROOT): void
{
    $files = [
        '/app/Core/DB.php',
        '/app/Core/Jalali.php',
        '/app/Core/Helpers.php',
        '/app/Core/RuntimeCache.php',
        '/app/Core/Auth.php',
        '/app/Core/Schema.php',
        '/app/Core/Notify.php',
        '/app/Core/Tenant.php',
        '/app/Core/ModuleRegistry.php',
        '/app/Core/ChoiceRegistry.php',
        '/app/Core/Audit.php',
        '/app/Core/FileLibrary.php',
        '/app/Core/Sharing.php',
        '/app/Core/V5Schema.php',
        '/app/Core/AccountingSchema.php',
        '/app/Core/AccountingExtendedSchema.php',
        '/app/Core/AccountingRepository.php',
        '/app/Core/WarehouseStockApi.php',
        '/app/Core/InventoryDomain.php',
        '/app/Core/ProductionDomain.php',
        '/app/Core/ManufacturingInventoryAdapter.php',
        '/app/Core/ManufacturingCostEvidence.php',
        '/app/Core/ManufacturingExecutionDomain.php',
        '/app/Core/CostingAccountingDomain.php',
        '/app/Core/ErpSuiteDomain.php',
        '/app/Core/WorkflowDomain.php',
        '/app/Core/EnterpriseCatalog.php',
        '/app/Core/EnterpriseDomain.php',
        '/app/Core/PurchaseMatchingDomain.php',
        '/app/Core/SalesDomain.php',
        '/app/Core/OrderToCashDomain.php',
        '/app/Core/AccountingPostingDomain.php',
        '/app/Core/ArSettlementDomain.php',
        '/app/Core/ApPostingDomain.php',
        '/app/Core/ApSettlementDomain.php',
        '/app/Core/BusinessProfileDomain.php',
        '/app/Core/CrmDomain.php',
        '/app/Core/AiSchema.php',
    ];
    foreach ($files as $rel) {
        require_once $ROOT . $rel;
    }
}

/**
 * Ordered cumulative migrate matching app/bootstrap.php schema gate (no HTTP).
 *
 * @return list<array{step:string,ok:bool,ms:int,error?:string}>
 */
function rc1_run_cumulative_migrate(PDO $pdo): array
{
    $steps = [
        'Schema::migrate' => static fn () => Schema::migrate($pdo),
        'Tenant::ensureSchema' => static fn () => Tenant::ensureSchema(),
        'ChoiceRegistry::ensureSchema' => static fn () => ChoiceRegistry::ensureSchema(),
        'Audit::ensureSchema' => static fn () => Audit::ensureSchema(),
        'FileLibrary::ensureSchema' => static fn () => FileLibrary::ensureSchema(),
        'V5Schema::migrate' => static fn () => V5Schema::migrate($pdo),
        'AccountingSchema::migrate' => static fn () => AccountingSchema::migrate($pdo),
        'AccountingExtendedSchema::migrate' => static fn () => AccountingExtendedSchema::migrate($pdo),
        'SalesDomain::migrate' => static fn () => SalesDomain::migrate($pdo),
        'OrderToCashDomain::migrate' => static fn () => OrderToCashDomain::migrate($pdo),
        'AccountingPostingDomain::migrate' => static fn () => AccountingPostingDomain::migrate($pdo),
        'ArSettlementDomain::migrate' => static fn () => ArSettlementDomain::migrate($pdo),
        'ApPostingDomain::migrate' => static fn () => ApPostingDomain::migrate($pdo),
        'ApSettlementDomain::migrate' => static fn () => ApSettlementDomain::migrate($pdo),
        'BusinessProfileDomain::migrate' => static fn () => BusinessProfileDomain::migrate($pdo),
        'CrmDomain::migrate' => static fn () => CrmDomain::migrate($pdo),
        'ProductionDomain::migrate' => static fn () => ProductionDomain::migrate($pdo),
        'InventoryDomain::migrate' => static fn () => InventoryDomain::migrate($pdo),
        'ManufacturingExecutionDomain::migrate' => static fn () => ManufacturingExecutionDomain::migrate($pdo),
        'CostingAccountingDomain::migrate' => static fn () => CostingAccountingDomain::migrate($pdo),
        'ErpSuiteDomain::migrate' => static fn () => ErpSuiteDomain::migrate($pdo),
        'WorkflowDomain::migrate' => static fn () => WorkflowDomain::migrate($pdo),
        'EnterpriseDomain::migrate' => static fn () => EnterpriseDomain::migrate($pdo),
        'PurchaseMatchingDomain::migrate' => static fn () => PurchaseMatchingDomain::migrate($pdo),
        'AiSchema::migrate' => static fn () => AiSchema::migrate($pdo),
        'ModuleRegistry::ensureSchema' => static fn () => ModuleRegistry::ensureSchema(),
        'RuntimeCache::markSchema' => static fn () => RuntimeCache::markSchema(RuntimeCache::SCHEMA_VERSION),
    ];
    $out = [];
    foreach ($steps as $name => $fn) {
        $t0 = microtime(true);
        try {
            $fn();
            $out[] = ['step' => $name, 'ok' => true, 'ms' => (int)((microtime(true) - $t0) * 1000)];
        } catch (Throwable $e) {
            $out[] = [
                'step' => $name,
                'ok' => false,
                'ms' => (int)((microtime(true) - $t0) * 1000),
                'error' => $e->getMessage(),
            ];
            break;
        }
    }
    return $out;
}

function rc1_table_count(PDO $pdo, string $db): int
{
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . $pdo->quote($db)
    )->fetchColumn();
}

/**
 * Spot-check RC1 tables expected after cumulative migrate.
 *
 * @return array<string,bool>
 */
function rc1_validate_rc1_tables(PDO $pdo, string $dbName): array
{
    $want = [
        'erp_ar_postings',
        'erp_ap_postings',
        'erp_mf_production_orders',
        'erp_company_business_profiles',
        'erp_p2p_vendor_bills',
        'erp_o2c_invoice_links',
    ];
    $present = [];
    foreach ($want as $t) {
        $n = (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . $pdo->quote($dbName) .
            ' AND table_name=' . $pdo->quote($t)
        )->fetchColumn();
        $present[$t] = $n === 1;
    }
    return $present;
}

/**
 * Clear schema.json markers ONLY under an explicit cache directory (never prod root blindly).
 */
function rc1_clear_schema_json_under(string $cacheDir): int
{
    if ($cacheDir === '' || !is_dir($cacheDir)) {
        return 0;
    }
    $removed = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getFilename() === 'schema.json') {
            if (@unlink($f->getPathname())) {
                $removed++;
            }
        }
    }
    return $removed;
}
