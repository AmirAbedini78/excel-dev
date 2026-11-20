<?php
/**
 * Isolated WMS / InventoryDomain harness for disposable MySQL (RC1 T2).
 * Does NOT load full app bootstrap / production config.
 * Env: O2C_DB_HOST/PORT/USER/PASS/NAME — verified sandbox only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$host = getenv('O2C_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('O2C_DB_PORT') ?: '3307');
$dbName = getenv('O2C_DB_NAME') ?: 'erpsmart_p2p_test';
$user = getenv('O2C_DB_USER') ?: 'p2p';
$pass = getenv('O2C_DB_PASS') ?: 'p2p_test';

$safeHosts = ['127.0.0.1', 'localhost', 'host.docker.internal', 'erpsmart-p2p-mysql-test', '172.17.0.2', 'mysql'];
if (!in_array($host, $safeHosts, true)) {
    fwrite(STDERR, "REFUSED: unsafe host {$host}\n");
    exit(3);
}
if (stripos($dbName, 'test') === false && stripos($dbName, 'e2e') === false && stripos($dbName, 'p2p') === false) {
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
    private static array $permissions = [
        'accounting.purchase.manage', 'accounting.purchase.view', 'accounting.view',
        'accounting.settings.manage', 'procurement.view',
    ];
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
    public static function logForWorkspace(int $w, string $a, string $e = '', int $id = 0, string $s = '', $b = null, $af = null, array $m = []): void
    {
        self::$logs[] = ['w' => $w, 'a' => $a, 'e' => $e, 'id' => $id, 'm' => $m];
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

function wms_e2e_reset_schema(): void
{
    global $host, $port, $user, $pass, $dbName;
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
    $db = pdo();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'erp_wms_idempotency','erp_wms_stock_events','erp_o2c_stock_locks',
        'erp_o2c_delivery_idempotency','erp_o2c_invoice_idempotency','erp_o2c_invoice_links',
        'acc_inventory_reservations','acc_stock_movements',
        'acc_inventory_receipt_lines','acc_inventory_receipts',
        'acc_purchase_lines','acc_purchase_docs','erp_p2p_vendor_bills','acc_units',
        'acc_items','acc_warehouses','acc_parties','companies',
    ] as $t) {
        $db->exec("DROP TABLE IF EXISTS `{$t}`");
    }

    $db->exec("CREATE TABLE companies (id INT PRIMARY KEY, workspace_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1)");
    $db->exec("CREATE TABLE acc_warehouses (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      code VARCHAR(64) NULL, name VARCHAR(120) NOT NULL, warehouse_type VARCHAR(40) NOT NULL DEFAULT 'general',
      address TEXT NULL, active TINYINT NOT NULL DEFAULT 1
    )");
    $db->exec("CREATE TABLE acc_items (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      code VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, item_type VARCHAR(40) NOT NULL DEFAULT 'goods',
      purchase_price_1 DECIMAL(20,2) NOT NULL DEFAULT 0, min_stock DECIMAL(20,4) NOT NULL DEFAULT 0,
      max_stock DECIMAL(20,4) NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1
    )");
    $db->exec("CREATE TABLE acc_parties (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      code VARCHAR(64) NULL, name VARCHAR(120) NOT NULL
    )");
    $db->exec("CREATE TABLE acc_purchase_docs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, doc_type VARCHAR(64) NOT NULL,
      document_no VARCHAR(64) NOT NULL, document_date DATE NOT NULL, party_id BIGINT NOT NULL,
      warehouse_id BIGINT NULL, notes TEXT NULL, workflow_status VARCHAR(32) NOT NULL,
      taxpayer_status VARCHAR(32) NOT NULL DEFAULT 'not_sent',
      total_before_discount DECIMAL(20,2) NOT NULL DEFAULT 0, discount_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      tax_total DECIMAL(20,2) NOT NULL DEFAULT 0, net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_purchase_lines (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, purchase_doc_id BIGINT NOT NULL,
      line_no INT NOT NULL, item_id BIGINT NOT NULL, unit_id BIGINT NULL, description VARCHAR(255) NULL,
      quantity DECIMAL(20,4) NOT NULL, unit_price DECIMAL(20,2) NOT NULL,
      discount_amount DECIMAL(20,2) NOT NULL DEFAULT 0, warehouse_id BIGINT NULL,
      line_total DECIMAL(20,2) NOT NULL, created_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_inventory_receipts (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      receipt_no VARCHAR(64) NULL, receipt_date DATE NOT NULL, warehouse_id BIGINT NOT NULL,
      source_type VARCHAR(40) NULL, purchase_doc_id BIGINT NOT NULL, supplier_id BIGINT NULL,
      status VARCHAR(32) NOT NULL, notes TEXT NULL, created_by INT NULL, approved_by INT NULL,
      posted_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL
    )");
    $db->exec("CREATE TABLE acc_inventory_receipt_lines (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, receipt_id BIGINT NOT NULL,
      line_no INT NOT NULL, purchase_line_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
      expected_qty DECIMAL(20,4) NOT NULL DEFAULT 0, received_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
      accepted_qty DECIMAL(20,4) NOT NULL DEFAULT 0, rejected_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
      unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0, line_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      notes VARCHAR(255) NULL, created_at DATETIME NULL
    )");
    // Match production unique key so partial/idempotent paths are realistic.
    $db->exec("CREATE TABLE acc_stock_movements (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, movement_date DATE NOT NULL,
      movement_type VARCHAR(50) NOT NULL, direction VARCHAR(10) NOT NULL, warehouse_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
      quantity DECIMAL(20,4) NOT NULL, unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0, source_type VARCHAR(60) NOT NULL,
      source_id BIGINT NOT NULL, source_line_id BIGINT NOT NULL DEFAULT 0, reference_no VARCHAR(120) NULL, status VARCHAR(30) NOT NULL,
      created_by INT NULL, created_at DATETIME NOT NULL,
      UNIQUE KEY uniq_acc_stock_source (workspace_id,source_type,source_id,source_line_id,direction),
      INDEX idx_acc_stock_position (workspace_id,company_id,warehouse_id,item_id,status)
    )");
    $db->exec("CREATE TABLE acc_inventory_reservations (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, warehouse_id BIGINT NOT NULL,
      item_id BIGINT NOT NULL, source_type VARCHAR(60) NULL, source_id BIGINT NULL, quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'active', notes VARCHAR(500) NULL, created_by INT NULL,
      created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
      INDEX idx_acc_inventory_reservation (workspace_id,company_id,warehouse_id,item_id,status)
    )");
    // Stub tables so purchasePipeline / createReceipt queries succeed.
    $db->exec("CREATE TABLE IF NOT EXISTS erp_p2p_vendor_bills (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      canonical_doc_id BIGINT NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'draft'
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS acc_units (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, name VARCHAR(64) NOT NULL
    )");

    InventoryDomain::migrate($db);

    $db->exec("INSERT INTO companies (id,workspace_id,active) VALUES (1,10,1),(2,10,1)");
    $db->exec("INSERT INTO acc_warehouses (id,workspace_id,company_id,code,name,active) VALUES
      (1,10,1,'WH1','Warehouse 1',1),(2,10,1,'WH2','Warehouse 2',1),(3,10,2,'WH-C2','Company2 WH',1)");
    $db->exec("INSERT INTO acc_items (id,workspace_id,company_id,code,name,purchase_price_1,active) VALUES
      (1,10,1,'RM1','Raw Material',100.00,1),(2,10,1,'FG1','Finished Good',500.00,1),
      (3,10,2,'RM-C2','Other Co Item',10.00,1)");
    $db->exec("INSERT INTO acc_parties (id,workspace_id,company_id,code,name) VALUES (1,10,1,'S1','Supplier')");
    // Seed on_hand 10 for company 1 item 1 warehouse 1
    $db->exec("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
      VALUES (10,1,CURDATE(),'adjustment','in',1,1,10.0000,100.00,'seed',1,1,'SEED','posted',1,NOW())");
    Audit::$logs = [];
}

function wms_seed_po(int $createdBy = 1, string $qty = '10.0000', string $price = '1000.00'): int
{
    $db = pdo();
    $no = 'PO-'.bin2hex(random_bytes(3));
    $total = number_format((float)$qty * (float)$price, 2, '.', '');
    $db->prepare("INSERT INTO acc_purchase_docs (workspace_id,company_id,doc_type,document_no,document_date,party_id,warehouse_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at)
        VALUES (10,1,'purchase_order_goods',?,'2026-11-20',1,1,'po','approved','not_sent',?,0,0,?,?,NOW(),NOW())")
        ->execute([$no, $total, $total, $createdBy]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO acc_purchase_lines (workspace_id,purchase_doc_id,line_no,item_id,description,quantity,unit_price,discount_amount,warehouse_id,line_total,created_at)
        VALUES (10,?,1,1,'line',?,?,0,1,?,NOW())")->execute([$id, $qty, $price, $total]);
    return $id;
}

function wms_json_out($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
}

function wms_on_hand(int $wid, int $cid, int $wh, int $item): string
{
    $st = pdo()->prepare("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END),0) FROM acc_stock_movements WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND status='posted'");
    $st->execute([$wid, $cid, $wh, $item]);
    return OrderToCashDomain::fixedQty(OrderToCashDomain::scaledQty((string)$st->fetchColumn()));
}

function wms_reserved(int $wid, int $cid, int $wh, int $item): string
{
    $st = pdo()->prepare("SELECT COALESCE(SUM(quantity),0) FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND status='active'");
    $st->execute([$wid, $cid, $wh, $item]);
    return OrderToCashDomain::fixedQty(OrderToCashDomain::scaledQty((string)$st->fetchColumn()));
}
