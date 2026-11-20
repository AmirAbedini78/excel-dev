<?php
/**
 * Isolated O2C domain harness for disposable MySQL.
 * Does NOT load full app bootstrap / production config.
 * Env: O2C_DSN, O2C_DB_USER, O2C_DB_PASS
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

// Sandbox guard
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

// Minimal stubs before requiring domains.
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
    public static function check(): bool
    {
        return true;
    }
    public static function user(): array
    {
        return self::$user;
    }
    public static function setUser(array $u): void
    {
        self::$user = $u;
    }
}

final class Tenant
{
    private static int $wid = 10;
    private static array $permissions = [
        'accounting.sales.manage', 'accounting.sales.view', 'accounting.view',
        'accounting.settings.manage', 'accounting.vouchers.manage', 'accounting.vouchers.view',
    ];
    public static function id(): int
    {
        return self::$wid;
    }
    public static function setId(int $id): void
    {
        self::$wid = $id;
    }
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
        self::$logs[] = ['w' => $w, 'a' => $a, 'e' => $e, 'id' => $id];
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

// Lightweight InventoryDomain for on_hand/available from stock + reservations.
final class InventoryDomain
{
    public static function inventoryPosition(int $wid, int $cid, array $args = []): array
    {
        $wh = (int)($args['warehouse_id'] ?? 0);
        $st = pdo()->prepare("SELECT id FROM acc_items WHERE workspace_id=? AND company_id=?");
        $st->execute([$wid, $cid]);
        $items = $st->fetchAll();
        $params = [$wid, $cid];
        $where = "workspace_id=? AND company_id=? AND status='posted'";
        if ($wh > 0) {
            $where .= ' AND warehouse_id=?';
            $params[] = $wh;
        }
        $m = pdo()->prepare("SELECT item_id,SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END) on_hand FROM acc_stock_movements WHERE $where GROUP BY item_id");
        $m->execute($params);
        $on = [];
        foreach ($m->fetchAll() as $r) {
            $on[(int)$r['item_id']] = (float)$r['on_hand'];
        }
        $rp = [$wid, $cid];
        $rw = "workspace_id=? AND company_id=? AND status='active'";
        if ($wh > 0) {
            $rw .= ' AND warehouse_id=?';
            $rp[] = $wh;
        }
        $r = pdo()->prepare("SELECT item_id,SUM(quantity) q FROM acc_inventory_reservations WHERE $rw GROUP BY item_id");
        $r->execute($rp);
        $reserved = [];
        foreach ($r->fetchAll() as $row) {
            $reserved[(int)$row['item_id']] = (float)$row['q'];
        }
        $rows = [];
        foreach ($items as $i) {
            $iid = (int)$i['id'];
            $oh = (float)($on[$iid] ?? 0);
            $rv = (float)($reserved[$iid] ?? 0);
            $rows[] = ['id' => $iid, 'on_hand' => $oh, 'reserved' => $rv, 'available' => $oh - $rv];
        }
        return ['rows' => $rows];
    }
}

final class TradeDomain
{
    public static function landedCostSummary(int $wid, int $cid, int $caseId): array
    {
        return ['allocations' => [], 'actual_cost_type_coverage' => 0, 'case_no' => ''];
    }
}

require_once $ROOT.'/app/Core/PurchaseMatchingDomain.php';
require_once $ROOT.'/app/Core/SalesDomain.php';
require_once $ROOT.'/app/Core/OrderToCashDomain.php';
require_once $ROOT.'/app/Core/AccountingPostingDomain.php';

function o2c_e2e_reset_schema(): void
{
    global $host, $port, $user, $pass, $dbName;
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
    $db = pdo();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'erp_ar_postings','erp_ar_company_maps',
        'erp_o2c_invoice_idempotency','erp_o2c_invoice_links','erp_o2c_delivery_idempotency','erp_o2c_stock_locks',
        'acc_sales_delivery_lines','acc_sales_deliveries','acc_inventory_reservations','acc_stock_movements',
        'acc_voucher_lines','acc_vouchers','acc_period_closes','acc_fiscal_years','acc_accounts',
        'acc_sales_lines','acc_sales_docs','acc_items','acc_warehouses','acc_parties','companies','acc_trade_cases','acc_purchase_lines',
    ] as $t) {
        $db->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $db->exec("CREATE TABLE companies (id INT PRIMARY KEY, workspace_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1)");
    $db->exec("CREATE TABLE acc_warehouses (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, code VARCHAR(64) NULL, name VARCHAR(120) NOT NULL)");
    $db->exec("CREATE TABLE acc_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, item_type VARCHAR(40) NOT NULL DEFAULT 'goods', purchase_price_1 DECIMAL(20,2) NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE acc_parties (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, code VARCHAR(64) NULL, name VARCHAR(120) NOT NULL)");
    $db->exec("CREATE TABLE acc_sales_docs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, doc_type VARCHAR(32) NOT NULL,
      document_no VARCHAR(64) NOT NULL, document_date DATE NOT NULL, due_date DATE NULL, party_id BIGINT NOT NULL,
      warehouse_id BIGINT NULL, cost_center_id BIGINT NULL, project_id BIGINT NULL, notes TEXT NULL,
      workflow_status VARCHAR(32) NOT NULL, taxpayer_status VARCHAR(32) NOT NULL,
      total_before_discount DECIMAL(20,2) NOT NULL DEFAULT 0, discount_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      tax_total DECIMAL(20,2) NOT NULL DEFAULT 0, net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
      created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_sales_lines (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, sales_doc_id BIGINT NOT NULL, line_no INT NOT NULL,
      item_id BIGINT NOT NULL, unit_id BIGINT NULL, warehouse_id BIGINT NULL, cost_center_id BIGINT NULL, project_id BIGINT NULL,
      description VARCHAR(255) NULL, quantity DECIMAL(20,4) NOT NULL, unit_price DECIMAL(20,2) NOT NULL,
      discount_amount DECIMAL(20,2) NOT NULL DEFAULT 0, tax_percent DECIMAL(20,4) NOT NULL DEFAULT 0,
      tax_amount DECIMAL(20,2) NOT NULL DEFAULT 0, line_total DECIMAL(20,2) NOT NULL, created_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_inventory_reservations (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, warehouse_id BIGINT NOT NULL,
      item_id BIGINT NOT NULL, source_type VARCHAR(60) NULL, source_id BIGINT NULL, quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'active', notes VARCHAR(500) NULL, created_by INT NULL,
      created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_stock_movements (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, movement_date DATE NOT NULL,
      movement_type VARCHAR(40) NOT NULL, direction VARCHAR(8) NOT NULL, warehouse_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
      quantity DECIMAL(20,4) NOT NULL, unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0, source_type VARCHAR(40) NOT NULL,
      source_id BIGINT NOT NULL, source_line_id BIGINT NULL, reference_no VARCHAR(120) NULL, status VARCHAR(20) NOT NULL,
      created_by INT NULL, created_at DATETIME NOT NULL
    )");
    $db->exec("CREATE TABLE acc_trade_cases (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, purchase_doc_id BIGINT NOT NULL)");
    $db->exec("CREATE TABLE acc_purchase_lines (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, purchase_doc_id BIGINT NOT NULL, item_id BIGINT NOT NULL)");
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
    SalesDomain::migrate($db);
    OrderToCashDomain::migrate($db);
    AccountingPostingDomain::migrate($db);
    // Seed
    $db->exec("INSERT INTO companies (id,workspace_id,active) VALUES (1,10,1),(2,10,1)");
    $db->exec("INSERT INTO acc_warehouses (id,workspace_id,company_id,code,name) VALUES (1,10,1,'WH1','Warehouse 1')");
    $db->exec("INSERT INTO acc_items (id,workspace_id,company_id,code,name,purchase_price_1) VALUES (1,10,1,'SKU1','Item1',10.00)");
    $db->exec("INSERT INTO acc_parties (id,workspace_id,company_id,code,name) VALUES (1,10,1,'C1','Customer')");
    $db->exec("INSERT INTO acc_accounts (id,workspace_id,company_id,code,name,account_type,nature,allow_posting,active) VALUES
      (1,10,1,'1101','AR','asset','debit',1,1),(2,10,1,'4101','Revenue','income','credit',1,1),(3,10,1,'2101','Tax','liability','credit',1,1),
      (4,10,1,'9999','WrongRevAsAsset','asset','debit',1,1),(5,10,1,'8888','NoPost','asset','debit',0,1)");
    $db->exec("INSERT INTO acc_fiscal_years (workspace_id,company_id,title,start_date,end_date,status,is_active,created_at,updated_at)
      VALUES (10,1,'FY2026','2026-01-01','2026-12-31','open',1,NOW(),NOW())");
    $db->exec("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,status,created_by,created_at)
      VALUES (10,1,CURDATE(),'adjustment','in',1,1,100.0000,10.00,'seed',1,'posted',1,NOW())");
}

function o2c_e2e_create_draft_order(int $createdBy, string $qty = '10.0000', string $price = '100.00'): int
{
    $db = pdo();
    $no = 'SO-'.bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO acc_sales_docs (workspace_id,company_id,doc_type,document_no,document_date,party_id,warehouse_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at)
        VALUES (10,1,'preinvoice',?,CURDATE(),1,1,'order','draft','not_sent',0,0,0,0,?,NOW(),NOW())")
        ->execute([$no, $createdBy]);
    $id = (int)$db->lastInsertId();
    $total = (float)$qty * (float)$price;
    $db->prepare("INSERT INTO acc_sales_lines (workspace_id,sales_doc_id,line_no,item_id,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total,created_at)
        VALUES (10,?,1,1,?,?,0,0,0,?,NOW())")->execute([$id, $qty, $price, $total]);
    $db->prepare('UPDATE acc_sales_docs SET net_total=?, total_before_discount=? WHERE id=?')->execute([$total, $total, $id]);
    return $id;
}

function o2c_json_out($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
}
