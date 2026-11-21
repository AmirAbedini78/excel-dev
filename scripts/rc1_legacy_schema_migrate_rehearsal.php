<?php
/**
 * Offline operator-controlled cumulative migrate rehearsal (CLI only).
 * Targets a disposable DB restored from sanitized legacy SCHEMA-ONLY dump.
 * Does NOT touch production. Does NOT destroy the untouched baseline schema.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$host = getenv('O2C_DB_HOST') ?: 'host.docker.internal';
$port = (int)(getenv('O2C_DB_PORT') ?: '3307');
$user = getenv('O2C_DB_USER') ?: 'rc1_admin';
$pass = getenv('O2C_DB_PASS') ?: 'rc1_admin_test';
$dbName = getenv('O2C_DB_NAME') ?: 'erpsmart_rc1_legacy_schema_migrate';

$safeHosts = ['127.0.0.1', 'localhost', 'host.docker.internal', 'erpsmart-p2p-mysql-test', '172.17.0.2', 'mysql'];
if (!in_array($host, $safeHosts, true)) {
    fwrite(STDERR, "REFUSED: unsafe host {$host}\n");
    exit(3);
}
if (stripos($dbName, 'legacy_schema_migrate') === false && stripos($dbName, 'test') === false) {
    fwrite(STDERR, "REFUSED: db must be disposable legacy migrate/test ({$dbName})\n");
    exit(3);
}
if (stripos($dbName, 'baseline') !== false) {
    fwrite(STDERR, "REFUSED: never migrate the untouched baseline DB\n");
    exit(3);
}

$ROOT = dirname(__DIR__);
define('APP_ROOT', $ROOT);

// Mirror bootstrap.php core requires (offline CLI; no HTTP / no install redirect).
require_once $ROOT . '/app/Core/DB.php';
require_once $ROOT . '/app/Core/Jalali.php';
require_once $ROOT . '/app/Core/Helpers.php';
require_once $ROOT . '/app/Core/RuntimeCache.php';
require_once $ROOT . '/app/Core/Auth.php';
require_once $ROOT . '/app/Core/Schema.php';
require_once $ROOT . '/app/Core/Notify.php';
require_once $ROOT . '/app/Core/Tenant.php';
require_once $ROOT . '/app/Core/ModuleRegistry.php';
require_once $ROOT . '/app/Core/ChoiceRegistry.php';
require_once $ROOT . '/app/Core/Audit.php';
require_once $ROOT . '/app/Core/FileLibrary.php';
require_once $ROOT . '/app/Core/Sharing.php';
require_once $ROOT . '/app/Core/V5Schema.php';
require_once $ROOT . '/app/Core/AccountingSchema.php';
require_once $ROOT . '/app/Core/AccountingExtendedSchema.php';
require_once $ROOT . '/app/Core/AccountingRepository.php';
require_once $ROOT . '/app/Core/WarehouseStockApi.php';
require_once $ROOT . '/app/Core/InventoryDomain.php';
require_once $ROOT . '/app/Core/ProductionDomain.php';
require_once $ROOT . '/app/Core/ManufacturingInventoryAdapter.php';
require_once $ROOT . '/app/Core/ManufacturingCostEvidence.php';
require_once $ROOT . '/app/Core/ManufacturingExecutionDomain.php';
require_once $ROOT . '/app/Core/CostingAccountingDomain.php';
require_once $ROOT . '/app/Core/ErpSuiteDomain.php';
require_once $ROOT . '/app/Core/WorkflowDomain.php';
require_once $ROOT . '/app/Core/EnterpriseCatalog.php';
require_once $ROOT . '/app/Core/EnterpriseDomain.php';
require_once $ROOT . '/app/Core/PurchaseMatchingDomain.php';
require_once $ROOT . '/app/Core/SalesDomain.php';
require_once $ROOT . '/app/Core/OrderToCashDomain.php';
require_once $ROOT . '/app/Core/AccountingPostingDomain.php';
require_once $ROOT . '/app/Core/ArSettlementDomain.php';
require_once $ROOT . '/app/Core/ApPostingDomain.php';
require_once $ROOT . '/app/Core/ApSettlementDomain.php';
require_once $ROOT . '/app/Core/BusinessProfileDomain.php';
require_once $ROOT . '/app/Core/CrmDomain.php';
require_once $ROOT . '/app/Core/AiSchema.php';

$dbCfg = [
    'host' => $host,
    'port' => $port,
    'database' => $dbName,
    'username' => $user,
    'password' => $pass,
    'charset' => 'utf8mb4',
];

function table_count(PDO $pdo, string $db): int
{
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . $pdo->quote($db)
    )->fetchColumn();
}

function run_cumulative(PDO $pdo): array
{
    $steps = [
        'Schema::migrate' => fn () => Schema::migrate($pdo),
        'Tenant::ensureSchema' => fn () => Tenant::ensureSchema(),
        'ChoiceRegistry::ensureSchema' => fn () => ChoiceRegistry::ensureSchema(),
        'Audit::ensureSchema' => fn () => Audit::ensureSchema(),
        'FileLibrary::ensureSchema' => fn () => FileLibrary::ensureSchema(),
        'V5Schema::migrate' => fn () => V5Schema::migrate($pdo),
        'AccountingSchema::migrate' => fn () => AccountingSchema::migrate($pdo),
        'AccountingExtendedSchema::migrate' => fn () => AccountingExtendedSchema::migrate($pdo),
        'SalesDomain::migrate' => fn () => SalesDomain::migrate($pdo),
        'OrderToCashDomain::migrate' => fn () => OrderToCashDomain::migrate($pdo),
        'AccountingPostingDomain::migrate' => fn () => AccountingPostingDomain::migrate($pdo),
        'ArSettlementDomain::migrate' => fn () => ArSettlementDomain::migrate($pdo),
        'ApPostingDomain::migrate' => fn () => ApPostingDomain::migrate($pdo),
        'ApSettlementDomain::migrate' => fn () => ApSettlementDomain::migrate($pdo),
        'BusinessProfileDomain::migrate' => fn () => BusinessProfileDomain::migrate($pdo),
        'CrmDomain::migrate' => fn () => CrmDomain::migrate($pdo),
        'ProductionDomain::migrate' => fn () => ProductionDomain::migrate($pdo),
        'InventoryDomain::migrate' => fn () => InventoryDomain::migrate($pdo),
        'ManufacturingExecutionDomain::migrate' => fn () => ManufacturingExecutionDomain::migrate($pdo),
        'CostingAccountingDomain::migrate' => fn () => CostingAccountingDomain::migrate($pdo),
        'ErpSuiteDomain::migrate' => fn () => ErpSuiteDomain::migrate($pdo),
        'WorkflowDomain::migrate' => fn () => WorkflowDomain::migrate($pdo),
        'EnterpriseDomain::migrate' => fn () => EnterpriseDomain::migrate($pdo),
        'PurchaseMatchingDomain::migrate' => fn () => PurchaseMatchingDomain::migrate($pdo),
        'AiSchema::migrate' => fn () => AiSchema::migrate($pdo),
        'ModuleRegistry::ensureSchema' => fn () => ModuleRegistry::ensureSchema(),
        'RuntimeCache::markSchema' => fn () => RuntimeCache::markSchema(RuntimeCache::SCHEMA_VERSION),
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

$cacheDir = $ROOT . '/storage/cache/rc1_legacy_rehearsal';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
RuntimeCache::boot($cacheDir, $dbCfg);
DB::connect($dbCfg);
$pdo = pdo();

$before = table_count($pdo, $dbName);
$passes = [];
foreach ([1, 2] as $i) {
    // Clear schema gate marker so pass 2 still executes full cumulative chain (idempotency).
    $schemaMarker = $cacheDir . '/runtime_cache';
    // RuntimeCache stores under hash subdir; wipe marker files under rehearsal cache.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getFilename() === 'schema.json') {
            @unlink($f->getPathname());
        }
    }
    $t0 = microtime(true);
    $steps = run_cumulative($pdo);
    $ok = !in_array(false, array_column($steps, 'ok'), true);
    $passes[] = [
        'pass' => $i,
        'ok' => $ok,
        'ms' => (int)((microtime(true) - $t0) * 1000),
        'steps' => $steps,
        'tables_after' => table_count($pdo, $dbName),
    ];
    if (!$ok) {
        break;
    }
}
$after = table_count($pdo, $dbName);

// Structural presence checks for key RC1 additive tables
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

echo json_encode([
    'ok' => !in_array(false, array_column($passes, 'ok'), true),
    'drill' => 'legacy_schema_only_cumulative_migrate_x2',
    'classification' => 'STRUCTURAL_ONLY_NOT_DATA_RECONCILED',
    'db' => $dbName,
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'repo_schema_version' => RuntimeCache::SCHEMA_VERSION,
    'tables_before' => $before,
    'tables_after' => $after,
    'migrate_twice' => $passes,
    'rc1_tables_present' => $present,
    'note' => 'Schema-only legacy restore; cannot prove voucher/stock/PII data preservation. Unredacted dump NOT used.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

exit(!empty(array_filter($passes, fn ($p) => empty($p['ok']))) ? 1 : 0);
