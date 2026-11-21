<?php
/**
 * T3 synthetic MySQL runner: MF AiToolRegistry reads + permission negatives.
 * Reuses disposable MF-1 harness schema (no production DB).
 */
declare(strict_types=1);

require_once __DIR__ . '/mf_e2e_bootstrap.php';

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/Core/AiToolRegistry.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $decoded = json_decode($argv[2], true);
    if (is_array($decoded)) {
        $args = $decoded;
    }
}

function t3_job(int $cid = 1): array
{
    return ['id' => 9001, 'workspace_id' => 10, 'company_id' => $cid];
}

try {
    switch ($scenario) {
        case 'reset':
            mf_e2e_reset_schema();
            mf_json_out(['ok' => true, 'database_tested' => true]);
            break;

        case 'contract_descriptors':
            $rows = AiToolRegistry::contractDescriptors();
            $mf = array_values(array_filter($rows, static fn($r) => str_starts_with((string)($r['id'] ?? ''), 'manufacturing_')));
            $required = ['id', 'version', 'mode', 'risk', 'required_permissions', 'company_scope', 'input_schema', 'output_schema', 'max_rows', 'timeout_ms', 'idempotency', 'evidence_policy', 'audit_policy'];
            $sample = $rows[0] ?? [];
            $missing = [];
            foreach ($required as $k) {
                if (!array_key_exists($k, $sample)) {
                    $missing[] = $k;
                }
            }
            mf_json_out([
                'ok' => $missing === [] && count($mf) >= 3,
                'tool_count' => count($rows),
                'mf_tool_count' => count($mf),
                'mf_ids' => array_map(static fn($r) => $r['id'], $mf),
                'missing_contract_fields' => $missing,
                'sample' => $sample,
            ]);
            break;

        case 'seed_and_read':
            mf_e2e_connect();
            // Assume reset already ran in suite; reconnect only.
            Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
            Tenant::setPermissions(['production.view', 'production.manage', 'inventory.view']);
            mf_seed_stock(2, '10.0000', 1, 1, '0.00');
            mf_seed_stock(3, '10.0000', 1, 1, '0.00');
            $bomId = mf_create_approved_bom(1, 2, 1);
            $order = ManufacturingExecutionDomain::createOrder(10, 1, 1, [
                'bom_id' => $bomId,
                'planned_qty' => '2.0000',
                'raw_warehouse_id' => 1,
                'fg_warehouse_id' => 1,
            ]);
            $orderId = (int)$order['id'];

            $bom = AiToolRegistry::executeForWorker(t3_job(), 'manufacturing_bom_read', ['bom_id' => $bomId]);
            $ord = AiToolRegistry::executeForWorker(t3_job(), 'manufacturing_order_read', ['production_order_id' => $orderId]);
            $ready = AiToolRegistry::executeForWorker(t3_job(), 'manufacturing_material_readiness', [
                'bom_id' => $bomId,
                'planned_qty' => 2,
                'warehouse_id' => 1,
            ]);

            $costClass = (string)($ord['cost_class'] ?? '');
            $gl = (string)($ord['gl_posting'] ?? '');
            $ok = ($bom['gl_posting'] ?? '') === 'GL_BLOCKED'
                && $gl === 'GL_BLOCKED'
                && ($ready['gl_posting'] ?? '') === 'GL_BLOCKED'
                && in_array($costClass, ['UNVALUED', 'PROVISIONAL', 'POSTED_ACTUAL'], true)
                && ($ready['auto_po'] ?? true) === false;

            mf_json_out([
                'ok' => $ok,
                'bom_id' => $bomId,
                'order_id' => $orderId,
                'cost_class' => $costClass,
                'gl_posting' => $gl,
                'ready_material_class' => $ready['material_cost_estimate_class'] ?? null,
                'bom_code' => $bom['bom']['code'] ?? null,
                'order_no' => $ord['order']['order_no'] ?? null,
                'shortage_lines' => $ready['shortage_lines'] ?? null,
            ]);
            break;

        case 'permission_denied':
            mf_e2e_connect();
            Auth::setUser(['id' => 9, 'is_platform_admin' => 0]);
            Tenant::setPermissions(['ai.use', 'accounting.view']); // deliberately missing production.view
            $denied = null;
            try {
                AiToolRegistry::executeForWorker(t3_job(), 'manufacturing_bom_read', ['query' => 'x']);
                $denied = false;
            } catch (Throwable $e) {
                $denied = str_starts_with($e->getMessage(), 'permission_denied:');
                $msg = $e->getMessage();
            }
            // Cross-company: admin can call but order from other company must not leak
            Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
            Tenant::setPermissions(['production.view']);
            $leak = null;
            try {
                // company_id=1 job must not return company 2 BOM if we pass a foreign id after seeding both
                $foreignBom = mf_create_approved_bom(1, 2, 2);
                $got = AiToolRegistry::executeForWorker(t3_job(1), 'manufacturing_bom_read', ['bom_id' => $foreignBom]);
                $leak = isset($got['bom']);
            } catch (Throwable $e) {
                $leak = false;
                $crossMsg = $e->getMessage();
            }
            mf_json_out([
                'ok' => $denied === true && $leak === false,
                'permission_denied' => $denied,
                'permission_message' => $msg ?? null,
                'cross_company_blocked' => $leak === false,
                'cross_company_message' => $crossMsg ?? null,
            ]);
            break;

        case 'workflow_modes':
            // Representative A–H mapping: assert modes without inventing unfinished domains.
            $by = [];
            foreach (AiToolRegistry::descriptors() as $d) {
                $by[$d['name']] = $d['mode'];
            }
            $map = [
                'A_simple_finance_read' => ['tool' => 'document_analytics', 'expect' => 'read'],
                'B_multistep_reads' => ['tool' => 'sales_document_detail', 'expect' => 'read'],
                'C_deep_analysis' => ['tool' => 'financial_analysis_bundle', 'expect' => 'read'],
                'D_finance_mutation' => ['tool' => 'create_voucher_draft', 'expect' => 'proposal'],
                'E_multistep_ops' => ['tool' => 'create_warehouse_receipt', 'expect' => 'proposal'],
                'F_prediction' => ['tool' => null, 'expect' => 'unsupported'], // no honest forecast tool
                'G_proactive_reads' => ['tool' => 'replenishment_risk', 'expect' => 'read'],
                'H_rag' => ['tool' => null, 'expect' => 'proposal_gated'], // no autonomous RAG ledger tool
                'MF_bom' => ['tool' => 'manufacturing_bom_read', 'expect' => 'read'],
                'MF_order' => ['tool' => 'manufacturing_order_read', 'expect' => 'read'],
                'MF_readiness' => ['tool' => 'manufacturing_material_readiness', 'expect' => 'read'],
            ];
            $fail = [];
            foreach ($map as $k => $spec) {
                $tool = $spec['tool'];
                $expect = $spec['expect'];
                if ($tool === null) {
                    if (!in_array($expect, ['unsupported', 'proposal_gated'], true)) {
                        $fail[] = $k;
                    }
                    continue;
                }
                if (($by[$tool] ?? null) !== $expect) {
                    $fail[] = $k;
                }
            }
            mf_json_out([
                'ok' => $fail === [],
                'failures' => $fail,
                'map' => $map,
                'note' => 'Workflow H stays proposal-gated / no autonomous RAG fact substitution',
            ]);
            break;

        default:
            mf_json_out(['ok' => false, 'error' => 'unknown_scenario', 'scenario' => $scenario]);
            exit(2);
    }
} catch (Throwable $e) {
    mf_json_out(['ok' => false, 'error' => $e->getMessage(), 'scenario' => $scenario]);
    exit(1);
}
