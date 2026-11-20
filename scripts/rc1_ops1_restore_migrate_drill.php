<?php
/**
 * OPS-1 disposable restore migrate-twice drill (CLI).
 * Uses MF harness bootstrap (safe host/db name guards).
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/tests/harness/mf_e2e_bootstrap.php';

$host = getenv('O2C_DB_HOST') ?: 'host.docker.internal';
$port = (int)(getenv('O2C_DB_PORT') ?: 3307);
$user = getenv('O2C_DB_USER') ?: 'rc1_admin';
$pass = getenv('O2C_DB_PASS') ?: 'rc1_admin_test';
$dbName = getenv('O2C_DB_NAME') ?: 'erpsmart_rc1_ops1_restore_test';

DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
$pdo = pdo();
$runs = [];
foreach ([1, 2] as $i) {
    $t0 = microtime(true);
    InventoryDomain::migrate($pdo);
    ManufacturingExecutionDomain::migrate($pdo);
    CostingAccountingDomain::migrate($pdo);
    $runs[] = [
        'pass' => $i,
        'ms' => (int)((microtime(true) - $t0) * 1000),
        'ok' => true,
    ];
}
$tables = (int)$pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='.$pdo->quote($dbName)
)->fetchColumn();

echo json_encode([
    'ok' => true,
    'drill' => 'migrate_twice_on_restored_db',
    'db' => $dbName,
    'user' => $user,
    'migrate_twice' => $runs,
    'tables' => $tables,
    'schema_version' => class_exists('RuntimeCache') ? RuntimeCache::SCHEMA_VERSION : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
