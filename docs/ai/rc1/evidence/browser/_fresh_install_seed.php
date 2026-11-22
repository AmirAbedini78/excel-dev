<?php
declare(strict_types=1);
/**
 * Disposable local browser UAT: cumulative migrate + admin + module enable.
 * Does not print secrets. Uses shared RC1 migrate order (not partial Schema-only).
 */
$ROOT = dirname(__DIR__, 5);
require_once $ROOT . '/app/bootstrap.php';
require_once $ROOT . '/scripts/lib/rc1_cumulative_migrate.php';

$db = pdo();
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$tables = rc1_table_count($db, $dbName);
$hasWorkspaces = (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . $db->quote($dbName) .
    " AND table_name='workspaces'"
)->fetchColumn() > 0;

$migrateSteps = [];
if ($tables < 5 || !$hasWorkspaces) {
    $migrateSteps = rc1_run_cumulative_migrate($db);
    $failed = array_filter($migrateSteps, static fn ($s) => empty($s['ok']));
    if ($failed) {
        fwrite(STDERR, json_encode(['ok' => false, 'error' => 'cumulative_migrate_failed', 'steps' => $migrateSteps], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }
}

$adminEmail = 'rc1.uat@example.local';
$exists = (int) $db->query('SELECT COUNT(*) FROM users WHERE email=' . $db->quote($adminEmail))->fetchColumn();
if ($exists < 1) {
    Schema::createAdmin($db, 'RC1 UAT Admin', $adminEmail, 'Rc1UatPass99!');
    Schema::seed($db);
}

// Ensure platform admin + workspace membership (createAdmin alone is insufficient after Tenant bootstrap).
$db->prepare('UPDATE users SET is_platform_admin=1, role=\'admin\', status=\'active\', updated_at=NOW() WHERE email=?')
    ->execute([$adminEmail]);
$adminId = (int) $db->query('SELECT id FROM users WHERE email=' . $db->quote($adminEmail))->fetchColumn();
$ownerRoleId = (int) $db->query("SELECT id FROM workspace_roles WHERE workspace_id=1 AND role_key='owner' LIMIT 1")->fetchColumn();
if ($adminId > 0 && $ownerRoleId > 0) {
    $db->prepare("INSERT INTO workspace_members (workspace_id,user_id,role_id,status,joined_at,created_at,updated_at)
        VALUES (1,?,?, 'active', NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE role_id=VALUES(role_id), status='active', updated_at=NOW()")
        ->execute([$adminId, $ownerRoleId]);
}
$db->prepare("UPDATE workspaces SET owner_user_id=?, updated_at=NOW() WHERE id=1")->execute([$adminId]);

// workspace_modules has no created_at in current schema.
foreach (['production', 'service', 'p2p', 'procurement', 'inventory'] as $mod) {
    $db->prepare('INSERT INTO workspace_modules (workspace_id, module_key, enabled, updated_at)
        VALUES (1, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE enabled=1, updated_at=NOW()')
        ->execute([$mod]);
}

$tables = rc1_table_count($db, $dbName);
$users = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$companies = (int) $db->query('SELECT COUNT(*) FROM companies')->fetchColumn();
$member = (int) $db->query('SELECT COUNT(*) FROM workspace_members WHERE workspace_id=1 AND user_id=' . (int)$adminId . " AND status='active'")->fetchColumn();
$mods = $db->query("SELECT module_key, enabled FROM workspace_modules WHERE workspace_id=1 AND module_key IN ('production','service','p2p','procurement','inventory')")->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'ok' => true,
    'database' => $dbName,
    'tables' => $tables,
    'users' => $users,
    'companies' => $companies,
    'admin_id' => $adminId,
    'admin_member' => $member,
    'modules' => $mods,
    'migrate_steps' => count($migrateSteps),
    'rc1_tables' => rc1_validate_rc1_tables($db, $dbName),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
