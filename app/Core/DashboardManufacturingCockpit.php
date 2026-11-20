<?php
/**
 * Read-only manufacturing cockpit (MF-1 evidence surface).
 * Uses ProductionDomain reads + stock movement evidence when present.
 * Issue/QC/FG writers remain T1/T2-owned; this cockpit shows empty/blocked states clearly.
 */
final class DashboardManufacturingCockpit
{
    public const VERSION = 'rc1-t6-mf-cockpit';
    public const VALUATION_DEFAULT = 'BLOCKED';

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت/محیط کاری برای کابین تولید انتخاب نشده است.');
        }
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid, $cid]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست.');
        }
    }

    /**
     * @return array{company_id:int,boms:array,plans:array,evidence:array,blocks:array,next_actions:array,version:string}
     */
    public static function snapshot(int $wid, int $cid): array
    {
        self::assertCompany($wid, $cid);
        $moduleOn = class_exists('ModuleRegistry') ? ModuleRegistry::enabled('production') : true;
        $boms = [];
        $plans = [];
        $blocks = [];

        if (!$moduleOn) {
            $blocks[] = [
                'key' => 'module_disabled',
                'title' => 'ماژول تولید غیرفعال است',
                'detail' => 'از مرکز ماژول‌ها تولید را فعال کنید (پیش‌فرض opt-in).',
                'owner' => 'T0/admin',
            ];
        }

        try {
            $boms = ProductionDomain::listBoms($wid, $cid);
            $plans = ProductionDomain::listPlans($wid, $cid);
        } catch (Throwable $e) {
            $blocks[] = [
                'key' => 'schema_or_read_failed',
                'title' => 'خواندن BOM/برنامه ممکن نیست',
                'detail' => $e->getMessage(),
                'owner' => 'T1',
            ];
        }

        $evidence = self::stockEvidence($wid, $cid);
        $qc = self::qcEvidenceState($wid, $cid);
        $valuation = [
            'policy' => self::VALUATION_DEFAULT,
            'claim' => 'never_actual_without_t5',
            'note' => 'RC1: WIP/FG GL valuation remains BLOCKED unless T5 proves safe mapping.',
        ];

        if ($evidence['issue_count'] < 1 && $evidence['fg_count'] < 1) {
            $blocks[] = [
                'key' => 'no_stock_postings',
                'title' => 'شواهد مصرف/رسید ساخته‌شده موجود نیست',
                'detail' => 'حرکات production_issue / production_fg_receipt هنوز ثبت نشده‌اند یا API تی۲ آماده نیست.',
                'owner' => 'T1/T2',
            ];
        }
        if ($qc['status'] === 'blocked') {
            $blocks[] = [
                'key' => 'qc_blocked',
                'title' => 'ثبت QC عملیاتی مسدود',
                'detail' => $qc['detail'],
                'owner' => 'T1',
            ];
        }

        $planRows = [];
        foreach ($plans as $p) {
            $planRows[] = [
                'id' => (int)$p['id'],
                'bom_code' => $p['bom_code'] ?? '',
                'output_name' => $p['output_name'] ?? '',
                'warehouse_name' => $p['warehouse_name'] ?? '',
                'planned_qty' => $p['planned_qty'],
                'target_date' => $p['target_date'],
                'status' => $p['status'],
                'stage_label' => $p['status'] === 'draft' ? 'پیش‌نویس برنامه' : (string)$p['status'],
                'stage_tone' => $p['status'] === 'draft' ? 'info' : 'muted',
                'links' => [
                    'production' => 'index.php?page=production',
                ],
            ];
        }

        return [
            'company_id' => $cid,
            'module_enabled' => $moduleOn,
            'boms' => $boms,
            'plans' => $planRows,
            'evidence' => $evidence + ['qc' => $qc, 'valuation' => $valuation],
            'blocks' => $blocks,
            'counts' => [
                'boms' => count($boms),
                'plans' => count($planRows),
                'issues' => $evidence['issue_count'],
                'fg_receipts' => $evidence['fg_count'],
            ],
            'next_actions' => self::nextActions(count($boms), count($planRows), $evidence, $qc),
            'version' => self::VERSION,
        ];
    }

    private static function stockEvidence(int $wid, int $cid): array
    {
        $types = ['production_issue', 'production_fg_receipt', 'production_return'];
        $out = [
            'issue_count' => 0,
            'fg_count' => 0,
            'return_count' => 0,
            'rows' => [],
            'source' => 'acc_stock_movements',
        ];
        try {
            $st = pdo()->prepare("SELECT id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,source_type,source_id,reference_no,status
                FROM acc_stock_movements
                WHERE workspace_id=? AND company_id=? AND status='posted'
                  AND movement_type IN ('production_issue','production_fg_receipt','production_return')
                ORDER BY id DESC LIMIT 40");
            $st->execute([$wid, $cid]);
            $rows = $st->fetchAll();
            foreach ($rows as $r) {
                $t = (string)$r['movement_type'];
                if ($t === 'production_issue') {
                    $out['issue_count']++;
                } elseif ($t === 'production_fg_receipt') {
                    $out['fg_count']++;
                } elseif ($t === 'production_return') {
                    $out['return_count']++;
                }
                $out['rows'][] = $r;
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        $out['supported_types'] = $types;
        return $out;
    }

    private static function qcEvidenceState(int $wid, int $cid): array
    {
        // No QC table in G0 ProductionDomain; surface explicit blocked state.
        $hasQcTable = false;
        try {
            $st = pdo()->query("SHOW TABLES LIKE 'erp_production_qc%'");
            $hasQcTable = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            $hasQcTable = false;
        }
        if (!$hasQcTable) {
            return [
                'status' => 'blocked',
                'detail' => 'جدول/API کنترل کیفیت تولیدی در baseline فعلی وجود ندارد.',
                'rows' => [],
            ];
        }
        return ['status' => 'available', 'detail' => '', 'rows' => []];
    }

    private static function nextActions(int $bomCount, int $planCount, array $evidence, array $qc): array
    {
        $actions = [];
        $can = static fn(string $p): bool => class_exists('Tenant') && Tenant::can($p);
        if ($bomCount < 1 && $can('production.manage')) {
            $actions[] = [
                'label' => 'ایجاد فرمول ساخت پیش‌نویس',
                'href' => 'index.php?page=production',
                'allowed' => true,
                'permission' => 'production.manage',
            ];
        } else {
            $actions[] = [
                'label' => 'مشاهده BOM و سنجش مواد',
                'href' => 'index.php?page=production',
                'allowed' => $can('production.view'),
                'permission' => 'production.view',
            ];
        }
        if ($planCount < 1 && $can('production.manage')) {
            $actions[] = [
                'label' => 'ثبت برنامه تولید پیش‌نویس',
                'href' => 'index.php?page=production',
                'allowed' => true,
                'permission' => 'production.manage',
            ];
        }
        if ($evidence['issue_count'] < 1) {
            $actions[] = [
                'label' => 'مصرف مواد (منتظر API تی۲ / دستور T1)',
                'href' => 'index.php?page=inventory',
                'allowed' => false,
                'permission' => 'inventory.manage',
                'blocked' => true,
            ];
        }
        if ($evidence['fg_count'] < 1) {
            $actions[] = [
                'label' => 'رسید کالای ساخته‌شده (شواهد FG)',
                'href' => 'index.php?page=inventory',
                'allowed' => false,
                'permission' => 'inventory.manage',
                'blocked' => true,
            ];
        }
        if ($qc['status'] === 'blocked') {
            $actions[] = [
                'label' => 'QC — مسدود تا تحویل T1',
                'href' => 'index.php?page=production',
                'allowed' => false,
                'permission' => 'production.manage',
                'blocked' => true,
            ];
        }
        return $actions;
    }
}
