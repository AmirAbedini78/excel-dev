<?php
/**
 * Disposable MySQL harness for AiUniversalOperations (Suite/Enterprise + proposals).
 * Never points at production DBs — name must contain test/e2e/universal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$host = getenv('O2C_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('O2C_DB_PORT') ?: '3307');
$dbName = getenv('O2C_DB_NAME') ?: 'erpsmart_universal_ai_test';
$user = getenv('O2C_DB_USER') ?: 'p2p';
$pass = getenv('O2C_DB_PASS') ?: 'p2p_test';

$safeHosts = ['127.0.0.1', 'localhost', 'host.docker.internal', 'erpsmart-p2p-mysql-test', '172.17.0.2', 'mysql'];
if (!in_array($host, $safeHosts, true)) {
    fwrite(STDERR, "REFUSED: unsafe host {$host}\n");
    exit(3);
}
if (stripos($dbName, 'test') === false && stripos($dbName, 'e2e') === false && stripos($dbName, 'universal') === false) {
    fwrite(STDERR, "REFUSED: database name must be disposable ({$dbName})\n");
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
        if (!self::$pdo) throw new RuntimeException('pdo_not_connected');
        return self::$pdo;
    }
}
function pdo(): PDO { return DB::pdo(); }

final class Auth
{
    private static array $user = ['id' => 2, 'is_platform_admin' => 0];
    public static function check(): bool { return true; }
    public static function user(): array { return self::$user; }
    public static function setUser(array $u): void { self::$user = $u; }
}

final class Tenant
{
    private static int $wid = 10;
    private static array $permissions = [];
    public static function id(): int { return self::$wid; }
    public static function setId(int $id): void { self::$wid = $id; }
    public static function setPermissions(array $permissions): void { self::$permissions = array_values($permissions); }
    public static function permissions(): array { return self::$permissions; }
    public static function can(string $p): bool
    {
        if (!empty(Auth::user()['is_platform_admin'])) return true;
        return in_array($p, self::$permissions, true);
    }
    public static function requirePermission(string $p): void
    {
        if (!self::can($p)) throw new RuntimeException('permission_denied:'.$p);
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
        self::$logs[] = compact('w', 'a', 'e', 'id', 's');
    }
}

final class ModuleRegistry
{
    private static array $enabled = ['hr' => true, 'expenses' => true, 'projects' => true, 'enterprise_people' => true];
    public static function setEnabled(string $key, bool $on): void { self::$enabled[$key] = $on; }
    public static function reset(): void
    {
        self::$enabled = ['hr' => true, 'expenses' => true, 'projects' => true, 'enterprise_people' => true];
    }
    public static function enabled(string $key, ?int $wid = null): bool
    {
        return !empty(self::$enabled[$key]);
    }
}

final class AccountingRepository
{
    public static function date(string $raw): ?string
    {
        $raw = trim($raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }
}

require_once $ROOT . '/app/Core/ErpSuiteDomain.php';
require_once $ROOT . '/app/Core/EnterpriseCatalog.php';
require_once $ROOT . '/app/Core/EnterpriseDomain.php';
require_once $ROOT . '/app/Core/AiCapabilityRegistry.php';
require_once $ROOT . '/app/Core/AiUniversalOperations.php';
require_once $ROOT . '/app/Core/AiToolRegistry.php';
require_once $ROOT . '/app/Core/AiRepository.php';

function ua_json_out($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
}

function ua_ensure_database(): void
{
    global $host, $port, $user, $pass, $dbName;
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    try {
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        // CI/local often pre-create via root; p2p may lack CREATE privilege — connect must still succeed.
        $check = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=".$pdo->quote($dbName));
        if (!$check || !$check->fetchColumn()) {
            throw $e;
        }
    }
}

function ua_connect(): void
{
    global $host, $port, $user, $pass, $dbName;
    ua_ensure_database();
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
}

function ua_full_perms(): array
{
    return [
        'ai.use', 'ai.actions.approve',
        'hr.view', 'hr.manage', 'hr.approve',
        'expenses.view', 'expenses.manage', 'expenses.approve',
        'projects.view', 'projects.manage',
        'enterprise.people.view', 'enterprise.people.manage', 'enterprise.people.approve',
    ];
}

function ua_reset_schema(): void
{
    ua_connect();
    ModuleRegistry::reset();
    Audit::$logs = [];
    $db = pdo();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $existing = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($existing as $row) {
        $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string)$row[0]) . '`');
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    $db->exec('CREATE TABLE companies (id INT PRIMARY KEY, workspace_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1)');
    $db->exec("INSERT INTO companies (id, workspace_id, active) VALUES (1,10,1),(2,10,1)");
    ErpSuiteDomain::migrate($db);
    EnterpriseDomain::migrate($db);
    $db->exec("CREATE TABLE ai_jobs (
        id BIGINT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NULL,
        conversation_id BIGINT NULL, requested_by INT NULL, job_type VARCHAR(60) NOT NULL,
        prompt MEDIUMTEXT NULL, status VARCHAR(30) NOT NULL, priority INT NOT NULL DEFAULT 100,
        created_at DATETIME NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE ai_action_proposals (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL, company_id INT NULL, job_id BIGINT NOT NULL,
        tool_name VARCHAR(120) NOT NULL, idempotency_key VARCHAR(190) NULL,
        arguments_json JSON NOT NULL, summary VARCHAR(500) NOT NULL,
        risk_level VARCHAR(20) NOT NULL DEFAULT 'medium',
        requires_approval TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'proposed',
        result_json JSON NULL, error_text MEDIUMTEXT NULL,
        proposed_at DATETIME NULL, approved_by INT NULL, approved_at DATETIME NULL,
        rejected_by INT NULL, rejected_at DATETIME NULL,
        executed_by INT NULL, executed_at DATETIME NULL,
        UNIQUE KEY uniq_ai_action_idempotency (workspace_id,job_id,idempotency_key),
        INDEX idx_ai_action_job (workspace_id,job_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("INSERT INTO ai_jobs (id, workspace_id, company_id, conversation_id, requested_by, job_type, prompt, status, priority, created_at, updated_at)
        VALUES (5001,10,1,NULL,1,'agent_chat','universal e2e','queued',100,NOW(),NOW())");
}

function ua_job(int $cid = 1): array
{
    return ['id' => 5001, 'workspace_id' => 10, 'company_id' => $cid];
}

function ua_as(int $userId, array $perms, bool $admin = false): void
{
    Auth::setUser(['id' => $userId, 'is_platform_admin' => $admin ? 1 : 0]);
    Tenant::setId(10);
    Tenant::setPermissions($perms);
}
