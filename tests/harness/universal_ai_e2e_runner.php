<?php
/**
 * Scenario runner: Universal ERP Agent MySQL E2E (PASS-required under RC1).
 */
declare(strict_types=1);

require_once __DIR__ . '/universal_ai_e2e_bootstrap.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $decoded = json_decode($argv[2], true);
    if (is_array($decoded)) $args = $decoded;
}

try {
    switch ($scenario) {
        case 'reset':
            ua_reset_schema();
            ua_json_out(['ok' => true, 'DATABASE_TESTED' => true, 'database' => getenv('O2C_DB_NAME') ?: 'erpsmart_universal_ai_test']);
            break;

        case 'reads_and_navigation':
            ua_reset_schema();
            ua_as(1, ua_full_perms());
            $empId = ErpSuiteDomain::save('employees', 10, 1, 1, [
                'code' => 'E-100', 'full_name' => 'علی رضایی', 'department' => 'مالی',
                'job_title' => 'حسابدار', 'hire_date' => '2024-01-10', 'status' => 'active',
            ], 0);
            $cat = AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_catalog', ['domain' => 'suite']);
            $search = AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_search', [
                'domain' => 'suite', 'entity' => 'employees', 'query' => 'علی',
            ]);
            $read = AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_read', [
                'domain' => 'suite', 'entity' => 'employees', 'query' => 'E-100',
            ]);
            $report = AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_report', [
                'domain' => 'suite', 'entity' => 'employees', 'group_by' => 'status',
            ]);
            $nav = AiToolRegistry::executeForWorker(ua_job(), 'erp_navigation_resolve', [
                'domain' => 'suite', 'entity' => 'employees', 'query' => 'E-100',
            ]);
            $leaveOps = [];
            foreach (($cat['entities'] ?? []) as $ent) {
                if (($ent['entity'] ?? '') === 'leave') $leaveOps = $ent['operations'] ?? [];
            }
            ua_json_out([
                'ok' => true,
                'DATABASE_TESTED' => true,
                'employee_id' => $empId,
                'catalog_count' => (int)($cat['count'] ?? 0),
                'search_count' => (int)($search['count'] ?? 0),
                'read_label' => (string)($read['label'] ?? ''),
                'report_groups' => count((array)($report['groups'] ?? [])),
                'nav_url' => (string)($nav['url'] ?? ''),
                'leave_has_review' => in_array('review', $leaveOps, true),
                'leave_has_create' => in_array('create', $leaveOps, true),
            ]);
            break;

        case 'catalog_rbac_view_only':
            ua_reset_schema();
            ua_as(3, ['ai.use', 'hr.view']);
            $cat = AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_catalog', ['domain' => 'suite']);
            $leaveOps = [];
            foreach (($cat['entities'] ?? []) as $ent) {
                if (($ent['entity'] ?? '') === 'leave') $leaveOps = $ent['operations'] ?? [];
            }
            ua_json_out([
                'ok' => true,
                'leave_ops' => $leaveOps,
                'no_create' => !in_array('create', $leaveOps, true),
                'no_review' => !in_array('review', $leaveOps, true),
                'has_read' => in_array('read', $leaveOps, true),
            ]);
            break;

        case 'propose_create_approve':
            ua_reset_schema();
            ua_as(1, ua_full_perms());
            ErpSuiteDomain::save('employees', 10, 1, 1, [
                'code' => 'E-200', 'full_name' => 'سارا نوری', 'department' => 'HR',
                'job_title' => 'کارشناس', 'hire_date' => '2024-02-01', 'status' => 'active',
            ], 0);
            $prop = AiToolRegistry::executeForWorker(ua_job(), 'propose_erp_operation', [
                'domain' => 'suite', 'entity' => 'leave', 'operation' => 'create',
                'data' => [
                    'employee_id' => 'سارا نوری',
                    'leave_type' => 'annual',
                    'starts_on' => '2026-12-01',
                    'ends_on' => '2026-12-03',
                    'notes' => 'مرخصی تست',
                ],
            ], 'idem-leave-create-1');
            $proposalId = (int)($prop['proposal_id'] ?? 0);
            $dup = AiToolRegistry::executeForWorker(ua_job(), 'propose_erp_operation', [
                'domain' => 'suite', 'entity' => 'leave', 'operation' => 'create',
                'data' => [
                    'employee_id' => 'سارا نوری',
                    'leave_type' => 'annual',
                    'starts_on' => '2026-12-01',
                    'ends_on' => '2026-12-03',
                    'notes' => 'مرخصی تست',
                ],
            ], 'idem-leave-create-1');
            // Human approver (may be same or different user); create itself is not maker/checker-gated.
            ua_as(2, ua_full_perms());
            $result = AiRepository::approveProposal($proposalId);
            $st = pdo()->prepare("SELECT status FROM ai_action_proposals WHERE id=?");
            $st->execute([$proposalId]);
            $propStatus = (string)$st->fetchColumn();
            $leaveCount = (int)pdo()->query("SELECT COUNT(*) FROM erp_leave_requests WHERE workspace_id=10 AND company_id=1 AND is_archived=0")->fetchColumn();
            $leaveStatus = (string)pdo()->query("SELECT status FROM erp_leave_requests WHERE workspace_id=10 AND company_id=1 AND is_archived=0 ORDER BY id DESC LIMIT 1")->fetchColumn();
            ua_json_out([
                'ok' => true,
                'proposal_id' => $proposalId,
                'idempotent_same_id' => (int)($dup['proposal_id'] ?? 0) === $proposalId,
                'executed_id' => (int)($result['id'] ?? 0),
                'proposal_status' => $propStatus,
                'leave_rows' => $leaveCount,
                'leave_status' => $leaveStatus,
                'physical_delete' => !empty($result['physical_delete']),
            ]);
            break;

        case 'maker_checker_review':
            ua_reset_schema();
            ua_as(1, ua_full_perms());
            ErpSuiteDomain::save('employees', 10, 1, 1, [
                'code' => 'E-300', 'full_name' => 'رضا کریمی', 'department' => 'ops',
                'job_title' => 'اپراتور', 'hire_date' => '2024-03-01', 'status' => 'active',
            ], 0);
            $leaveId = ErpSuiteDomain::save('leave', 10, 1, 1, [
                'employee_id' => 1, 'leave_type' => 'sick',
                'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'notes' => 'بیماری',
            ], 0);
            $prop = AiToolRegistry::executeForWorker(ua_job(), 'propose_erp_operation', [
                'domain' => 'suite', 'entity' => 'leave', 'operation' => 'review',
                'target_query' => (string)$leaveId, 'target_status' => 'approved',
            ]);
            $pid = (int)$prop['proposal_id'];
            $blocked = false;
            try {
                AiRepository::approveProposal($pid);
            } catch (Throwable $e) {
                $blocked = str_contains($e->getMessage(), 'سازنده');
            }
            ua_as(2, ua_full_perms());
            $ok = AiRepository::approveProposal($pid);
            $st = pdo()->prepare('SELECT status FROM erp_leave_requests WHERE id=?');
            $st->execute([$leaveId]);
            ua_json_out([
                'ok' => true,
                'creator_review_blocked' => $blocked,
                'final_status' => (string)$st->fetchColumn(),
                'executed' => !empty($ok['executed']),
            ]);
            break;

        case 'stale_snapshot_rejects':
            ua_reset_schema();
            ua_as(1, ua_full_perms());
            $empId = ErpSuiteDomain::save('employees', 10, 1, 1, [
                'code' => 'E-400', 'full_name' => 'مینا احمدی', 'department' => 'IT',
                'job_title' => 'تحلیلگر', 'hire_date' => '2024-04-01', 'status' => 'active',
            ], 0);
            $prop = AiToolRegistry::executeForWorker(ua_job(), 'propose_erp_operation', [
                'domain' => 'suite', 'entity' => 'employees', 'operation' => 'update',
                'target_query' => 'E-400',
                'data' => ['department' => 'فناوری'],
            ]);
            $pid = (int)$prop['proposal_id'];
            // Concurrent edit invalidates snapshot
            ErpSuiteDomain::save('employees', 10, 1, 1, [
                'code' => 'E-400', 'full_name' => 'مینا احمدی', 'department' => 'تغییر موازی',
                'job_title' => 'تحلیلگر', 'hire_date' => '2024-04-01', 'status' => 'active',
            ], $empId);
            ua_as(2, ua_full_perms());
            $stale = false;
            $msg = '';
            try {
                AiRepository::approveProposal($pid);
            } catch (Throwable $e) {
                $stale = true;
                $msg = $e->getMessage();
            }
            ua_json_out(['ok' => true, 'stale_rejected' => $stale, 'message' => $msg]);
            break;

        case 'enterprise_stale_revision':
            ua_reset_schema();
            ua_as(1, ua_full_perms());
            $id = EnterpriseDomain::save('positions', 10, 1, 1, [
                'code' => 'POS-1', 'title' => 'موقعیت تست',
                'department' => 'مالی', 'openings' => 2,
            ], 0);
            $prop = AiToolRegistry::executeForWorker(ua_job(), 'propose_erp_operation', [
                'domain' => 'enterprise', 'entity' => 'positions', 'operation' => 'update',
                'target_query' => 'POS-1',
                'data' => ['title' => 'موقعیت به‌روز', 'openings' => 3, 'department' => 'مالی', 'code' => 'POS-1'],
            ]);
            $pid = (int)$prop['proposal_id'];
            EnterpriseDomain::save('positions', 10, 1, 1, [
                'code' => 'POS-1', 'title' => 'موقعیت موازی', 'department' => 'مالی', 'openings' => 9,
            ], $id, 1);
            ua_as(2, ua_full_perms());
            $stale = false;
            $msg = '';
            try {
                AiRepository::approveProposal($pid);
            } catch (Throwable $e) {
                $stale = true;
                $msg = $e->getMessage();
            }
            $hist = AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_history', [
                'entity' => 'positions', 'query' => 'POS-1',
            ]);
            ua_json_out([
                'ok' => true,
                'stale_rejected' => $stale,
                'message' => $msg,
                'history_count' => (int)($hist['count'] ?? 0),
            ]);
            break;

        case 'cross_company_reject':
            ua_reset_schema();
            ua_as(1, ua_full_perms());
            ErpSuiteDomain::save('employees', 10, 2, 1, [
                'code' => 'E-X2', 'full_name' => 'کارمند شرکت دو', 'department' => 'A',
                'job_title' => 'X', 'hire_date' => '2024-01-01', 'status' => 'active',
            ], 0);
            $blocked = false;
            $msg = '';
            try {
                AiToolRegistry::executeForWorker(ua_job(1), 'erp_entity_read', [
                    'domain' => 'suite', 'entity' => 'employees', 'query' => 'E-X2',
                ]);
            } catch (Throwable $e) {
                $blocked = true;
                $msg = $e->getMessage();
            }
            ua_json_out(['ok' => true, 'cross_company_blocked' => $blocked, 'message' => $msg]);
            break;

        case 'module_disabled':
            ua_reset_schema();
            ModuleRegistry::setEnabled('hr', false);
            ua_as(1, ua_full_perms());
            $blocked = false;
            $msg = '';
            try {
                AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_search', [
                    'domain' => 'suite', 'entity' => 'employees', 'query' => 'x',
                ]);
            } catch (Throwable $e) {
                $blocked = true;
                $msg = $e->getMessage();
            }
            $cat = AiToolRegistry::executeForWorker(ua_job(), 'erp_entity_catalog', ['domain' => 'suite']);
            $hasEmployees = false;
            foreach (($cat['entities'] ?? []) as $ent) {
                if (($ent['entity'] ?? '') === 'employees') $hasEmployees = true;
            }
            ua_json_out([
                'ok' => true,
                'search_blocked' => $blocked,
                'message' => $msg,
                'catalog_hides_employees' => !$hasEmployees,
            ]);
            break;

        case 'reject_proposal':
            ua_reset_schema();
            ua_as(1, ua_full_perms());
            ErpSuiteDomain::save('employees', 10, 1, 1, [
                'code' => 'E-500', 'full_name' => 'نیما صادقی', 'department' => 'فروش',
                'job_title' => 'کارشناس', 'hire_date' => '2024-05-01', 'status' => 'active',
            ], 0);
            $prop = AiToolRegistry::executeForWorker(ua_job(), 'propose_erp_operation', [
                'domain' => 'suite', 'entity' => 'leave', 'operation' => 'create',
                'data' => [
                    'employee_id' => 'نیما صادقی', 'leave_type' => 'unpaid',
                    'starts_on' => '2026-12-10', 'ends_on' => '2026-12-11', 'notes' => 'رد شود',
                ],
            ]);
            $pid = (int)$prop['proposal_id'];
            ua_as(2, ua_full_perms());
            AiRepository::rejectProposal($pid);
            $st = pdo()->prepare("SELECT status FROM ai_action_proposals WHERE id=?");
            $st->execute([$pid]);
            $leaveCount = (int)pdo()->query("SELECT COUNT(*) FROM erp_leave_requests WHERE workspace_id=10 AND company_id=1")->fetchColumn();
            ua_json_out([
                'ok' => true,
                'status' => (string)$st->fetchColumn(),
                'no_leave_created' => $leaveCount === 0,
            ]);
            break;

        default:
            ua_json_out(['ok' => false, 'error' => 'unknown_scenario', 'scenario' => $scenario]);
            exit(2);
    }
} catch (Throwable $e) {
    ua_json_out(['ok' => false, 'error' => $e->getMessage(), 'scenario' => $scenario, 'trace' => $e->getFile().':'.$e->getLine()]);
    exit(1);
}
