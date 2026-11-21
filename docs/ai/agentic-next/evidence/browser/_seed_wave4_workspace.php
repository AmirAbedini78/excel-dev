<?php
declare(strict_types=1);
/**
 * Disposable Wave4 Universal AI Workspace browser seed — SYNTHETIC ONLY.
 * NOT production. Creates/updates a local test user with ai.use for sidecar journeys.
 */
require __DIR__ . '/../../../../../app/bootstrap.php';

$hash = password_hash('Wave4Workspace99!', PASSWORD_DEFAULT);
$pdo = pdo();

$pdo->prepare("INSERT INTO workspace_roles (workspace_id, name, role_key, is_system, created_at, updated_at)
VALUES (1, 'Wave4 AI User', 'wave4_ai_user', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=NOW()")->execute();
$rid = (int)$pdo->query("SELECT id FROM workspace_roles WHERE workspace_id=1 AND role_key='wave4_ai_user'")->fetchColumn();

$keys = ['dashboard.view', 'companies.view', 'ai.use', 'ai.actions.approve', 'ai.audit.view'];
$st = $pdo->prepare('SELECT id FROM workspace_permissions WHERE permission_key=?');
$ins = $pdo->prepare('INSERT IGNORE INTO workspace_role_permissions (role_id, permission_id) VALUES (?, ?)');
foreach ($keys as $k) {
    $st->execute([$k]);
    $pid = (int)$st->fetchColumn();
    if ($pid > 0) {
        $ins->execute([$rid, $pid]);
    }
}

$pdo->prepare("INSERT INTO users (name,email,password_hash,role,status,is_platform_admin,created_at,updated_at)
VALUES ('Wave4 Workspace','wave4.workspace@example.local', ?, 'accountant','active',0,NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), is_platform_admin=0, updated_at=NOW()")
    ->execute([$hash]);
$uid = (int)$pdo->query("SELECT id FROM users WHERE email='wave4.workspace@example.local'")->fetchColumn();

$pdo->prepare("INSERT INTO workspace_members (workspace_id,user_id,role_id,status,joined_at,created_at,updated_at)
VALUES (1,?,?, 'active', NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE role_id=VALUES(role_id), status='active', updated_at=NOW()")
    ->execute([$uid, $rid]);

$out = [
    'ok' => true,
    'synthetic' => true,
    'NOT production' => true,
    'email' => 'wave4.workspace@example.local',
    'password' => 'Wave4Workspace99!',
    'uid' => $uid,
    'role_id' => $rid,
    'jalali_today' => class_exists('Jalali') ? Jalali::today() : null,
    'notes' => 'Use only against local/synthetic DB. Do not point at production.',
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
