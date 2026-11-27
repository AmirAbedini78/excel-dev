<?php
/** Static contract gate for the additive Universal ERP Agent foundation. */
$root = dirname(__DIR__);
$checks = [
    'app/Core/AiToolRegistry.php' => [
        "'erp_entity_catalog'", "'erp_entity_search'", "'erp_entity_read'", "'erp_entity_report'",
        "'erp_navigation_resolve'", "'erp_entity_history'", "'propose_erp_operation'",
        'AiUniversalOperations::executeProposal', 'AiUniversalOperations::history',
    ],
    'app/Core/AiUniversalOperations.php' => [
        'function catalog(', 'function search(', 'function read(', 'function report(',
        'function navigation(', 'function history(', 'function prepareProposal(', 'function executeProposal(',
        'active_company_only', 'physical_delete', 'assertSuiteArchiveSafe', 'expected_snapshot_hash',
        'سازنده درخواست نمی‌تواند تأییدکننده',
    ],
    'app/Core/ErpSuiteDomain.php' => [
        'function review(', 'سازنده درخواست نمی‌تواند تأییدکننده همان درخواست باشد.',
    ],
    'app/bootstrap.php' => ["AiUniversalOperations.php"],
    'engine/worker.py' => ['write_intent', 'propose_erp_operation', 'erp_entity_report', 'erp_entity_history'],
    'engine/business_skills.py' => ['universal-erp-operations', 'erp_entity_catalog', 'erp_entity_history'],
    'tests/test_universal_ai_ops_php_e2e.py' => [
        'UniversalAiOpsPhpE2e', 'DATABASE_TESTED', 'RC1_REQUIRED',
    ],
    'scripts/rc1_ci_mysql_php_gate.py' => [
        'erpsmart_universal_ai_test', 'test_universal_ai_ops_php_e2e',
    ],
];
$failed = [];
foreach ($checks as $relative => $needles) {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($path)) { $failed[] = "$relative missing"; continue; }
    $body = file_get_contents($path);
    foreach ($needles as $needle) if (!str_contains($body, $needle)) $failed[] = "$relative missing: $needle";
}
$ops = file_get_contents($root . '/app/Core/AiUniversalOperations.php');
foreach (['DELETE FROM ', 'TRUNCATE TABLE ', 'DROP TABLE '] as $forbidden) {
    if (stripos($ops, $forbidden) !== false) $failed[] = "forbidden destructive SQL found: $forbidden";
}
if ($failed) {
    fwrite(STDERR, "UNIVERSAL ERP AGENT CONTRACT: FAIL\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}
echo "UNIVERSAL ERP AGENT CONTRACT: PASS (static foundation only; not database E2E)\n";
