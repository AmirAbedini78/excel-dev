<?php
/**
 * Disposable probe: simulate first HTTP schema gate after offline CLI migrate.
 * Exits JSON: whether RuntimeCache::schemaReady would skip cumulative DDL.
 *
 * Env (required):
 *   RC1_STAGING_DB_HOST/PORT/USER/PASS/NAME
 *   RC1_STAGING_CACHE_DIR (must match migrate / web root for sync proof)
 *
 * Never connects to production; refuses prod-like DB names.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/scripts/lib/rc1_cumulative_migrate.php';
rc1_cumulative_migrate_autoload($ROOT);

function env_req(string $k): string
{
    $v = getenv($k);
    if ($v === false || trim((string)$v) === '') {
        fwrite(STDERR, "missing {$k}\n");
        exit(3);
    }
    return trim((string)$v);
}

$host = env_req('RC1_STAGING_DB_HOST');
$port = (int)env_req('RC1_STAGING_DB_PORT');
$user = env_req('RC1_STAGING_DB_USER');
$pass = env_req('RC1_STAGING_DB_PASS');
$dbName = env_req('RC1_STAGING_DB_NAME');
$cacheDir = getenv('RC1_STAGING_CACHE_DIR') ?: ($ROOT . '/storage/cache');
$cacheDir = trim((string)$cacheDir);

foreach (['prod', 'production', 'zzflgmfd', 'live_db'] as $bad) {
    if (stripos($dbName, $bad) !== false) {
        echo json_encode(['ok' => false, 'refused' => 'prod_like_db'], JSON_UNESCAPED_SLASHES) . "\n";
        exit(3);
    }
}

$dbCfg = [
    'host' => $host,
    'port' => $port,
    'database' => $dbName,
    'username' => $user,
    'password' => $pass,
    'charset' => 'utf8mb4',
];

RuntimeCache::boot($cacheDir, $dbCfg);
$ready = RuntimeCache::schemaReady(RuntimeCache::SCHEMA_VERSION);
$info = RuntimeCache::schemaInfo();
$fp = substr(hash('sha256', implode('|', [$host, (string)$port, $dbName, $user])), 0, 16);
$marker = rtrim(str_replace('\\', '/', $cacheDir), '/') . '/runtime_cache/' . $fp . '/schema.json';

$wouldRunDdl = !$ready;
$ddlInvocations = 0;
if ($wouldRunDdl) {
    // Do NOT actually run DDL in this probe — only report that web would.
    $ddlInvocations = 1;
}

echo json_encode([
    'ok' => true,
    'drill' => 'rc1_staging_first_http_probe',
    'schema_ready' => $ready,
    'would_rerun_cumulative_ddl' => $wouldRunDdl,
    'ddl_invocations_if_http' => $ddlInvocations,
    'first_http_skips_ddl' => $ready,
    'schema_info' => $info,
    'db_config_fingerprint' => $fp,
    'cache_dir' => $cacheDir,
    'schema_marker_path' => $marker,
    'schema_marker_exists' => is_file($marker),
    'repo_schema_version' => RuntimeCache::SCHEMA_VERSION,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
