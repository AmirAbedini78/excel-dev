<?php
/**
 * Isolated MF-1 manufacturing harness for disposable MySQL (RC1 contracts §9).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$host = getenv('O2C_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('O2C_DB_PORT') ?: '3307');
// Dedicated disposable schema on the verified RC1 MySQL container (avoids cross-lane races on erpsmart_p2p_test).
$dbName = getenv('O2C_DB_NAME') ?: 'erpsmart_mf1_test';
$user = getenv('O2C_DB_USER') ?: 'p2p';
$pass = getenv('O2C_DB_PASS') ?: 'p2p_test';

$safeHosts = ['127.0.0.1', 'localhost', 'host.docker.internal', 'erpsmart-p2p-mysql-test', '172.17.0.2', 'mysql'];
if (!in_array($host, $safeHosts, true)) {
    fwrite(STDERR, "REFUSED: unsafe host {$host}\n");
    exit(3);
}
if (stripos($dbName, 'test') === false && stripos($dbName, 'e2e') === false && stripos($dbName, 'mf') === false) {
    fwrite(STDERR, "REFUSED: database name must be disposable test/e2e ({$dbName})\n");
    exit(3);
}

$ROOT = dirname(__DIR__, 2);

final class DB
{
    private static ?PDO $pdo = null;
    public static function connect(array $cfg): void
    {
        self::$pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']),
            $cfg['user'],
            $cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            throw new RuntimeException('pdo_not_connected');
        }
        return self::$pdo;
    }
}
function pdo(): PDO
{
    return DB::pdo();
}

final class Auth
{
    private static array $user = ['id' => 2, 'is_platform_admin' => 1];
    public static function check(): bool { return true; }
    public static function user(): array { return self::$user; }
    public static function setUser(array $u): void { self::$user = $u; }
}

final class Tenant
{
    private static int $wid = 10;
    private static array $permissions = ['production.view', 'production.manage', 'inventory.view'];
    public static function id(): int { return self::$wid; }
    public static function setId(int $id): void { self::$wid = $id; }
    public static function can(string $p): bool
    {
        if (!empty(Auth::user()['is_platform_admin'])) {
            return true;
        }
        return in_array($p, self::$permissions, true);
    }
    public static function requirePermission(string $p): void
    {
        if (!self::can($p)) {
            throw new RuntimeException('permission_denied:'.$p);
        }
    }
}

final class Audit
{
    public static array $logs = [];
    public static function log(string $a, string $e = '', int $id = 0, string $s = '', $b = null, $af = null, array $m = []): void
    {
        self::$logs[] = compact('a', 'e', 'id', 's');
    }
}

final class ModuleRegistry
{
    public static function enabled(string $key, ?int $wid = null): bool
    {
        return $key === 'production';
    }
}

final class AccountingRepository
{
    public static function date(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return null;
    }
}

require_once $ROOT.'/app/Core/PurchaseMatchingDomain.php';
require_once $ROOT.'/app/Core/OrderToCashDomain.php';
require_once $ROOT.'/app/Core/WarehouseStockApi.php';
require_once $ROOT.'/app/Core/InventoryDomain.php';
require_once $ROOT.'/app/Core/ProductionDomain.php';
require_once $ROOT.'/app/Core/ManufacturingInventoryAdapter.php';
require_once $ROOT.'/app/Core/ManufacturingCostEvidence.php';
require_once $ROOT.'/app/Core/ManufacturingExecutionDomain.php';
require_once $ROOT.'/app/Core/CostingAccountingDomain.php';

function mf_json_out($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
}

function mf_e2e_ensure_database(): void
{
    global $host, $port, $user, $pass, $dbName;
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    // Grant is typically already present for p2p@% on this sandbox; ignore failures.
    try {
        $pdo->exec("GRANT ALL ON `{$dbName}`.* TO 'p2p'@'%'");
        $pdo->exec('FLUSH PRIVILEGES');
    } catch (Throwable $e) {
        // ignore
    }
}

function mf_e2e_connect(): void
{
    global $host, $port, $user, $pass, $dbName;
    mf_e2e_ensure_database();
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
}

function mf_e2e_reset_schema(): void
{
    mf_e2e_connect();
    $db = pdo();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SHOW TABLES') as $r) {
        $t = array_values($r)[0];
        $db->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $db->exec("CREATE TABLE companies (id INT PRIMARY KEY, workspace_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1)");
    $db->exec("CREATE TABLE workspace_modules (
      workspace_id INT NOT NULL, module_key VARCHAR(80) NOT NULL, enabled TINYINT NOT NULL DEFAULT 0,
      PRIMARY KEY (workspace_id, module_key)
    )");
    $db->exec("CREATE TABLE acc_warehouses (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      code VARCHAR(64) NULL, name VARCHAR(120) NOT NULL, warehouse_type VARCHAR(40) NULL, address VARCHAR(255) NULL, active TINYINT NOT NULL DEFAULT 1
    )");
    $db->exec("CREATE TABLE acc_items (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      code VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, item_type VARCHAR(40) NOT NULL DEFAULT 'goods',
      min_stock DECIMAL(20,4) NOT NULL DEFAULT 0, max_stock DECIMAL(20,4) NOT NULL DEFAULT 0,
      purchase_price_1 DECIMAL(20,2) NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1
    )");
    $db->exec("CREATE TABLE acc_stock_movements (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, movement_date DATE NOT NULL,
      movement_type VARCHAR(50) NOT NULL, direction VARCHAR(10) NOT NULL, warehouse_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
      quantity DECIMAL(20,4) NOT NULL, unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0, source_type VARCHAR(60) NOT NULL,
      source_id BIGINT NOT NULL, source_line_id BIGINT NULL, reference_no VARCHAR(120) NULL, status VARCHAR(30) NOT NULL DEFAULT 'posted',
      created_by INT NULL, created_at DATETIME NULL,
      INDEX idx_acc_stock_position (workspace_id,company_id,warehouse_id,item_id,status)
    )");
    $db->exec("CREATE TABLE acc_inventory_reservations (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      warehouse_id BIGINT NOT NULL, item_id BIGINT NOT NULL, source_type VARCHAR(60) NULL, source_id BIGINT NULL,
      quantity DECIMAL(20,4) NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'active',
      expires_at DATETIME NULL, notes VARCHAR(500) NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
      INDEX idx_acc_inventory_reservation (workspace_id,company_id,warehouse_id,item_id,status)
    )");
    $db->exec("CREATE TABLE acc_fiscal_years (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      title VARCHAR(120) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'open', is_active TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME NULL, updated_at DATETIME NULL
    )");
    $db->exec("CREATE TABLE acc_period_closes (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      fiscal_year_id BIGINT NOT NULL, period_key VARCHAR(20) NOT NULL, close_type VARCHAR(30) NOT NULL DEFAULT 'monthly',
      status VARCHAR(30) NOT NULL DEFAULT 'open', closed_by INT NULL, closed_at DATETIME NULL,
      created_at DATETIME NULL, updated_at DATETIME NULL,
      UNIQUE KEY uniq_acc_period_close (workspace_id,company_id,fiscal_year_id,period_key)
    )");
    // Minimal purchase stubs so InventoryDomain::inventoryPosition → purchasePipeline does not fail.
    $db->exec("CREATE TABLE acc_purchase_docs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, doc_type VARCHAR(64) NOT NULL,
      document_no VARCHAR(64) NOT NULL, document_date DATE NOT NULL, party_id BIGINT NOT NULL, warehouse_id BIGINT NULL,
      workflow_status VARCHAR(32) NOT NULL, net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_purchase_lines (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, purchase_doc_id BIGINT NOT NULL,
      line_no INT NOT NULL, item_id BIGINT NOT NULL, quantity DECIMAL(20,4) NOT NULL, unit_price DECIMAL(20,2) NOT NULL,
      warehouse_id BIGINT NULL, line_total DECIMAL(20,2) NOT NULL, created_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_inventory_receipts (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      receipt_no VARCHAR(64) NULL, receipt_date DATE NOT NULL, warehouse_id BIGINT NOT NULL,
      source_type VARCHAR(40) NULL, purchase_doc_id BIGINT NOT NULL, supplier_id BIGINT NULL,
      status VARCHAR(32) NOT NULL, created_by INT NULL, created_at DATETIME NULL
    )");
    $db->exec("CREATE TABLE acc_inventory_receipt_lines (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, receipt_id BIGINT NOT NULL,
      line_no INT NOT NULL, purchase_line_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
      accepted_qty DECIMAL(20,4) NOT NULL DEFAULT 0, rejected_qty DECIMAL(20,4) NOT NULL DEFAULT 0, created_at DATETIME NULL
    )");
    $db->exec("CREATE TABLE erp_p2p_vendor_bills (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      canonical_doc_id BIGINT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending'
    )");
    $db->exec("CREATE TABLE acc_parties (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, name VARCHAR(120) NOT NULL
    )");
    $db->exec("CREATE TABLE acc_units (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, name VARCHAR(80) NOT NULL
    )");

    OrderToCashDomain::migrate($db);
    InventoryDomain::migrate($db);
    ProductionDomain::migrate($db);
    ManufacturingExecutionDomain::migrate($db);
    CostingAccountingDomain::migrate($db);

    $db->exec("INSERT INTO companies (id,workspace_id,active) VALUES (1,10,1),(2,10,1)");
    $db->exec("INSERT INTO workspace_modules (workspace_id,module_key,enabled) VALUES (10,'production',1)");
    $db->exec("INSERT INTO acc_warehouses (id,workspace_id,company_id,code,name,active) VALUES
      (1,10,1,'WH1','Raw/FG WH',1),(2,10,2,'WH2','OtherCo WH',1)");
    // items: 1=FG, 2=compA, 3=compB for company1; 11/12/13 company2
    $db->exec("INSERT INTO acc_items (id,workspace_id,company_id,code,name,item_type,purchase_price_1,active) VALUES
      (1,10,1,'FG1','Finished Good','product',0,1),
      (2,10,1,'RAW-A','Component A','goods',100.00,1),
      (3,10,1,'RAW-B','Component B','goods',50.00,1),
      (11,10,2,'FG2','Other FG','product',0,1),
      (12,10,2,'RAW-X','Other A','goods',10,1),
      (13,10,2,'RAW-Y','Other B','goods',10,1)");
    $db->exec("INSERT INTO acc_fiscal_years (workspace_id,company_id,title,start_date,end_date,status,is_active,created_at,updated_at)
      VALUES (10,1,'FY2026','2026-01-01','2026-12-31','open',1,NOW(),NOW()),
             (10,2,'FY2026','2026-01-01','2026-12-31','open',1,NOW(),NOW())");
}

/** Seed opening stock for components (posted movements). */
function mf_seed_stock(int $itemId, string $qty, int $warehouseId = 1, int $cid = 1, string $unitCost = '0'): int
{
    $db = pdo();
    $db->prepare("INSERT INTO acc_stock_movements
        (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
        VALUES (10,?, '2026-11-01','opening','in',?,?,?,?,'seed',1,1,'OPEN','posted',1,NOW())")
        ->execute([$cid, $warehouseId, $itemId, $qty, $unitCost]);
    return (int)$db->lastInsertId();
}

function mf_on_hand(int $itemId, int $warehouseId = 1, int $cid = 1): string
{
    $st = pdo()->prepare("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END),0)
        FROM acc_stock_movements WHERE workspace_id=10 AND company_id=? AND warehouse_id=? AND item_id=? AND status='posted'");
    $st->execute([$cid, $warehouseId, $itemId]);
    return PurchaseMatchingDomain::fixed(OrderToCashDomain::scaledQty((string)$st->fetchColumn()), 4);
}

function mf_create_approved_bom(int $maker = 1, int $checker = 2, int $cid = 1): int
{
    $bomId = ProductionDomain::createBom(10, $cid, $maker, [
        'code' => 'BOM-MF1-'.($cid === 1 ? 'A' : 'B').'-'.bin2hex(random_bytes(2)),
        'output_item_id' => $cid === 1 ? 1 : 11,
        'output_qty' => '1.0000',
        'lines' => [
            ['component_item_id' => $cid === 1 ? 2 : 12, 'quantity_per' => '1.0000', 'scrap_percent' => 0],
            ['component_item_id' => $cid === 1 ? 3 : 13, 'quantity_per' => '1.0000', 'scrap_percent' => 0],
        ],
        'notes' => 'MF-1 fixture',
    ]);
    ProductionDomain::approveBom(10, $cid, $checker, $bomId);
    return $bomId;
}
