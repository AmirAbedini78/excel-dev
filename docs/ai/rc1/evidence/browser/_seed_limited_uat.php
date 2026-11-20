<?php
declare(strict_types=1);
/**
 * Disposable RC1 browser UAT seed — limited role + business profiles.
 * Not a product feature; evidence helper only.
 */
require __DIR__ . '/../../../../../app/bootstrap.php';

$hash = password_hash('Rc1Limited99!', PASSWORD_DEFAULT);
$pdo = pdo();

$pdo->prepare("INSERT INTO workspace_roles (workspace_id, name, role_key, is_system, created_at, updated_at)
VALUES (1, 'RC1 Viewer', 'rc1_viewer', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=NOW()")->execute();
$rid = (int)$pdo->query("SELECT id FROM workspace_roles WHERE workspace_id=1 AND role_key='rc1_viewer'")->fetchColumn();

$keys = ['dashboard.view', 'companies.view', 'procurement.view', 'production.view', 'accounting.view', 'accounting.purchase.view'];
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
VALUES ('RC1 Limited','rc1.limited@example.local', ?, 'accountant','active',0,NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), is_platform_admin=0, updated_at=NOW()")
    ->execute([$hash]);
$uid = (int)$pdo->query("SELECT id FROM users WHERE email='rc1.limited@example.local'")->fetchColumn();

$pdo->prepare("INSERT INTO workspace_members (workspace_id,user_id,role_id,status,joined_at,created_at,updated_at)
VALUES (1,?,?, 'active', NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE role_id=VALUES(role_id), status='active', updated_at=NOW()")
    ->execute([$uid, $rid]);

BusinessProfileDomain::migrate($pdo);
// Direct disposable seed (CLI has no Tenant session); mirrors setCompanyProfile storage.
$pdo->prepare('INSERT INTO erp_company_business_profiles (workspace_id,company_id,profile_code,display_currency,toman_factor,updated_by,updated_at)
    VALUES (?,?,?,?,10,?,NOW()) ON DUPLICATE KEY UPDATE profile_code=VALUES(profile_code),display_currency=VALUES(display_currency),toman_factor=VALUES(toman_factor),updated_by=VALUES(updated_by),updated_at=NOW()')
    ->execute([1, 2, 'retail', 'IRR', 1]);
$pdo->prepare('INSERT INTO erp_company_business_profiles (workspace_id,company_id,profile_code,display_currency,toman_factor,updated_by,updated_at)
    VALUES (?,?,?,?,10,?,NOW()) ON DUPLICATE KEY UPDATE profile_code=VALUES(profile_code),display_currency=VALUES(display_currency),toman_factor=VALUES(toman_factor),updated_by=VALUES(updated_by),updated_at=NOW()')
    ->execute([1, 1, 'service', 'TOMAN', 1]);

$out = [
    'ok' => true,
    'limited_uid' => $uid,
    'role_id' => $rid,
    'retail_company_2' => BusinessProfileDomain::getCompanyProfile(1, 2),
    'service_company_1' => BusinessProfileDomain::getCompanyProfile(1, 1),
    'has_purchase_manage' => (bool)$pdo->query(
        "SELECT 1 FROM workspace_role_permissions rp
         JOIN workspace_permissions p ON p.id=rp.permission_id
         WHERE rp.role_id={$rid} AND p.permission_key='accounting.purchase.manage'"
    )->fetchColumn(),
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
