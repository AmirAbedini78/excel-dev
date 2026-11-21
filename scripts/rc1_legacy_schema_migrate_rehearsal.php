<?php
/**
 * Offline operator-controlled cumulative migrate rehearsal (CLI only).
 * Targets a disposable DB restored from sanitized/anonymized legacy dump.
 * Does NOT touch production. Does NOT destroy the untouched baseline schema.
 *
 * Hardening:
 *  - Require explicit disposable DB allowlist (no unsafe credential fallbacks)
 *  - Reject baseline / prod-like names
 *  - Refuse host-network / unknown hosts
 *  - Emit container id, @@server_uuid, host/port in JSON evidence
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

function env_req(string $key): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        fwrite(STDERR, "REFUSED: missing required env {$key} (no credential fallbacks)\n");
        exit(3);
    }
    return $v;
}

$host = env_req('O2C_DB_HOST');
$port = (int)env_req('O2C_DB_PORT');
$user = env_req('O2C_DB_USER');
$pass = env_req('O2C_DB_PASS');
$dbName = env_req('O2C_DB_NAME');
$expectedContainer = getenv('RC1_MYSQL_CONTAINER') ?: 'erpsmart-p2p-mysql-test';
$expectedIdPrefix = getenv('RC1_MYSQL_CONTAINER_ID_PREFIX')
    ?: 'ab78c5c42f00463c9fa5092eb8512ee5c9168082d3387e3b36b9b15af9294baa';
$allowAny = in_array(strtolower((string)getenv('RC1_ALLOW_ANY_MYSQL')), ['1', 'true', 'yes'], true);

$safeHosts = ['127.0.0.1', 'localhost', 'host.docker.internal', 'erpsmart-p2p-mysql-test', 'mysql'];
// Explicitly refuse docker host-network style / bare wildcards
$unsafeHosts = ['0.0.0.0', '*', '::', ''];
if (in_array($host, $unsafeHosts, true) || str_contains($host, ' ')) {
    fwrite(STDERR, "REFUSED: unsafe host {$host}\n");
    exit(3);
}
if (!in_array($host, $safeHosts, true) && !preg_match('/^172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}$/', $host)) {
    fwrite(STDERR, "REFUSED: host not in disposable allowlist ({$host})\n");
    exit(3);
}

$allowDb = [
    'erpsmart_rc1_legacy_schema_migrate',
    'erpsmart_rc1_legacy_data_migrate',
    'erpsmart_rc1_legacy_data_recovery',
    'erpsmart_rc1_legacy_schema_rollback',
    'erpsmart_rc1_legacy_data_rollback',
];
$dbOk = in_array($dbName, $allowDb, true)
    || (str_contains($dbName, 'legacy') && str_contains($dbName, 'migrate') && str_contains($dbName, 'erpsmart_rc1'))
    || (str_contains($dbName, 'legacy') && str_contains($dbName, 'recovery') && str_contains($dbName, 'erpsmart_rc1'))
    || (str_contains($dbName, 'legacy') && str_contains($dbName, 'rollback') && str_contains($dbName, 'erpsmart_rc1'));
if (!$dbOk) {
    fwrite(STDERR, "REFUSED: db not on disposable legacy allowlist ({$dbName})\n");
    exit(3);
}
foreach (['baseline', 'prod', 'production', 'zzflgmfd', 'cpanel', 'live'] as $bad) {
    if (stripos($dbName, $bad) !== false) {
        fwrite(STDERR, "REFUSED: baseline/prod-like db name ({$dbName})\n");
        exit(3);
    }
}

$ROOT = dirname(__DIR__);
define('APP_ROOT', $ROOT);

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

function docker_identity(string $name, string $prefix, bool $allowAny): array
{
    if ($allowAny) {
        return [
            'mode' => 'RC1_ALLOW_ANY_MYSQL',
            'container_name' => null,
            'container_id' => null,
            'verified' => false,
            'note' => 'container identity check relaxed for CI service MySQL only',
        ];
    }
    $cmd = 'docker inspect ' . escapeshellarg($name) . ' --format "{{.Id}} {{.State.Status}} {{.HostConfig.NetworkMode}}"';
    $out = trim((string)shell_exec($cmd . ' 2>NUL'));
    if ($out === '') {
        fwrite(STDERR, "REFUSED: cannot inspect disposable MySQL container {$name}\n");
        exit(3);
    }
    $parts = preg_split('/\s+/', $out) ?: [];
    $cid = $parts[0] ?? '';
    $status = $parts[1] ?? '';
    $net = $parts[2] ?? '';
    if ($status !== 'running') {
        fwrite(STDERR, "REFUSED: container not running ({$status})\n");
        exit(3);
    }
    if (strtolower($net) === 'host') {
        fwrite(STDERR, "REFUSED: unsafe host-network mode on MySQL container\n");
        exit(3);
    }
    if (substr($cid, 0, 12) !== substr($prefix, 0, 12)) {
        fwrite(STDERR, "REFUSED: container id mismatch\n");
        exit(3);
    }
    return [
        'mode' => 'G0_NAMED_CONTAINER',
        'container_name' => $name,
        'container_id' => $cid,
        'network_mode' => $net,
        'verified' => true,
    ];
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

$identity = docker_identity($expectedContainer, $expectedIdPrefix, $allowAny);

$cacheDir = $ROOT . '/storage/cache/rc1_legacy_rehearsal';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
RuntimeCache::boot($cacheDir, $dbCfg);
DB::connect($dbCfg);
$pdo = pdo();

$serverUuid = (string)$pdo->query('SELECT @@server_uuid')->fetchColumn();
$mysqlVer = (string)$pdo->query('SELECT VERSION()')->fetchColumn();

$before = table_count($pdo, $dbName);
$passes = [];
foreach ([1, 2] as $i) {
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
    'drill' => 'legacy_anonymized_or_schema_cumulative_migrate_x2',
    'db' => $dbName,
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'server_uuid' => $serverUuid,
    'mysql_version' => $mysqlVer,
    'disposable_identity' => $identity,
    'repo_schema_version' => RuntimeCache::SCHEMA_VERSION,
    'tables_before' => $before,
    'tables_after' => $after,
    'migrate_twice' => $passes,
    'rc1_tables_present' => $present,
    'note' => 'Requires env credentials; no fallbacks. Baseline DB never targeted.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

exit(!empty(array_filter($passes, fn ($p) => empty($p['ok']))) ? 1 : 0);
