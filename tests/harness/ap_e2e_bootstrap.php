<?php
/**
 * Isolated AP / P2P domain harness for disposable MySQL.
 * Does NOT load full app bootstrap / production config.
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
        'accounting.settings.manage', 'accounting.vouchers.manage', 'accounting.vouchers.view',
        'accounting.treasury.manage', 'procurement.view',
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
}

final class ModuleRegistry
{
    private static bool $p2p = true;
    public static function enabled(string $key, ?int $wid = null): bool
    {
        return $key === 'p2p' ? self::$p2p : false;
    }
    public static function setP2p(bool $on): void
    {
        self::$p2p = $on;
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
require_once $ROOT.'/app/Core/AccountingPostingDomain.php';
require_once $ROOT.'/app/Core/ApPostingDomain.php';

function ap_e2e_reset_schema(): void
{
    global $host, $port, $user, $pass, $dbName;
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
    $db = pdo();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'erp_ap_allocations','erp_ap_payments','erp_ap_postings','erp_ap_workflow','erp_ap_company_maps',
        'erp_p2p_policy_exceptions','erp_p2p_bill_revisions','erp_p2p_vendor_bill_lines','erp_p2p_vendor_bills','erp_p2p_company_policy',
        'acc_inventory_receipt_lines','acc_inventory_receipts','acc_stock_movements',
        'acc_voucher_lines','acc_vouchers','acc_period_closes','acc_fiscal_years','acc_accounts',
        'acc_purchase_lines','acc_purchase_docs','acc_items','acc_warehouses','acc_parties','companies','workspace_modules',
        'erp_ar_postings','erp_ar_company_maps','acc_cash_accounts','acc_cash_transactions',
    ] as $t) {
        $db->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $db->exec("CREATE TABLE companies (id INT PRIMARY KEY, workspace_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1)");
    $db->exec("CREATE TABLE workspace_modules (
      workspace_id INT NOT NULL, module_key VARCHAR(80) NOT NULL, enabled TINYINT NOT NULL DEFAULT 0,
      PRIMARY KEY (workspace_id, module_key)
    )");
    $db->exec("CREATE TABLE acc_warehouses (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, code VARCHAR(64) NULL, name VARCHAR(120) NOT NULL)");
    $db->exec("CREATE TABLE acc_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, item_type VARCHAR(40) NOT NULL DEFAULT 'goods')");
    $db->exec("CREATE TABLE acc_parties (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, code VARCHAR(64) NULL, name VARCHAR(120) NOT NULL)");
    $db->exec("CREATE TABLE acc_purchase_docs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, doc_type VARCHAR(64) NOT NULL,
      document_no VARCHAR(64) NOT NULL, party_invoice_no VARCHAR(120) NULL, document_date DATE NOT NULL, party_id BIGINT NOT NULL,
      cost_center_id BIGINT NULL, project_id BIGINT NULL, warehouse_id BIGINT NULL, notes TEXT NULL,
      workflow_status VARCHAR(32) NOT NULL, taxpayer_status VARCHAR(32) NOT NULL,
      total_before_discount DECIMAL(20,2) NOT NULL DEFAULT 0, discount_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      tax_total DECIMAL(20,2) NOT NULL DEFAULT 0, net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_purchase_lines (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, purchase_doc_id BIGINT NOT NULL,
      line_no INT NOT NULL, item_id BIGINT NOT NULL, unit_id BIGINT NULL, description VARCHAR(255) NULL,
      quantity DECIMAL(20,4) NOT NULL, unit_price DECIMAL(20,2) NOT NULL,
      discount_amount DECIMAL(20,2) NOT NULL DEFAULT 0, discount_percent DECIMAL(20,4) NOT NULL DEFAULT 0,
      commission_percent DECIMAL(20,4) NOT NULL DEFAULT 0,
      warehouse_id BIGINT NULL, cost_center_id BIGINT NULL, project_id BIGINT NULL,
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
    $db->exec("CREATE TABLE acc_stock_movements (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, movement_date DATE NOT NULL,
      movement_type VARCHAR(40) NOT NULL, direction VARCHAR(8) NOT NULL, warehouse_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
      quantity DECIMAL(20,4) NOT NULL, unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0, source_type VARCHAR(40) NOT NULL,
      source_id BIGINT NOT NULL, source_line_id BIGINT NULL, reference_no VARCHAR(120) NULL, status VARCHAR(20) NOT NULL,
      created_by INT NULL, created_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_accounts (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      code VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, account_type VARCHAR(40) NOT NULL DEFAULT 'asset',
      nature VARCHAR(20) NOT NULL DEFAULT 'debit', allow_posting TINYINT NOT NULL DEFAULT 1, active TINYINT NOT NULL DEFAULT 1
    )");
    $db->exec("CREATE TABLE acc_period_closes (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      fiscal_year_id BIGINT NOT NULL, period_key VARCHAR(20) NOT NULL, close_type VARCHAR(30) NOT NULL DEFAULT 'monthly',
      status VARCHAR(30) NOT NULL DEFAULT 'open', closed_by INT NULL, closed_at DATETIME NULL,
      created_at DATETIME NULL, updated_at DATETIME NULL,
      UNIQUE KEY uniq_acc_period_close (workspace_id,company_id,fiscal_year_id,period_key)
    )");
    $db->exec("CREATE TABLE acc_fiscal_years (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      title VARCHAR(120) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'open', is_active TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME NULL, updated_at DATETIME NULL
    )");
    $db->exec("CREATE TABLE acc_vouchers (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
      fiscal_year_id BIGINT NULL, voucher_no VARCHAR(100) NOT NULL, voucher_date DATE NOT NULL,
      voucher_type VARCHAR(40) NOT NULL DEFAULT 'general', status VARCHAR(30) NOT NULL DEFAULT 'draft',
      description VARCHAR(500) NULL, source_type VARCHAR(80) NULL, source_id BIGINT NULL,
      auto_generated TINYINT(1) NOT NULL DEFAULT 0, total_debit DECIMAL(20,2) NOT NULL DEFAULT 0,
      total_credit DECIMAL(20,2) NOT NULL DEFAULT 0, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
      UNIQUE KEY uniq_acc_voucher_no (workspace_id,company_id,voucher_no)
    )");
    $db->exec("CREATE TABLE acc_voucher_lines (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, voucher_id BIGINT NOT NULL, line_no INT NOT NULL,
      account_id BIGINT NOT NULL, party_id BIGINT NULL, cost_center_id BIGINT NULL, project_id BIGINT NULL,
      description VARCHAR(500) NULL, debit DECIMAL(20,2) NOT NULL DEFAULT 0, credit DECIMAL(20,2) NOT NULL DEFAULT 0,
      created_at DATETIME NULL
    )");

    PurchaseMatchingDomain::migrate($db);
    AccountingPostingDomain::migrate($db);
    ApPostingDomain::migrate($db);

    $db->exec("INSERT INTO companies (id,workspace_id,active) VALUES (1,10,1),(2,10,1)");
    $db->exec("INSERT INTO workspace_modules (workspace_id,module_key,enabled) VALUES (10,'p2p',1)");
    $db->exec("INSERT INTO acc_warehouses (id,workspace_id,company_id,code,name) VALUES (1,10,1,'WH1','Warehouse 1')");
    $db->exec("INSERT INTO acc_items (id,workspace_id,company_id,code,name) VALUES (1,10,1,'SKU1','Item1')");
    $db->exec("INSERT INTO acc_parties (id,workspace_id,company_id,code,name) VALUES (1,10,1,'S1','Supplier'),(2,10,2,'S2','OtherCoSupplier')");
    // 1 AR-recv, 2 AR-rev, 3 tax out, 8 AP, 9 Purchase/Inv, 10 Input VAT, 11 Bank, 5 NoPost
    $db->exec("INSERT INTO acc_accounts (id,workspace_id,company_id,code,name,account_type,nature,allow_posting,active) VALUES
      (1,10,1,'1101','AR','asset','debit',1,1),(2,10,1,'4101','Revenue','income','credit',1,1),(3,10,1,'2101','OutputTax','liability','credit',1,1),
      (5,10,1,'8888','NoPost','liability','credit',0,1),(8,10,1,'2102','AP','liability','credit',1,1),
      (9,10,1,'1401','InventoryPurchase','asset','debit',1,1),(10,10,1,'1105','InputVAT','asset','debit',1,1),
      (11,10,1,'1102','Bank','asset','debit',1,1),(12,10,1,'5101','PurchaseExp','expense','debit',1,1),
      (21,10,2,'2102','AP-C2','liability','credit',1,1),(22,10,2,'1401','Inv-C2','asset','debit',1,1)");
    $db->exec("INSERT INTO acc_fiscal_years (workspace_id,company_id,title,start_date,end_date,status,is_active,created_at,updated_at)
      VALUES (10,1,'FY2026','2026-01-01','2026-12-31','open',1,NOW(),NOW()),
             (10,2,'FY2026','2026-01-01','2026-12-31','open',1,NOW(),NOW())");
    PurchaseMatchingDomain::ensurePolicyRow(10, 1);
    ModuleRegistry::setP2p(true);
}

function ap_json_out($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
}

function ap_seed_po(int $createdBy = 1, string $qty = '10.0000', string $price = '1000.00'): int
{
    $db = pdo();
    $no = 'PO-'.bin2hex(random_bytes(3));
    $total = PurchaseMatchingDomain::fixed(
        PurchaseMatchingDomain::cents(
            PurchaseMatchingDomain::scaled($qty, 4, 100000),
            PurchaseMatchingDomain::scaled($price, 2, 10000000000)
        ),
        2
    );
    $db->prepare("INSERT INTO acc_purchase_docs (workspace_id,company_id,doc_type,document_no,document_date,party_id,warehouse_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at)
        VALUES (10,1,'purchase_order_goods',?,'2026-11-20',1,1,'po','draft','not_sent',?,0,0,?,?,NOW(),NOW())")
        ->execute([$no, $total, $total, $createdBy]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO acc_purchase_lines (workspace_id,purchase_doc_id,line_no,item_id,description,quantity,unit_price,discount_amount,warehouse_id,line_total,created_at)
        VALUES (10,?,1,1,'line',?,?,0,1,?,NOW())")->execute([$id, $qty, $price, $total]);
    return $id;
}

function ap_post_receipt(int $poId, string $acceptedQty, int $userId = 1): int
{
    $db = pdo();
    $lq = $db->prepare('SELECT id,item_id,unit_price FROM acc_purchase_lines WHERE workspace_id=10 AND purchase_doc_id=? ORDER BY id LIMIT 1');
    $lq->execute([$poId]);
    $line = $lq->fetch();
    if (!$line) {
        throw new RuntimeException('po_line_missing');
    }
    $unit = (string)$line['unit_price'];
    $lineTotal = PurchaseMatchingDomain::fixed(
        PurchaseMatchingDomain::cents(
            PurchaseMatchingDomain::scaled($acceptedQty, 4, 100000),
            PurchaseMatchingDomain::scaled($unit, 2, 10000000000)
        ),
        2
    );
    $no = 'GR-'.bin2hex(random_bytes(2));
    $db->prepare("INSERT INTO acc_inventory_receipts (workspace_id,company_id,receipt_no,receipt_date,warehouse_id,source_type,purchase_doc_id,supplier_id,status,created_by,approved_by,posted_at,created_at,updated_at)
        VALUES (10,1,?, '2026-11-20',1,'purchase',?,1,'posted',?,?,NOW(),NOW(),NOW())")
        ->execute([$no, $poId, $userId, $userId]);
    $rid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO acc_inventory_receipt_lines (workspace_id,receipt_id,line_no,purchase_line_id,item_id,expected_qty,received_qty,accepted_qty,rejected_qty,unit_cost,line_total,created_at)
        VALUES (10,?,1,?,?,?,?,?,0,?,?,NOW())")
        ->execute([$rid, (int)$line['id'], (int)$line['item_id'], $acceptedQty, $acceptedQty, $acceptedQty, $unit, $lineTotal]);
    $db->prepare("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
        VALUES (10,1,'2026-11-20','purchase_receipt','in',1,?,?,?,'inventory_receipt',?,?,?,'posted',?,NOW())")
        ->execute([(int)$line['item_id'], $acceptedQty, $unit, $rid, (int)$line['id'], $no, $userId]);
    return $rid;
}
