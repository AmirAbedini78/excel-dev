<?php
/**
 * Operator-controlled STAGING cumulative migrate (PHP CLI only).
 *
 * Reuses scripts/lib/rc1_cumulative_migrate.php — does NOT duplicate schema DDL.
 * Does NOT depend on Docker (shared hosting / cPanel friendly).
 * Does NOT replace disposable rehearsal runner; that runner keeps Docker identity gates.
 *
 * Hard gates:
 *  - Explicit staging operator auth + REQUIRED expected token match (nonempty)
 *  - Refuse production-like hosts/DBs and missing identity/auth
 *  - Staging credentials via protected runtime env only (no config.php password fallback)
 *  - Staging DB identity must differ from declared production DB identity
 *  - Verified pre-migration backup path + SHA-256 + restorable SQL shape required
 *  - Operator-reviewed dry-run/preflight ack required before apply
 *  - Engage web maintenance lock until offline migration completes
 *  - Schema-ready marker written under SAME cache root as web bootstrap
 *  - Record steps/failures as JSON evidence (failed migrate leaves lock + failure marker)
 *  - Validate RC1 table completeness + migrate×2 idempotency
 *  - Never run install.php; never clear production schema cache
 *
 * Disposable test mode (local only):
 *  RC1_STAGING_EXECUTOR_TEST=1 + disposable DB allowlist — still requires auth/backup/preflight.
 *  Never use this mode against remote staging/production.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only — refuse web SAPI\n");
    exit(2);
}

$ROOT = dirname(__DIR__);
define('APP_ROOT', $ROOT);

require_once $ROOT . '/scripts/lib/rc1_cumulative_migrate.php';

function staging_env(string $key): ?string
{
    $v = getenv($key);
    if ($v === false) {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function staging_env_req(string $key): string
{
    $v = staging_env($key);
    if ($v === null) {
        fwrite(STDERR, "REFUSED: missing required env {$key}\n");
        exit(3);
    }
    return $v;
}

function staging_truthy(?string $v): bool
{
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

function staging_fail(string $msg, array $evidence, int $code = 3): never
{
    $evidence['ok'] = false;
    $evidence['refused'] = $msg;
    $evidence['finished_at'] = date('c');
    echo json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    fwrite(STDERR, "REFUSED: {$msg}\n");
    exit($code);
}

function staging_emit(array $evidence, int $code = 0): never
{
    $evidence['finished_at'] = date('c');
    echo json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($code);
}

function staging_looks_prod_name(string $name): bool
{
    foreach (['prod', 'production', 'zzflgmfd', 'cpanel_live', 'live_db', 'baseline'] as $bad) {
        if (stripos($name, $bad) !== false) {
            return true;
        }
    }
    return false;
}

function staging_looks_prod_host(string $host): bool
{
    $h = strtolower($host);
    foreach (['prod.', 'production.', 'live.', 'cpanel-prod'] as $bad) {
        if (str_contains($h, $bad)) {
            return true;
        }
    }
    return false;
}

function staging_disposable_db_ok(string $dbName): bool
{
    $allow = [
        'erpsmart_rc1_staging_executor_test',
        'erpsmart_rc1_staging_migrate_sim',
        'erpsmart_rc1_staging_backup_test',
    ];
    if (in_array($dbName, $allow, true)) {
        return true;
    }
    return str_contains($dbName, 'erpsmart_rc1')
        && str_contains($dbName, 'staging')
        && (str_contains($dbName, 'executor') || str_contains($dbName, 'migrate_sim') || str_contains($dbName, 'sim') || str_contains($dbName, 'backup'));
}

function staging_disposable_host_ok(string $host): bool
{
    $safe = ['127.0.0.1', 'localhost', 'host.docker.internal', 'erpsmart-p2p-mysql-test', 'mysql'];
    if (in_array($host, $safe, true)) {
        return true;
    }
    return (bool)preg_match('/^172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}$/', $host);
}

function staging_web_cache_root(): string
{
    return APP_ROOT . '/storage/cache';
}

function staging_maintenance_lock_path(): string
{
    return APP_ROOT . '/storage/maintenance/rc1_offline_migrate.lock';
}

function staging_failure_marker_path(): string
{
    return APP_ROOT . '/storage/maintenance/rc1_offline_migrate.FAILED.json';
}

/**
 * Auth is NEVER optional: operator token alone ≠ authenticated.
 * RC1_STAGING_AUTH_EXPECTED must be nonempty and match via hash_equals.
 *
 * @return array{0:string,1:string} [auth, identity]
 */
function staging_require_auth(array &$evidence): array
{
    $auth = staging_env('RC1_STAGING_OPERATOR_AUTH');
    $identity = staging_env('RC1_STAGING_ENV_IDENTITY');
    $expectedAuth = staging_env('RC1_STAGING_AUTH_EXPECTED');
    if ($auth === null || $identity === null) {
        staging_fail('staging authorization or env identity missing', $evidence);
    }
    if ($expectedAuth === null) {
        staging_fail('RC1_STAGING_AUTH_EXPECTED is required (nonempty operator token alone is not authenticated)', $evidence);
    }
    if (!hash_equals($expectedAuth, $auth)) {
        staging_fail('staging operator auth token mismatch', $evidence);
    }
    $evidence['auth_present'] = true;
    $evidence['auth_expected_enforced'] = true;
    return [$auth, $identity];
}

function staging_engage_maintenance(array &$evidence): void
{
    $dir = APP_ROOT . '/storage/maintenance';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        staging_fail('cannot create maintenance directory', $evidence);
    }
    $path = staging_maintenance_lock_path();
    $payload = [
        'reason' => 'rc1_offline_staging_migrate',
        'engaged_at' => date('c'),
        'pid' => getmypid(),
        'operator_note' => staging_env('RC1_STAGING_OPERATOR_NOTE') ?? 'staging offline migrate',
    ];
    if (@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        staging_fail('cannot write maintenance lock', $evidence);
    }
    @chmod($path, 0640);
    $evidence['maintenance_lock'] = ['path' => $path, 'engaged' => true];
}

function staging_release_maintenance(array &$evidence): void
{
    $path = staging_maintenance_lock_path();
    if (is_file($path)) {
        @unlink($path);
    }
    $fail = staging_failure_marker_path();
    if (is_file($fail)) {
        @unlink($fail);
    }
    $evidence['maintenance_lock'] = ['path' => $path, 'engaged' => false, 'released' => true];
}

function staging_write_failure_marker(array $evidence): void
{
    $dir = APP_ROOT . '/storage/maintenance';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $marker = [
        'ok' => false,
        'state' => 'MIGRATE_FAILED_OR_INTERRUPTED',
        'recorded_at' => date('c'),
        'maintenance_lock_engaged' => is_file(staging_maintenance_lock_path()),
        'refused' => $evidence['refused'] ?? null,
        'note' => $evidence['note'] ?? null,
        'db' => $evidence['db'] ?? null,
        'guidance' => 'Do not release maintenance until operator restores from verified backup or completes a successful migrate.',
    ];
    @file_put_contents(
        staging_failure_marker_path(),
        json_encode($marker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function staging_verify_backup(string $path, string $expectedSha, array &$evidence): void
{
    if (!is_file($path)) {
        staging_fail('pre-migration backup file missing', $evidence);
    }
    if (!is_readable($path)) {
        staging_fail('pre-migration backup not readable', $evidence);
    }
    $size = filesize($path);
    if ($size === false || $size < 1) {
        staging_fail('pre-migration backup empty', $evidence);
    }
    $minBytes = (int)(staging_env('RC1_PRE_MIGRATE_BACKUP_MIN_BYTES') ?? '256');
    if ($minBytes < 64) {
        $minBytes = 64;
    }
    if ($size < $minBytes) {
        staging_fail(
            "pre-migration backup too small ({$size} < {$minBytes} bytes) — refuse placeholder dumps",
            $evidence
        );
    }
    $actual = hash_file('sha256', $path);
    if ($actual === false) {
        staging_fail('cannot hash pre-migration backup', $evidence);
    }
    if (!hash_equals(strtolower($expectedSha), strtolower($actual))) {
        staging_fail('pre-migration backup checksum mismatch', $evidence);
    }
    $head = (string)@file_get_contents($path, false, null, 0, 4096);
    $looksSql = (bool)preg_match(
        '/(?:^|--\s*)(?:MySQL dump|MariaDB dump)|CREATE\s+TABLE|INSERT\s+INTO|DROP\s+TABLE/i',
        $head
    );
    if (!$looksSql) {
        staging_fail('pre-migration backup does not look like restorable SQL dump', $evidence);
    }
    $evidence['backup'] = [
        'path' => $path,
        'sha256' => $actual,
        'bytes' => $size,
        'min_bytes' => $minBytes,
        'sql_shape_ok' => true,
        'verified' => true,
    ];
}

/**
 * DB fingerprint must match web RuntimeCache::boot (host|port|database|username).
 */
function staging_db_fingerprint(array $dbCfg): string
{
    $fingerprint = implode('|', [
        (string)($dbCfg['host'] ?? ''),
        (string)($dbCfg['port'] ?? ''),
        (string)($dbCfg['database'] ?? ''),
        (string)($dbCfg['username'] ?? ''),
    ]);
    return substr(hash('sha256', $fingerprint !== '' ? $fingerprint : 'default'), 0, 16);
}

$started = date('c');
$evidence = [
    'ok' => false,
    'drill' => 'rc1_staging_schema_migrate',
    'started_at' => $started,
    'repo_schema_version' => null,
    'mode' => null,
    'dry_run' => false,
    'steps_log' => [],
];

$args = array_slice($argv, 1);
$wantReleaseOnly = in_array('--release-maintenance', $args, true);
$forceDryRun = in_array('--dry-run', $args, true) || staging_truthy(staging_env('RC1_STAGING_DRY_RUN'));

if ($wantReleaseOnly) {
    // Same auth strength as migrate path — never weaker.
    staging_require_auth($evidence);
    $identity = staging_env('RC1_STAGING_ENV_IDENTITY');
    $evidence['env_identity'] = $identity;
    staging_release_maintenance($evidence);
    $evidence['ok'] = true;
    $evidence['mode'] = 'release_maintenance_only';
    staging_emit($evidence, 0);
}

$executorTest = staging_truthy(staging_env('RC1_STAGING_EXECUTOR_TEST'));
$targetMode = staging_env('RC1_STAGING_TARGET_MODE') ?? ($executorTest ? 'disposable_sim' : 'staging');
$evidence['mode'] = $targetMode;
$evidence['dry_run'] = $forceDryRun;

// --- Gate: explicit auth + env identity (expected token REQUIRED) ---
[, $identity] = staging_require_auth($evidence);
if (!preg_match('/staging/i', $identity) && $targetMode === 'staging') {
    staging_fail('RC1_STAGING_ENV_IDENTITY must identify staging role', $evidence);
}
if ($targetMode === 'disposable_sim') {
    if (!$executorTest) {
        staging_fail('disposable_sim requires RC1_STAGING_EXECUTOR_TEST=1', $evidence);
    }
    if (!preg_match('/(staging|disposable|sim)/i', $identity)) {
        staging_fail('disposable_sim identity must mention staging|disposable|sim', $evidence);
    }
}
$evidence['env_identity'] = $identity;

// --- Gate: operator-reviewed preflight ---
$preflight = staging_env('RC1_STAGING_PREFLIGHT_REVIEWED');
if (!staging_truthy($preflight)) {
    staging_fail('operator preflight/dry-run review not acknowledged (RC1_STAGING_PREFLIGHT_REVIEWED=1)', $evidence);
}
$evidence['preflight_reviewed'] = true;

// --- Credentials from protected runtime env only ---
$host = staging_env_req('RC1_STAGING_DB_HOST');
$port = (int)staging_env_req('RC1_STAGING_DB_PORT');
$user = staging_env_req('RC1_STAGING_DB_USER');
$pass = staging_env_req('RC1_STAGING_DB_PASS');
$dbName = staging_env_req('RC1_STAGING_DB_NAME');

// Refuse accidental reuse of O2C_* as silent fallbacks — staging keys are mandatory above.
$evidence['db'] = [
    'host' => $host,
    'port' => $port,
    'database' => $dbName,
    'username' => $user,
    // password never logged
];

if (staging_looks_prod_host($host) || staging_looks_prod_name($dbName) || staging_looks_prod_name($user)) {
    staging_fail('production-like host/db/user refused', $evidence);
}
foreach (['0.0.0.0', '*', '::'] as $unsafe) {
    if ($host === $unsafe) {
        staging_fail('unsafe host refused', $evidence);
    }
}

$prodDb = staging_env_req('RC1_PROD_DB_NAME');
$prodHost = staging_env('RC1_PROD_DB_HOST'); // optional but recommended
if (strcasecmp($dbName, $prodDb) === 0) {
    staging_fail('staging DB name equals declared production DB identity', $evidence);
}
if ($prodHost !== null && strcasecmp($host, $prodHost) === 0 && strcasecmp($dbName, $prodDb) === 0) {
    staging_fail('staging target matches production host+db identity', $evidence);
}
$evidence['prod_identity_declared'] = [
    'db_name' => $prodDb,
    'host' => $prodHost,
    'differs_from_staging_db' => true,
];

if ($targetMode === 'disposable_sim') {
    if (!staging_disposable_host_ok($host)) {
        staging_fail('disposable_sim host not on local allowlist', $evidence);
    }
    if (!staging_disposable_db_ok($dbName)) {
        staging_fail('disposable_sim db not on executor allowlist', $evidence);
    }
} else {
    // Real staging: refuse disposable-only localhost unless operator explicitly allows loopback staging lab.
    if (staging_disposable_host_ok($host) && !staging_truthy(staging_env('RC1_STAGING_ALLOW_LOOPBACK'))) {
        staging_fail('staging mode refuses disposable/loopback hosts without RC1_STAGING_ALLOW_LOOPBACK=1', $evidence);
    }
    if (!preg_match('/staging|stg|stage/i', $dbName) && !staging_truthy(staging_env('RC1_STAGING_DB_NAME_OVERRIDE_ACK'))) {
        staging_fail('staging DB name should contain staging|stg|stage (or set RC1_STAGING_DB_NAME_OVERRIDE_ACK=1)', $evidence);
    }
}

// --- Gate: verified pre-migration backup + checksum + SQL shape ---
$backupPath = staging_env_req('RC1_PRE_MIGRATE_BACKUP_PATH');
$backupSha = staging_env_req('RC1_PRE_MIGRATE_BACKUP_SHA256');
staging_verify_backup($backupPath, $backupSha, $evidence);

// --- Cache: MUST match web bootstrap root so schema-ready is recognized ---
$webCacheRoot = staging_web_cache_root();
$cacheDir = staging_env('RC1_STAGING_CACHE_DIR') ?? $webCacheRoot;
$norm = static function (string $p): string {
    $r = realpath($p);
    $use = $r !== false ? $r : $p;
    return strtolower(str_replace('\\', '/', rtrim($use, '/\\')));
};
$realCache = $norm($cacheDir);
$realWeb = $norm($webCacheRoot);
$isolationAck = staging_truthy(staging_env('RC1_STAGING_CACHE_ISOLATION_ACK'));
if ($realCache !== $realWeb && !$isolationAck) {
    // Disposable sims may still use web root (db-hash isolates). Isolated roots require explicit ack
    // and will NOT sync with web gate — refuse for real staging.
    if ($targetMode === 'staging') {
        staging_fail(
            'RC1_STAGING_CACHE_DIR must equal web RuntimeCache root (storage/cache) so offline schema-ready is visible to HTTP',
            $evidence
        );
    }
    if ($targetMode === 'disposable_sim' && !str_contains($realCache, 'staging')) {
        staging_fail('isolated disposable cache path must contain staging (or use web storage/cache)', $evidence);
    }
}
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0750, true)) {
    staging_fail('cannot create staging cache dir', $evidence);
}
$evidence['cache_dir'] = $cacheDir;
$evidence['web_cache_root'] = $webCacheRoot;
$evidence['cache_matches_web_root'] = ($realCache === $realWeb);
$evidence['prod_cache_cleared'] = false;

rc1_cumulative_migrate_autoload($ROOT);
$evidence['repo_schema_version'] = RuntimeCache::SCHEMA_VERSION;

$dbCfg = [
    'host' => $host,
    'port' => $port,
    'database' => $dbName,
    'username' => $user,
    'password' => $pass,
    'charset' => 'utf8mb4',
];
$dbHash = staging_db_fingerprint($dbCfg);
$evidence['db_config_fingerprint'] = $dbHash;

RuntimeCache::boot($cacheDir, $dbCfg);

try {
    DB::connect($dbCfg);
    $pdo = pdo();
} catch (Throwable $e) {
    staging_fail('DB connect failed: ' . $e->getMessage(), $evidence);
}

$serverUuid = (string)$pdo->query('SELECT @@server_uuid')->fetchColumn();
$mysqlVer = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$evidence['server_uuid'] = $serverUuid;
$evidence['mysql_version'] = $mysqlVer;

$before = rc1_table_count($pdo, $dbName);
$evidence['tables_before'] = $before;

// Engage maintenance BEFORE apply (and for dry-run we still engage to prove gate wiring).
staging_engage_maintenance($evidence);

if ($forceDryRun) {
    $evidence['ok'] = true;
    $evidence['dry_run'] = true;
    $evidence['note'] = 'Dry-run/preflight only — no migrate applied. Re-run without --dry-run / RC1_STAGING_DRY_RUN after review.';
    $evidence['steps_log'][] = ['phase' => 'dry_run', 'ok' => true];
    // Keep maintenance engaged until operator applies or explicitly releases.
    staging_emit($evidence, 0);
}

$passes = [];
foreach ([1, 2] as $i) {
    // Clear ONLY this target's schema markers between passes (idempotency proof).
    // Never wipe unrelated DB fingerprints under the shared web cache root.
    $targetSchema = $cacheDir . '/runtime_cache/' . $dbHash . '/schema.json';
    $cleared = 0;
    if (is_file($targetSchema) && @unlink($targetSchema)) {
        $cleared = 1;
    }
    $t0 = microtime(true);
    $steps = rc1_run_cumulative_migrate($pdo);
    $ok = !in_array(false, array_column($steps, 'ok'), true);
    $pass = [
        'pass' => $i,
        'ok' => $ok,
        'ms' => (int)((microtime(true) - $t0) * 1000),
        'schema_json_cleared' => $cleared,
        'schema_marker' => $targetSchema,
        'steps' => $steps,
        'tables_after' => rc1_table_count($pdo, $dbName),
    ];
    $passes[] = $pass;
    $evidence['steps_log'][] = $pass;
    if (!$ok) {
        $evidence['ok'] = false;
        $evidence['migrate_twice'] = $passes;
        $evidence['note'] = 'Migrate failed — maintenance lock LEFT ENGAGED; restore from verified backup; do not release until operator decision.';
        staging_write_failure_marker($evidence);
        staging_emit($evidence, 1);
    }
}

$after = rc1_table_count($pdo, $dbName);
$present = rc1_validate_rc1_tables($pdo, $dbName);
$allPresent = !in_array(false, array_values($present), true);

$evidence['tables_after'] = $after;
$evidence['migrate_twice'] = $passes;
$evidence['rc1_tables_present'] = $present;
$evidence['schema_complete'] = $allPresent;
$evidence['idempotent'] = ($passes[0]['ok'] ?? false) && ($passes[1]['ok'] ?? false);
$evidence['install_php_used'] = false;
$evidence['docker_required'] = false;
$evidence['schema_ready_after'] = RuntimeCache::schemaReady(RuntimeCache::SCHEMA_VERSION);
$evidence['schema_marker_path'] = $cacheDir . '/runtime_cache/' . $dbHash . '/schema.json';

if (!$allPresent) {
    $evidence['ok'] = false;
    $evidence['note'] = 'Schema incomplete after migrate — maintenance remains engaged.';
    staging_write_failure_marker($evidence);
    staging_emit($evidence, 1);
}

if (!$evidence['schema_ready_after']) {
    $evidence['ok'] = false;
    $evidence['note'] = 'Schema marker missing after migrate — web would re-run DDL; maintenance remains engaged.';
    staging_write_failure_marker($evidence);
    staging_emit($evidence, 1);
}

// Success: release maintenance so web can resume (operator may also use --release-maintenance).
staging_release_maintenance($evidence);
$evidence['ok'] = true;
$evidence['note'] = 'Staging cumulative migrate×2 PASS. Not STAGING_VERIFIED / Not PRODUCTION_READY. Remote staging still requires owner auth.';
staging_emit($evidence, 0);
