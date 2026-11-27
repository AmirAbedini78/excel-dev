#!/usr/bin/env php
<?php
/**
 * Adversarial / compat tests for PHP Staging Cron Deploy Manager.
 * Run: php tests/php_staging_cron_deploy_adversarial_test.php
 * Compatible with PHP 8.1 / 8.2 / 8.3 — does NOT change ERP app PHP requirements.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(99);
}

$root = dirname(__DIR__);
$deploy = $root . '/scripts/staging_cron_deploy.php';
if (!is_file($deploy)) {
    fwrite(STDERR, "missing staging_cron_deploy.php\n");
    exit(2);
}

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $name, ?string $detail = null): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail ? " — {$detail}" : '') . "\n";
}

// --- Version floor ---
assert_true(version_compare(PHP_VERSION, '8.1.0', '>='), 'php_version_ge_81', PHP_VERSION);

// --- Selftest bundle (covers zip slip, lock, checksum, backup, rollback, secrets) ---
$work = sys_get_temp_dir() . '/php_cron_adv_' . getmypid();
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($deploy)
    . ' --selftest --selftest-work ' . escapeshellarg($work);
exec($cmd, $out, $code);
$json = null;
foreach (array_reverse($out) as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded) && isset($decoded['ok'])) {
        $json = $decoded;
        break;
    }
}
// Selftest prints a single JSON object (possibly multiline) — parse full output
$full = implode("\n", $out);
$json = json_decode($full, true);
assert_true(is_array($json) && !empty($json['ok']), 'selftest_bundle', $full);
if (is_array($json) && isset($json['checks']) && is_array($json['checks'])) {
    foreach ($json['checks'] as $k => $v) {
        assert_true((bool) $v, 'selftest_' . $k);
    }
}
assert_true($code === 0, 'selftest_exit_0', (string) $code);

// --- Require libs for unit-level checks without network ---
require_once $root . '/scripts/lib/erpsmart_controlled_sync.php';
require_once $root . '/scripts/lib/erpsmart_staging_health.php';

// Include deploy functions via subprocess already covered; re-test helpers by requiring deploy is hard
// because it runs CLI. Test contract libs + gates via reflection of duplicated minimal checks.

assert_true(
    ErpsmartControlledSync::refuseNonStagingDest('/home3/zzflgmfd/excel.bcsrp') === 'refused_production_web_root',
    'refuse_production_path'
);
assert_true(
    ErpsmartControlledSync::refuseNonStagingDest('/home3/zzflgmfd/excel2.bcsrp') === null,
    'allow_staging_path'
);

$health = ErpsmartStagingHealth::selftest();
assert_true(!empty($health['ok']), 'health_lib_selftest');

// --- Gates / checksum / wrong dest via selftest already; add explicit gate helper via CLI dry-run N/A ---
// Build a tiny package and verify Controlled Sync preserves secrets
$td = sys_get_temp_dir() . '/php_cron_sync_' . getmypid();
@mkdir($td . '/dest/app', 0755, true);
@mkdir($td . '/dest/storage/uploads', 0755, true);
@mkdir($td . '/pkg', 0755, true);
file_put_contents($td . '/dest/' . ErpsmartControlledSync::IDENTITY_MARKER, ErpsmartControlledSync::EXPECTED_MARKER . "\n");
file_put_contents($td . '/dest/app/config.php', "RUNTIME\n");
file_put_contents($td . '/dest/storage/uploads/x.pdf', "BLOB\n");
file_put_contents($td . '/pkg/index.php', "NEW\n");
$ops = ErpsmartControlledSync::buildPlan($td . '/pkg', $td . '/dest');
ErpsmartControlledSync::applyPlan($td . '/pkg', $td . '/dest', $ops, null);
assert_true(file_get_contents($td . '/dest/app/config.php') === "RUNTIME\n", 'secret_config_preserved');
assert_true(file_get_contents($td . '/dest/storage/uploads/x.pdf') === "BLOB\n", 'upload_preserved');
assert_true(file_get_contents($td . '/dest/index.php') === "NEW\n", 'index_copied');

// Cleanup
$cleanup = static function (string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
};
$cleanup($td);
$cleanup($work);

$summary = [
    'ok' => $failed === 0,
    'passed' => $passed,
    'failed' => $failed,
    'php' => PHP_VERSION,
    'note' => 'Host cron + Release publish + SHA match still required before STAGING_DEPLOY_VERIFIED',
];
echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
exit($failed === 0 ? 0 : 1);
