<?php
declare(strict_types=1);
require_once __DIR__ . '/ap_e2e_bootstrap.php';
try {
    ap_e2e_reset_schema();
    $tables = pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    echo json_encode([
        'ok' => true,
        'tables' => $tables,
        'has_receipt_lines' => in_array('acc_inventory_receipt_lines', $tables, true),
    ], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
