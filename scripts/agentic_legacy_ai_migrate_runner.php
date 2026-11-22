<?php
/**
 * Disposable Agentic AI cumulative migrate runner (CLI only).
 * Allowlist: erpsmart_agentic_legacy_ai_{migrate,recovery,rollback} only.
 * Never production. No credential fallbacks beyond explicit env.
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
        fwrite(STDERR, "REFUSED: missing required env {$key}\n");
        exit(3);
    }
    return $v;
}

$host = env_req('O2C_DB_HOST');
$port = (int)env_req('O2C_DB_PORT');
$user = env_req('O2C_DB_USER');
$pass = env_req('O2C_DB_PASS');
$dbName = env_req('O2C_DB_NAME');

$safeHosts = ['127.0.0.1', 'localhost', 'host.docker.internal', 'erpsmart-p2p-mysql-test', 'mysql'];
if (!in_array($host, $safeHosts, true) && !preg_match('/^172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}$/', $host)) {
    fwrite(STDERR, "REFUSED: host not allowlisted ({$host})\n");
    exit(3);
}

$allow = [
    'erpsmart_agentic_legacy_ai_migrate',
    'erpsmart_agentic_legacy_ai_recovery',
    'erpsmart_agentic_legacy_ai_rollback',
];
if (!in_array($dbName, $allow, true)) {
    fwrite(STDERR, "REFUSED: db not on agentic AI disposable allowlist ({$dbName})\n");
    exit(3);
}
foreach (['baseline', 'prod', 'production', 'zzflgmfd', 'cpanel', 'live'] as $bad) {
    if (stripos($dbName, $bad) !== false) {
        fwrite(STDERR, "REFUSED: baseline/prod-like db name ({$dbName})\n");
        exit(3);
    }
}
if (getenv('AGENTIC_AI_MIGRATE_ALLOW') !== '1') {
    fwrite(STDERR, "REFUSED: AGENTIC_AI_MIGRATE_ALLOW=1 required\n");
    exit(3);
}

$ROOT = dirname(__DIR__);
define('APP_ROOT', $ROOT);

require_once $ROOT . '/scripts/lib/rc1_cumulative_migrate.php';
rc1_cumulative_migrate_autoload($ROOT);

$dbCfg = [
    'host' => $host,
    'port' => $port,
    'database' => $dbName,
    'username' => $user,
    'password' => $pass,
    'charset' => 'utf8mb4',
];

$cacheDir = $ROOT . '/storage/cache/rc1_legacy_rehearsal';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
RuntimeCache::boot($cacheDir, $dbCfg);
DB::connect($dbCfg);
$pdo = DB::pdo();

rc1_clear_schema_json_under($cacheDir);

$t0 = microtime(true);
$steps = rc1_run_cumulative_migrate($pdo);
$ok = !in_array(false, array_column($steps, 'ok'), true);

$aiVersion = null;
try {
    $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=" . $pdo->quote($dbName) . " AND table_name='settings'")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_map('strtolower', array_map('strval', $cols ?: []));
    if (in_array('setting_key', $cols, true) && in_array('setting_value', $cols, true)) {
        if (in_array('workspace_id', $cols, true)) {
            $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='ai_schema_version' AND workspace_id=0 LIMIT 1");
        } else {
            $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='ai_schema_version' LIMIT 1");
        }
        $st->execute();
        $aiVersion = $st->fetchColumn();
    } elseif (in_array('key', $cols, true) && in_array('value', $cols, true)) {
        $st = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='ai_schema_version' LIMIT 1");
        $st->execute();
        $aiVersion = $st->fetchColumn();
    }
    if ($aiVersion === false) {
        $aiVersion = null;
    }
} catch (Throwable $e) {
    $aiVersion = null;
}

$aiTables = $pdo->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema=" . $pdo->quote($dbName) .
    " AND table_name LIKE 'ai\\_%' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'ok' => $ok,
    'db' => $dbName,
    'ms' => (int)((microtime(true) - $t0) * 1000),
    'tables' => rc1_table_count($pdo, $dbName),
    'ai_schema_version' => $aiVersion,
    'ai_tables' => $aiTables,
    'rc1_tables' => rc1_validate_rc1_tables($pdo, $dbName),
    'steps' => $steps,
    'agent_task_schema' => 'erpsmart.agent_task.v1',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

exit($ok ? 0 : 1);
